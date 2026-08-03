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
  smoke checks (see `docs/PHASE_6_5_OPTIMIZATION.md`).
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
  `/books/{id}/reviews` routes (91/91 checks, see
  `docs/PHASE_7_1_REVIEWS_RATINGS.md`). Phase 7.2 embeds the review
  section + form on the book page.
- **Category / author browse pages** – the browse query supports
  `category_id` / `author_id` filters today; a dedicated page only
  needs a new route + view reusing `_results.php` and
  `books/components/book-card.php`.
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
  store) and redirect with a flash otherwise — no-JS stays intact.
- Run `php -l` after every edit (or rely on the test suite).
