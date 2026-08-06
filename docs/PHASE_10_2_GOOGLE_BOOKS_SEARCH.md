# Phase 10.2 - Google Books Search (UI + Provider Layer)

> **Status:** Implemented. Search is live, admin-only, network-tested by
> a stubbed transport. **Scope discipline:** this phase implements the
> Google Books SEARCH only - the `ProviderBookDTO` records are rendered
> and never written to the catalogue. No import, no cover download, no
> sync, no database writes.
> **Constraint honored:** no new framework or dependency (PHP 8.2
> stdlib + curl + its one existing JSON helper), the Stack
> Convention (Controller -> Service -> Client/Provider -> DTO) and the
> file-cache decision from Phase 10.1.

---

## What was built

| Layer | Class / file | Responsibility |
|---|---|---|
| Controller | `GoogleBooksController` | 2 admin routes, thin orchestration, no try/catch |
| Service | `GoogleBooksService` | the ONE entry point: search, cache, circuit breaker, memo |
| Cache | `CacheManager` | namespaced JSON files (search/volume), atomic rename, TTL, stale() |
| Breaker | `CircuitBreaker` | open/half-open/closed after N failures, cache-only mode |
| Transport | `GoogleBooksClient` | curl + exponential retry + typed `GoogleBooksException` mapping |
| Mapper | `GoogleBooksProvider` | payload -> `ProviderBookDTO`, ISBN checksums, thumbnail zoom |
| DTOs | `ProviderBookDTO`, `ProviderSearchResult` | immutable, provider-neutral records |
| Request | `SearchBooksRequest` | declarative rules, scope->`intitle:` prefixes, ISBN gates |
| Views | `admin/google-books*.php` + partials | search page + shared results partial |
| Assets | `google-books.css`, `google-books.js` | design-token styling + debounced/abortable live search |
| Routes | `routes/web.php` | `GET /admin/google-books` + `/admin/google-books/search` |

## Routes (both behind `AdminMiddleware`)

| Route | Action | Returns |
|---|---|---|
| `GET /admin/google-books` | `index()` | the search page (server-renders results for no-JS) |
| `GET /admin/google-books/search` | `searchJson()` | `{html, total, page, pages, perPage, query, stale, cached}` or `422 {errors}` |

The two routes are wired as the browse module is: the index renders the
page (and the results inline), the JSON route returns the SAME
`_results.php` partial as a fragment. A result set is shareable
(`history.replaceState` keeps `/admin/book?type=...&q=...&page=N`).

## The search contract (graceful by design)

`GoogleBooksService::search()` **never throws**. Every failure degrades:

1. **disabled** (`GOOGLE_BOOKS_ENABLED=false`) -> empty result with a
   friendly notice; no network, no cache write.
2. **memoized** -> the same request already ran this page.
3. **fresh cache** -> served without contacting the provider (TTL
   `cache.search_ttl_seconds`, 900s).
4. **circuit open** -> stale cache only (`cache.stale()`), never a live call.
5. **live call** -> client + mapper + cache write + breaker heal.
6. **failure** -> stale cache preferred, otherwise a friendly error
   (reason is recorded + logged once).

## Request -> Google Books query mapping

`SearchBooksRequest` maps the scope selector to the `q` param, quoting
every term so a phrase stays a phrase:

| Scope | Prefix | Example |
|---|---|---|
| any | *(raw)* | `"harry potter"` |
| title | `intitle:` | `intitle:"Harry Potter"` |
| author | `inauthor:` | `inauthor:tolkien` |
| isbn | `isbn:` | `isbn:9780439064873` |
| publisher | `inpublisher:` | `inpublisher:penguin` |
| subject | `subject:` | `subject:"science fiction"` |

`type=isbn` is checksum-gated **before** any request: a malformed ISBN
is a 422 field error, never an API call.

## Degradation + safety

- **Circuit breaker** `cache.circuit_breaker.{max_failures=3, recovery_seconds=300}`:
  after 3 consecutive failures the module answers from cache only.
- **Fail-open**: on a provider failure the stale cache entry (if any)
  is served instead of an empty page; the view flags it.
- **Index ceiling**: Google Books stops at index 1000; the page count is
  clamped to what the API can physically serve, and a requested page
  beyond it snaps back to the last real page.
- **Escaping**: every provider field passes through `e()` at render
  time (third-party data); HTML is stripped + entities decoded by the
  mapper.

## Files added this phase

```
app/DTO/ProviderBookDTO.php              (already in 10.1 design)
app/DTO/ProviderSearchResult.php
app/Exceptions/GoogleBooksException.php  (already in 10.1 design)
app/Requests/SearchBooksRequest.php
app/Services/GoogleBooksClient.php
app/Services/GoogleBooksProvider.php
app/Services/GoogleBooksService.php
app/Services/CacheManager.php
app/Services/CircuitBreaker.php
app/Controllers/GoogleBooksController.php
app/Views/admin/google-books.php
app/Views/admin/google-books/partials/_results.php
app/Views/admin/google-books/partials/_card.php
public/assets/css/google-books.css
public/assets/js/google-books.js
tests/GoogleBooksSearchTest.php
```

**Modified** (additive only): `app/Core/Validator.php` (`error()`),
`routes/web.php`, `app/Views/partials/{head,scripts,sidebar}.php`.

## Running the suite

```bash
php tests/GoogleBooksSearchTest.php   # 57 checks
php tests/BrowseTest.php               # 69 (regression, master layout)
php tests/AuthTest.php                  # 73 (regression)
php tests/LibraryTest.php               # 278 (regression)
```

The search suite runs entirely offline: the transport is a stubbed
client, the cache/breaker write to the system temp folder, and the
controller smoke renders the real master layout + partials.