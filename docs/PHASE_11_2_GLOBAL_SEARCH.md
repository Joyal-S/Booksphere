# Phase 11.2 - Global Search (implementation)

> **Status:** DONE. This sub-phase implements the architecture laid out
> in `docs/PHASE_11_1_SEARCH_ARCHITECTURE.md` (the design-only phase that
> shipped `config/search.php`).
> **Constraint honored:** no new frameworks or dependencies (PHP 8.2
> standard library only) - the browse-module patterns, the Config/
> Session/RateLimiter wiring and the Views partials layout are all
> reused. No schema migration is needed (verified by the 11.1 database
> audit: the existing B-tree index inventory covers every filter/sort
> path).
> **Stack:** Controller -> Service -> Builders -> Providers ->
> Repositories -> PDO -> SQLite; one shared service instance wired in
> `routes/web.php`; session-backed rate limiting; the same Validator the
> existing request gates use.

---

## What ships in this phase

| Concern | File(s) | Role |
|---|---|---|
| Inbound gate | `app/Requests/SearchQueryRequest.php` | validates `q`, `scope`, `page`, `per_page` against whitelists + caps |
| Query spec | `app/DTO/SearchQuerySpec.php` | immutable, provider-neutral data structure the builder *and* repository consume |
| Result records | `app/DTO/SearchHit.php` + `app/DTO/SearchResult.php` | immutable result rows / envelope |
| Typed failure | `app/Exceptions/SearchException.php` | static factories, mapped to HTTP by the controller |
| Data access | `app/Repositories/SearchRepository.php` | per-scope prepared SQL (books/authors/categories/publishers/reviews) |
| Provider seam | `app/Services/SearchProvider.php` (interface) + `app/Services/SqliteSearchProvider.php` (impl) + `app/Services/SearchProviderFactory.php` (registry) | future engine drops behind the SAME interface |
| Orchestrator | `app/Services/SearchService.php` | the ONLY class the controller calls |
| Response shape | `app/Services/SearchResultFormatter.php` | raw rows -> `SearchResult` (+ JSON envelope + engine flags) |
| Controller | `app/Controllers/SearchController.php` | thin; one route answers JSON (fetch) or a rendered page |
| Views | `app/Views/search/index.php`, `partials/_results.php`, `partials/_hit.php` | full page, results list, single hit card |
| Assets | `public/assets/js/search.js` (debounced JSON search), `search.css`, `app.js` (module) | live search on the search page only |
| Wiring | `routes/web.php` + `app/Views/partials/{head,scripts,sidebar,header}.php` | route registration, head search box, scripts preload |
| Tests | `tests/SearchTest.php` | 47 checks; every feature verified against a throwaway DB |

---

## The search layer (what each class does)

```
 Browser query  ->  SearchQueryRequest (gate: whitelists/caps)
        |
        v
 SearchService::search()  (orchestrator; never touches SQL)
        |  SearchQueryBuilder::build() -> SearchQuerySpec (neutral)
        v
 SearchProvider (SqliteSearchProvider) -> SearchRepository (prepared SQL)
        |  rows: books | authors | categories | publishers | reviews
        v
 SearchResultFormatter::format() -> SearchHit[] + pagination envelope
        v
  JSON  (fetch callers)  /  rendered page (app/Views/search/)
```

### SearchQueryRequest - the inbound gate
- Trims, strips control characters, enforces `min_length`/`max_length`/
  `max_words` from `config('search.query.*')`.
- Whitelists `scope` against the enabled entities in
  `config('search.entities.*.enabled')` (default `books`); rejects
  disabled future entities (`users`, `collections`) with a clean message.
- Whitelists `per_page` against `config('search.pagination.allowed')`
  (12/24/48/96); clamps `page` below 1 to 1.
- Exposes `toSpec(): SearchQuerySpec` so the builder and the repository
  consume the SAME normalized shape.

### SearchQueryBuilder - the query spec
- Translates the validated request into an immutable `SearchQuerySpec`:
  `term`, `words[]` (phrases preserved), `scope`, `page`, `perPage`,
  `limit` (=min(perPage, max_results)), `offset`.
- Holds the tokenizing rules (multi-word AND-combination - "harry
  potter" must match both words somewhere).

### SearchRepository - the data access
- The module's ONLY SQL owner (the existing entity facades are reused
  for entity reads). Prepared statements only; every condition bound.
- Five reads, one per scope (all share the COUNT + page clamp):
  - `searchBooks($spec, $filters)` -> `[[id, hit, url, description, ...]]`
  - `searchAuthors()` / `searchCategories()` / `searchPublishers()` /
    `searchReviews()`
- **Page clamp (browse parity):** the OFFSET is clamped AFTER the COUNT,
  `offset = max(0, min(offset, total - limit))`. A request for page 999
  slides to the LAST real page of rows instead of returning an empty
  page; the formatter reflects the clamped page number. This matches the
  browse module's behavior exactly.
- Author/category relations use `EXISTS` subqueries (never a JOIN that
  multiplies rows), the proven browse rule.

### SqliteSearchProvider + SearchProviderFactory
- The provider seam (Phase 11.1 Task 11): the interface is
  `search(SearchQuerySpec) : SearchResultRow[]`; the factory resolves
  `config('search.provider')` back to `SqliteSearchProvider`. A future
  FTS5/Meilisearch drop implements the same interface - the application
  never changes.
- Dispatch is per-scope: the provider builds ONE matched query per scope
  (no cross-entity scoring yet; a deterministic ORDER BY `title` /
  `created_at` keeps results stable until Phase 11.6 ranking).

### SearchService - the orchestrator
- The page never sees SQL or the provider. `search()` calls
  `SearchProviderFactory::create()`, wraps the call in the
  `performance.timeout_seconds` budget, and degrades *gracefully*:
  a failing search answers a `SearchResult` with an `error` field +
  empty hits, exactly like the browse/Google-Books empty states.

### SearchResultFormatter - the response shape
- Converts repository rows into `SearchHit[]` (normalized `title`,
  `description`, `subtitle`, `page number`, `date`, `author`/`category`
  names) and computes the pagination envelope (`total`, `page`,
  `perPage`, `pages` with the page clamp).
- **Exactness flag:** offers `shows both matched and other` snap; the
  JSON responses carry the SAME `hit` shape used by HTML partials.
- Every rendered field is `e()`-escaped (the untrusted provider data
  rule) - the view models built here are what the partials actually
  render.

### SearchController - thin
- `search()`: 200 JSON when the request is a fetch (`X-Requested-With:
  fetch` or `Accept: application/json` => JSON envelope); otherwise the
  full rendered page `search/index.php`.
- Maps `SearchException` -> HTTP status; validates via the gate; never
  touches SQL or the provider directly.

### Views + assets
- `search/index.php` - the full page (search box, scope tabs, result
  region `<div id="search-results">`), renders the classic layout via
  the existing section/partial structure.
- `partials/_results.php` - the list of `_hit.php` cards (book: cover +
  title + creator + description + author chips; labels for other
  scopes), plus the empty state (no matches / still typing / error).
- `partials/_hit.php` - one card, always `e()`-escaped.
- `search.css` - the page's design-system CSS (cards, chips, pagination,
  scope tabs, responsive grid).
- `search.js` - debounced fetch against `GET /search` (JSON), swaps the
  results region, keeps the query in its own module; DRY: the page ALSO
  has a progressive enhancement path.
- `partials/head.php` - the header search box, wired to the search page;
  `scripts.php` - preloads the search bundle; `sidebar.php` - "Search"
  nav entry.

---

## Routing & safety (what the route actually guards)

- `GET /search?q=...&scope=...&page=...&per_page=...` is registered in
  `routes/web.php` behind the existing middleware stack and wired to the
  shared `SearchController` instance.
- Every input passes the validator gate before the builder sees it. No
  raw concatenation: every condition is a bound prepared statement.
  column/sort tokens come from whitelists. Session buckets `search`
  (60/min) guard the endpoints.

---

## Config this phase (all from `config/search.php`, Phase 11.1)

| Key | Effect in this phase |
| --- | --- |
| `enabled` | master switch (disabled => 503 friendly) |
| `provider` | 'sqlite' solver (factory) |
| `query.*` | min/max length, max words, max results |
| `pagination.*` | default page size + allowed whitelist |
| `performance.timeout_seconds` | search budget (5s default) |
| `rate_limit.search` | session bucket (60/min) |
| `entities` | scope catalog; books/authors/categories/publishers/reviews enabled; users/collections disabled |

## Scope card (this phase)

| Scope | Search source | Matches when |
| --- | --- | --- |
| books | `books` | `title`/`subtitle`/`description`/`publisher`/`language`/`isbn` (`LIKE`, exact fields also `=`), author/category via EXISTS |
| authors | `authors.name` | LIKE |
| categories | `categories.name`/`slug` | LIKE |
| publishers | distinct `books.publisher` | LIKE |
| reviews | `reviews.body` | LIKE |

No schema change needed (the B-tree index inventory from the 11.1 audit
covers every filter/sort path; SQLite FTS is the documented scale-up
path and stays a later provider-drop).

---

## Files added / modified in this phase

```
ADDED
app/Controllers/SearchController.php
app/DTO/SearchHit.php
app/DTO/SearchQuerySpec.php
app/DTO/SearchResult.php
app/Exceptions/SearchException.php
app/Repositories/SearchRepository.php
app/Requests/SearchQueryRequest.php
app/Services/SearchProvider.php          (interface)
app/Services/SearchProviderFactory.php
app/Services/SearchResultFormatter.php
app/Services/SearchService.php
app/Services/SqliteSearchProvider.php
app/Builders/SearchQueryBuilder.php
app/Views/search/index.php
app/Views/search/partials/_hit.php
app/Views/search/partials/_results.php
public/assets/css/search.css
public/assets/js/search.js
tests/SearchTest.php
docs/PHASE_11_2_GLOBAL_SEARCH.md        (this file)

MODIFIED (additive wiring only):
  routes/web.php                        (+ /search route + shared SearchController)
  app/Views/partials/head.php           (+ search box + scope options)
  app/Views/partials/scripts.php        (+ search.js preload)
  app/Views/partials/sidebar.php        (+ Search nav entry)
  app/Views/partials/header.php         (+ live-search box)
  public/assets/js/app.js               (+ fetch helper; search.js loaded on search page)
  .env                                  (nothing required; SEARCH_* stay optional)
```

---

## Test coverage (`tests/SearchTest.php`, 47 checks)

- **Request gate:** valid query, whitelisted per_page, default scope,
  over-long term rejected, over word cap rejected, disabled scope
  rejected, controls stripped.
- **Builder/valid: multi-tokenized spec, word combinations, offset for a
  page, clamp per_page, scope normalization.
- **Books search:** matching by title/subtitle/description, author name
  (relation), category name, publisher, language, ISBN; multi-word AND;
  JSON shape.
- **Other scopes:** authors, categories, publishers, reviews each match
  a row and return sane metadata.
- **Pagination:** page params, total/pages calculation, page-beyond-last
  clamps to the real last page (browse parity), per_page whitelist.
- **Error paths:** over-long term, over word cap, disabled scope, no
  SQL error leaks.
- **Response envelope:** JSON keys, pages flag, empty page when no hits.

Coverage summary (35 new + a 12-check regression section). Run:
`php tests/SearchTest.php` (throwaway DB `database/search_test.db` is
deleted after the run; inspect-on-failure keeps it in place).

### Full regression suite (all green after this phase)

`AuthTest` (73), `BrowseTest` (69), `LandingTest`, `ReviewTest`, ... ,
`GoogleBooksSearchTest` (57), `RecommendationDashboardTest` (64),
`NotificationCenterTest` (83), `LibraryTest`, `LibrarySecurityTest`,
`LibraryPerformanceTest`, `FollowTest`, `EmailNotificationTest`, etc. -
**0 failures** across the board.

---

## Verified outcomes

1. 47/47 SearchTest checks pass.
2. Every existing suite re-runs green (regression-safe wiring changes).
3. `php -l` clean across every new/modified PHP file.
4. Manual smoke (via a throwaway site DB) confirms:
   - `GET /search?q=harry&scope=books` -> books page with hits.
   - `scope=authors/categories/publishers/reviews` each return their
     scope rows.
   - Page 999 clamps to the last page; empty term renders the empty
     state; over-limit queries answer the friendly validation message.
   - Fetch callers receive the JSON envelope; the page stays
     server-rendered otherwise.
   - Rate limiter (search bucket) 429s after the configured window.

## What is NOT in this phase (deferred, per the 11.1 mandate)

- Advanced filters (status, category, author, year, rating, language) ->
  **Phase 11.3** (`docs/PHASE_11_3_FILTERS.md`).
- Suggestions + query history -> **Phase 11.4**.
- Analytics + SearchCache -> **Phase 11.5**.
- Relevance ranking -> **Phase 11.6**.