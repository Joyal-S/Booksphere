# Phase 7.6 — Reviews & Ratings Across the Platform

## 1. Project Analysis (before implementation)

Phase 7.6 was built on Phases 7.1–7.5, which were already verified
green (345/345 ReviewTest checks, 588 total across the six suites):

- **The ratings lived in one module, but not across the platform.**
  The book pages, the review lists, the dashboard shelves and the
  admin analytics used the Reviews module; the dashboard had no
  community-favourite shelf and no "my highest rated book" card; the
  author and category destinations were still PageController
  placeholders ("coming soon"); the profile stopped at the Phase 7.3
  rating activity block (no genres, no activity timeline); and the
  admin analytics showed only the six Phase 7.3 blocks.
- **The recommendation engine ignored review quality.** The hybrid
  formula had six factors (category / author / wishlist / rating /
  trending / popularity); the community signal of *how well a book is
  actually reviewed* was not part of the score.
- **The brief's review vocabulary is "one implementation, two
  names"**: `latestCommunityReviews()` delegates to the Phase 7.4
  `latestReviews()`, `communityFavorites()` to `mostReviewedBooks()`,
  and `topRatedBooks()` is the public name of the aggregation the
  admin `highestRatedBooks()` already ran privately — so integration
  code reads the way the brief describes it without duplicating SQL.
- **The two house rules harden every new query**: only `approved`
  reviews count, and soft-deleted books never appear (`b.deleted_at
  IS NULL`) — verified by dedicated test sections (9 and 10).
- **Multi-entity maths**: a review can belong to several categories
  and a book to several authors, so every aggregation that joins
  through `book_categories` / `book_authors` uses
  `COUNT(DISTINCT r.id)` (or an EXISTS subquery) — the numbers can
  never inflate.
- **The weights stay config-driven**: the `review_score` factor
  follows the Phase 6.3 rule — weights live in
  `config/recommendations.php`, `RecommendationScoring` mirrors them,
  and the Reviews module learns its own weight through
  `ReviewService::recommendationWeight()` (the recommendation page
  shows "review score x 10%" from the same source).

## 2. Files Created

| File | Purpose |
|---|---|
| `app/Controllers/AuthorController.php` | the author directory (`index`: every author + `authorAverage()` joined by id) and the author page (`show`: one `authorStatistics()` payload, 404 for unknown ids) |
| `app/Controllers/CategoryController.php` | the category directory (`index`: every category + `categoryAverage()`) and the category page (`show`: one `categoryStatistics()` payload, 404 for unknown ids) |
| `app/Views/authors/index.php` | the author directory: rating-badge + review-counter per author, links to the author pages |
| `app/Views/authors/show.php` | the author page: 3 stat tiles (Total reviews / Books reviewed / Average), review summary with distribution bars, Top reviewers, Highest rated book, Most reviewed book, Recent community reviews |
| `app/Views/categories/index.php` | the category directory: rating-badge + review-counter per category |
| `app/Views/categories/show.php` | the category page: 3 stat tiles, Top rated books, Most reviewed books, Community favourite spotlight, Recently reviewed |
| `app/Views/components/rating-badge.php` | compact stars + numeric value chip (reuses star-rating in compact mode) |
| `app/Views/components/review-counter.php` | "12 reviews" / "No reviews yet" text chip |
| `app/Views/components/review-summary-card.php` | big average + distribution bars (reuses the admin markup + `data-bar-percent`) |
| `app/Views/components/community-review-card.php` | avatar initial + stars + excerpt + helpful badge |
| `app/Views/components/top-rated-book-card.php` | rank + cover + aggregated average/count keys |
| `app/Views/components/statistics-card.php` | the analytics-tile design as a reusable card |
| `app/Views/components/recent-review-card.php` | cover + book title + stars + "Read the review" |
| `app/Views/components/community-activity-widget.php` | header + total badge + community-review-card list |
| `tests/ReviewIntegrationTest.php` | the Phase 7.6 suite: 109 checks (see §10) |

## 3. Files Modified

| File | Change |
|---|---|
| `app/Repositories/ReviewRepository.php` | Phase 7.6 section: `topRatedBooks()`, `mostReviewedBooks()`, `authorAverage()`, `mostReviewedCategories()`, `mostActiveReviewers()` (with the helpful-votes subquery, deleted books excluded), `platformStatistics()`, `authorStatistics()`, `categoryStatistics()`, `userStatistics()` (extends `userRatingStats()` with favourite genres + most reviewed category), `reviewActivityTimeline()` (`strftime('%Y-%m')`), `userHighestRatedBook()`; `topRatedBooksQuery()` gained the optional `$categoryId` (book_categories join + `COUNT(DISTINCT r.id)`) and the `deleted_at IS NULL` filter; `categoryAverage()` now also selects `c.id` (needed by the directory join); the platform totals and reviewer ranking now join `books` so soft-deleted books never count |
| `app/Services/ReviewService.php` | Phase 7.6 section: `latestCommunityReviews()`, `topRatedBooks()`, `mostReviewedBooks()`, `communityFavorites()`, `mostActiveReviewers()`, `authorAverage()`, `userReviewStatistics()`, `reviewActivityTimeline()`, `userHighestRatedBook()`, `authorStatistics()`, `categoryStatistics()`, `platformStatistics()`, `dashboardStatistics()` (six keys in one call), `recommendationWeight()`; `adminAnalytics()` rewritten to compose from `platformStatistics()` (`overallAverage` is now `['average' => float, 'count' => int]`) |
| `app/Models/Review.php` | facade forwards for the eleven new repository reads |
| `config/recommendations.php` | `hybrid_weights` rebalanced to the 7-factor formula: category 40 / author 25 / wishlist 10 / rating 10 / review_score 10 / trending 0 / popularity 5 (sum 100); docblock updated |
| `app/Services/RecommendationScoring.php` | `HYBRID_WEIGHTS_DEFAULT` + `REVIEW_SCORE_FACTOR_CAP = 1.0`; `hybridScore()` now sums the `review_score` term (signal = approved average / 5, capped) |
| `app/Services/RecommendationService.php` | `review_score` added to the signals; `matchedFactors()` includes `review_score`; the `reviewScoreSignal()` helper (0 for books without approved reviews); the "Highly rated by the community." reason; `personalNote()` prints all seven weights |
| `app/Controllers/PageController.php` | the `categories()` / `authors()` placeholder actions removed (replaced by the new controllers) |
| `routes/web.php` | `use` statements + constructions (`new AuthorController(new Author(), $reviewService)`, `new CategoryController(new Category(), $reviewService)`) and the routes `GET /authors`, `GET /authors/{id}`, `GET /categories`, `GET /categories/{id}` — all AuthMiddleware + SecureHeaders, registered before the old placeholders (which are gone) |
| `app/Controllers/DashboardController.php` | rewritten to one `dashboardStatistics($userId)` payload → view keys `topRated`, `latestReviews`, `communityFavourites`, `highestRatedReviews`, `myLatestReview`, `myHighestRatedBook` |
| `app/Controllers/UserController.php` | `show()` passes `userReviewStats` + `activityTimeline` |
| `app/Views/dashboard/index.php` | new Section 4 "Community Favourite Books" (real, most-reviewed) and Section 8 "My Highest Rated Book" (real, user's own highest rating) |
| `app/Views/profile/show.php` | "Most reviewed category" tile, "Favourite genres" chips (linking to the category pages) and the "Review activity" monthly timeline with animated bars |
| `app/Views/admin/index.php` | four headline tiles (Total reviews / Active reviewers / Books without reviews / Average platform rating) above the analytics grid, plus Most active reviewers, Most reviewed categories and Average by author blocks |
| `README.md`, `docs/ARCHITECTURE.md`, `docs/MANUAL_TEST_CHECKLIST.md` | Phase 7.6 documentation (§11) |

## 4. Database Changes

None. Every new read is a live aggregation over the existing tables
(`reviews`, `books`, `users`, `book_categories`, `categories`,
`book_authors`, `authors`, `review_helpful_votes`) with prepared
statements. No migration was needed — Phase 7.6 is a read-only
integration phase.

## 5. Routes Added

| Route | Controller | Purpose |
|---|---|---|
| `GET /authors` | `AuthorController::index` | the author directory with the real average author rating |
| `GET /authors/{id}` | `AuthorController::show` | one author's rating profile (404 for unknown ids) |
| `GET /categories` | `CategoryController::index` | the category directory with real averages |
| `GET /categories/{id}` | `CategoryController::show` | one category's rating profile (404 for unknown ids) |

All four sit behind `AuthMiddleware` + SecureHeaders like every
catalogue page and replace the PageController placeholders.

## 6. The composed payloads

**`ReviewService::dashboardStatistics($userId)`** — one call, six
keys, four queries:

| Key | Source | The dashboard section |
|---|---|---|
| `topRated` | `topRatedBooks(4)` | Top Rated Books (Phase 7.3 seam, now public) |
| `recentlyReviewed` | `latestReviews(4)` | Recent Reviews |
| `communityFavourites` | `mostReviewedBooks(4)` | Community Favourite Books (NEW) |
| `recentCommunityReviews` | `highestRatedReviews(4)` | Highest Rated Reviews |
| `myLatestReview` | `reviewsByUser(me, 1)` | My Latest Review |
| `myHighestRatedBook` | `userHighestRatedBook(me)` | My Highest Rated Book (NEW) |

**`ReviewService::adminAnalytics()`** — the extended platform
picture, composed from `platformStatistics()`: `overallAverage`
(now `['average', 'count']`), `distribution`, `highestRated`,
`lowestRated`, `booksWithoutRatings`, `categoryAverage`,
`totalReviews`, `activeReviewers`, `booksWithoutReviews`,
`mostActiveReviewers`, `mostReviewedCategories`, `authorAverage`.

**`ReviewRepository::authorStatistics($id)`** — `reviews`,
`booksReviewed`, `average`, `highestRated`, `mostReviewed`,
`recentReviews` (with `cover_image`), `topReviewers` — every read
joins through `book_authors`; the list reads use EXISTS so a
multi-author book never duplicates a row.

**`ReviewRepository::categoryStatistics($id)`** — `reviews`,
`booksReviewed`, `average`, `topRated` (via the category-aware
`topRatedBooksQuery`), `mostReviewed`, `communityFavourite` (the
current top-rated book), `recentReviews`.

**`ReviewRepository::userStatistics($id)`** — everything
`userRatingStats()` reports plus `favouriteCategories` (top 3 by
count) and `mostReviewedCategory`.

## 7. The review-score factor (recommendation integration)

The hybrid formula grew from six factors to seven:

```
review_score = w.review_score x min(approved average / 5, 1.0)
```

- the weight comes from `config/recommendations.php →
  hybrid_weights.review_score` (10), mirrored by
  `RecommendationScoring::HYBRID_WEIGHTS_DEFAULT` and reported by
  `ReviewService::recommendationWeight()` — one source, three
  readers, so the UI percentage can never drift;
- the signal is the book's real approved-review average normalized
  to 0–1 (`min(average / 5, 1.0)`, cap constant
  `REVIEW_SCORE_FACTOR_CAP`); a book without approved reviews earns
  nothing (the signal defaults to 0) — the factor is community
  signal, never the seeded sample columns;
- the engine's `signals()` map feeds `review_score` to the score
  mirror and the SQL ordering identically, `matchedFactors()`
  explains it ("Highly rated by the community.") and
  `personalNote()` prints the weight on the recommendation shelf.

The rebalance keeps the total at 100 (category 40 / author 25 /
wishlist 10 / rating 10 / review_score 10 / trending 0 / popularity
5). `trending` stays at 0 — the shelf and the tiebreak still work,
and a future phase can turn it on without touching the formula.

## 8. The truthful surfaces

| Surface | Source | Shows |
|---|---|---|
| Dashboard Community Favourite Books | `mostReviewedBooks(4)` | the most-reviewed books with real averages |
| Dashboard My Highest Rated Book | `userHighestRatedBook(me)` | the book the user rated highest (invite when none) |
| Author directory / page | `authorAverage()` / `authorStatistics()` | real averages, totals, top reviewers, spotlights |
| Category directory / page | `categoryAverage()` / `categoryStatistics()` | real averages, top rated, most reviewed, community favourite |
| Profile | `userStatistics()` + `reviewActivityTimeline()` | favourite genres, most reviewed category, monthly activity |
| Admin analytics | `platformStatistics()`-composed payload | totals, active reviewers, rankings, per-author averages |
| Recommendations | `review_score` signal | community-rated books rank higher |

Every number is a prepared aggregation of the `approved` reviews of
live books; nothing is sampled.

## 9. Security & Accessibility

- **Security**: every new query is a prepared statement; the only
  interpolated fragment is the allowlisted direction of
  `topRatedBooksQuery()` (`ASC`/`DESC`); all limits are bound
  parameters; every controller resolves the entity through the model
  and 404s unknown ids before any query runs; all output passes
  through `e()`; the new routes are behind AuthMiddleware +
  SecureHeaders; controllers stay thin (orchestration only — the
  aggregations live in the Reviews module).
- **Accessibility**: the new components reuse the shared star-rating
  (compact mode) so keyboard/no-JS behaviour is identical to every
  other star; the counters and badges are text chips (screen-reader
  friendly); the activity-timeline bars animate with
  `data-bar-percent` like the rating bars (reduced-motion aware);
  the avatar initials reuse the deterministic gradient tones; the
  stretched-link cards keep their focusable anchor first.

## 10. Testing Checklist — all green

Automated: `php tests/ReviewIntegrationTest.php` **109/109** — the
Phase 7.6 suite covers the seeded picture (12 approved reviews, 4
reviewers, 8 unreviewed books): the platform statistics (totals,
average 53/12, active reviewers, highest/lowest shelves, reviewer /
category / author rankings), the top-rated vs most-reviewed
orderings and the community-favourites alias, the Orwell author
profile (and the empty untouched author + unknown id), the
Technology category profile (top rated, most reviewed, community
favourite, recent reviews) and the untouched category, Riya's
enriched profile (favourite genres, most reviewed category,
timeline, highest rated book) and the fresh user's empty profile,
the composed dashboard payload (all six keys, per-user slices), the
review-score factor (config weight 10, cap 1.0, partial credit,
service self-report), the extended admin payload shape, the
moderation discipline (a hidden review moves nothing; approving it
moves every counter), the soft-delete discipline (a deleted book
vanishes from every shelf and every statistics payload and returns
on restore), the Review model facade forwards and the controller
smoke renders of the two new pages.

Regression: all six existing suites **0 failures** (BrowseTest,
RecommendationArchitecture, RecommendationOptimization,
RecommendationDashboard, Personalization 62/62, ReviewTest 345/345)
— the recommendation-engine change (new factor, rebalanced weights)
kept every PersonalizationTest scenario green. Full-file PHP lint on
every touched file: 0 errors. Live smoke over HTTP is documented in
`docs/MANUAL_TEST_CHECKLIST.md` §18.

## 11. Documentation Updated

`README.md` (phase banner now 7.6 + the Phase 7.5/7.6 story and the
done-so-far list), `docs/ARCHITECTURE.md` (the Reviews module entry
grew the Phase 7.5 + Phase 7.6 layers; the "Category / author browse
pages" extension point is marked DONE), `docs/MANUAL_TEST_CHECKLIST.md`
(new §17 Phase 7.5 + §18 Phase 7.6 manual checks), and this report.

**The integration flow:** a reader lands on the dashboard → the
Community Favourite shelf shows what everyone is reading → an author
page shows the author's community rating, top reviewers and the
highest rated / most reviewed books → a category page shows the same
for the genre → the profile shows favourite genres and the review
activity timeline → the admin sees the full platform picture → and
the recommendation shelf ranks community-rated books higher, with
"Review score x 10%" explained on the card.

**Future work (Phase 7.7+):** the reputation badges (Verified / Top
Reviewer / Expert) now have their aggregation seams
(`reviewReputation()` + `mostActiveReviewers()`), and the platform's
one ratings source makes reading challenges, clubs and forum
rankings trivial consumers of the same numbers.
