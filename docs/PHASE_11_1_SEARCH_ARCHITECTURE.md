# Phase 11.1 - Advanced Search & Discovery: Architecture Blueprint

> **Status:** Design only. Nothing in this phase is implemented.
> **Already shipped in this phase:** `config/search.php` (the centralized
> configuration system, Task 6) and the matching `.env.example` section.
> Everything else below is the architecture that the Phase 11.2+
> sub-phases implement.
> **Constraint:** no new frameworks or dependencies (PHP 8.2 standard
> library only); every decision follows the existing project
> conventions - the Stack (Controller -> Service -> Model facade ->
> Repository -> PDO -> SQLite), module-owned exceptions, DTOs and
> policies, one shared service instance wired in `routes/web.php`,
> file-based caches, incremental forward-only migrations.
> **Scope discipline:** the architecture below supports Global Search,
> Advanced Filters, Search Suggestions, Search History, Search
> Analytics and future full-text/relevance search WITHOUT rewriting.
> None of those features is implemented here (they are Phase 11.2 -
> 11.6); this phase only fixes the seams, the contracts and the
> configuration.

---

## Conventions this design reuses (do not re-invent)

| Concern | Existing pattern | Blueprint rule |
|---|---|---|
| Data access | `Book` facade + `BookRepository` | `SearchRepository` (module-owned SQL) + the EXISTING `Book`/`Author`/`Category`/`Review` facades for entity reads |
| Provider abstraction | `RecommendationStrategy` interface + `RecommendationFactory` registry | `SearchProvider` interface + `SearchProviderFactory` registry (Task 11) |
| Business orchestration | `GoogleBooksService` / `BookService` (single facade, guards, logger) | `SearchService` (the only entry point controllers call) |
| Query gate | `SearchBooksRequest` (Validator + whitelists) | `SearchQueryRequest` (the Phase 11.2 inbound gate; same Validator) |
| Module failure type | `GoogleBooksException` / `FollowException` with `::static` factories | `SearchException` with factories per failure class (Task 7) |
| Result records | `ProviderSearchResult` / `LibraryItemDTO` (immutable value objects) | `SearchResult` + `SearchHit` (provider-neutral, immutable) |
| Read throttle | `RateLimiter(session())` + config buckets | session buckets `search` / `search_suggestions` (Task 8) |
| Result cache | `CacheManager` / `PersonalizationCache` (JSON files, atomic rename, TTL) | `SearchCache` with the exact same file technique (Task 9, Phase 11.5+) |
| Config | `config/*.php` groups read through `config()` / `env()` | `config/search.php` (already created, this phase) |
| Schema evolution | `Migrator`, forward-only `00NN_*.php` migrations | `0033`+ designed below (written in Phase 11.2+) |
| Error handling | central `ErrorHandler` logs once; module errors become HTTP statuses | identical: `SearchException` -> 400/422/429/500, never a raw SQL error |
| JSON vs page | one route answers both (`X-Requested-With: fetch` -> JSON, else full page) | identical on the search endpoints |

---

## Task 1 - The search layer (responsibilities)

Every concern lives in exactly ONE class. The layer, top to bottom:

```
                    ┌─────────────────────────────────────────────────┐
   Controllers ────► │  SearchService (orchestrator, facade)           │
  (Phase 11.2+ )     │  search() · suggest() · record() · analytics()  │
                    └───────────────┬─────────────────────────────────┘
                      │             │             │
                      ▼             ▼             ▼
           ┌────────────────┐ ┌────────────┐ ┌──────────────────┐
           │ SearchQuery-   │ │ SearchResult│ │ SearchProvider   │
           │ Builder        │ │ Formatter   │ │ (interface)      │
           │ (query SPEC)   │ │ (response)  │ └───────┬──────────┘
           └───────┬────────┘ └────────────┘         │
                   │                                 ▼
                   ▼                     ┌──────────────────────────┐
           ┌────────────────┐            │  SqliteSearchProvider    │
           │ SearchRepository│ ─────────►│  (Phase 11.2 impl.)      │
           │ (data access)   │           └──────────────────────────┘
           └───────┬────────┘
                   ▼
          Book/Author/Category/Review facades -> existing repositories -> SQLite

  Side services (record-keeping, never in the hot path):
     SearchHistoryService   - the user's past queries (Phase 11.4)
     SearchAnalyticsService - search events + aggregates (Phase 11.5)
     SearchSuggestionService- prefix suggestions (Phase 11.4)
```

### SearchService — the orchestrator / single entry point
- The ONLY class the controllers talk to. Mirrors `GoogleBooksService`
  as a thin, well-named public API:
  - `search(SearchQueryRequest $request): SearchResult` — validate,
    build, dispatch, format. Never touches SQL itself.
  - `suggest(string $prefix, int $limit): array` — Phase 11.4.
  - `recordHistory(...)`, `analytics()` — Phase 11.4/11.5 entry points.
- Holds: `SearchProvider` (resolved by the factory), `SearchQueryBuilder`,
  `SearchRepository`, `Logger`, the module config.
- Failure mode: **graceful** — the service never throws at the page;
  failures degrade to a `SearchResult` with an error + empty hits.

### SearchQueryBuilder — the query SPEC
- Owns the translation of a VALIDATED request into a provider-neutral
  **query spec** (a plain value object: term, words[], fields, scope,
  match modes, page, perPage, sort, filters). This is the object the
  provider translates into SQL (or, later, into a Meilisearch/ES query
  DSL) - so the SPEC is the seam that keeps application logic provider-
  agnostic.
- Implements Task 4's strategy: keyword, exact, partial, multi-field,
  multi-word, case-insensitive - all expressed as spec flags/options,
  NOT as SQL.

### SearchRepository — data access (Phase 11.2)
- The module's ONLY SQL owner (besides reusing the existing entity
  repositories). Prepared statements only, every condition bound, every
  column/sort token whitelisted. Exposes the entity reads the provider
  needs: `searchBooks($spec)`, `searchAuthors`, `searchCategories`,
  `searchPublishers`, `searchReviews` - each returning the entity's own
  row shape.
- Deliberately NO business rules (scoring, filters semantics) - those
  live in the service/builder.

### SearchResultFormatter — the response shape
- Converts provider-returned rows into the immutable `SearchResult`
  (hits + total + page/perPage/pages + flags) and later into the
  renderable view-model / JSON envelope. The views consume ONLY the
  formatted result - they never touch raw rows.

### SearchHistoryService / SearchAnalyticsService / SearchSuggestionService
- One file each, constructed in `routes/web.php` with their own models
  (`SearchQueryLog`, `SearchEventLog`, `SearchSuggestionLog` - schema in
  Task 5/11.2) and injected into `SearchService` as optional slots.
- They are **additive**: in Phase 11.2 they are no-ops (disabled by
  config); each later sub-phase turns ONE of them on without touching
  the search pipeline.

---

## Task 2 - Search scope (entities)

Searchable now (all exist in the schema today):

| Entity | Table | Fields (config `search.entities.*.fields`) | Notes |
|---|---|---|---|
| Books | `books` | title, subtitle, description, publisher, language, isbn, published_year + relations (authors, categories via EXISTS) | the Phase 11.2 baseline scope |
| Authors | `authors` | name | |
| Categories | `categories` | name, slug | |
| Publishers | (derived) | distinct `books.publisher` | same source as the browse filter dropdown |
| Reviews | `reviews` | body | |

Future-ready (config blocks already declared, `enabled => false`):

| Entity | Status | Hook |
|---|---|---|
| Users | `users` table exists; searching it ships in a later phase | one config block + one repository read |
| Collections | no table yet; a future phase adds it | same |

**Extension rule:** adding an entity = add one `search.entities.*`
block + one `SearchRepository::searchXxx()` read. The builder, service,
formatter and controller NEVER change per entity - they work off the
spec.

---

## Task 3 - Search flow

```
User Input (query string: ?q=...&scope=books&page=2)
   │
   ▼
1. SearchQueryRequest (inbound gate)
   │  trim · length min/max · word cap · charset · page/perPage whitelist
   │  -> invalid: 422 field-error map (same contract as SearchBooksRequest)
   ▼
2. RateLimiter::allow('search', ...)  -> exhausted: 429
   ▼
3. SearchService::search($request)
   │
   ▼
4. SearchQueryBuilder::build($request) -> QuerySpec (neutral)
   │
   ▼
5. SearchProvider (SqliteSearchProvider::search($spec))
   │  translates spec -> prepared SQL via SearchRepository (entity reads)
   │
   ▼
6. SearchResultFormatter::format(...) -> SearchResult (hits/total/pages)
   │
   ▼
7. Response: JSON for fetch callers / rendered view for pages
```

Every step is swappable: 3 is the only controller dependency, 4 and 5
are the provider seam (Task 11), 6 is the only shape views see.

---

## Task 4 - Query strategy

All six strategies are **spec-level** decisions made by
`SearchQueryBuilder`; the provider merely executes the spec.

| Strategy | Spec expression | Phase 11.2 SQL behavior (design intent) |
|---|---|---|
| Keyword | `term`, `words[]` (tokenized, quoted phrases preserved) | LIKE per word, AND-combined |
| Exact match | `exact => true` per field (config `fields.*.exact`) | `column = ?` on exact fields (title, isbn, language, slug) |
| Partial match | `partial => true` | `column LIKE %term%` |
| Multi-field | `fields[]` (config entity catalog) | OR over the chosen columns |
| Multi-word | `words[]` | each word must match somewhere (AND), so "harry potter" finds both terms |
| Case-insensitive | implicit (SQLite LIKE is case-insensitive for ASCII) | no extra work |

Relevance scoring (ranking) is **deferred**: the config already carries
per-field `weight` values, and the spec carries a `scoring` slot - but
no ranking is computed until Phase 11.6 (per the phase mandate). Phase
11.2 orders results by a deterministic sort (relevance-neutral: title /
created_at) so later ranking slots in without changing the contract.

---

## Task 5 - Database review (current state, audited this phase)

Audit performed on `database/booksphere.db` (PRAGMA index_list per
table + schema inspection):

**Existing indexes - the search-relevant inventory:**

| Table | Index | Why it matters for search |
|---|---|---|
| `books` | UNIQUE `isbn`, UNIQUE `google_book_id` | exact-match lookups |
| `books` | `title`, `publisher`, `language`, `published_year`, `average_rating`, `status`, `status_deleted`, `deleted_at` | filters + exact/prefix matching |
| `books` | `created_at`, `updated_at` | sort orders |
| `book_authors` | UNIQUE `(book_id, author_id)` + `(author_id, book_id)` | EXISTS subqueries (search by author name) |
| `book_categories` | UNIQUE `(book_id, category_id)` + `(category_id, book_id)` | EXISTS subqueries (search by category name) |
| `authors` | UNIQUE `name` | author scope |
| `categories` | UNIQUE `name`, UNIQUE `slug` | category scope |
| `reviews` | `book_id`, `user_id`, `rating`, `created_at`, `book_id+created_at` | review scope + sort |

**Missing / deliberately absent:**

- **No FTS tables** (checked: no `books_fts*`). The browse module
  (migration 0011) deliberately did NOT index free-text columns:
  a B-tree cannot accelerate `LIKE '%term%'`. For a catalogue in the
  low thousands the full scan is instant. **Design decision:** Phase
  11.2 keeps the LIKE strategy as the baseline (no schema change
  required); SQLite **FTS5** is the documented scale-up path and ships
  in a later sub-phase as an additional provider (`SqliteFtsProvider`)
  behind the SAME `SearchProvider` interface - it never rewrites the
  application.
- **No search-support tables yet** (Phase 11.4/11.5 migrations, all
  designed now, none applied in this phase):
  - `search_query_log` (history: id, user_id, query, created_at) with
    index on `(user_id, created_at)` - Phase 11.4.
  - `search_event_log` (analytics: id, user_id nullable, scope, term,
    result_count, elapsed_ms, created_at) with index on
    `(scope, created_at)` - Phase 11.5.
  - `search_suggestion_log` (top-N caching of popular terms) -
    Phase 11.4.
- **Fields requiring indexing when FTS lands:** none extra - FTS5
  carries its own index; the B-tree inventory above already covers
  every filter/sort.

**Conclusion:** NO schema modification is necessary for the Phase 11.2
baseline. Nothing in this phase touches the database. Migrations
`0033+` arrive with the sub-phases that actually use them.

---

## Task 6 - Search configuration (DONE this phase)

`config/search.php` is created and auto-loaded (the `search` group).
Every value is env-overridable:

| Key | Env | Default | Used by |
|---|---|---|---|
| `enabled` | `SEARCH_ENABLED` | true | SearchService master switch |
| `provider` | `SEARCH_PROVIDER` | sqlite | SearchProviderFactory |
| `query.min_length` | `SEARCH_QUERY_MIN_LENGTH` | 1 | SearchQueryRequest / builder |
| `query.max_length` | `SEARCH_QUERY_MAX_LENGTH` | 200 | query gate (very long query) |
| `query.max_words` | `SEARCH_QUERY_MAX_WORDS` | 10 | query gate (unsupported terms) |
| `query.max_results` | `SEARCH_MAX_RESULTS` | 500 | response ceiling |
| `pagination.per_page` | `SEARCH_PER_PAGE` | 24 | default page size |
| `pagination.allowed` | (code-fixed) | [12,24,48,96] | page-size whitelist |
| `suggestions.*` | `SEARCH_SUGGESTIONS_*` | enabled, limit 8 | Phase 11.4 |
| `history.*` | `SEARCH_HISTORY_*` | enabled, limit 12, ttl 90d | Phase 11.4 |
| `analytics.*` | `SEARCH_ANALYTICS_*` | enabled, retention 365d | Phase 11.5 |
| `performance.timeout_seconds` | `SEARCH_TIMEOUT_SECONDS` | 5.0 | timeout error path |
| `rate_limit.search/.suggestions` | (env or code) | 60/min, 120/min | RateLimiter buckets |
| `entities.*` | (code-fixed) | per Task 2 table | scope catalog + weights |

The `.env.example` documents the same table (architecture-only section).

---

## Task 7 - Error handling

One typed exception (`SearchException`, mirroring `FollowException`)
with static factories; the controller maps them to HTTP statuses; the
central `ErrorHandler` logs anything unexpected ONCE:

| Condition | Detection | Response |
|---|---|---|
| Empty query | `query.min_length` / trimmed empty | 200 + empty-state result (the page renders its empty state - matches SearchBooksRequest philosophy) OR 422 when a term was required |
| Invalid query (bad scope, bad page) | Validator whitelists | 422 field-error map |
| Very long query | `query.max_length` | 422 ("term too long") |
| Unsupported characters / too many words | `query.max_words` + a safe-charset rule | 422 with a plain message |
| Database failure | PDO exception (NOT wrapped) | bubbles to ErrorHandler -> 500 + logged once; the controller answers a generic safe message |
| Timeout | `performance.timeout_seconds` budget | 500 with "search timed out - try again" |
| Disabled module | `enabled => false` | 503 friendly error (the Google Books pattern) |

Recovery: search never 500s the page - the formatter always produces a
`SearchResult` (empty hits + `error` field) that the view renders as a
friendly alert, exactly like the browse/Google-Books empty states.

---

## Task 8 - Security

- **Input validation:** every inbound value passes `SearchQueryRequest`
  (Validator: whitelisted scope enum, length caps, word cap, page
  bounds, sort whitelist) BEFORE the builder sees it.
- **Output escaping:** the existing `e()` helper on every rendered
  field, including result snippets (the browse/card pattern). Provider
  (DB) data is treated as untrusted in views.
- **SQL injection protection:** zero raw concatenation - every
  condition is a bound prepared-statement parameter; column/sort/scope
  tokens come from hard whitelists only (the `SORTS` / `DISTINCT_COLUMNS`
  pattern of `BookRepository`). The builder emits parameter placeholders;
  the repository binds.
- **Rate limiting compatibility:** `RateLimiter(session())` buckets
  `search` + `search_suggestions` from `config.search.rate_limit`,
  answered with 429 (same throttle as the write endpoints).
- **Safe query construction:** the builder is the ONLY place where
  terms become predicates; it never emits SQL, only the neutral spec -
  so a future provider can't be injection-prone by construction.

---

## Task 9 - Performance strategy

- **Indexed queries:** the inventory in Task 5 covers exact/filter
  paths; free-text LIKE stays full-scan BY DESIGN until FTS5 lands
  (documented ceiling: low thousands of rows are instant).
- **Pagination:** COUNT + LIMIT/OFFSET, exactly like `browse()`; the
  catalogue is never loaded whole; `max_results` caps the response.
- **Lazy loading:** entity reads are per-scope (books search only
  fetches book columns); relation/cover joins happen only when the
  formatter needs them.
- **Efficient joins:** author/category scope via EXISTS subqueries
  (never a multiplying JOIN - the browse module's proven rule).
- **Prepared statements:** every query via the existing `db()`
  wrapper (PDO, `EMULATE_PREPARES=false`).
- **Future caching:** `SearchCache` (file-based, the `CacheManager`
  technique) caches popular term/scope/page keys with a TTL; invalidation
  hooks on book/review writes. Designed as an optional decorator around
  `SearchProvider` so the pipeline is unchanged when it arrives (Phase
  11.5+).
- **No unnecessary scans:** scope/field limits come from config; the
  builder prunes disabled entities before the provider runs.

---

## Task 10 - Folder structure (the target tree)

```
app/
├── Controllers/SearchController.php          (Phase 11.2 - thin)
├── Services/
│   ├── SearchService.php                     (orchestrator, facade)
│   ├── SearchProvider.php                    (interface - Task 11)
│   ├── SqliteSearchProvider.php              (Phase 11.2 implementation)
│   ├── SqliteFtsProvider.php                 (future FTS5 drop)
│   ├── SearchProviderFactory.php             (registry)
│   ├── SearchSuggestionService.php           (Phase 11.4)
│   ├── SearchHistoryService.php              (Phase 11.4)
│   └── SearchAnalyticsService.php            (Phase 11.5)
├── Repositories/SearchRepository.php         (Phase 11.2 - module SQL)
├── Requests/SearchQueryRequest.php           (Phase 11.2 - inbound gate)
├── DTO/
│   ├── SearchQuerySpec.php                   (neutral query spec)
│   ├── SearchResult.php                      (immutable result)
│   └── SearchHit.php                         (one result row)
├── Exceptions/SearchException.php            (typed failures)
├── Models/SearchQueryLog.php, SearchEventLog.php, SearchSuggestionLog.php
│                                             (Phase 11.4/11.5 facades)
└── Views/search/…                            (Phase 11.2 partials)
config/search.php                             ✔ SHIPPED this phase
database/migrations/0033+_…                  (sub-phase migrations)
docs/PHASE_11_1_SEARCH_ARCHITECTURE.md        (this file)
```

Wiring: `SearchService` + friends constructed in `routes/web.php` (the
single shared-instance pattern); routes `GET /search` (+ future
`/search/suggest`, `/search/history`, `/search/analytics`) behind the
existing middleware stack.

---

## Task 11 - Future compatibility (provider abstraction)

The provider seam is `SearchProvider` (interface) + `SearchProviderFactory`
(registry), the exact `RecommendationStrategy` / `RecommendationFactory`
pattern:

```php
interface SearchProvider {
    public function search(SearchQuerySpec $spec): array;   // raw rows
    public function suggest(string $prefix, int $limit): array;
}
```

- **Today:** `SqliteSearchProvider` executes the spec via
  `SearchRepository` (prepared SQL).
- **Tomorrow (Elasticsearch / Meilisearch / Typesense / Algolia):** each
  is a NEW class implementing `SearchProvider`, translating the SAME
  `SearchQuerySpec` into its own DSL; `SearchProviderFactory` resolves
  the name from `config('search.provider')`. The service, builder,
  formatter, controller and views NEVER change - the spec is the
  contract. FTS5 (`SqliteFtsProvider`) is the same-drop, no-dependency
  middle step.
- Sorting/scoring arrive as spec fields, so ranked engines (Algolia,
  Meilisearch) need no contract change when Phase 11.6 ships.

---

## Task 12 - Documentation (this file)

- Search architecture ......... Tasks 1-4 (layer diagram, scope, flow, strategy)
- Service responsibilities .... Task 1 (each class's single concern)
- Search flow diagram ......... Task 3
- Folder structure ............ Task 10
- Configuration design ........ Task 6 + `config/search.php`
- Database review ............. Task 5 (index inventory + FTS decision)
- Extension strategy .......... Tasks 2 + 11 (entities + providers)
- Future scalability notes .... Tasks 5 (FTS5), 9 (SearchCache), 11 (engines)

---

## What ships in Phase 11.2+ (nothing implemented here)

| Sub-phase | Ships | Uses |
|---|---|---|
| 11.2 | Global search: request, spec, provider, repository, controller, view | this architecture, config/search.php |
| 11.3 | Advanced filters (status, category, author, year, rating, language) | spec filters + Task 5 indexes |
| 11.4 | Suggestions + history (+ migrations 0033/0034) | config suggestions/history |
| 11.5 | Analytics + SearchCache (+ migration 0035) | config analytics/performance |
| 11.6 | Relevance ranking | entity weights already in config |

## Files added this phase

```
config/search.php                     (the centralized configuration - Task 6)
.env.example                          (+ SEARCH_* variables, documented)
docs/PHASE_11_1_SEARCH_ARCHITECTURE.md (this file - Tasks 1-12)
```

**Modified:** `.env.example` only (additive section). No database
migration, no class stubs, no route - per the phase mandate ("no
placeholder implementations"). The architecture is verified by
inspection against the audited codebase (conventions table in Tasks
1-11) and is consistent with the existing `config()`, `RateLimiter`,
Validator, repository and factory patterns.
