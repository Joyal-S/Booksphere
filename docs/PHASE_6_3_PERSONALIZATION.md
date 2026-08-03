# Phase 6.3 – Personalized Hybrid Recommendations

**Status:** delivered, tested (62/62 checks + 86/86 Phase 6.2 regression
+ 69/69 browse regression) and smoke-tested over HTTP.

Phase 6.2 delivered six generic, explainable shelves. Phase 6.3 adds a
per-user **personal shelf**: a hybrid formula that combines eight
personalization signals into one 0–100 score, explains every
recommendation in plain language, and caches the result per user.
The generic shelves are untouched; personalization is an additive
layer (`RecommendationService::getPersonalizedRecommendations()`).

All weights live in `config/recommendations.php` — the engine is a
tuning problem, not a code change.

---

## 1. Personalization factors

Each factor contributes to a profile and/or the hybrid score:

| Factor | Weight | Contribution |
|---|---|---|
| Shared favourite categories | 40 | A candidate book in a favourite category |
| Favourite authors | 25 | A candidate book by a favourite author (binary) |
| Wishlist similarity | 15 | Candidate category overlaps the user's wishlist |
| Rating similarity | 10 | Candidate category overlaps the user's 4–5 star books |
| Trending | 5 | The book is trending this month |
| Popularity | 5 | Popularity bonus (normalized, capped) |
| Recently viewed | — | Rides the wishlist factor as a `viewed` overlap signal |
| Favourites (derived) | — | Categories/authors auto-detected from wishlist + high ratings + reviews |

Design notes:

- **Recently viewed** is not a separate weight: viewed books count as
  a similarity overlap in the 15-point wishlist factor (they express
  the same "what interests this user" signal), and the *exact* viewed
  books are excluded from the shelf so the user never sees the book
  they just opened.
- **Favourites are never hardcoded**: `favouriteCategories`/
  `favouriteAuthors` are derived per user (see §5). A 1–2 star rating
  never builds a favourite; ratings of 1–2 are ignored entirely.
- **Popularity can never dominate**: its 5-point factor is
  normalized and capped, so a popular book can never outrank a
  personal match (the "never dominates" rule from the brief).

## 2. Scoring formula

```
category   = 40 x min(shared favourite categories, 2) / 2      partial credit
author     = 25 x min(shared favourite authors, 1)             binary
wishlist   = 15 x min(wishlist-overlap categories, 3) / 3      incl. viewed overlap
rating     = 10 x min(rating-overlap categories, 3) / 3
trending   =  5 x 1  (when trending)
popularity =  5 x min(popularity / 3, 1)                       small bonus
score      = category + author + wishlist + rating + trending + popularity
```

- The caps give **partial credit** (a book sharing one favourite
  category earns 20 of 40 points), so scores stay smooth instead of
  binary while the caps still bound every factor to its weight.
- The author factor is **binary**: a book is either by a favourite
  author or it is not (single-author books would otherwise only ever
  earn half points).
- Tiebreaks in `sortRecommendations()`: score DESC → trending DESC →
  popularity DESC → id ASC (deterministic shelves).
- **Confidence**: `high` = score ≥ 60 with ≥ 2 personal factors
  (category/author/wishlist/viewed/rating); `medium` = score ≥ 30;
  else `low`.
- **Reasons** (max two sentences, data-driven):
  - category: "You enjoy Science Fiction and History books."
  - author: "Because you follow Arundhati Roy and Yuval Noah Harari."
  - wishlist (viewed overlap): "Similar to books in your wishlist
    [or recently viewed]."
  - rating: "Popular among readers of your highly rated books."
  - trending: "Gaining momentum this month."
  - fallback: "A community favourite - a starting point for your profile."

## 3. Configuration

`config/recommendations.php` is the single tuning point:

```php
'hybrid_weights' => ['category' => 40, 'author' => 25, 'wishlist' => 15,
                     'rating' => 10, 'trending' => 5, 'popularity' => 5], // = 100
'profile' => ['wishlist_weight' => 3, 'high_rating_weight' => 2,
              'review_weight' => 1, 'min_favourite_rating' => 4,
              'ignore_rating' => 2, 'favourite_categories' => 5,
              'favourite_authors' => 5],
'candidates' => ['pool_limit' => 50, 'signal_book_cap' => 20,
                 'popularity_fallback' => 10],
'confidence' => ['high' => 60, 'medium' => 30],
'cache' => ['enabled' => true, 'ttl_seconds' => 1800,
            'directory' => root_path('database/cache/recommendations')],
```

Every consumer reads through `config()` and falls back to constants in
`RecommendationScoring`, so a missing key can never crash the engine.

## 4. Files created / modified (6.3)

Created:

- `config/recommendations.php` — all tuning knobs (§3)
- `database/migrations/0012_create_book_views_table.php` — the
  recently-viewed signal (`book_views` with `UNIQUE(user_id, book_id)`,
  upsert semantics)
- `app/DTO/PersonalizationProfile.php` — per-user signal snapshot
- `app/DTO/PersonalizedRecommendationItem.php` — book + score + reason
  + confidence + matched factors
- `app/Services/PersonalizationCache.php` — per-user file cache
- `tests/PersonalizationTest.php` — 62-check scenario suite
- `docs/PHASE_6_3_PERSONALIZATION.md` — this document

Modified:

- `app/Services/RecommendationScoring.php` — `hybridWeights()`,
  `hybridScore()` + factor caps
- `app/Services/RecommendationService.php` — the personalization
  pipeline (§5)
- `app/Repositories/RecommendationRepository.php` — signal reads,
  `recordBookView()`, `hybridCandidates()` (§6)
- `app/Controllers/RecommendationController.php` — `index()` renders
  the personal shelf
- `app/Controllers/BookController.php` — `show()` records the view
  (logged-in users only)
- `routes/web.php` — cache wiring into the service
- `app/Views/recommendations/index.php` + `public/assets/css/app.css`
  — score/confidence chip
- `README.md`, `docs/ARCHITECTURE.md`, `docs/MANUAL_TEST_CHECKLIST.md`

## 5. Service methods (RecommendationService)

Public API:

- `getPersonalizedRecommendations(?int $userId, int $limit)` —
  cache → build profile → score pool → filter → sort → limit → cache.
- `calculateHybridScore(...)` — the §2 formula.
- `getRecommendationReason(...)` — phrase builder for a matched set.
- `filterRecommendations(...)` — drops wishlist + recently-viewed
  books, duplicates, junk ids.
- `sortRecommendations(...)` — §2 tiebreaks.
- `limitRecommendations(...)` — cuts to shelf size.
- `invalidatePersonalization(int $userId)` — cache drop (used when
  the user's signals change).
- `recordBookView(int $userId, int $bookId)` — upsert into
  `book_views` (ignores junk ids).

Private pipeline (the heart of the phase):

- `buildProfile()` — reads wishlist, ratings, reviews and views in 4
  batch queries; assembles the profile from the `profile` config.
- `favourites()` — aggregates link rows into top-N favourite
  categories/authors; ties broken alphabetically (stable shelves);
  needs ≥ `min_favourite_rating`-rated books with 3+ signal weight.
- `scoreCandidates()` — builds the pool via `hybridCandidates()`,
  batch-loads categories/authors per candidate, computes signals and
  per-book scores, reasons and confidence.
- `matchedFactors()` / `confidenceFor()` — factor bookkeeping.
- `restoreResult()` / `storeResult()` — cache serialization
  round-trip (items keep their exact shape).

## 6. Repository methods (RecommendationRepository)

- `wishlistBookIds(userId)` — the user's wishlist.
- `ratedBooks(userId)` — id → rating map (signal weights use this).
- `reviewedBookIds(userId)` — books with a non-empty written review.
- `recentlyViewedBookIds(userId, limit)` — most recent views first.
- `recordBookView(userId, bookId)` — `INSERT ... ON CONFLICT
  (user_id, book_id) DO UPDATE SET viewed_at = excluded.viewed_at`
  (UTC timestamps).
- `categoriesForBooks(ids)` / `authorsForBooks(ids)` — batch link
  reads, so N candidates cost 2 queries, not 2N.
- `categoryNames(ids)` / `authorNames(ids)` — names for reasons.
- `hybridCandidates(...)` — one query for the whole candidate pool:
  favourite-category `EXISTS` clauses, favourite-author `EXISTS`,
  popularity fallback `IN (...)` (for users without favourites),
  always `status = 'published'` + `deleted_at IS NULL`, ordered by
  popularity DESC, `LIMIT` the pool size. Parameter order follows
  the SQL text order (the Phase 6.2 rule).

## 7. DTO structure

`PersonalizationProfile` (readonly): `userId`, `favouriteCategories`
and `favouriteAuthors` (`id => [name, weight]`), `wishlistBookIds`,
`highlyRatedBookIds`, `reviewedBookIds`, `recentlyViewedBookIds`,
`builtAt`. Helpers `favouriteCategoryIds()` / `favouriteAuthorIds()`
return `array_keys()`.

`PersonalizedRecommendationItem` (readonly): `book` (the full book
row), `score`, `reason`, `confidence`, `matched` (factor list).
`toArray()` merges the extra keys into the book row, so views and the
cache only ever see plain arrays.

## 8. Caching strategy

- One JSON file per user (`database/cache/recommendations/user_{id}.json`),
  TTL 30 minutes (`cache.ttl_seconds`), `cache.enabled` can disable
  the cache entirely (used by tests and useful for debugging).
- Writes go through a temp file + atomic rename, so a crash can never
  leave a half-written payload.
- `invalidatePersonalization()` is called by nothing automatic yet —
  the 30-minute TTL is the staleness bound. When new signals land
  (wishlist/rating/view) the app can invalidate explicitly; the tests
  prove stale-serving + invalidation + fresh recompute work (§10).
- Cache misses cost one recompute; hits are pure file reads (no
  queries), which is why the personal shelf stays cheap.

## 9. Performance optimizations

- **Single pool query**: all favourite categories/author conditions
  are one `hybridCandidates()` SELECT with EXISTS clauses + one
  popularity fallback, not N queries.
- **Batch reads**: categories/authors for the whole pool in 2 queries
  (`idsPerBook()` + `categoriesForBooks()`), names in 2 more.
- **Profile in 4 queries** regardless of signal count (wishlist,
  ratings, reviews, views).
- **Caps bound work**: `signal_book_cap` (20) limits per-signal
  books, `pool_limit` (50) bounds the pool, favourites capped at 5.
- **Cache**: a hit does zero SQL.

## 10. Testing checklist

Run: `php tests\PersonalizationTest.php` (62 checks), then
`php tests\RecommendationArchitectureTest.php` (86) and
`php tests\BrowseTest.php` (69) for regression. Sections:

1. CONFIG — weights exist, sum to 100, popularity smallest.
2. SCORING — formula arithmetic, caps, partial credit, popularity
   never dominates, empty signals → 0.
3. PIPELINE — filter (exclusions/duplicates/junk), sort tiebreaks,
   limit.
4. PROFILES — favourites derived from wishlist/ratings/reviews; 1-star
   ratings never build favourites; fallback shelf for signal-less
   users.
5. SHELVES — new-user fallback with honest reasons; every item
   explained/scored/confidence-labeled; wishlist exclusion; different
   users differ; stable recompute; heavy user; recently-viewed
   similarity + exclusion; reviews-only user; honest "starting point"
   fallback.
6. CACHE — fresh compute, cache hit, payload file, stale serving
   after a signal change, invalidation, re-caching, flush, short-TTL
   expiry, disabled cache.
7. VIEWS — upsert semantics, junk ids ignored, most-recent cap.
8. CONTROLLER — index() renders the personal shelf with reasons and
   scores, strategy cards still present, no wishlist books.

Manual (browser): log in as `admin@booksphere.test` / `Admin@123`,
open `/recommendations` — the "Recommended for you" shelf reflects
admin's signals (wishlist/ratings), reasons explain every card, scores
and confidence chips are visible; opening a book then returning to
the shelf shows "recently viewed"-style reasons for similar books.
HTTP smoke: `php tools\smoke_recommendations.php` (11 checks).

## 11. Future improvements (Phase 6.4)

- **Dashboard UI**: a dedicated profile view (favourite categories/
  authors, why-scores, tunable weights) using the already-public
  service methods — no engine changes required.
- **Read tracking**: a `read_books` table would let the engine exclude
  already-read books and weight recently-read authors more (the
  exclusion list already exists; only the signal needs wiring).
- **Explicit invalidation hooks**: invalidate the cache when a
  wishlist item or rating is added (API exists, no caller yet).
- **Cold-start blending**: interpolate trending/popular shelves into
  the fallback so new users see a "trending for you" mix.
- **More signals**: publisher, series, review length, rating variance;
  each is a new factor + weight, no architectural change.
- **Cache eviction**: max-age sweep for stale user files.
- **A/B tuning**: expose the config weights behind an admin form to
  tune the 40/25/15/10/5/5 split against click data.
