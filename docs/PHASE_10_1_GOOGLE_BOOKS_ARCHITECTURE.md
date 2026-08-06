# Phase 10.1 - Google Books API Integration: Architecture Blueprint

> **Status:** Design only. Nothing in this phase is implemented.
> **Already shipped in this phase:** `config/google_books.php` (the
> configuration system, Task 2) and the `.env` documentation in
> `.env.example`. Everything else below is the architecture that the
> Phase 10.2+ sub-phases implement.
> **Constraint:** no new frameworks or dependencies (PHP 8.2 standard
> library only); every decision follows the existing project
> conventions - the Stack (Controller -> Service -> Model facade ->
> Repository -> PDO -> SQLite), module-owned exceptions, DTOs and
> policies, one shared service instance wired in `routes/web.php`,
> file-based caches, incremental forward-only migrations.

---

## Conventions this design reuses (do not re-invent)

| Concern | Existing pattern | Blueprint rule |
|---|---|---|
| Data access | `Book` / `Review` facade + `BookRepository` / `ReviewRepository` | `Book` facade (reused) + `GoogleBooksRepository` (provider reads/writes) |
| Provider abstraction | `RecommendationStrategy` interface + `RecommendationFactory` registry | `BookProvider` interface + `ProviderFactory` registry (Task 9) |
| Business orchestration | `RecommendationService` / `LibraryService` (single facade, guards, logger) | `GoogleBooksService` (the only entry point controllers call) |
| HTTP transport | `SmtpTransport` (curl), built-in PHP only | `GoogleBooksClient` (curl, typed exceptions) |
| Module failure type | `ReviewException` / `LibraryException` with `::static` factories | `GoogleBooksException` with factories per failure class (Task 5) |
| Result records | `ReviewDTO` / `LibraryItemDTO` (immutable value objects) | `ProviderBookDTO` (provider-neutral, immutable) |
| Write throttle | `RateLimiter(session())` + config buckets | session bucket `google_book_search` / `google_book_import` |
| Result cache | `PersonalizationCache` (JSON files, atomic rename, TTL, invalidation, stats) | `CacheManager` with the EXACT same file technique (Task 6) |
| Config | `config/*.php` groups read through `config()` / `env()` | `config/google_books.php` (already created, this phase) |
| Skill cheating / schema | `Migrator`, forward-only `00NN_*.php` migrations | `0030`-`0032` designed below (written in Phase 10.2) |
| Media files | `MediaService` (MIME sniff, structural PNG/JPEG/WebP checks, random names) | reused verbatim by `CoverDownloadService` for downloads |
| Caching downfall walks | `RecommendationService` degrades silently, logs, rebuilds | identical cache-first / offline degradation |
| JSON vs form | one route answers both (`X-Requested-With: fetch` -> JSON, else redirect + flash) | identical on the Google Books endpoints |

---

## Task 1 - The integration layer (service responsibilities)

The layer is split so that every concern lives in exactly one class:

```
                    ┌───────────────────────────────────────────────┐
  Controllers ────► │  GoogleBooksService   (orchestrator, facade)   │
 (Phase 10.3+ )     │  search() lookup() - the ONLY public entry      │
                    └───────────────┬───────────────────────────────┘
                          │ uses ProviderFactory + CacheManager + Repository
                          ▼
                    ┌──────────────────────────────┐
                    │  BookProvider (interface)     │   Task 9 abstraction
                    └───────────────┬──────────────┘
                                    ▼
                    ┌──────────────────────────────┐
                    │  GoogleBooksProvider        │  (implements BookProvider)
                    │   · endpoint shapes         │   mappers inside here
                    │   · payload → ProviderBookDTO│
                    └───────────────┬──────────────┘
                                    ▼
                    ┌──────────────────────────────┐
                    │  GoogleBooksClient (HTTP)    │   transport only
                    │   timeout · retries · 429     │
                    └──────────────────────────────┘

  Persisting the result:
                    BookImportService ─► CoverDownloadService
                          │
                          ▼
                    Book / Author / Category (facades)
                          │
                          ▼
                    GoogleBooksRepository (SQL) ─► SQLite

  Background:        SyncService  (cron/CLI metadata refresh)
```

### GoogleBooksClient — the HTTP transport (no business logic)
- Owns the base URL, the optional API key, the timeout, the User-Agent
  and the retry loop. Talks to the provider REST endpoints only.
- `search(string $query, int $maxResults): array` — raw `items` payload.
- `lookup(string $googleId): ?array` — single volume payload (404 -> null).
- `download(string $url): string` — bytes of a remote cover
  (https-only, host allowlist, `Content-Length` cap, timeout).
- Every failure becomes a typed `GoogleBooksException` (network /
  timeout / 429 / 5xx / invalid JSON). Retries ONLY transient errors
  with exponential backoff and honors the provider's `Retry-After`.
- **Deliberately no** caching, no mapping, no business logic.

### BookProvider (interface) + GoogleBooksProvider (adapter)
- `BookProvider` defines the contract: `search()`, `lookup()` and
  return **provider-neutral** `ProviderBookDTO`s. Google Book-specific
  knowledge (endpoint paths, response shapes, thumbnail size mapping,
  `industryIdentifiers` parsing) lives ONLY inside `GoogleBooksProvider`.
- This is the seam that makes Open Library / ISBNdb / others additive
  later (Task 9): a new provider implements the same interface and the
  `ProviderFactory` picks it from config `google_books.php` semantics.

### GoogleBooksService — the orchestrator / single entry point
- The only class the controllers (and future sync hook) talk to. Mirrors
  `BookService` as a thin, well-named public API:
  - `search(string $term, array $opts): ProviderSearchResult` — sanitize
    the term (length cap >= `search.query_max_length`), consult the
    cache, ask the chosen provider, cache the result, return normalized
    records. **Never touches the database.**
  - `lookup(string $id): ?ProviderBookDTO` — cache-first volume read.
- Holds: `ProviderFactory`, `CacheManager`, `GoogleBooksRepository`
  (for duplicate hints), `Logger`.
- Failure mode when the provider is disabled/unreachable: **graceful
  degradation** — returns empty results + a safe notice, never a crash.

### BookImportService — the only place provider data becomes books
- Converts a `ProviderBookDTO` into a persisted `books` row (Task 3
  mapping), create-or-find authors (`authors.name` UNIQUE) and
  categories (`categories.name` UNIQUE), wires the many-to-many links,
  records provenance in `book_provider_records`.
- Dedupe order: 1) `books.google_book_id` (the provider's own id),
  2) any ISBN (`books.isbn` / `isbn_13` / `isbn_10`) -> link to the
  existing book instead of inserting (Task 5 duplicate handling).
- On re-import of an already-known book it MERGES provider fields,
  never clobbering fields the user edited in the book admin form.
- Fires the same recommendation-cache flush as a manual book write
  (`GoogleBooksService` -> `RecommendationService::flushPersonalization`).
- Always wrapped in a transaction; a failure rolls back the whole
  import (no half books).

### CoverDownloadService — safe cover persistence
- When `config.google_books.import.fetch_covers` is true, downloads the
  cover to `database/cache/google_books`/`storage/cache/covers` after
  import and writes `books.cover_image` to the local copy; otherwise
  `cover_image` keeps the remote provider URL.
- Reuses `MediaService`'s exact validation (content-sniffed MIME,
  size cap, PNG/JPEG/WebP structural checks, random file names) so a
  corrupt or hostile image is never written.
- **Never throws into the caller**: a failed download logs and falls
  back to the remote URL, then the app's default placeholder.

### SyncService — background reconciliation (Phase 10.5+)
- Periodically re-fetches metadata for imported books, compares the
  stored `books.metadata_hash`, updates provider-sourced fields ONLY
  when the payload actually changed, refreshes `last_synced_at` and
  the volume cache, optionally re-downloads expired covers.
- Runs as a CLI/cron worker (`tools/google_books_sync.php`) with
  `config.google_books.sync.batch_size`. Isolated per book so one bad
  volume never stalls the job. Never deletes a book; never overwrites
  manual admin edits; skips cleanly when the API is down.

### CacheManager — the response cache (Task 6 for the full strategy)
- File-based TTL cache with the same proven technique as
  `PersonalizationCache` (JSON file per key, `temp + rename` atomic
  writes, TTL freshness check, `invalidate`/`flush`/`stats`).
- Namespaces: `search:<sha1(query+max)>`, `volume:<google_id>`,
  covers live in the `cover_directory` as files.
- Single source of read for `search()` / `lookup()`; the cache miss
  path re-computes and repopulates.

### GoogleBooksRepository — every SQL query of this module
- `findByGoogleId(string): ?book row` • `findByIsbn(string): ?book row`
- `upsertProviderRecord(...)` • `bookProviderRecords(int $bookId)`
- `metadataHashFor(int $bookId)` / `updateProviderRecord(
  ...)`
- `syncDueBooks(int $batch, ...)` • cache/statistics reads.
- Prepared statements only, `deleted_at IS NULL` respected (a soft
  deleted book is NOT matched for import/dedupe).

---

## Task 2 - Configuration

Shipped this phase: **`config/google_books.php`** (see the file). Every
value comes from `env()`/`.env`; nothing is hardcoded.

| Setting | Env var | Default | Purpose |
|---|---|---|---|
| Master switch | `GOOGLE_BOOKS_ENABLED` | `false` | off = whole layer a no-op (module optional, like Email) |
| Base URL | `GOOGLE_BOOKS_BASE_URL` | `https://www.googleapis.com/books/v1` | endpoint family; lets a local mock replace the API |
| API key | `GOOGLE_BOOKS_API_KEY` | `null` (optional) | server-side only, never printed |
| Request timeout | `GOOGLE_BOOKS_TIMEOUT` | `10` (seconds) | socket connect/read timeout |
| Retry attempts | `GOOGLE_BOOKS_RETRIES` | `2` | transient failures only + exponential backoff |
| Cache duration | `GOOGLE_BOOKS_SEARCH_CACHE_TTL` / `..._VOLUME_CACHE_TTL` / `..._COVER_CACHE_TTL` | 900 / 86400 / 30 d | per-namespace freshness |
| Max search results | `GOOGLE_BOOKS_MAX_RESULTS` | `20` | API ceiling per request |
| Image size preference | `GOOGLE_BOOKS_IMAGE_SIZE` | `thumbnail` | selected by the provider when building the cover |
| Import / sync toggles | `GOOGLE_BOOKS_FETCH_COVERS`, `GOOGLE_BOOKS_SYNC_ENABLED` | true / false | behavioural switches |
| Circuit breaker | `GOOGLE_BOOKS_CIRCUIT_RECOVERY` | 300 s | cache-only window after repeated failures |

Read with `config('google_books.client.timeout_seconds')` exactly like
`config('email.smtp.host')`.

---

## Task 3 - Data mapping (Google Books -> BookSphere)

The mapper lives in `GoogleBooksProvider` (Google-specific) and returns
a provider-neutral `ProviderBookDTO`. **Missing fields are handled per
field** — nothing ever assumes a value exists.

| Google Books | BookSphere column | Notes / graceful handling |
|---|---|---|
| `id` (top level) | `google_book_id` (existing UNIQUE column) | Required for import; without it the record is unusable and skipped |
| `volumeInfo.title` | `title` (NOT NULL) | Required-ish: missing/empty -> record flagged "no title", not imported |
| `volumeInfo.subtitle` | `subtitle` | missing -> NULL |
| `volumeInfo.authors[]` | `book_authors` -> `authors.name` | create-or-find by UNIQUE name; missing/empty -> no author link |
| `volumeInfo.categories[]` | `book_categories` -> `categories` | "Genres"; create-or-find by UNIQUE name (slug derived); missing -> no genre link |
| `volumeInfo.imageLinks.thumbnail` | `cover_image` | missing -> null (placeholder shows); download decided by `fetch_covers` |
| `volumeInfo.description` | `description` | HTML stripped (`strip_tags`), trimmed, length-capped (e.g. 20000); missing -> NULL |
| `volumeInfo.publishedDate` | `published_year` (+ `published_date`) | parse leading 4 digits for year; full ISO string in the new column; unparseable -> NULL |
| `volumeInfo.industryIdentifiers[]` | `isbn_13` / `isbn_10` / `isbn` | ISBN_13 into `isbn`, else ISBN_10; **checksum-validated**; invalid/missing -> NULL (google_book_id stays the dedupe) |
| `volumeInfo.language` | `language` | validated against `BookService::LANGUAGES`; unknown -> `en` (column default) |
| `volumeInfo.pageCount` | `page_count` | int cast, 1..big; junk -> NULL |
| `volumeInfo.publisher` | `publisher` | missing -> NULL |
| `volumeInfo.previewLink` / `infoLink` | `source_url` (new) | link to the provider page |
| `volumeInfo.averageRating` / `ratingsCount` | **NOT mapped** | these are Google's own reviews; BookSphere's `average_rating` / `ratings_count` are the community's. Mapping would poison recommendation + display data. The raw pair is stored in `book_provider_records.metadata` only |

Graceful-degradation summary: a receipt with *no* ISBN and *no*
category still imports (google_book_id is the identity); a record with
*no title* is the only one rejected. Everything else degrades to NULL /
placeholder and is rechecked on the next sync.

---

## Task 4 - Database review

### What already exists and is reused
`books` (migrations 0002 + 0010): `google_book_id` (UNIQUE) — the
primary dedupe key; `isbn` (UNIQUE) - secondary dedupe; `title`,
`subtitle`, `description`, `publisher`, `published_year`, `language`,
`page_count`, `cover_image`, `status`, `deleted_at`, `created_at`,
`updated_at`. `authors` (`name` UNIQUE), `categories` (`name`+`slug`
UNIQUE) + the two junction tables. `books### stale rating columns
`average_rating`/`ratings_count` are NOT imported (see mapping note).

### Additions required (migrations written in Phase 10.2; designed here)
**`0030_add_provider_metadata_to_books`** - all NULLABLE / defaulted,
forward-only, no existing data touched:
- `published_date TEXT` - full ISO publication date (Google gives a
  full date where the admin form only holds a year).
- `isbn_10 TEXT`, `isbn_13 TEXT` - the two industry identifiers kept
  separately for lookup (`books.isbn` keeps the preferred one for
  uniqueness).
- `source TEXT NOT NULL DEFAULT 'manual'` - provenance
  ('manual' | 'google_books' | 'open_library' | ...) - Task 9.
- `source_url TEXT` - canonical provider page.
- `metadata_hash TEXT` - sha256 of the last imported provider payload
  (the sync change-detector).
- `last_synced_at TEXT` - the worth of the last provider refresh.
- indexes: `idx_books_isbn_10`, `idx_books_isbn_13`, `idx_books_source`.

**`0031_create_book_provider_records`** - the multi-provider
provenance table (this is what makes Task 9 cheap):
```sql
CREATE TABLE book_provider_records (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    book_id        INTEGER NOT NULL REFERENCES books(id) ON DELETE CASCADE,
    provider       TEXT    NOT NULL,            -- 'google_books' | ...
    external_id    TEXT    NOT NULL,            -- the provider's own id
    metadata       TEXT,                        -- last raw payload (JSON)
    primary_flag   INTEGER NOT NULL DEFAULT 0,  -- which provider drives sync first
    last_synced_at TEXT,
    created_at     TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    UNIQUE (provider, external_id)              -- cross-provider dedupe fence
);
CREATE INDEX idx_bpr_book ON book_provider_records (book_id);
```
- `google_book_id` on `books` remains the fast Google import check
  (already UNIQUE-indexed by the schema); `book_provider_records`
  generalizes dedupe to every future provider without touching `books`.

**`0032` (optionally, Phase 10.2)** - the response CACHE needs **no
table** (file-based, Task 6), so this slot is reserved for the cover
folder setup or a stats counter only if the file cache later proves
insufficient.

### Constraints / indices
- Keep `books.google_book_id` UNIQUE and `books.isbn` UNIQUE - these
  ARE the dedupe fence. `book_provider_records` adds the composite
  `UNIQUE (provider, external_id)`.
- New columns never require a data migration (all nullable/defaulted).
- The `deleted_at IS NULL` rule stays: an import/sync never revives a
  soft-deleted book.

---

## Task 5 - Error handling

A single module exception with factory methods (the `LibraryException`
pattern), then a recovery matrix.

`app/Exceptions/GoogleBooksException.php`:
- `networkFailure()` - curl could not connect / DNS / TLS.
- `timeout()` - `timeout_seconds` exceeded.
- `rateLimited(int $retryAfterSeconds)` - HTTP 429: backoff, honour
  `Retry-After`, batch jobs pause, the session bucket denominators.
- `invalidResponse()` - JSON that does not match the schema (wrong
  types, malformed payload): leak no payload, treat as a cache miss.
- `notFound()` - a `lookup()` 404 - normal flow (null result).
- `invalidIsbn(string $isbn)` - checksum failure before any request.
- `duplicateBook()` - the book already exists (resolve, not throw at
  the top level - see below).
- `providerUnavailable()` - `enabled=false` or circuit open.

| Scenario | Behaviour |
| --- | --- |
| Network failure / timeout / 5xx | retry with backoff; exhausted -> `CacheManager` cache-first read; a db cache read; miss -> empty result + flash "provider is temporarily unreachable"; logged once |
| Rate limiting (429) | honour `Retry-After`; circuit breaker ticks; session `RateLimiter` bucket on search endpoints so one user can't burst |
| Invalid/unexpected response (200 but junk) | logged, treated as a miss (never a crash); next retry/refresh heals |
| Missing metadata | the graceful-degradation mapping of Task 3; book still imports without the missing parts |
| Invalid ISBN | checksum rejected **before** the API call - no request wasted, a clear message |
| Duplicate book | by id -> "already in your catalogue" linking the existing `{id}`; by ISBN -> same, via `GoogleBooksRepository::findByIsbn` |
| Cover download failure | logged, falls back to the remote URL, then the placeholder; never aborts the import |
| Timeout | same as network failure (they share the retry path) |
| Batch (sync) | per-volume isolation: one bad record is skipped+logged, the job continues and completes next run |

---

## Task 6 - Caching strategy

Same proven file technique as `PersonalizationCache`:

- **Namespaces & keys**: `search:<sha1("$query|$max")>` (result set),
  `volume:<sha1($googleId)>` (single record), covers = bytes in
  `cache.cover_directory`.
- **Expiration** (config-driven): search 15 min, volume 24h, cover
  30 days (a longer TTL allows the offline fallback to work for days).
- **Invalidation**: `CacheManager::invalidateSearch()`,
  `invalidateVolume($id)` and `flush()` (an admin "flush provider
  cache" mirroring `admin/recommendations/cache/flush`); an import /
  sync overwrites the volume entry with the fresh payload.
- **Offline fallback**: every read is cache-first; on a network dark
  window the cache IS the fallback. A stale volume entry older than
  TTL is served but flagged `stale: true` so the UI can show "cached
  - could not refresh".
- **Guardrails**: corrupt JSON = miss; broken cache dir = degrades to
  uncached + logged warning (never an exception); atomic writes
  prevent half-reads.
- **Why files, not a new table**: no new dependency, mirrors the
  established recommendation cache, keeps cache management off the
  hot DB row.

---

## Task 7 - Folder structure (all files of the module)

```
config/
  google_books.php          DONE this phase

app/DTO/
  ProviderBookDTO.php            provider-neutral imported record (immutable)
  ProviderSearchResult.php      { items: ProviderBookDTO[], totalItems, stale }

app/Exceptions/
  GoogleBooksException.php      the single module exception (Task 5)

app/Controllers/
  GoogleBooksController.php     Phase 10.3:+ search (JSON), preview, import,
                                import-confirm; thin, dual fetch/form pattern

app/Providers/                  (root-mirrors app/Strategies of the rec engine)
  BookProvider.php              interface: searchBooks() / lookup() -> DTOs
  ProviderFactory.php          builds the configured provider
  GoogleBooksProvider.php      Google-specific adapter + the Task 3 mapper

app/Services/
  GoogleBooksService.php        orchestrator - public entry (Task 1)
  GoogleBooksClient.php         HTTP transport + retry + typed exceptions
  BookImportService.php         provider-data -> persisted books (dedupe,
                                authors/categories, provenance, transaction)
  CoverDownloadService.php      cover persistence + safe image validation
  SyncService.php               CLI/cronsync (batch, isolated)
  CacheManager.php              file cache (Task 6)

app/Repositories/
  GoogleBooksRepository.php     all module SQL (dedupe, provider records)

app/Models/
  Book.php                      extended with the new columns' accessors only

app/Requests/
  SearchBooksRequest.php        query length/country caps (testable rules)
  ImportBooksRequest.php         google ids / selection validation

routes/web.php                  Phase 10.3 route block (AdminMiddleware +
                                AuthMiddleware + RateLimiter)
database/migrations/
  0030_add_provider_metadata_to_books.php
  0031_create_book_provider_records.php

tools/
  google_books_sync.php         CLI entry for SyncService
docs/
  PHASE_10_1_GOOGLE_BOOKS_ARCHITECTURE.md   (this document)

storage/cache/covers/           downloaded cover files (gitignored)
database/cache/google_books/    the TTL response cache
```

---

## Task 8 - Security

| Concern | Where it is handled |
| --- | --- |
| Input validation | `SearchBooksRequest` (length + strip control chars), `ImportBooksRequest` (sha1 pattern) `isbn` validated (regex + checksum) BEFORE the request |
| API response validation | `GoogleBooksProvider` schema-checks every key's type/count before the DTO; junk -> logged invalid response, never trusted |
| Safe image handling | `CoverDownloadService` reuses `MediaService` (content-sniffed MIME, PNG/JPEG/WebP structural checks, size cap, min dimensions); No file is executed |
| No photo-arbitrary writes | filenames are random (`bin2hex(random_bytes(8))`), dirs fixed from config, writes only under `cover_directory`; URL never used in a path |
| Rate limiting | (a) session `RateLimiter` buckets on the endpoints (`google_search` 60/min), (b) circuit breaker for the provider, (c) Google's own daily quota honored by `max_results` |
| No exposed API key | key only in `.env` (gitignored) + read server-side; never rendered in views nor sent to the browser JS |
| Secure error handling | provider errors are logged (`Logger`) with context; only friendly, non-internal messages reach the page; no payload/key in flashes |
| SSRF guard | the client only talks to `base_url` (config) and cover downloads are restricted to an https allowlist (`books.google.com` family, configurable) |

---

## Task 9 - Future compatibility (provider abstraction)

- The seam is the **`BookProvider` interface + `ProviderFactory`**,
  exactly mirroring `RecommendationStrategy` + `RecommendationFactory`.
- `ProviderBookDTO` is provider-neutral; provider-specific parsing
  lives in provider, so adding a source is:
  1. a new class that implements `BookProvider` (e.g.
     `OpenLibraryProvider`),
  2. an entry in the factory's config,
  3. `book_provider_records` already stores `provider` + `external_id`,
     so provenance and dedupe remain correct across sources,
  4. nothing changes in the service, importer, sync, cache, controller
     or schema.
- Open Library (free, no key) is the natural second provider; ISBNdb /
  Google / Amazon adapt at the same seam. Goodreads has no public API
  - it would be read-only importing or a wrapper, still a provider.

---

## Task 10 - Data flows (the documentation that ships with the code)

### Search flow
```
GoogleBooksController::search
  -> SearchBooksRequest (validate)
  -> GoogleBooksService::search(term)
       1. CacheManager::get("search:{sha1(term)}")      [hit -> return]
       2. (miss) GoogleBooksClient::search(term)        [with 429 retries]
       3. GoogleBooksProvider::buildDtoList(items)      [schema-validate]
       4. CacheManager::put(..., ttl=search_ttl)        [atomic write]
     |-- returns ProviderSearchResult -> view/JSON
     |-- any failure -> circuit breaker + empty result + flash
```

### Import flow
```
BookImportService::import(ProviderBookDTO, ...)
  -> validate DTO (title present)
  -> dedupe: GoogleBooksRepository.findByGoogleId / findByIsbn
       -> existing book -> GoogleBooksService::duplicateBook
  -> transaction:
       Book::create(row from Task 3 mapping)
       authors find-or-create (name UNIQUE) -> book_authors
       categories find-or-create (name/slug UNIQUE) -> book_categories
       book_provider_records.upsert(provider, googleId, payload, metaHash)
  -> CoverDownloadService (best effort, non-throwing)
  -> RecommendationService.flushPersonalization()
```

### Sync flow (background)
```
google_books_sync.php
  -> SyncService::run(batch: 25)
     for each due book (last_synced_at old, not deleted):
         lookup -> ProviderBookDTO
         sha1(payload) vs books.metadata_hash
            changed -> BookService::updateFromProvider(...) ONLY source-sourced
                     cover refresh -> last_synced_at now
            unchanged -> last_synced_at now
         volume cache entry refreshed
         any single-book failure -> log + continue
  -> batched, isolated, idempotent
```

---

## Verification of THIS phase (nothing implemented yet)

1. `php -l config/google_books.php` and lint the env example.
2. Smoke `config()` loads the new group without the module enabled:

```php
php -r "require 'bootstrap/app.php'; ... "
```

3. The full existing test suite still passes (config additions are the
only diff; the module is disabled by default).

---

## Final report (this phase)

### Files created
- `config/google_books.php` - the Task 2 configuration system.
- `docs/PHASE_10_1_GOOGLE_BOOKS_ARCHITECTURE.md` - this blueprint.
- `.env.example` - the `GOOGLE_BOOKS_*` documentation.
  (No PHP classes yet: search / import / cover / sync / controllers /
  repositories are intentionally NOT implemented - they are the Phase
  10.2+ sub-phases.)

### Files to be created in later sub-phases (designed above)
- `app/Providers/BookProvider.php`, `ProviderFactory.php`,
  `GoogleBooksProvider.php`
- `app/Services/GoogleBooksClient.php`, `BookImportService.php`,
  `CoverDownloadService.php`, `SyncService.php`, `CacheManager.php`
- `app/Repositories/GoogleBooksRepository.php`
- `app/Exceptions/GoogleBooksException.php`
- `app/DTO/ProviderBookDTO.php`, `ProviderSearchResult.php`
- `app/Requests/SearchBooksRequest.php`, `ImportBooksRequest.php`
- `app/Controllers/GoogleBooksController.php` + the route block
- `tools/google_books_sync.php`
- migrations `0030`, `0031` (and `0032` as reserved)

### Files modified (this phase)
- `.env.example` (documentation only).

### Database changes (this phase)
- **none**. Designed for the sub-phases: m0030 (provider metadata
  columns) + 0031 (`book_provider_records`), both additive/forward-only.

### Architecture summary
A 7-piece provider layer (`GoogleBooksClient` transport → `BookProvider`
+ `GoogleBooksProvider` adapter → `GoogleBooksService` orchestrator →
`BookImportService` / `CoverDownloadService` / `SyncService` / file `CacheManager`)
that follows the exact MVC + Repository + file-cache conventions of the
existing app, with `book_provider_records` + the `BookProvider`
interface making every future provider purely additive.

### Risks
- **Rate limits / quota** without a key are hard (1000 req/day/IP);
  search-hit caching + session throttles mitigate but do not remove it.
- **Data quality**: description HTML, partial dates, missing
  ISBN/covers need the degraded mapping to be conservative (never a
  business-crash).
- **Import collisions** with existing manual books (ISBN) - the
  merge-not-clobber rule and the "link duplicate" UX must be built
  carefully in 10.3.
- **API drift**: schema validation makes a Google Books schema change
  a logged "invalid response" degradation instead of a break.

### Recommendations
1. Ship the config master switch OFF (default) so the module stays
   entirely dormant until Phase 10.3 UI exists.
2. Implement thin unauthenticated CLIENT + provider mapping first
   (Phase 10.2) and validate against the live API (tests can point
   `base_url` at `tools/` a local fixture) - the integration layer the
   later UI sub-phases can trust.
3. Do NOT map Google's ratings into `books.average_rating` - keep user
   data honest.
4. When a second provider is added, extend the **factory config** and
   add its tests; the interface already exists.
5. Re-run the full suite at the end of every Phase 10 sub-phase; the
   module never touches the DB/cache unless `enabled` is true.