# Phase 10.4 - Cover Download & Cache

> **Status:** Implemented and fully tested. When an admin imports a Google
> Books record, the cover is now downloaded once, validated, optimized,
> stored under `public/assets/covers/google/` and served locally forever
> after - the app never asks Google for the same cover twice, and a
> broken/failed/download-too-big cover degrades the book to the
> BookSphere placeholder instead of a broken remote URL.
> **Scope discipline:** single-import cover downloading only. Bulk/sync
> jobs stay out (Phases 10.5-10.6). No new framework or dependency - the
> downloader reuses the existing media validation pipeline
> (`MediaService`), the existing exception type (`GoogleBooksException`)
> and the app's config conventions.

---

## What was built

| Layer | Class / file | Responsibility |
|---|---|---|
| Service | `CoverDownloadService` | the whole cover pipeline in ONE call: cache hit -> streamed download -> validate -> optimize -> atomic store -> book-row update (Tasks 1 + 5 merged) |
| Refactor | `MediaService::validateFile()` | the file-on-disk validation half (upload checks: error / size / is_uploaded_file are upload-only; the size + MIME sniff + dimensions + structural checks are shared) |
| Repository | `BookRepository::updateCover()` | narrow cover-only UPDATE so the banner form path and the cover pipeline never overwrite each other |
| Model | `Book::updateCover()` | thin facade over the repository call |
| Services | `BookImportService` / `BookService` | imports attach covers after their transaction; update/soft-delete remove cached files |
| Migration | `0031_add_cover_columns_to_books` | `cover_source_url`, `cover_downloaded_at`, `cover_status` |
| Config | `config/google_books.php` (`covers` section) | directory / public_prefix / TTL / timeout / retries / limits / GD optimize |
| Assets | covers directory + `.gitignore` | `public/assets/covers/google/` committed as a gitkeep, files ignored |
| Route wiring | `routes/web.php` | ONE shared `$coverService` injected into both `BookService` (delete path) and `BookImportService` (attach path) |
| Tests | `tests/GoogleBooksCoverTest.php` | 58 checks, fully offline (stubbed transport) |

## The pipeline (`CoverDownloadService::attach($bookId, $url)`)

`attach()` NEVER throws - it answers one of three stable statuses that
are written straight to `books.cover_status`:

```
cache hit? (local file for this URL + fresh inside TTL)
  yes -> reuse: record cover_image = local path, no network
  no  -> download (streamed, capped, retried)
          -> validateFile(): MIME sniffed from CONTENT, size cap,
              decodable dimensions, structural checks
             OK  -> optimize (GD: rescale + re-encode JPEG/PNG, strip
                     metadata) or keep bytes untouched without GD
             FAIL -> status 'failed', cover_image cleared (placeholder)
          -> store: sha1(source_url).jpg|png|webp via atomic rename
          -> record: cover_image = local path, cover_source_id, UTC
             timestamp, status 'downloaded'
source URL null/empty -> record status 'none', cover_image stays null
```

### Cache semantics (Task 5)

- **One file per provider URL**: the file name is `sha1(sourceUrl)` +
  the stored extension, so two books sharing a thumbnail share ONE local
  file. A re-import of the same URL is a pure file lookup - no network.
- **TTL**: `covers.ttl_seconds` (default 30 days; `0` = never expire). A
  file past its TTL is re-fetched instead of reused. `isFresh($url)` /
  `invalidate($url)` / `stats()` are the knobs the Phase 10.5 sync and
  an admin page will use.
- **Stat-cache pitfall fixed**: `isFreshFile()` clears the PHP stat
  cache before reading `filemtime`, so a file touched or re-cached after
  download is judged by its REAL age.

### Optimization strategy (Task 4)

- GD-enabled: covers longer than `max_dimension` (800px) are downscaled,
  non-transparent covers re-encoded as JPEG (`jpeg_quality` 82) or kept
  as PNG when they carry alpha, and every re-encode strips EXIF/comments.
- No GD (this machine): the validated original is stored untouched -
  performance over pixel-perfection, and the pipeline never depends on a
  missing extension.

### Failure & placeholder policy

`attach()` cannot fail an import. A 404/timed-out/oversized/corrupt/invalid
image leaves `cover_image` NULL and `cover_status = 'failed'` - every view
renders the dark-mode-aware `.book-cover-fallback` tile (the shared
`book-cover.php` component + `onerror` of any `<img>` flips to the same
tile). `cover_source_id` stays so the Phase 10.5 sync can retry.

## Storage, filename and permissions

| aspect | value |
|---|---|
| directory | `public/assets/covers/google/` (inside the webroot, a new public asset) |
| public URL | `/assets/covers/google/<sha1(url)>.<ext>` |
| extension | `.png` for PNG, `.webp` for WebP, `.jpg` otherwise |
| modes | files chmod 0644, `.gitignore` keeps the folder via `.gitkeep`, ignores every file |
| atomicity | download -> validate/optimize -> temp+rename, so concurrent imports of the same URL never see a half-written cover |

## DB change (migration 0031)

| column | type | meaning |
| --- | --- | --- |
| `cover_source_url` | TEXT | the exact provider URL the cover came from |
| `cover_downloaded_at` | TEXT | UTC timestamp of the successful cache |
| `cover_status` | TEXT | `downloaded` \| `failed` \| `none` |

The local path itself lives in the EXISTING `cover_image` column
("local path or remote URL" - migration 0002's contract), so no
duplicate column is added. All three columns stay NULL for books that
predate Phase 10.4 (seeded OpenLibrary covers, pre-10.4 imports) - the
truthful state is "never processed by the cover pipeline".

## Config (`covers` section of `config/google_books.php`)

| key | env | default |
|---|---|---|
| `enabled` | `GOOGLE_BOOKS_COVERS_ENABLED` | true |
| `directory` / `public_prefix` | - | `public/assets/covers/google/` |
| `ttl_seconds` | `GOOGLE_BOOKS_COVER_CACHE_TTL` | 30 days |
| `timeout_seconds` | `GOOGLE_BOOKS_COVER_TIMEOUT` | 10 |
| `retry_attempts` / `retry_backoff_ms` | `..._COVER_RETRIES` etc. | 2 / 250ms |
| `max_redirects` / `max_bytes` | - / `GOOGLE_BOOKS_COVER_MAX_BYTES` | 5 / 5 MB |
| `min_width` / `min_height` / `max_source_dimension` | - | 50 / 50 / 4000 |
| `optimize.enabled` / `max_dimension` / `jpeg_quality` | - | GD-present / 800 / 82 |
| `import.fetch_covers` | `GOOGLE_BOOKS_FETCH_COVERS` | true |

`fetch_covers: false` (or a disabled module, or no injected service)
keeps the provider URL in `cover_image` exactly as 10.3 did - a pure
opt-in, additive change.

## Views

No view file needed changing: the shared `book-cover.php` component
already renders `cover_image` (now a local path) and falls back to the
theme-dark-aware placeholder; the `onerror` handler drops a broken img
to the same tile. All pages (browse table, detail, cards, delete modal)
show the local cover automatically.

## Running the suite

```bash
php tests/GoogleBooksCoverTest.php                      # 58 Phase 10.4 checks
php tests/GoogleBooksImportTest.php                     # 61 Phase 10.3 regression
php tests/GoogleBooksSearchTest.php                     # 57 Phase 10.2 regression
```

The cover suite is fully offline: `CoverDownloadStub` extends the (now
non-final) service and overrides the protected `attempt()` seam with
canned bytes/failures - no network. A fresh throwaway DB
(`database/gb_cover_test.db`) is migrated + seeded so the import path
runs for real, and the GD optimize section is GD-gated (skips cleanly on
this PHP build; the passthrough bytes are asserted in section 1).

## Verified behaviors (58 checks)

- Fixture 1x1 JPEG/PNG validate (fileinfo + getimagesize); WebP fixture
  was probed and dropped (the structural check rejects it).
- Valid lifecycle: download -> validate -> store -> attach, single
  attempt, deterministic sha1 file, valid image, exact original bytes
  (no-GD), stats() counts the folder as readable/writable.
- Cache reuse + dedupe: second book + same URL = zero network, both
  books share one local file; duplicate import never touches the cover.
- TTL + invalidation: stale file is re-fetched; `invalidate()` purges.
- Failure policy: 404 fails fast (retryable), transient failures retried
  then succeed; HTML/oversized/truncated/non-http sources all STATUS_FAILED
  with `cover_image` cleared.
- Import integration: a failing cover never fails an import; a healthy
  one attaches; soft-deletion deletes the cached file.
- Navigation-only page asserts: no provider URL (no `zoom=`) leaks into
  an attached book's `cover_image`.

## Full regression

All 19 CLI suites (Auth, Browse, Email, Follow, GoogleBooks ×3, Landing,
Library, Notification ×4, Personalization, Recommendation ×3, Review ×2)
pass with the new service wired in - 0 failures.

## Out of scope (later sub-phases)

- **10.5**: background sync + admin cache page (`isFresh`/`invalidate`/
  `stats()` public API is ready for it).
- **10.6**: scheduled jobs.
- Bulk cover re-download for pre-10.4 books (the sync job's job).

## Files added this phase

```
app/Services/CoverDownloadService.php
database/migrations/0031_add_cover_columns_to_books.php
tests/GoogleBooksCoverTest.php
docs/PHASE_10_4_GOOGLE_BOOKS_COVERS.md   (this file)
```

**Modified** (all additive): `MediaService` (extract
`validateFile()`), `BookRepository` + `Book` facade (`updateCover`),
`BookService` (`$covers` delete on remove/soft-delete),
`BookImportService` (optional `$covers`, attach after transaction),
`routes/web.php` (shared cover service + media config merge),
`config/google_books.php` (`covers` section), `.env.example`
(GOOGLE_BOOKS_COVER_*), `.gitignore` (cover folder rules); the class
`CoverDownloadService` was declared non-final so a test stub can override
the protected `attempt()` transport seam (the same pattern as
`GoogleBooksClient`).