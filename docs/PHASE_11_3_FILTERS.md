# Phase 11.3 - Advanced Search Filters

> **Status:** DONE. This sub-phase adds the books-scope filter toolbar
> to the Phase 11.2 global search: status, category, author, publisher,
> language, publication-year range and minimum rating - the same
> filter contract the browse module has shipped since Phase 5.5.
> **Constraint honored:** no new dependencies, no schema change (the
> 11.1 database audit's existing index inventory covers every filter
> column: `books.status`, `books.language`, `books.average_rating`,
> `books.published_year`, `books.publisher` and the `book_authors` /
> `book_categories` junction indexes). Every new query travels the
> existing prepared-statement `db()` wrapper.

---

## What ships in this phase

| Concern | File(s) | Change |
|---|---|---|
| Filter whitelist config | `config/search.php` | new `filters` group: per-filter `enabled` toggle + the value maps (statuses / languages / ratings) and bounds (year min/max, publisher max_length) |
| Query spec | `app/DTO/SearchQuerySpec.php` | `filters` slot (normalized map) + `hasFilters()` |
| Inbound gate | `app/Requests/SearchQueryRequest.php` | `filters()` - whitelist-driven normalization of every filter input; tampered values SILENTLY DROPPED (browse philosophy), non-book scopes get `[]` |
| Builder | `app/Builders/SearchQueryBuilder.php` | threads `request->filters()` into the spec |
| SQL | `app/Repositories/SearchRepository.php` | `bookFilters()` (one bound condition per active filter, EXISTS for relations) + `filterOptions()` (distinct categories / authors / publishers of the live catalogue) |
| Provider seam | `app/Services/SearchProvider.php` + `SqliteSearchProvider.php` | new `filterOptions()` interface method (delegates to the repository) |
| Service | `app/Services/SearchService.php` | `filterOptions()` (provider values + config whitelists) + static `queryString()` (the single chip/pagination URL builder, mirroring `BookService::queryString`) + empty-term-with-filters now RUNS the search |
| Controller | `app/Controllers/SearchController.php` | reads the 8 filter inputs, passes `$filters` + `$options` to page & JSON partial |
| Views | `search/index.php`, `search/partials/_filters.php`, `search/partials/_results.php` | the filter grid + active-filter chips + filter-aware pagination/empty states |
| Assets | `search.css` (chips row), `search.js` (`[data-auto-submit]` on filter selects) | reuses the browse module's `book-browse-filter-grid` classes - no parallel design system |
| Tests | `tests/SearchTest.php` | +23 checks (gate normalization, every filter effect, combined filters, options vocabulary, queryString, controller page/JSON) - 70/70 green |

---

## The filter contract (what the user can ask)

| Filter | Query param | SQL condition | Vocabulary source |
|---|---|---|---|
| Status | `status` | `b.status = ?` | `config('search.filters.status.values')` |
| Language | `language` | `b.language = ?` | `config('search.filters.language.values')` |
| Minimum rating | `min_rating` | `b.average_rating >= ?` | `config('search.filters.rating.values')` |
| Publication year from | `year_from` | `b.published_year >= ?` | `config('search.filters.year.min')` .. `max` |
| Publication year to | `year_to` | `b.published_year <= ?` | same bounds |
| Category | `category_id` | `EXISTS (book_categories ... category_id = ?)` | `filterOptions()` - distinct live categories |
| Author | `author_id` | `EXISTS (book_authors ... author_id = ?)` | `filterOptions()` - distinct live authors |
| Publisher | `publisher` | `b.publisher LIKE ?` | `filterOptions()` - distinct live publishers |

Rules that hold the contract together:

1. **Whitelist everything.** Static maps and bounds live in
   `config/search.php`; catalogue-derived options come from the
   provider. A value outside its whitelist is silently dropped by
   `SearchQueryRequest::filters()` - it never becomes an error, never
   reaches SQL. This is the browse module's exact philosophy (a
   tampered query string degrades gracefully).
2. **Books only.** Filters carry book columns; the other scopes
   (authors, categories, publishers, reviews) have nothing to filter,
   so `filters()` returns `[]` for them and the UI hides the bar.
3. **Filters + term compose.** Both AND into the same WHERE clause, so
   "harry + status=published" narrows the term search and
   "status=published" alone browses the catalogue (the browse
   module's filters-without-a-term behavior - the Phase 11.2 empty
   page now only appears when there is NO term AND NO filter).
4. **Combined filters AND together.** Selecting category + author +
   year range narrows the result set intersection-style; each active
   filter renders as a removable chip.

---

## The pipeline (what changed where)

```
 GET /search?q=harry&status=published&category_id=4&min_rating=4
   |
   v
 SearchQueryRequest  ->  term (as before) + filters()  [whitelist drop]
   |
   v
 SearchQueryBuilder  ->  SearchQuerySpec(term, filters: [...])
   |
   v
 SearchService::search()   - runs even with an empty term when filters
   |                          are active (filters-only browsing)
   v
 SqliteSearchProvider ->  SearchRepository::searchBooks()
   |                        bookWhere()  (term, EXISTS relations)
   |                     + bookFilters() (one bound condition per filter)
   v
 SearchResultFormatter ->  SearchResult (hits + total + pages)
   |
   v
 search/index.php  +  _filters.php (the bar)  +  _results.php (the list)
     - chips: every active filter = one removable link
     - pagination: queryString() keeps q + scope + EVERY filter
```

### SearchQueryRequest::filters() - the whitelist gate
- Reads `status`, `language`, `min_rating`, `year_from`, `year_to`,
  `category_id`, `author_id`, `publisher` from the input.
- Validates EACH against its config vocabulary/bounds; invalid values
  are skipped silently. `year_from`/`year_to` must be integers within
  `[filters.year.min, filters.year.max]`; ids must be positive
  integers; publisher is a trimmed string capped at
  `filters.publisher.max_length`.
- Returns `[]` for every non-books scope - the controller hides the
  filter bar there and the repository never sees a filter it cannot
  apply.

### SearchRepository - bookFilters() + filterOptions()
- `bookFilters()` appends exactly one prepared condition per active
  filter (every value bound, every column hard-coded - no
  interpolation). Author/category filters use the SAME EXISTS route
  as the term search, so multi-author/multi-category books never
  multiply COUNT (the browse module's proven rule).
- `filterOptions()` returns the DISTINCT category/author/publisher
  values of the LIVE catalogue (non-deleted books only) so the
  dropdowns can never offer an option that yields zero rows by
  definition.

### SearchService - filterOptions() + queryString()
- `filterOptions()` merges the provider's catalogue values with the
  config whitelists into one vocabulary map the view renders.
- `queryString()` is the static URL builder for the chips and the
  pagination bar - the ONE place that knows how filters map to query
  parameters, so the two can never disagree (the exact mirror of
  `BookService::queryString`). Signature:
  `queryString($filters, $remove = [], $overrides = [])` - empty
  values are dropped; `$remove` deletes a filter (chip links);
  `$overrides` swaps page numbers.

### Views
- `_filters.php` renders ONLY inside the books scope: a compact
  grid of selects (status/language/rating/category/author), a
  publisher text input with a datalist, and the from/to year
  inputs. Every select carries `data-auto-submit` so search.js
  refetches on change (progressive enhancement - the no-JS form
  submits the same query string).
- `index.php` renders the chips row under the form: one removable
  pill per active filter (status/language/rating/category/author/
  publisher/chip "Published X–Y" for the year range) plus a
  "Clear all" link.
- `_results.php` now builds its pagination links through
  `queryString()` (filters preserved across pages) and its
  empty/summary states distinguish "no term + no filters" (Type to
  search) from "filters produced nothing" (No results with these
  filters).

### search.js
- Filter selects are wired to the same debounce-free immediate
  refetch as the scope radios: `[data-auto-submit]` -> reset page
  to 1 -> fetch. The publisher/year free-text fields keep the
  debounced `[data-live-search]` behavior. The URL stays shareable
  through the existing `history.replaceState` sync.

---

## Config added (`config/search.php`)

```php
'filters' => [
    'status'    => ['enabled' => true, 'values' => ['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived']],
    'category'  => ['enabled' => true],
    'author'    => ['enabled' => true],
    'publisher' => ['enabled' => true, 'max_length' => 120],
    'language'  => ['enabled' => true, 'values' => ['en' => 'English', 'hi' => 'Hindi', 'es' => 'Spanish', 'fr' => 'French', 'de' => 'German']],
    'year'      => ['enabled' => true, 'min' => 1000, 'max' => 2100],
    'rating'    => ['enabled' => true, 'values' => ['3' => '3 stars & up', '4' => '4 stars & up', '4.5' => '4.5 stars & up']],
],
```

Every filter is independently toggleable (an operator can disable the
status filter without touching a class); the same config drives the
gate, the service and the view - there is exactly ONE source of truth
for the vocabulary.

---

## Files added / modified this phase

```
MODIFIED:
  config/search.php                       (filters group)
  app/DTO/SearchQuerySpec.php             (filters slot + hasFilters())
  app/Requests/SearchQueryRequest.php     (filters() whitelist gate)
  app/Builders/SearchQueryBuilder.php     (threads filters into spec)
  app/Repositories/SearchRepository.php   (bookFilters + filterOptions)
  app/Services/SearchProvider.php         (interface: filterOptions())
  app/Services/SqliteSearchProvider.php   (delegates filterOptions)
  app/Services/SearchService.php          (filterOptions + queryString + empty-term-with-filters)
  app/Controllers/SearchController.php    (8 filter inputs, view data)
  app/Views/search/index.php              (chips row + _filters include)
  app/Views/search/partials/_results.php  (filter-aware pagination/empty states)
  public/assets/css/search.css            (chips row styles)
  public/assets/js/search.js              (data-auto-submit handler)
  tests/SearchTest.php                    (+23 checks; section 4)

ADDED:
  app/Views/search/partials/_filters.php  (the filter bar)
  docs/PHASE_11_3_FILTERS.md              (this file)
```

---

## Test coverage (`tests/SearchTest.php` section 4, 23 checks)

- **Gate:** whitelisted values survive; tampered values (bad status,
  rating 9, year 9999/77) silently dropped; non-book scopes get no
  filters.
- **Effects:** status=published = full seeded catalogue, status=draft
  = 0; language=en = full catalogue; min_rating=4.5 = only the 4.5+
  book; year_from=2010 returns only >= 2010 (verified per hit);
  category=Fantasy = 2 books; author=Arundhati Roy = 1 book;
  publisher=Scholastic = 2 volumes.
- **Combination:** term + status and term + min_rating both narrow;
  term + impossible filter = clean 0.
- **Vocabulary:** filterOptions() returns DB-derived categories /
  authors / publishers (HarperCollins present) and config whitelists.
- **URL builder:** queryString() baseline, filter-drop, page override,
  empty-state - all exact-match.
- **Controller:** page renders the grid + chips; live JSON carries the
  filtered partial (verified in the manual smoke above).

Run: `php tests/SearchTest.php` -> **70/70 checks, 0 failures**.

### Regression after this phase (all green)

`AuthTest`, `BrowseTest` (69), `SearchTest` (70), `LandingTest`,
`ReviewTest`, `FollowTest`, `LibraryTest`, `RecommendationDashboardTest`
(64), `PersonalizationTest` (62), `ReviewIntegrationTest` (109),
`NotificationCenterTest` (83), `GoogleBooksSearchTest` (57 PASS /
0 FAIL), `EmailNotificationTest` - **0 failures** across the board.

---

## Manual smoke (verified during this phase)

- `GET /search?status=published&min_rating=4` -> the filter bar
  renders, 19 results (all but the 3.9-rated book), chips show
  "Published status" and "4 stars & up rating", summary says "with
  the applied filters".
- `GET /search?q=harry&status=published` -> 1 result (Harry Potter),
  chip present, term preserved in the URL.
- Live JSON with filters -> `{ok: true, total: 19, html: <filtered
  partial>}`.
- Tampered `status=BOGUS` -> filter silently dropped (no error, no
  SQL leakage), results = the plain catalogue.

---

## What is NOT in this phase (deferred, per the 11.1 mandate)

- Suggestions + query history -> **Phase 11.4**.
- Analytics + SearchCache -> **Phase 11.5**.
- Relevance ranking (per-field weights already in config) -> **11.6**.
- Filter-aware URL persistence is already shipped (queryString
  everywhere); live chip syncing while typing is a Phase 11.4/11.5
  polish candidate (the chips refresh on the next full page load /
  filter change).
