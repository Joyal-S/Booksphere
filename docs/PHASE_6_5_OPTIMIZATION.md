# Phase 6.5 — Recommendation Engine: Optimization, Performance, Monitoring & Production Readiness

## 1. Objective

Prepare the completed Recommendation Engine (Phases 6.1–6.4) for production:
optimize performance, strengthen the cache and its invalidation, normalize every
score onto one scale, make failures graceful, harden the write endpoints, add an
admin-only monitoring surface with a cache tool, polish the dashboard UI and
document everything — **without redesigning the engine or changing its
recommendation behaviour** (the hard constraint of the brief).

Result: **334 automated checks green** (4 existing suites + the new Phase 6.5
suite) and **21/21 live HTTP smoke checks green**, including the real 429
rate-limit response.

---

## 2. What changed, step by step

### Step 1 — Refactor

The engine was reviewed in full (`RecommendationService` 1,077 lines,
`RecommendationRepository` 868 lines, all six strategies, DTOs, scoring, cache,
presenter). The verdict: the pipeline is already structured for production
(single-responsibility steps, no SQL outside the repository, no scoring outside
`RecommendationScoring`, no strategy decisions outside the factory). The
constraint "do not redesign" was respected — no structural refactor was forced.
The Phase 6.5 additions extend the existing seams instead:

- cache access moved behind three small private helpers
  (`cacheRead` / `cacheWrite` / `cacheWarning`) instead of scattered `?->` calls;
- the cache instance was extracted in `routes/web.php` so the engine and the
  admin metrics page share ONE cache object.

### Step 2 — Query optimization

`database/migrations/0013_add_recommendation_indexes.php` adds four composite
indexes (forward-only, exactly like 0010/0011):

| Index | Serves |
|---|---|
| `idx_reviews_book_created ON reviews (book_id, created_at)` | the all-time + 30-day windowed review counts of the popularity/trending/hybrid queries |
| `idx_wishlist_book_created ON wishlist (book_id, created_at)` | the same two counts over the wishlist |
| `idx_book_views_user_viewed ON book_views (user_id, viewed_at)` | "last N views of one user" (filter + sort) |
| `idx_books_status_deleted ON books (status, deleted_at)` | the `ACTIVE_WHERE` rule every recommendation query starts from |

**Proof it works**: the new test suite runs SQLite's `EXPLAIN QUERY PLAN` and
asserts the planner actually uses each index (`SEARCH r USING COVERING INDEX
idx_reviews_book_created (book_id=? AND created_at>?)`, etc.).

### Step 3 — Cache invalidation

- **Per-user** (already present): wishlist toggle, refresh, and the future
  rating/review write-controllers call
  `RecommendationService::invalidatePersonalization($userId)`.
- **Catalogue-wide (new)**: `RecommendationService::flushPersonalization()`
  drops every cached shelf at once. `BookController::store() / update() /
  destroy()` call it after every catalogue write, because a created / updated /
  soft-deleted book can change what EVERY user is recommended.
- Reviews/ratings write controllers are Phase 7 — the hook is ready and
  documented in `RecommendationService::invalidatePersonalization()`.

### Step 4 — Freshness ("Updated X minutes ago")

The dashboard presenter now derives a human freshness phrase from the shelf's
`generatedAt`: *"Updated just now"*, *"Updated 5 minutes ago"*, hours, days —
and renders it in the hero (next to the quality ring, exact timestamp as
tooltip) and in the "Recommended for you" section header. `generatedAt` travels
through the cache round-trip, so the phrase is always honest about whether the
page holds this minute's signals or a cached snapshot.

### Step 5 — Duplicate removal

The engine already deduplicated INSIDE every shelf. The presenter now also
deduplicates ACROSS the dashboard sections (`dedupeSections()` /
`withoutSeen()`): the main shelf wins, then "Because you liked", "Follow",
"Trending", "Recent" — a book can never appear on two sections, and the
section `total` stays in sync. Verified by a suite check over all sections.

### Step 6 — Score normalization (0-100, one class)

`RecommendationScoring` is the single home of every scoring decision; it now
also owns the 0-100 normalization:

- `popularityPercent(raw)` — raw popularity ÷ `POPULARITY_NORMALIZER` (3.0),
  capped at 100;
- `trendingPercent(raw)` — raw trending ÷ `TRENDING_MAX_RAW` (5.0), capped;
- the hybrid score was already 0-100.

Normalization is **monotonic**, so the SQL `ORDER BY` keeps using the raw
scores and the ranking never changes — only the reported value is unified. The
admin page and the dashboard insights now show one comparable scale.

### Step 7 — Error handling / graceful degradation

The cache is an optimization, never a dependency:

- `PersonalizationCache::get()` treats a corrupt JSON payload as a miss;
- every cache read/write/invalidate/flush in the service is guarded
  (`cacheRead` / `cacheWrite` / `cacheWarning`): a broken cache degrades to an
  uncached run and logs a `warning` (injected optional `Logger`) instead of a
  500;
- the presenter already caught per-section `RecommendationException`s; the
  controller still turns engine exceptions into polite 404s.

### Step 8 — Security

- **New `app/Core/RateLimiter.php`** — a session-backed sliding-window
  throttle. The two write endpoints (`/recommendations/refresh`,
  `/wishlist/toggle`) call it before touching the database; past the limit the
  request answers **HTTP 429**. Limits are configuration
  (`config/recommendations.php → security.rate_limit`, defaults 30 refreshes
  and 60 toggles per minute).
- Review findings (already in place): prepared statements everywhere (weights
  bound as parameters), CSRF on every POST, `AuthMiddleware` + `AdminMiddleware`
  + the `RecommendationPolicy` gate, sanitized `RecommendationContext` at the
  edge, cache files under `database/cache` (outside the document root), JSON
  responses with `Content-Type: application/json`, production errors never
  leak internals (`ErrorHandler`).

### Step 9 — Metrics (admin only)

- `app/Services/RecommendationMetrics.php` composes the read-only health
  picture; the new repository methods (`signalTotals`, `topCategories`,
  `topAuthors`) own the SQL.
- `GET /admin/recommendations` (AdminMiddleware) renders four blocks:
  **Cache** (files, bytes, stale entries, cached users, writability, TTL),
  **Config** (the live weights/pool/confidence/limits), **Data** (published
  books, reviews, wishlist saves, tracked views, average rating, top
  categories/authors by signal) and **Scores** (average popularity/trending,
  raw + normalized).

### Step 10 — Admin tools

`POST /admin/recommendations/cache/flush` (AdminMiddleware + CSRF) drops every
cached shelf — the administrative counterpart of the user-facing refresh
button, applied to all users at once. The monitoring page hosts the button.

### Step 11 — UI improvements

- freshness phrase in the hero and the recommended section (Step 4);
- cross-section deduplication (Step 5);
- graceful empty/error states were already honest; the cache degradation
  (Step 7) guarantees the dashboard renders even when the cache is broken.

### Step 12 — Comments

Every new class, method and migration carries the codebase's viva-style
docblocks (why it exists, inputs, business responsibility, decisions).

### Step 13 — Testing

New `tests/RecommendationOptimizationTest.php` — **53 checks**:
indexes + EXPLAIN proof, normalization bounds/caps/monotonicity, freshness
phrase shape, cross-section dedupe, cache round-trip/invalidate/flush,
corrupted-cache degradation, `RateLimiter` window/limit/reset, metrics summary,
admin middleware + page render. Full regression: **334/334** checks across all
five suites, plus **21/21** live smoke checks (incl. the 429 path). See §4.

### Step 14 — Documentation

This report + README / ARCHITECTURE / MANUAL_TEST_CHECKLIST updates.

---

## 3. Files created / modified

**Created**

| File | Purpose |
|---|---|
| `database/migrations/0013_add_recommendation_indexes.php` | the four composite indexes |
| `app/Core/RateLimiter.php` | session-backed sliding-window throttle |
| `app/Services/RecommendationMetrics.php` | admin health picture composer |
| `app/Views/admin/recommendations.php` | the monitoring + flush page |
| `tests/RecommendationOptimizationTest.php` | the Phase 6.5 suite (53 checks) |
| `docs/PHASE_6_5_OPTIMIZATION.md` | this report |

**Modified**

| File | Change |
|---|---|
| `app/Services/RecommendationScoring.php` | `popularityPercent()` / `trendingPercent()` + `TRENDING_MAX_RAW` |
| `app/Services/RecommendationService.php` | `flushPersonalization()`, optional `Logger`, guarded cache helpers |
| `app/Services/PersonalizationCache.php` | `stats()` health read |
| `app/Repositories/RecommendationRepository.php` | `signalTotals()` / `topCategories()` / `topAuthors()` |
| `app/Presenters/RecommendationDashboardPresenter.php` | `updatedAgo`, `dedupeSections()` / `withoutSeen()` |
| `app/Controllers/RecommendationController.php` | optional `RateLimiter` + `throttle()` on the two writes |
| `app/Controllers/BookController.php` | catalogue flush after store/update/destroy |
| `app/Controllers/AdminController.php` | `metrics()` + `flushCache()` |
| `routes/web.php` | shared cache instance, `/admin/recommendations` routes, limiter wiring |
| `config/recommendations.php` | `security.rate_limit` block |
| `app/Views/recommendations/_hero.php`, `_section-recommended.php`, `index.php` | freshness phrase |
| `public/assets/css/app.css` | `.rec-freshness` chip style |
| `tools/smoke_recommendations.php` | 6 new Phase 6.5 checks (freshness, admin 200/403/302, flush, 429) |

---

## 4. Testing report

Automated (all from the project root):

| Suite | Checks | Result |
|---|---|---|
| `tests/RecommendationDashboardTest.php` (6.4) | 64 | pass |
| `tests/RecommendationArchitectureTest.php` (6.2) | 86 | pass |
| `tests/PersonalizationTest.php` (6.3) | 62 | pass |
| `tests/BrowseTest.php` (Book module) | 69 | pass |
| `tests/RecommendationOptimizationTest.php` (6.5) | 53 | pass |
| **Total** | **334** | **0 failed** |

Live HTTP smoke (`php -S 127.0.0.1:8123 -t public` +
`php tools/smoke_recommendations.php`): **21/21 ok**, including:
regular user → `403` on `/admin/recommendations`, guest → `302`,
admin flush → `302`, and the refresh throttle → `429` after 30 hits in one
minute.

Performance notes: the count subqueries that run once per candidate book are
now served by covering composite indexes; the recent-views read sorts inside
its index; the active-catalogue filter is an index seek. SQLite's planner was
verified with `EXPLAIN QUERY PLAN` rather than assumed.

---

## 5. Design decisions worth defending in a viva

1. **Normalization without re-ranking** — the SQL keeps raw scores; the 0-100
   percents are monotonic maps for display/metrics, so the brief's "do not
   change behaviour" constraint holds while every score gains one scale.
2. **One cache object, two owners** — the admin flush and the engine
   invalidation touch the same `PersonalizationCache` instance (extracted in
   `routes/web.php`), so the tool can never fight the engine.
3. **Cache degradation over cache failure** — a broken cache is a logged
   `warning` + uncached run, never a 500: the shelf is always worth one
   rebuild.
4. **Catalogue flush instead of a user walk** — a book write can change every
   user's shelf; `flushPersonalization()` drops all files in one pass instead
   of iterating user ids.
5. **Session rate limiting** — the throttle lives in the session the app
   already keeps (no new table, no daemon); per-user buckets die with logout,
   and a distributed deployment only has to move `RateLimiter` to shared
   storage.
6. **Forward-only schema evolution** — migration 0013 (like 0010/0011) never
   rewrites existing tables.

## 6. Remaining debt / Phase 7 hand-off

- Reviews & Ratings (Phase 7) write-controllers should call
  `invalidatePersonalization($userId)` on every rating/review change — the hook
  exists and is documented.
- `RateLimiter` buckets reset on TTL only; a logout hook (`reset()`) is ready
  for `AuthController::logout()` if desired.
- The count subqueries scale with the signal tables; if the catalogue ever
  reaches tens of thousands of books, the documented path is denormalised
  counters or a view, not a redesign.
- Stop condition: **wait for Phase 7 – Reviews & Ratings.**
