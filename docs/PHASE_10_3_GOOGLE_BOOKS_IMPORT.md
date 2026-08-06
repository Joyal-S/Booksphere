# Phase 10.3 - Google Books Import (single books)

> **Status:** Implemented. An admin imports ONE Google Books search
> result into the local catalogue behind a CSRF-protected admin route,
> with JSON answers for fetch() calls and a redirect + flash for no-JS
> forms. Scope discipline: **single book imports only** - covers are
> kept as the provider's remote thumbnail URL (no download yet), bulk
> import, sync and scheduled metadata refresh are deliberately out of
> scope (they are Phases 10.4-10.6).
> **Constraint honored:** no new framework or dependency; imports follow
> the exact conventions of the app's manual book form (find-or-create
> authors/categories, junction links, one SQLite transaction).

---

## What was built

| Layer | Class / file | Responsibility |
|---|---|---|
| Service | `BookImportService` | the ONLY place provider data becomes a `books` row: dedupe, find-or-create staging, single transaction |
| DTO | `ImportResult` | `{ status: imported\|duplicate, bookId, message }` + `isDuplicate()` |
| Service | `GoogleBooksService::volume()` | single-volume lookup: cache -> breaker -> live -> stale -> typed exception |
| Controller | `GoogleBooksController::import()` | `POST /admin/google-books/import`, dual JSON / redirect+flash answer |
| Repository | `BookRepository` | `findByGoogleBookId`, `findByIsbns`, `findByTitleAndAuthors`, `createImported`, `importedIds` |
| Models | `Book` / `Author` / `Category` | facade methods + `findOrCreate` (Author), `findOrCreate` + slug (Category) |
| Migration | `0030_add_import_columns_to_books` | `preview_link`, `provider_rating`, `provider_ratings_count` |
| Views | `_card.php` / `_results.php` / `google-books.php` | per-card Import form, "In library" state, feedback region |
| Assets | `google-books.css`, `google-books.js` | design-token styles + delegated fetch import handler |
| Route | `routes/web.php` | `POST /admin/google-books/import` (Admin + CSRF) |
| Tests | `GoogleBooksImportTest.php` | 61 checks, fully offline (stubbed transport) |

## Routes (all three are behind `AdminMiddleware`)

| Route | Action | Returns |
|---|---|---|
| `GET /admin/google-books` | `index()` | the search page (`GET` params) |
| `GET /admin/google-books/search` | `searchJson()` | results partial as JSON |
| `POST /admin/google-books/import` | `import()` | JSON `{ok, status, bookId, message}` or flash + redirect |

The import POST carries `CsrfMiddleware` like every data change; each
card submits its OWN `_token` + `google_book_id`, so it works through
JavaScript (intercepted by `google-books.js`) AND without it (plain
submit -> redirect + flash).

## The import flow (server always re-reads the source of truth)

```
card button (volume id) ──POST── google_book_id
  GoogleBooksController::import()
    id = trimmed POST body (empty / >128 chars -> 422, fetch; flash, redirect for no-JS)
    GoogleBooksService::volume(id)          <-- NEVER trusts the card:
        1. disabled       -> throw unavailable (an import is an explicit action;
                                                    a silent no-op would be wrong)
        2. fresh cache    -> serve without a provider call (TTL volume_ttl_seconds)
        3. breaker open   -> stale cache for THAT volume, or throw unavailable
        4. live call      -> client->lookup() + cache write + breaker heal
        5. failure        -> stale cache first, else the typed exception rethrown
    GoogleBooksException caught -> status mapping: not_found=404, rate_limited=503,
                            unavailable=503, default=502  (JSON {ok:false,reason})
    record maps to null (no title)      -> 422 "no usable title"
  BookImportService::import(ProviderBookDTO)->ImportResult
    dedupe (see below) -> duplicate/imported
    + one transaction: books row + authors + categories + junctions
```

The controller deliberately **re-fetches the volume server-side**: the
id in the card is only a lookup key, so a tampered card cannot smuggle
garbage into the catalogue.

## The dedupe order (the import's core decision)

Every import runs the checks in this order; the first hit answers
"duplicate" without writing anything:

1. **`google_book_id`** - the volume's own Google id (the `books`
   column is UNIQUE, so even a soft-deleted row blocks a re-import).
2. **ISBN candidates** - the record's ISBN-13 AND ISBN-10 AND their
   converted mirror forms (`isbn13From10` / `isbn10From13`). The books
   table stores ONE canonical ISBN; two records of the same physical
   book can carry the OTHER form, so each form is cross-checked and
   both forms are compared against the stored value.
3. **title + authors fallback** - a case-insensitive match for records
   with no ISBN at all (the last resort that keeps a repeat import from
   duplicating a record whose ISBN the provider omitted).

Only a fully absent candidate set (no ISBN, no title match) imports.

## Field mapping (what a provider record becomes)

| Provider DTO | books column(s) | Decision |
|---|---|---|
| `externalId` | `google_book_id` | required, UNIQUE, the primary dedupe key |
| `title`, `subtitle` | `title`, `subtitle` | plain copy |
| `description` | `description` | HTML stripped + entities decoded by the mapper |
| `publisher` | `publisher` | plain |
| `publishedYear` | `published_year` | parsed (missing -> NULL) |
| `language` | `language` | fallback `en` |
| `pageCount` | `page_count` | int |
| `isbn()` | `isbn` | ISBN-13 preferred, else ISBN-10 |
| `thumbnail` | `cover_image` | the provider's remote URL (cover download is Phase 10.4) |
| `previewLink`/`infoLink` | `preview_link` | best Google Books link |
| `averageRating`/`ratingsCount` | `provider_rating` / `provider_ratings_count` | **kept SEPARATE** from `average_rating`/`ratings_count` - those stay at zero; writing Google's figures into them would poison the recommendation scorer |
| config | `status` | `'published'` by default (`import.default_status`), so an import is instantly visible |
| — | `deleted_at` | explicitly NULL (fresh row) |

## The transaction (all-or-nothing)

`BookImportService::import()` wraps the book insert + the two junction
rels in ONE `beginTransaction()`:

```
beginTransaction
  createImported(columns)                     <- new books row
  replaceAuthors(bookId, authorIds)           <- authors find-or-create + links
  replaceCategories(bookId, categoryIds)      <- categories find-or-create + links
commit
any Throwable -> rollBack + rethrow           <- no orphan rows, no half-book
```

`findOrCreate` is the race-safe `INSERT OR IGNORE` + read-back pattern
on the UNIQUE `name` columns: `Author` inserts by `name`, `Category` by
`slug` (with a `-sha1` suffix fallback when the slug collides). Database
errors are deliberately NOT caught here (the shared ErrorHandler path
handles them); only the provider layer throws typed exceptions, caught
upstairs in the controller.

## Card state (one query per page)

`_results.php` receives an `existing` map (`[google_book_id => local
book id]`) built by `BookImportService::importedMap()`. **One** query
for the whole results page - the card compares its own id against the
map to render either a disabled "In library" button (check step) or an
active "Import" form. The map answers `importedIds()` over the page's
google ids, so a soft-deleted record shows "In library" too (the
UNIQUE fence blocks re-import).

## Dual answer (the app's standard pattern)

- **fetch** (`X-Requested-With: fetch`) -> `Response::json()`:
  - success: `{ok: true, status: 'imported'|'duplicate', bookId, message}`
  - failure: `{ok: false, errors}` (422), `{ok: false, reason, error}` + mapped status
- **no-JS** -> `session()->flash(...)` + `Response::redirect('/admin/google-books')`.

## Views + JS + CSS

- `_card.php`: import form per card (CSRF token, hidden volume id),
  `data-gb-import*` hooks, `In library` disabled state, feedback region.
- `_results.php`: serves both the page AND the `/search` JSON fragment,
  prints the stale/cached alert + the grid.
- `google-books.js`: ONE delegated click handler on `[data-gb-results]`,
  disables the button during the request, renders the server message
  (escaped) into the card feedback region, state machine for the
  disabled/"In library" repaint after a successful import.
- `google-books.css`: `.gb-card-actions`, `.gb-import-form`, `.gb-card-feedback`.

## Running the suites

```bash
php tests/GoogleBooksImportTest.php       # 61 Phase 10.3 checks
php tests/GoogleBooksSearchTest.php        # 57 Phase 10.2 regression
php tests/BrowseTest.php                    # 69 (master layout regression)
php tests/AuthTest.php                      # 73 (auth regression)
php tests/LibraryTest.php / ...            # full regression, 18 files
```

The import suite is fully offline: `GoogleBooksImportStub` answers
canned volumes, the cache+breaker write to the system temp folder, and
a fresh throwaway SQLite DB (`database/gb_import_test.db`) is migrated
and seeded so find-or-create posts genuinely hit the real tables. The
controller's dual answer is proven in subprocesses (the CLI SAPI cannot
report `http_response_code()` in-process once output has started), and
one section forces a failure - `CREATE TEMPORARY TRIGGER fail_import`
- to verify the whole transaction rolls back.

## Verified behaviors (the test suite's outline)

- Volume lookup: mapping, validation, cache hit (TTL files), breaker
  open (no live call), typed failures (not_found / unavailable).
- Import mapping: field-by-field storage, published default, rating
  isolation, author/category find-or-create + links.
- Dedupe: same id, cross-form ISBN, title+author fallback, case
  -insensitive title-only, soft-deleted still blocks.
- Atomicity: failing import leaves zero rows, zero authors, zero
  categories, zero junction rows.
- Controller: fetch JSON 200/duplicate/422/404/422; no-JS flash probe;
  card view smoke (In library / CSRF / volume id / feedback).
- Idempotency: an identical POST never creates a second row.

## Out of scope (later sub-phases)

- **10.4**: cover download (validate + persist via MediaService,
  `cover_image` still falls back to the remote URL) and bulk import.
- **10.5**: background sync (`SyncService`, `google_books_sync.php`).
- **10.6**: scheduled jobs / journalist dumps - currently just tech-debt
  agreements written to the Phase 10.1 architecture blueprint.

## Files added this phase

```
app/DTO/ImportResult.php
app/Services/BookImportService.php
database/migrations/0030_add_import_columns_to_books.php
tests/GoogleBooksImportTest.php
docs/PHASE_10_3_GOOGLE_BOOKS_IMPORT.md   (this file)
```

**Modified** (additive only): `GoogleBooksService::volume()`,
`GoogleBooksController::import()` (+ constructor now takes
`BookImportService`), `BookRepository` + `Book` facade, `Author` /
`Category` `findOrCreate`, `routes/web.php`, the three Google Books
views, `google-books.css` / `google-books.js`, and
`tests/GoogleBooksSearchTest.php` (controller constructor + a migrated
in-memory DB for the card-state query).

## Migration 0030

`0030_add_import_columns_to_books` adds three NULL columns to `books`
(forward-only, no existing data touched):

| column | type | meaning |
| --- | --- | --- |
| `preview_link` | TEXT | the Google Books preview/detail link |
| `provider_rating` | REAL | the PROVIDER's rating (0-5) |
| `provider_ratings_count` | INTEGER | the PROVIDER's count |

`provider_rating`/`provider_ratings_count` stay NULL until a book is
imported - locally created books simply have no provider record, which
is the truthful state. The comment in the migration explains that
writing them into `average_rating`/`ratings_count` would corrupt the
recommendation scorer. The `down()` drops the three columns.