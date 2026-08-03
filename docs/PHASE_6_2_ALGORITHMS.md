# Phase 6.2 – Recommendation Algorithms

**Status:** delivered, tested (86/86 checks) and smoke-tested over HTTP.

Phase 6.1 delivered the recommendation engine's architecture
(strategy pattern, service, factory, repository, DTOs, policy,
routes) with placeholder algorithms. Phase 6.2 replaces the
placeholders with six real, explainable, SQL-scored algorithms:

| Strategy | Shelf | Source of truth |
|---|---|---|
| Popular | "Popular right now" | average rating + review count + wishlist count |
| Highest Rated | "Top Rated" | average rating (5+ reviews) |
| Recently Added | "Recently Added" | `created_at` (newest first) |
| Same Category | "By Category" | books sharing the category |
| Same Author | "More Like This" | books by the same author(s) |
| Trending | "Trending" | recent reviews + recent wishlists (30 days) |

Personalisation (a per-user profile) is deliberately **not** part of
this phase: it is the Phase 6.3 deliverable.

---

## 1. Algorithm definitions

All scores are computed in SQL and passed as bound parameters. Books
are always filtered to `status = 'published'` with `deleted_at IS
NULL`, and every shelf carries a per-item human-readable reason.

### Popular
Score = `(avg_rating/5) * 0.50 + review_count * 0.20 + wishlist_count * 0.30`.

- `avg_rating` capped at 5.0 (the rating column is already capped on
  insert, so the cap is a safety net).
- The weights make quality the dominant signal while reviews and
  wishlists provide breadth. The weights are plain parameters, so they
  are easy to tune without touching SQL.
- Result order: score DESC, then `average_rating` DESC as a tiebreak.
- No minimum review count: a 5-star book with one review is a
  legitimate "popular" entry in a small catalogue.

### Highest Rated
Score = `average_rating` (0–5), with `MIN_REVIEWS_FOR_RATING = 5`
so a single 5-star review cannot top the shelf. Books below the
threshold are excluded. Tiebreak: `ratings_count` DESC, then title.

### Trending
Score = `recent_reviews * 0.50 + recent_wishlists * 0.50`, where
`recent_reviews` counts reviews with `created_at >= now - 30 days`
and `recent_wishlists` counts wishlist additions in the same window
(`TRENDING_WINDOW_DAYS = 30`). Books with a zero score are excluded.

### Recently Added
Newest `created_at` first (tiebreak: newest `id` first). No score
involved.

### Same Category / Same Author
Candidate books joined through `book_categories` / `book_authors`.
The anchor book is excluded; drafts and deleted books are excluded.
Used both from an explicit `category_id`/`author_id` (browse-style
shelves) and from an anchor book (the "More Like This" shelf, where
the anchor's authors/categories are resolved first and the anchor
fails loudly if it is missing or has no authors).

### Note on "views"
The books table has no views column. Popularity therefore uses the
three signal columns that exist (`average_rating`, `ratings_count`,
`wishlist_count`); this is documented in the strategy docblocks so a
future phase can add a views signal without redesign.

---

## 2. Where the scoring lives

- `app/Services/RecommendationScoring.php` — single source of truth
  for the weights, the window, the review threshold and the score
  expressions. For each algorithm it provides a `*Sql()` fragment
  (embedding the weights as `?` placeholders), a `*Params()` list
  (matching the placeholders) and a `*Score()` mirror used by tests
  to assert the SQL order.
- `app/Repositories/RecommendationRepository.php` — the SQL. All six
  read methods return rows with `id`, `title`, `authors_list`,
  `categories_list`, `cover_image`, `average_rating`, `ratings_count`
  plus the per-algorithm score column.

### Placeholder ordering rule (important)
`?` markers bind **in the order they appear in the SQL text**. In
`trendingBooks()` the two scoring placeholders sit in the outer
SELECT, *before* the window/review-count placeholders inside the
correlated subqueries, so the params array must be
`[...trendingParams(), $cutoff, $cutoff, RECOMMENDED_STATUS, $limit]`.

### Why integers matter in SQLite
SQLite is loosely typed, but a literal `5 >= '5'` comparison is FALSE
when one side is TEXT. `Database::query()`/`execute()` therefore bind
PHP integers with `PDO::PARAM_INT` (new private `bindValues()`), so
the confidence threshold `average_rating >= ?` and the `>= $cutoff`
date comparisons behave correctly.

---

## 3. Files created / modified (6.2)

**New:**
- `app/Strategies/PopularBooksStrategy.php` (key `popular`)
- `app/Strategies/HighestRatedStrategy.php` (key `rating`)
- `app/Strategies/RecentlyAddedStrategy.php` (key `recent`)
- `app/Strategies/SameCategoryStrategy.php` (key `category`)
- `app/Strategies/SameAuthorStrategy.php` (key `author`)
- `app/Strategies/TrendingBooksStrategy.php` (key `trending`)
- `app/Strategies/AbstractRecommendationStrategy.php` (shared
  `resultFor()` / `withReason()` helpers)
- `app/Services/RecommendationScoring.php` (see above)
- `tools/smoke_recommendations.php` (HTTP smoke test: logs in as the
  seeded admin, then asserts the status code of every
  `/recommendations` route, including guest redirect and 404s)

**Removed:** the five Phase 6.1 placeholder strategies
(`PopularStrategy`, `RatingStrategy`, `CategoryStrategy`,
`AuthorStrategy`, `RecentStrategy`). Run `composer dump-autoload`
after adding/removing classes.

**Modified:**
- `app/Services/RecommendationService.php` — seven public methods:
  `getPopularBooks()`, `getHighestRatedBooks()`, `getRecentlyAddedBooks()`,
  `getTrendingBooks()`, `getBooksByCategory()`, `getBooksByAuthor()`,
  `getMoreLikeThis()` (book-anchored same-author shelf, anchor
  excluded), plus `recommend()`, `strategies()` and the `ROUTES` map.
- `app/Controllers/RecommendationController.php` — actions
  `index/popular/topRated/trending/recent/category/show`; a `render()`
  helper catches `RecommendationException` and turns it into a 404.
- `routes/web.php` — `/recommendations/top-rated`,
  `/recommendations/category/{id}` added; `/recommendations/personalized`
  removed.
- `app/Views/recommendations/index.php` + `public/assets/css/app.css` —
  the shelf now renders real book cards (`books/components/book-card.php`)
  with a per-book reason badge, a "running now" chip, run metadata
  and an empty-state component.
- `app/Core/Database.php` — integer-aware `bindValues()` (see above).
- `app/DTO/RecommendationContext.php` — `userId` made optional.
- `tests/RecommendationArchitectureTest.php` — rewritten for 6.2
  (86 checks, see below).

## 4. Routes

```
/recommendations                  index        (strategy chooser)
/recommendations/popular          popular
/recommendations/top-rated        topRated
/recommendations/trending         trending
/recommendations/recent           recent
/recommendations/category/{id}    category
/recommendations/book/{id}        show         ("More Like This")
```

All routes are behind `AuthMiddleware`; `RecommendationPolicy`
controls the admin-only management actions. `/recommendations/personalized`
no longer exists (404).

## 5. Testing

Automated: `php tests/RecommendationArchitectureTest.php` → **86/86**.

- Scoring order and mirror-score equivalence for Popular and
  Trending, including the 30-day window (reviews exactly 40 days old
  do not count) and the confidence threshold (`MIN_REVIEWS_FOR_RATING`).
- Exclusions: drafts, deleted books, the anchor book itself, and the
  anchor book's *reason text* disappearing from the shelf.
- Injection safety: category/author ids containing SQL are rejected.
- Controller smoke tests for all seven routes, including the
  book-anchored "More Like This" (a second book by the anchor's
  author is inserted into the throwaway test DB so the shelf is
  non-empty, and the anchor itself must not appear).
- Regression: `php tests/BrowseTest.php` → **69/69** (the shared
  `Database` binding change affects the whole app).

HTTP: `php -S 127.0.0.1:8123 -t public` then
`php tools/smoke_recommendations.php` — logs in as the seeded admin
(`admin@booksphere.test` / `Admin@123`) and verifies all routes
return 200, `/recommendations/personalized` and an unknown book id
return 404, and a guest is redirected to login.

Manual: see section 13 of `docs/MANUAL_TEST_CHECKLIST.md`.

## 6. Performance notes

- The popular/trending/rated queries scan `books` with a small set of
  aggregate subqueries over `reviews`/`wishlist`; the browse indexes
  (`idx_reviews_book`, `idx_wishlist_book`, etc.) cover the joins.
- `LIMIT` is applied in SQL; the merged home shelf dedupes three
  small result sets in PHP.
- `GROUP_CONCAT` builds `authors_list`/`categories_list` inside the
  aggregate queries (same technique as the Phase 5.6 browse pass).
- Cost is one query per shelf, plus one per strategy card (each with
  `LIMIT 5`) on the index page — fine at catalogue scale.

## 7. Future work (Phase 6.3)

- **Personalisation** — a per-user profile (read history, wishlists,
  ratings) feeding a personal shelf; the `RecommendationContext`
  already carries an optional `userId` and the strategy interface is
  ready for a `personal` strategy.
- **Views signal** — add a `views` counter and fold it into the
  popularity weight.
- **Cursor pagination** for the category/author shelves if a
  catalogue grows beyond a few thousand rows.
