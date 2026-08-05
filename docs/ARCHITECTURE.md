# BookSphere Architecture & Developer Notes

> Written for Phase 5.6. Describes the architecture of the Book module
> as it exists today, how a request flows through the application, and
> where the next phases (especially the Recommendation Engine) plug in.

---

## 1. Layering (MVC done properly)

```
Browser
   │
   ▼
public/index.php .................. front controller: boots the app
   ▼
Router (routes/web.php) ........... matches URL → controller action,
   │                            runs middleware first
   ▼
Middleware ........................ SecureHeaders → Auth/Admin → CSRF
   │
   ▼
Controller (BookController) ....... thin: read request, call service,
   │                            render view / redirect
   ▼
Service (BookService) ............. business rules: validation,
   │                            whitelisting, workflows
   ├──► Request (BookRequest) ..... pure form rules (Validator)
   ├──► MediaService .............. uploads: validate/store/delete
   ▼
Model (Book / Author / Category) .. thin facade (one method per query)
   ▼
Repository (BookRepository) ....... ALL SQL + prepared statements
   ▼
PDO → SQLite (database/booksphere.db)
```

Rules that hold the design together:

1. **Controllers never write SQL** and **never contain business
   decisions** – they translate the request and render a response.
2. **SQL lives only in repositories.** Changing a table column or
   adding an index never touches a controller or a view.
3. **User input is whitelisted before it reaches SQL.** Browse filters
   pass `BookService::combineFilters()` (constants + type checks) and
   sort/distinct columns are re-checked against repository whitelists
   (`SORTABLE_COLUMNS`, `DISTINCT_COLUMNS`) – defence in depth.
4. **Everything printed is escaped** with the `e()` helper.
5. **One query path for browsing.** `BookRepository::browse()` is the
   single query behind search, filters, sorting and pagination.

## 2. The Book module at a glance

| Layer | File | Responsibility |
|---|---|---|
| Routes | `routes/web.php` | `GET /books` + `/books/search` for all signed-in users; CRUD behind `AdminMiddleware` (+ `CsrfMiddleware` on POSTs) |
| Controller | `app/Controllers/BookController.php` | `index`, `searchJson`, `create`, `store`, `show`, `edit`, `update`, `destroy` + shared helpers (`catalogue()`, `formData()`, `formValues()`, `findOrFail()`) |
| Service | `app/Services/BookService.php` | Constants (`STATUSES`, `SORTS`, `PAGE_SIZES`, `LANGUAGES`, `RATING_FILTERS`), `combineFilters()`, `paginate()`, `search()`, `filter()`, `sort()`, `filterOptions()`, `queryString()`, `errorsFor()`, `store()`, `update()`, `softDelete()` |
| Repository | `app/Repositories/BookRepository.php` | `browse()`, `findById()`, `findWithRelations()`, `authorsFor()`, `categoriesFor()`, `distinct()`, `create()`, `update()`, `softDelete()`, `replaceAuthors()`, `replaceCategories()`, `isbnExists()` |
| Request | `app/Requests/BookRequest.php` | Declarative field rules (title required, year 1000–now, status/language whitelists, ...) |
| Models | `app/Models/Book.php`, `Author.php`, `Category.php` | Thin facades; each method forwards to its repository |
| Media | `app/Services/MediaService.php` | Validate (size, sniffed MIME, dimensions, structural checks), store (random names), delete (local files only) |
| Views | `app/Views/books/` | `index` (browse), `show` (detail), `create`/`edit` (share `partials/_form.php`), `partials/_results.php` (shared page + JSON), `partials/_delete-modal.php`, `components/` (form-input, book-cover, book-card, book-table-row, pagination, rating-stars, category-badge, form-section, upload-card) |

### The browse pipeline (Phase 5.5)

```
/books?q=harry&category_id=3&sort=title_asc&per_page=10&page=2
   │
   ▼
BookController::index()
   ├── rawFilters($request)      every query key, untouched
   ├── service->combineFilters() whitelists + type-checks everything
   ├── service->paginate()       ONE count + ONE LIMIT/OFFSET slice
   ├── service->filterOptions()  dropdown sources (4 light queries)
   └── books.index view          toolbar + _results.php partial
```

The live search (`/books/search`) runs the **same** pipeline and sends
the **same** `_results.php` partial back as JSON; `app.js` debounces
typing (300 ms) and swaps the region in place, keeping the URL
shareable via `history.replaceState`.

### Filter → URL mapping (single source of truth)

`BookService::queryString($filters, $remove, $overrides)` is the only
place that knows how filters map to the query string. The filter
chips, the pagination bar and the form all use it, so adding a new
filter touches exactly one method (plus `combineFilters()`).

## 3. Request flow example: "edit a book"

1. `POST /books/7/edit` (admin, valid CSRF token)
2. `AdminMiddleware` checks the role; `CsrfMiddleware` checks the token
3. `BookController::update()`:
   - `findOrFail()` loads the book (404 if gone)
   - `formValues($request, $book)` merges submitted values
   - `service->errorsFor()` runs field rules + ISBN uniqueness + cover
   - on errors → re-render the edit form with values and messages
   - on success → `service->update()` (new cover replaces old file,
     `remove_cover` clears it), flash message, redirect to `/books/7`
4. `BookService::update()` normalizes columns, swaps the cover file,
   replaces the author/category links (delete + insert in one
   transaction-friendly shape)

## 4. Security summary

| Concern | Where it is handled |
|---|---|
| SQL injection | Prepared statements everywhere; columns whitelisted |
| XSS | `e()` on every printed value, attribute and option |
| CSRF | Token in every form (`csrf_token()`), verified by `CsrfMiddleware` |
| Broken access control | Route middleware (`Auth` / `Admin`); buttons hidden for non-admins (browse + show pages) |
| File uploads | `MediaService`: `is_uploaded_file`, content sniffing, pixel bounds, PNG/JPEG/WebP structural checks, random file names, local-only deletes |
| Sessions | HttpOnly + SameSite=Lax (+ Secure on HTTPS), id regenerated on login/logout, login rate limiting (5 attempts, 15 min lock) |
| Input validation | `Validator` + `BookRequest` rules + `combineFilters()` whitelists |
| Write-endpoint abuse | `RateLimiter` (session-backed sliding window) on `/wishlist/toggle` and `/recommendations/refresh` → HTTP 429 past the limit (`config/recommendations.php → security.rate_limit`) |
| Review identity | the author id always comes from the session (`auth()->id()`), never the form; an update carries book/user from the stored row, so a tampered request cannot re-point a review at another book |

## 5. Performance notes

- Browse page runs **5 queries** (2 lookup lists, 1 distinct, COUNT +
  page slice) – down from 6 after Phase 5.6.
- Config files are parsed **once per request** (cached `config()`
  helper) – they were parsed up to 4× before.
- Free-text `LIKE '%term%'` cannot use B-tree indexes; measured ~6 ms
  over 2,500 rows (see the performance section of `tests/BrowseTest.php`).
- Indexes (verified in the database): one per filter column
  (status, language, publisher, published_year, average_rating,
  created_at, updated_at, title, deleted_at) plus composite junction
  indexes `(author_id, book_id)` / `(category_id, book_id)`.
- Pagination is offset-based – correct for this scale; the scale-up
  path is cursor pagination (see technical debt).
- Recommendation engine (Phase 6.5): four composite indexes in
  migration 0013 (`reviews(book_id, created_at)`,
  `wishlist(book_id, created_at)`, `book_views(user_id, viewed_at)`,
  `books(status, deleted_at)`) serve the engine's count subqueries,
  the recent-views read and the active-catalogue filter. The suite
  proves the planner uses them via `EXPLAIN QUERY PLAN`.
- The per-user recommendation cache (30-minute TTL, file-based) turns
  the multi-query personal pipeline into one file read on a hit; a
  broken cache degrades to an uncached run with a logged warning.
- Reviews (Phase 7.1): the book-level aggregates live on the books
  row (`average_rating`, `ratings_count`) and are maintained by ONE
  statement (`ReviewRepository::updateBookRatingStats`) after every
  review write, so browse/search/recommendations never pay an AVG;
  the review lookup indexes (user, rating, created_at, migration
  0014) serve the "my reviews", scopes and recent-reviews reads.

## 6. Extension points (Phase 6 +)

The architecture was prepared for the next phases:

- **Recommendation Engine** – Phase 6. The tables `reviews`,
  `wishlist` and `recommendations` already exist (migrations 0007–
  0009). Phase 6.1 delivered the full architecture: `RecommendationService`
  (orchestrator) beside `BookService`, a `RecommendationFactory`
  (strategy registry), five `RecommendationStrategy` implementations,
  `RecommendationRepository` (data reads over the three signal tables),
  `RecommendationContext` / `RecommendationResult` DTOs,
  `RecommendationPolicy` and the `/recommendations` routes in
  `RecommendationController` (see
  `docs/PHASE_6_1_RECOMMENDATION_ARCHITECTURE.md`). Phase 6.2 replaced
  the placeholders with six real algorithms (Popular, Top Rated,
  Recently Added, By Category, More Like This, Trending) scored in SQL
  via `RecommendationScoring` (weights as bound parameters) with
  per-item reasons, real book-card shelves in the views, and a
  rewritten test suite (86/86). Phase 6.3 added the personal shelf:
  a hybrid per-user score (category/author/wishlist/rating/trending/
  popularity, weights in `config/recommendations.php`), favourites
  auto-derived from wishlist/ratings/reviews, recently-viewed tracking
  (`book_views`, migration 0012), explainable reasons via DTOs and a
  per-user file cache with invalidation (62/62 checks, see
  `docs/PHASE_6_3_PERSONALIZATION.md`). The dashboard UI is Phase 6.4.
  Phase 6.5 productionized the engine without changing behaviour:
  index-backed signal queries (migration 0013), catalogue-wide cache
  flush on book writes plus graceful cache degradation, "updated X
  minutes ago" freshness, cross-section duplicate removal, one 0-100
  score scale (`popularityPercent` / `trendingPercent` in
  `RecommendationScoring`), a session-backed `RateLimiter` on the write
  endpoints (HTTP 429), and an admin-only monitoring page
  (`GET /admin/recommendations` + `POST .../cache/flush` via
  `RecommendationMetrics` / `AdminController`). 334/334 checks + 21/21
  smoke checks (see `docs/PHASE_6_5_OPTIMIZATION.md`). **Phase 8.5**
  connected the Personal Library as the engine's richest signal: a
  new `'library'` config block read through `RecommendationConfig`
  (weights 35/25/15/10/10/5, per-surface section limits, log
  retention, hidden-gems filter, accuracy window, similarity bands),
  `RecommendationScoring::libraryScore()` on the shared 0-100 scale,
  sixteen new `RecommendationRepository` reads (shelf ids, top
  categories/authors, the collaborative co-saved shelves, hidden
  gems, rating/popularity bands, the log trail), and four public
  service surfaces — `libraryRecommendations()` (8 sections incl.
  the dashboard shelves), `bookRecommendations()` (6 deduplicated
  sections on the book page), `libraryPageRecommendations()` (5
  sections on the library page) and `profileRecommendationInsights()`
  (reading preferences + the Recommendation Accuracy figure). The
  audit trail is migration 0019 (`recommendation_logs`, pruned per
  user on write); caching is per user AND per section
  (`PersonalizationCache::getSection/putSection`); the pages share
  ONE `RecommendationService` (see
  `docs/PHASE_8_5_LIBRARY_RECOMMENDATIONS.md`).
- **Reviews & Ratings** – Phase 7.1 delivered the backend on top of
  the engine's `reviews` table (migration 0014 added `title`,
  `status` (moderation enum), `is_edited`, `updated_at` and the
  lookup indexes): `ReviewController` (thin, dual fetch/JSON +
  redirect paths) → `ReviewService` (book-exists + duplicate rules,
  is_edited flag, automatic average/count sync via
  `updateBookRatingStats`, write logging, per-user recommendation
  cache invalidation) → `Review` model (facade + `book()`/`user()`
  relationships + the five scopes) → `ReviewRepository` (all SQL,
  approved-only aggregates), with `ReviewDTO`, `ReviewPolicy`
  (owner-or-admin), `StoreReviewRequest`/`UpdateReviewRequest`
  (rating 1–5, title ≤ 120, review 20–2000) and the `/reviews`,
  `/books/{id}/reviews` routes (133/133 checks, see
  `docs/PHASE_7_1_REVIEWS_RATINGS.md`). Phase 7.2 delivered the
  complete review CRUD: the shared review form + write section on
  the book detail page (with the "already reviewed" panel), the
  single-review page, the edit workflow with the "Edited" badge and
  the shared delete confirmation modal (one modal per page, one
  generic app.js handler) – see `docs/PHASE_7_2_REVIEWS_CRUD.md`.
  Phase 7.3 added the presentation + analytics layer on the same
  seam: `app/Views/components/star-rating.php` (the reusable
  display/input component – the old `rating-stars.php` is now an
  adapter over it), the interactive form input with a WAI-ARIA radio
  group + hidden no-JS fallback (behaviour in `public/assets/js/
  rating.js`, styles in `public/assets/css/rating.css`), the shared
  animated distribution panel
  (`reviews/partials/_rating-distribution.php`) and the TRUTHFUL
  analytics reads in `ReviewRepository` (approved-only aggregates:
  `overallAverage` / `overallDistribution` /
  `highestRatedBooks` / `lowestRatedBooks` / `booksWithoutRatings` /
  `categoryAverage` / `userRatingStats`) composed by `ReviewService`
  (`ratingSummary` / `ratingBreakdown` / `adminAnalytics` /
  `profileStats`) and surfaced on the dashboard (real Top Rated
  Books), the profile (rating activity) and the admin page (rating
  analytics) – 176/176 checks, see `docs/PHASE_7_3_RATING_SYSTEM.md`.
  Phase 7.4 added the professional review browsing layer on the same
  seam: `app/Presenters/ReviewListPresenter.php` (the view-model of
  every review list – `state` / `toolbar` / `pagination` payloads),
  the repository browsing vocabulary (`sort` allowlist,
  `paginate` with one COUNT + one SELECT sharing the private `where()`
  builder, `search` with a LIKE over title / body / reviewer name
  through the join, `statistics` with the aggregate + GROUP BY
  distribution, `userReviews`), the service normalization gate
  (`normalizeListOptions` – the single entry for every query-string
  value: allowlisted sort, page size 10/20/50, clamped page, rating
  1–5, trimmed term) and the shared components (`review-card` with
  read-more + reviewer links + owner actions and a `$compact`
  variant, `review-header`, `review-search`, `review-filters`,
  `review-pagination`, `review-stats`, `loading-skeleton`; behaviour
  in `public/assets/js/reviews.js`, styles in
  `public/assets/css/reviews.css`). New routes: `GET /reviews/search`
  (community search / timeline, "my reviews only" chip),
  `GET /reviews/statistics` (platform numbers + community shelves)
  and `GET /reviews/user/{id}` (one reviewer's public page) –
  registered before `/reviews/{id}`; the book detail review section
  is now the shared `_section.php` partial, and the dashboard /
  profile show real review shelves. 254/254 checks, see
  `docs/PHASE_7_4_REVIEW_UX.md`.
  Phase 7.5 added the community engagement layer on the same
  repository seam: the `review_helpful_votes` and `review_reports`
  tables (migration 0015, UNIQUE vote + CHECK-enforced reasons), the
  Helpful toggle (one vote per user, idempotent, truthful per-card
  counts through the `SELECT` subquery), the six-reason report flow,
  the admin moderation queue (`AdminController::reports` with
  pending / reviewed / resolved / dismissed tabs, hide / unhide via
  `updateStatus`) and per-user reputation on profiles.
  Phase 7.6 made the module the single ratings source across the
  platform: `ReviewRepository` gained the cross-platform aggregation
  reads (`topRatedBooks` / `mostReviewedBooks` / `authorAverage` /
  `categoryAverage` / `mostReviewedCategories` / `mostActiveReviewers`
  / `platformStatistics` / `authorStatistics` / `categoryStatistics` /
  `userStatistics` / `reviewActivityTimeline` /
  `userHighestRatedBook`), `ReviewService` composes them
  (`dashboardStatistics` – one payload for the whole dashboard,
  `adminAnalytics` – the extended platform picture) and the
  `review_score` factor joined the recommendation engine as its
  seventh hybrid weight (config-driven 10, signal = approved average
  / 5, cap 1.0). Two thin controllers (`AuthorController`,
  `CategoryController`) + four views serve the new `/authors`,
  `/categories` pages with eight new components (rating-badge,
  review-counter, review-summary-card, community-review-card,
  top-rated-book-card, statistics-card, recent-review-card,
  community-activity-widget); the profile gained favourite genres +
  a monthly review-activity timeline and the admin analytics grew
  four headline tiles + three ranking blocks. 109/109 new checks,
  see `docs/PHASE_7_6_RATINGS_INTEGRATION.md`.
  Phase 7.7 hardened the whole module without new features: the
  `UpdateReviewRequest` `TypeError` (missing `Validator` import) is
  fixed; the report flow throws its own `selfReport()` exception and
  logs the `book_id`; rule/status constants are single-sourced
  (`StoreReviewRequest` limits, `ReviewRepository` status constants);
  the repository was deduplicated (`approved()` → `latestReviews()`,
  one `bookSpotlight()` / `authorTopBook()` with allowlisted ORDER
  BYs, one `normalizeDistribution()`, one `percentageMap()`) and the
  N+1 in `attachVoteState()` is gone (`userHelpfulVotes()` batch);
  migration 0016 adds `UNIQUE (reported_by, review_id)` to
  `review_reports` (dedup first, then the constraint); the write
  endpoints carry `RateLimiter` throttles (`review_write` 20/h,
  `review_vote` 60/min, `review_report` 10/h → HTTP 429, limits in
  `config/recommendations.php`); and the views share three new
  single implementations (`_avatar.php`, `format_review_date()`, the
  parametrised `_rating-distribution.php`). 369/369 ReviewTest checks,
  812/812 across all suites, see
  `docs/PHASE_7_7_PRODUCTION_READINESS.md`.
- **Personal Library** – DONE in Phases 8.1 + 8.2 + 8.3. The
  `user_library` table (migration 0017) holds one record per user per
  book (`UNIQUE (user_id, book_id)`, ON DELETE CASCADE,
  CHECK-constrained statuses and 0–100 progress, four supporting
  indexes). The stack is the same seam as Reviews: `LibraryController`
  (thin, dual JSON / redirect) → `LibraryService` (the rules:
  book-exists, duplicate guard, the five-shelf status lifecycle with
  its timestamps, favourites independent of status, progress bounds +
  auto-finish at 100, the Phase 8.5 recommendation hooks
  `favoriteBooks` / `readingHistory` / `completedBooks` /
  `preferredGenres`) → `UserLibrary` model (facade + relationships) →
  `LibraryRepository` (every SQL query; `create` / `update` /
  `delete` / `find` / `findByBook` / `findByUser` / `findByStatus` /
  `favorites` / `wishlist` / `currentlyReading` / `finished` /
  `search` / `statistics` / `preferredGenres`), guarded by
  `LibraryPolicy` (only the owner manages, even for admins),
  `StoreLibraryRequest` / `UpdateLibraryRequest` (status enum, progress
  0–100, favourite boolean), `LibraryItemDTO` and `LibraryException`.
  Phase 8.2 added the presentation (the "My Library" page, the
  statistics page, the book-detail Add / Update Library panel, the
  fetch-driven favourite / status / progress interactions, the
  dashboard's Continue Reading shelf). **Phase 8.3** rebuilt the page
  as the premium library **dashboard**: the hero header + streak /
  total / progress chips, the statistics row (from the composed
  `libraryDashboard()` = statistics + summary + streak), the quick
  actions, the Continue Reading section, and the combined filter /
  sort / view / search / paginate grid. The grid reads share one WHERE
  builder (`filterClause()`), the ordering lives in the
  `LibraryRepository::SORTS` map (labels in `LibraryService::SORTS`),
  and the search now matches publisher / language as well. Sort / view
  persist per user into the one-row `user_preferences` table
  (migration 0018, UPSERTed by `savePreferences()`; `viewPreference()`
  merges only allowlisted values). The results live in one shared
  fragment (`library/partials/_grid.php`) rendered both server-side
  and as the JSON the JS swaps in; the statistics row and continue
   shelf refetch themselves after writes. The recommendation engine is
   an optional dashboard dependency — `recommendedFor()` badges the
   cards the engine suggests, and is empty without it.
   **Phase 8.4** turned the grid into the intelligent organization
   surface: the Smart Collections rail (All + the five shelves +
   Favourites, each with count / average rating / last updated from
   one `collectionStatistics()` UNION ALL over a defaulted map of all
   seven ids), the description reach of the shared `filterClause()`
   search, the `most_reviewed` sort (the platform's approved-review
   subquery, with `book_review_count` shipped in the row SELECT) and
   the `most_recommended` sort (`orderFor()` swaps a parameterized
   `CASE WHEN b.id IN (…)` when an engine suggestion set is present,
   a ratings-count fallback otherwise), the bulk actions
   (`bulkStatus` / `bulkFavorite` / `bulkDelete` over the
   `normalizeIds()` + `ownedIdsClause()` owner gate; `POST
   /library/bulk` with a closed action allowlist, CSRF + throttle),
   the per-card quick-action menu (View / Move To / Favourite /
   Share placeholder / Remove) and real review counts on the cards.
   The dashboard and the profile now both present the user's OWN
   recently-added / favourite books and library numbers through the
   SHARED `LibraryService` (never placeholder data).
   274/274 LibraryTest checks, see
   `docs/PHASE_8_4_SMART_LIBRARY.md`. **Phase 8.5** connected the
   library to the Recommendation Engine (see the Recommendations entry
   below): the engine reads the shelves through the SAME
   `RecommendationService` the dashboard / book page / profile share,
   and every library-derived shelf is explainable, cached per user per
   section and logged to `recommendation_logs` (migration 0019) for
   the profile's Recommendation Accuracy figure.
   See `docs/PHASE_8_5_LIBRARY_RECOMMENDATIONS.md`. **Phase 8.6**
   audited the module end to end and hardened it: the personalized
   shelf cache re-applies the caller's limit on hits (and the
   dashboard logs `dashboard_recommended` once per generation, gated
   by `personalizedShelfIsCached()`), the own-library exclusion set is
   loaded once per page (`LIBRARY_EXCLUSION_LIMIT`), the book-page
   same-author loop is one batched IN-query, `ratingBreakdown()`
   reuses the summary's distribution instead of re-aggregating,
   preference changes log `library.preference_changed`, a dead
   session answers 404 on `/profile`, the statistics payload carries
   the streak, the frontend recovers its grid / stat cells / streak
   chip after failed fetches and the 100% progress confirm actually
   fires, Remove controls are real CSRF forms (no-JS native POST,
   JS routed through the shared modal), the bulk bar works without
   JS, header chips were renamed `.library-chip` → `.library-stat-chip`
   (ending the CSS collision with the active-filter chips), and the
   dead `skeleton-stat.php` component was deleted. 1243/1243 checks
   across the nine suites, see `docs/PHASE_8_6_LIBRARY_QA.md`.
- **Category / author browse pages** – DONE in Phase 7.6: the browse
  query still supports `category_id` / `author_id` filters, and the
  dedicated `/authors` + `/categories` pages (directory + detail)
  now serve real aggregated rating profiles through the Reviews
  module.
- **New media types** (author photos, review images) – `MediaService`
  is already configured per media type via `config/media.php`; the
  `upload-card` component is media-agnostic.
- **Finner authorization** – if roles grow beyond `admin`, per-action
  checks plug into the controllers/services; the old (unused)
  `BookPolicy` was removed in Phase 5.6 to keep the codebase honest –
  authorization today lives in the route middleware.
- **Adding a browse filter** – extend `BookService::combineFilters()`
  (sanitization), `queryString()` (URL mapping), `whereParts()` in
  `BookRepository` (SQL) and the toolbar in `books/index.php` – four
  known places, no surprises.

## 7. Developer notes

- **Views are PHP templates.** "Components" are partials that read a
  documented `$variable` and are included with
  `require root_path('app/Views/...')`. Check the docblock at the top
  of each component for its contract.
- **Repositories return plain associative arrays**, never objects.
- **Time format** is UTC ISO-8601 (`gmdate('Y-m-d\TH:i:s\Z')`).
- **Soft deletes:** `deleted_at IS NULL` guards every read query; the
  cover file is removed on delete but the row is kept for restore.
- **Naming:** `browse` = search + filter + sort + paginate; `distinct`
  = dropdown values; `replace*` = delete-then-insert junction links.
- **Cache degradation over failure:** the engine never trusts the
  cache — every read/write/invalidate is guarded, a corrupt payload is
  a miss, and a broken cache logs a warning and rebuilds instead of
  erroring. The engine and the admin flush share ONE
  `PersonalizationCache` instance (extracted in `routes/web.php`).
- **Rate limiting:** new write endpoints should call
  `RecommendationController::throttle($bucket)`-style guards via
  `RateLimiter` (session-backed); limits live in
  `config/recommendations.php → security.rate_limit`. Buckets reset on
  TTL; `reset()` is available for explicit logout hooks.
- **Denormalized rating columns:** `books.average_rating` /
  `books.ratings_count` are the single read path for display and
  sorting; `ReviewService` is the single writer (one UPDATE after
  every review create/update/delete). Only `approved` reviews count,
  so moderation cannot leak into averages.
- **Dual-path forms:** state-changing forms answer JSON when the
  client sends `X-Requested-With: fetch` (wishlist toggle, review
  store) and redirect with a flash otherwise – no-JS stays intact.
- **Per-module front-end assets:** every module's stylesheet and
  behaviour script is loaded globally through the shared partials
  (`app/Views/partials/head.php` → `css/*.css`, `scripts.php` →
  `js/*.js`). Add a new module's `library.css` / `library.js` line
  there, never in a page template.
- Run `php -l` after every edit (or rely on the test suite).
