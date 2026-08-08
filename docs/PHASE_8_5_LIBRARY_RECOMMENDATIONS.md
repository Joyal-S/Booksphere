# Phase 8.5 — The Personal Library inside the Recommendation Engine

> **Phase:** 8.5 · **Module:** Recommendations (Library signals) + Personal Library
> **Builds on:** Phases 6.1–8.4 (engine, reviews, library, dashboard) · **Complete**

## 1. Project analysis (what Phase 8.5 started from)

Phases 6.1–6.5 built the Recommendation Engine (strategies, scoring,
hybrid personalization, cache, metrics) on three community signals:
reviews, the legacy wishlist and book views. Phases 8.1–8.4 replaced
the legacy wishlist with the five-shelf **Personal Library**
(`user_library`), showcased on the dashboard, the library page and the
profile — but the engine was **not yet reading that library**.

Phase 8.5 connects the two: the library becomes the engine's richest
personal signal, and the engine becomes a first-class citizen of the
library surfaces. Every requirement from the brief:

| Brief requirement | Where it lives |
|---|---|
| All weights / limits in `config/recommendations.php` (no magic numbers) | new `'library'` block read through **`RecommendationConfig`** (weights, section limits, log retention, hidden gems, accuracy window, similarity bands) |
| Weights: favourite categories, favourite authors, reading history, want-to-read, rating, popularity | `libraryScore()` — `35 + 25 + 15 + 10 + 10 + 5 = 100`, with partial credit caps |
| Profile peak: favourite categories & authors | `profileRecommendationInsights()` + `topLibraryCategories()` / `topLibraryAuthors()` |
| Explainable recommendations (real categories/authors named) | `libraryReason()` (batch name maps — no N+1) + `decorateCommunityItems()` |
| No duplicate books across the new shelves | cross-section dedupe in `bookRecommendations()` / `libraryPageRecommendations()` |
| No N+1 | batch category/author links (`categoriesForBooks` / `authorsForBooks`), one-query collaborative reads, annotated `recommendationLogs` |
| Caching per user / per section | **`PersonalizationCache` per-section files** (`section_{user}_{section}.json`) |
| Recommendation Accuracy: how many served books were acted on | `recommendation_logs` (migration 0019) + the profile's accuracy figure — strict attribution: an action counts only when created at or after the recommendation was served (an action predating the recommendation is never attributed, so the figure never inflates itself) |
| Logs table with retention | `recommendation_logs` pruned on write (`retention_per_user`, default 200) |
| Book page: Readers also enjoyed / Same author / Same category / Similar rating / Similar popularity / Recommended for you | `bookRecommendations()` — six deduped, explained, logged sections |
| Library page: Because in your library / People also saved / Favourite category / Favourite author / Recently discovered | `libraryPageRecommendations()` — five sections, own-library excluded |
| Dashboard: Recommended for you / Because you read / Trending | `DashboardController` — the last placeholder cards replaced by real engine shelves |

---

## 2. Architecture (what the phase added)

```
RecommendationService (Phase 8.5 block)
   │  libraryRecommendations()        bookRecommendations()
   │  libraryPageRecommendations()    profileRecommendationInsights()
   │  logRecommendations()
   ▼
RecommendationConfig            RecommendationScoring
   (config only, never SQL)        (libraryScore / ratingQuality /
                                    collaborativeScore mirrors)
   ▼
RecommendationRepository (new SQL: library reads + the log trail)
   ▼
SQLite (user_library + books + categories + authors +
        recommendation_logs [0019] + reviews + wishlist)
```

### The key design decisions

1. **One config source of truth.** `config/recommendations.php →
   'library'` holds every weight and limit; `RecommendationConfig`
   reads it through bounded, defaulted accessors (a bad/missing value
   can never produce a 0-weight shelf or an unbounded retention). The
   scoring mirrors live in `RecommendationScoring`, so the SQL users
   never see magic numbers.
2. **One bounded candidate query per section.** `scoreLibraryShelf()`
   resolves the section's factor sets (favourite-category ids, author
   ids, finished/want-to-read category ids from `librarySignalIds()`),
   runs a single `hybridCandidates()` pool read, then batch-loads the
   categories/authors of every candidate with two IN queries — no
   N+1, no in-memory joins.
3. **Explainable by construction.** `libraryReason()` names the actual
   category or author that fired, from batch name maps ("Recommended
   because you like Science Fiction."), and `decorateCommunityItems()`
   attaches the collaborative/quality reasons. Every served card shows
   one of them.
4. **One line of defence per page against duplicates.** `bookRecommendations()`
   deduplicates across its six sections with a first-served-wins map —
   and the **personal shelf is served first**, so the most specific
   section is never starved by the generic community shelves below it.
5. **A library that does not exist is never fabricated.** `libraryPageRecommendations()`
   returns honest empty sections for a library-less user (the page has
   its own empty states). The dashboard's cold-start popularity
   fallback stays in `libraryRecommendations()` for the personal
   shelves.
6. **The audit trail is a table, the accuracy is a read.** Migration
   0019 adds `recommendation_logs`; every served shelf appends one row
   per book (reason + score + signal) and prunes the user's rows to
   `retention_per_user` on the same write. The profile's accuracy is
   one `recommendationLogs()` query with three EXISTS annotations
   (in-library / rated / saved) — no N+1.
7. **Caching is per user AND per section.** The new `getSection()` /
   `putSection()` files share the hybrid shelf's TTL; `invalidate()`
   and `flush()` drop them together with the hybrid file — one signal
   change refreshes every shelf of the user.
8. **Guests are first-class.** `bookRecommendations(anchor, null)` runs
   the community shelves for anonymous visitors (logging is a quiet
   no-op); `libraryRecommendations(0, …)` serves the community
   sections and a placeholder for the personal ones.

---

## 3. Files created (Phase 8.5)

| File | Purpose |
|---|---|
| `app/Services/RecommendationConfig.php` | The config accessor of the library block: `libraryWeights()`, `sectionLimit($surface)`, `logRetention()`, `hiddenGems()`, `accuracyWindowDays()`, `similarity()` — all bounded/defaulted |
| `database/migrations/0019_create_recommendation_logs_table.php` | `recommendation_logs` (user_id / book_id FK CASCADE, reason, score, signal, `generated_at` UTC default) + `idx_recommendation_logs_user_generated` + `idx_recommendation_logs_book` |
| `app/Views/recommendations/components/shelf-strip.php` | The reusable shelf strip (section header + `recommendation-card.php` row), shared by the dashboard, book, library and profile blocks |
| `tests/RecommendationLibraryIntegrationTest.php` | The Phase 8.5 end-to-end suite (**147 checks**) |
| `docs/PHASE_8_5_LIBRARY_RECOMMENDATIONS.md` | This report |

## 4. Files modified (Phase 8.5)

| File | Change |
|---|---|
| `config/recommendations.php` | New `'library'` block: `weights`, `section_limits` (dashboard/book/library/profile), `logs.retention_per_user` 200, `hidden_gems`, `accuracy.window_days` 30, `similarity` (rating band, popularity factor, discovery window) — plus the header docs |
| `app/Services/RecommendationScoring.php` | +`LIBRARY_FACTOR_CAPS`, `LIBRARY_POPULARITY_NORMALIZER`, `CO_SAVED_NORMALIZER`, `libraryWeights()`, `libraryScore()`, `ratingQuality()`, `collaborativeScore()` |
| `app/Repositories/RecommendationRepository.php` | +16 library/log reads: `libraryBookIds?` (+shelf filter), `favouriteBookIds`, `finishedBookIds`, `wantToReadBookIds`, `topLibraryCategories`, `topLibraryAuthors`, `coSavedBooks`, `coSavedForLibrary` (fixed to the user's neighbourhood), `recentlyDiscoveredBooks`, `hiddenGemBooks`, `booksSimilarByRating`, `booksSimilarByPopularity`, `libraryProfileBooks`, `anchorBook`, `logRecommendations`, `pruneRecommendationLogs`, `recommendationLogs` |
| `app/Services/RecommendationService.php` | **The Phase 8.5 block**: `LIBRARY_SECTIONS` (8 dashboard/book sections), `librarySections()`, `libraryRecommendations()`, `bookRecommendations()` (6 sections + cross-section dedupe + null-user logging), `libraryPageRecommendations()` (5 sections + the library-less guard), `profileRecommendationInsights()`, `logRecommendations()` + `logShelf`/`logSections`; privates `scoreLibraryShelf`, `libraryReason`, `libraryConfidence`, `librarySignalIds`, `authorIdsOf`, `decorateCommunityItems`, `excludeOwnLibrary`, `libraryNote`, `cacheReadSection`/`cacheWriteSection`. **Bugs fixed by the new suite**: the section limit was never applied on the personal path; `logSections()` crashed on a guest; the book-page sections duplicated books across shelves. |
| `app/Services/PersonalizationCache.php` | +`getSection()` / `putSection()` (per-section files, key regex `/^[a-z_]+$/`); `invalidate()` / `flush()` now drop the section files too |
| `app/Exceptions/RecommendationException.php` | +`unknownLibrarySection($section)` |
| `app/Controllers/DashboardController.php` | +optional 3rd `RecommendationService`; `index()` feeds `recommendedForYou` / `becauseYouRead` / `trendingBooks` (real shelves) and logs the dashboard signals |
| `app/Controllers/BookController.php` | `show()` passes `bookRecommendations` (six explained sections for signed-in users) |
| `app/Controllers/UserController.php` | +optional 5th `RecommendationService`; `show()` passes `recommendationInsights` |
| `app/Controllers/LibraryController.php` | +optional 4th `RecommendationService`; `dashboardViewData()` passes `libraryRecommendations` |
| `routes/web.php` | The four controllers wired with the shared `RecommendationService` |
| `app/Views/dashboard/index.php` | Sections 2–4 replaced by shelf-strips: Recommended for You, Because You Read, Trending Books (real engine output, empty states guarded) |
| `app/Views/books/show.php` | +six shelf-strips under the reviews section (Readers also enjoyed … Recommended for you) |
| `app/Views/library/index.php` | +the "4b. Recommended for your library" block (five sections) before the filter bar |
| `app/Views/profile/show.php` | +the "Reading Preferences & Recommendation Insights" dash-section (favourite categories/authors chips, the Recommendation Accuracy tile, the books influencing the shelves) |
| `tests/LibraryTest.php` | Phase 8.5 hooks regression section 20 — **274 checks** |

---

## 5. Routes

| Method | Path | Action |
|---|---|---|
| GET | `/` (dashboard) | `DashboardController::index()` — Recommended for You / Because You Read / Trending |
| GET | `/books/{id}` | `BookController::show()` — six book-detail sections |
| GET | `/library` (+shelves) | `LibraryController` — the five library-page sections |
| GET | `/profile` | `UserController::show()` — the insights block |

No new routes: the Phase 8.5 surfaces ride the existing pages.

---

## 6. How each workflow flows

### The dashboard
`index()` reads the personal hybrid shelf (5), the library-derived
`because_you_read` shelf and the community `getTrendingBooks()` shelf,
each through the SHARED `RecommendationService`; the first two are
also logged (`dashboard_recommended`, `because_you_read`).

### The book page
`bookRecommendations($bookId, $userId)` builds the six sections —
personal first (dedupe priority), then collaborative / same-author /
same-category / similar-rating / similar-popularity — each limited,
excluded of the anchor and explained; all served items are logged with
their section signal.

### The library page
`libraryPageRecommendations($userId)` returns the weighted
`because_in_library` shelf, the neighbourhood `people_also_saved`
shelf, the `favourite_category` / `favourite_author` shelves (named
after the real top genre/author, own library excluded) and the
`recently_discovered` shelf. A user with no library gets empty
sections (never fabricated).

### The profile
`profileRecommendationInsights($userId)` answers the reading
preferences (top categories/authors), the **Recommendation Accuracy**
(served inside the window vs. how many of those the user saved /
rated / saved to the wishlist) and the books that shaped the shelves
(favourites + finished, with covers and category lists).

---

## 7. Repository methods added (Phase 8.5)

`libraryBookIds()?` · `favouriteBookIds()` · `finishedBookIds()` ·
`wantToReadBookIds()` · `topLibraryCategories()` · `topLibraryAuthors()`
· `coSavedBooks()` · `coSavedForLibrary()` · `recentlyDiscoveredBooks()`
· `hiddenGemBooks()` · `booksSimilarByRating()` ·
`booksSimilarByPopularity()` · `libraryProfileBooks()` · `anchorBook()`
· `logRecommendations()` · `pruneRecommendationLogs()` ·
`recommendationLogs()`. Every statement stays prepared; the
collaborative shelves carry their raw counts (`saved_count` /
`shared_count` / `discovery_count`); the log read annotates actions
with correlated EXISTS subqueries.

## 8. Service methods added (Phase 8.5)

`libraryRecommendations()` · `bookRecommendations()` ·
`libraryPageRecommendations()` · `profileRecommendationInsights()` ·
`logRecommendations()` (+`logShelf` / `logSections`) · `librarySections()`.

## 9. Controller methods added (Phase 8.5)

None — the controllers stay thin: each page already has its route, the
phase only wires the shared `RecommendationService` and passes the new
payload keys.

---

## 10. Validation & business rules (Phase 8.5)

- All weights/limits flow from `config/recommendations.php` via
  `RecommendationConfig`; the accessors clamp out-of-range values.
- The section key is a closed allowlist (`unknownLibrarySection`
  exception); the section cache key is regex-guarded (`/^[a-z_]+$/`).
- Personal shelves exclude the user's library **and** their wishlist;
  the library-page sections exclude only the user's library.
- Cross-section dedupe is first-served-wins with the personal shelf
  highest priority.
- Guests: community shelves only; logging is a quiet no-op.
- A library-less user sees the library-page empty states, never a
  fabricated "because you keep …" shelf.
- On write, logs are pruned to `retention_per_user` (deliberately no
  `ON DELETE` interplay: FKs cascade with users/books).
- A failing log write degrades to a logger warning — the shelf itself
  is never lost.

## 11. Security measures

- Prepared statements everywhere (weights, limits, cutoffs and even
  the bands are bound parameters).
- CSRF + `AuthMiddleware` unchanged on all routes; the new views keep
  `e()` escaping on every echoed category/author/title.
- No new secrets, no sessions expanded, no admin surface added.

---

## 12. Testing checklist

- `php tests/RecommendationLibraryIntegrationTest.php` — **147 checks,
  0 failed**: the config accessors (weights sum 100, section limits
  per surface, retention/gems/window bands), the scoring mirrors
  (full signal = 100, partial credit, binary author, rating quality,
  collaborative caps), the 0019 schema (columns, indexes, FK cascade),
  the 16 repository reads (deterministic on the seeded fixtures),
  every service surface (guest/exclusion/limit/exception/explanation/
  logging), the accuracy math (act on a recommendation → the figure
  rises), the retention pruning, the per-section cache + invalidation,
  and the four wired page renders (dashboard / book / library /
  profile).
- The suite also **caught and fixed three real bugs**: the personal
  section limit was never applied, `logSections()` crashed when
  bookRecommendations ran without a user, and the book page duplicated
  books across its sections.
- All nine suites green — **1233 checks total, 0 failed**
  (LibraryTest 274 + ReviewTest 369 + ReviewIntegrationTest 109 +
  RecommendationLibraryIntegrationTest 147 + 4 phase 6/8 suites + BrowseTest 69).

**Manual checklist** (full plan in `docs/MANUAL_TEST_CHECKLIST.md`):
sign in as Riya → the dashboard shows Recommended for You / Because
You Read / Trending; open any book with saves (e.g. 1984) → the six
explained shelves, no repeated cover; open `/library` → the five
recommendation sections named after the real genre/author; open the
profile → Reading Preferences chips, the accuracy tile; sign out →
the book page still shows the community shelves; empty-library user →
the library page shows its empty states; flush the cache from
`/admin/recommendations` and confirm the shelves rebuild.

---

## 13. Documentation updated

- `docs/PHASE_8_5_LIBRARY_RECOMMENDATIONS.md` (this report).
- `docs/ARCHITECTURE.md` — the Recommendation Engine entry extended
  with the library signals, and the Personal Library entry notes the
  engine hooks now live.
- `README.md` — phase list, current phase, test totals and docs index.
- `docs/MANUAL_TEST_CHECKLIST.md` — the Phase 8.5 manual plan.

---

## 14. Preparation notes for the next phase

- `recommendation_logs` is a clean base for **admin auditing**: the
  `idx_recommendation_logs_book` index already serves "what has the
  engine recommended to everyone".
- The accuracy figure is window-driven (`accuracy.window_days`); a
  trend chart (last 30/90 days) needs only one more grouped read over
  the same table.
- The per-section cache files could be surfaced on the admin metrics
  page by extending `PersonalizationCache::stats()`.
- A "Why was this recommended?" drilldown could reuse the existing
  `matched` factor keys already carried on every library item.
- Community shelves (trending / fresh arrivals / collections / social)
  remain future phases; the section catalogue in `LIBRARY_SECTIONS`
  is the single mount point.