# Recommendation Engine V2 — R1 Report

> **Phase**: R1 — Fix Rated & Reviewed Book Exclusions  
> **Status**: Completed & Verified  
> **Date**: 2026-09-18  
> **Database**: SQLite (`database/booksphere.db`)  
> **Target Branch**: `main`

---

## 1. Objective

The forensic audit of BookSphere's recommendation engine identified a critical correctness flaw in the hybrid personalization pipeline:

While personalized recommendations excluded books in a user's library (`finished`, `currently_reading`, `want_to_read`), wishlist, and recently viewed history, **already rated and reviewed books were not included in the hard exclusion set**.

Because rating a book $\ge 4$ stars awards maximum category (+40 pts) and author (+25 pts) affinity points to candidates sharing those attributes, the engine consistently recommended the exact book the user had just rated back to them at Rank #1.

**Phase R1 Objective**:
Ensure that a user never receives a book as a personalized recommendation if they have already rated or reviewed it, across all rating values (1 through 5 stars), while strictly preserving:
- All existing exclusions (library, wishlist, recently viewed)
- Scoring weights and formulas
- Candidate generation mechanics
- Zero extra SQL queries (reusing already-fetched profile data)
- User isolation and cache performance

---

## 2. Existing Exclusion Logic

Prior to Phase R1, recommendation filtering occurred in `RecommendationService::getPersonalizedRecommendations()` at line 406:

```php
// Existing code before R1:
$items = $this->filterRecommendations(
    $items,
    [...$profile->libraryBookIds, ...$profile->wishlistBookIds, ...$profile->recentlyViewedBookIds],
);
```

The underlying exclusion filter `RecommendationService::filterRecommendations()` operates by building a fast hash set:

```php
public function filterRecommendations(array $items, array $excludeIds = []): array
{
    $excluded = array_fill_keys(array_map('intval', $excludeIds), true);
    $seen = [];
    $kept = [];

    foreach ($items as $item) {
        $id = (int) ($item['id'] ?? 0);
        if ($id < 1 || isset($excluded[$id]) || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $kept[]    = $item;
    }

    return $kept;
}
```

Similarly, in `RecommendationDashboardPresenter::excludedIds()`, exclusions for dashboard cross-section deduplication (`becauseLiked`, `follow`, `trending`, `recent`) only excluded:
- `$this->repository->libraryBookIds($userId)`
- `$this->repository->wishlistBookIds($userId)`
- `$this->repository->recentlyViewedBookIds($userId, 20)`

---

## 3. Root Cause

1. **Incomplete Exclusion Array**:
   `RecommendationService::getPersonalizedRecommendations()` passed only `libraryBookIds`, `wishlistBookIds`, and `recentlyViewedBookIds` into `filterRecommendations()`. Neither `ratedBookIds` nor `reviewedBookIds` were passed.

2. **DTO Attribute Omission**:
   In `RecommendationService::buildProfile($userId)`:
   - `$ratings = $this->repository->ratedBooks($userId);` was already executed (fetching `[book_id => rating]`).
   - `$reviewedIds = $this->repository->reviewedBookIds($userId);` was already executed.
   However, `PersonalizationProfile` only stored:
   - `highlyRatedBookIds`: filtered to ratings $\ge 4$ (omitting ratings 1, 2, and 3).
   - `reviewedBookIds`: filtered to ratings $> 2$ (omitting low-rated reviews).
   Neither full set was exposed on `PersonalizationProfile` or routed to `filterRecommendations()`.

3. **Algorithm Recommending Already Consumed Books**:
   When a user rated a book 5 stars, that book was retained in the candidate generation pool (`hybridCandidates`). During feature scoring, the book matched its own category and author, receiving up to 83.4 points and ranking at #1 on the user's "Recommended for You" shelf.

---

## 4. Implementation

A surgical, minimal implementation was executed across 3 files with zero unnecessary queries:

### 4.1 Update `PersonalizationProfile` DTO
Added an optional `$ratedBookIds` property with a default of `[]` to maintain backwards compatibility:

```php
final readonly class PersonalizationProfile
{
    /**
     * @param array<int, array{name: string, weight: int}> $favouriteCategories
     * @param array<int, array{name: string, weight: int}> $favouriteAuthors
     * @param array<int, int> $wishlistBookIds
     * @param array<int, int> $highlyRatedBookIds
     * @param array<int, int> $reviewedBookIds
     * @param array<int, int> $recentlyViewedBookIds
     * @param array<int, int> $libraryBookIds
     * @param array<int, int> $ratedBookIds
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $favouriteCategories,
        public readonly array $favouriteAuthors,
        public readonly array $wishlistBookIds,
        public readonly array $highlyRatedBookIds,
        public readonly array $reviewedBookIds,
        public readonly array $recentlyViewedBookIds,
        public readonly string $builtAt,
        public readonly array $libraryBookIds = [],
        public readonly array $ratedBookIds = [],
    ) {}
```

### 4.2 Populate `$ratedBookIds` in `RecommendationService::buildProfile`
Reused the existing `$ratings` and `$reviewedIds` data without executing any duplicate queries:

```php
$ratedIds = array_values(array_unique([
    ...array_map('intval', array_keys($ratings)),
    ...array_map('intval', $reviewedIds),
]));

return new PersonalizationProfile(
    userId:                $userId,
    favouriteCategories:   $favouriteCategories,
    favouriteAuthors:      $favouriteAuthors,
    wishlistBookIds:       array_values(array_unique($wishlistIds)),
    highlyRatedBookIds:    $highlyRatedIds,
    reviewedBookIds:       $reviewedForProfile,
    recentlyViewedBookIds: $this->repository->recentlyViewedBookIds($userId, $viewCap),
    builtAt:               gmdate('Y-m-d\TH:i:s\Z'),
    libraryBookIds:        array_values(array_unique($libraryIds)),
    ratedBookIds:          $ratedIds,
);
```

### 4.3 Apply Hard Exclusion in `RecommendationService::getPersonalizedRecommendations`
Appended `...$profile->ratedBookIds` to the exclusion list:

```php
$items = $this->filterRecommendations(
    $items,
    [
        ...$profile->libraryBookIds,
        ...$profile->wishlistBookIds,
        ...$profile->recentlyViewedBookIds,
        ...$profile->ratedBookIds,
    ],
);
```

### 4.4 Dashboard Shelves Exclusion in `RecommendationDashboardPresenter`
Updated `excludedIds(int $userId, ?PersonalizationProfile $profile = null)` so other dashboard sections (`becauseLiked`, `follow`, `trending`, `recent`) also exclude rated/reviewed books:

```php
private function excludedIds(int $userId, ?PersonalizationProfile $profile = null): array
{
    if ($userId < 1) {
        return [];
    }

    $rated = $profile !== null
        ? $profile->ratedBookIds
        : array_values(array_unique([
            ...array_map('intval', array_keys($this->repository->ratedBooks($userId))),
            ...array_map('intval', $this->repository->reviewedBookIds($userId)),
        ]));

    return array_values(array_unique([
        ...$this->repository->libraryBookIds($userId),
        ...$this->repository->wishlistBookIds($userId),
        ...$this->repository->recentlyViewedBookIds($userId, 20),
        ...$rated,
    ]));
}
```

---

## 5. Files Changed

| File | Change Description |
| :--- | :--- |
| `app/DTO/PersonalizationProfile.php` | Added `public readonly array $ratedBookIds = []` to constructor and PHPDoc. |
| `app/Services/RecommendationService.php` | 1. Aggregated `$ratedIds` from `$ratings` and `$reviewedIds` in `buildProfile()`.<br>2. Appended `$profile->ratedBookIds` to `filterRecommendations()` in `getPersonalizedRecommendations()`. |
| `app/Presenters/RecommendationDashboardPresenter.php` | Updated `excludedIds()` to accept `$profile` and include `$profile->ratedBookIds`. |

---

## 6. Tests Added

A dedicated automated test suite was created:
`tests/RecommendationRatedExclusionTest.php`

The test suite runs against an isolated throwaway SQLite database with automatic cleanup on shutdown. It verifies 26 distinct assertions covering:
1. **Edge Cases A–E**: 1-star, 2-star, 3-star, 4-star, and 5-star ratings are each strictly excluded.
2. **Edge Case F**: Written reviews are strictly excluded.
3. **Edge Cases G–I**: Overlapping combinations (reviewed + wishlisted, reviewed + library, rated + recently viewed) remain strictly excluded.
4. **Edge Case J**: Multiple rated/reviewed books are all excluded simultaneously without leaking into the recommendations.
5. **Edge Case K**: Cold-start users (no ratings/reviews) retain their complete baseline recommendation behavior.
6. **Edge Case L & 10**: User isolation is strictly maintained; User A's exclusions do not leak into User B's recommendations or cache.
7. **Regressions 4–6**: Library exclusions, wishlist exclusions, and recently viewed exclusions continue to function independently.
8. **Regressions 7–8**: No duplicate recommendation IDs are introduced; candidate pool remains populated with eligible books.
9. **Dashboard Presentation**: `RecommendationDashboardPresenter` excludes rated books from the hero shelf, trending shelf, and recent shelf.

---

## 7. Test Results

Execution of `php tests/RecommendationRatedExclusionTest.php`:

```text
--- 1. BASELINE VERIFICATION ---
  PASS  Cold-start user receives recommendations
  PASS  Baseline candidate pool is healthy (>= 5 books)

--- 2. EDGE CASES: RATING VALUES 1 THROUGH 5 EXCLUSION ---
  PASS  A. 1-Star rated book is strictly excluded from recommendations
  PASS  B. 2-Star rated book is strictly excluded from recommendations
  PASS  C. 3-Star rated book is strictly excluded from recommendations
  PASS  D. 4-Star rated book is strictly excluded from recommendations
  PASS  E. 5-Star rated book is strictly excluded from recommendations

--- 3. COMBINATION & OVERLAP EDGE CASES ---
  PASS  F. Reviewed book (5-star with text) is excluded
  PASS  G. Book reviewed + wishlisted remains strictly excluded
  PASS  H. Book reviewed + in library remains strictly excluded
  PASS  I. Book rated + recently viewed remains strictly excluded
  PASS  J. All multiple rated/reviewed books remain excluded simultaneously
  PASS  K. Cold-start user recommendations remain active and populated
  PASS  L. User B can still receive books rated only by User A (user isolation)
  PASS  User A exclusion does not leak into User B profile

--- 4. REGRESSION VERIFICATION (LIBRARY, WISHLIST, RECENT VIEWS, DUPES) ---
  PASS  4. Existing library exclusion (currently_reading) still works
  PASS  5. Existing wishlist exclusion still works
  PASS  6. Existing recently-viewed exclusion still works
  PASS  7. No duplicate recommendation IDs are introduced on the shelf
  PASS  8. Unrelated eligible books remain available in recommendation pool
  PASS  10. Cache stores separate isolated keys per user
  PASS  Cache does not cross-contaminate excluded books between users

--- 5. DASHBOARD PRESENTER EXCLUSIONS ---
  PASS  Dashboard recommended shelf excludes 5-star rated book
  PASS  Dashboard recommended shelf excludes 1-star rated book
  PASS  Dashboard trending shelf excludes rated books
  PASS  Dashboard recent shelf excludes rated books

========================================================================
RESULT: 26 checks, 0 failed
========================================================================
```

---

## 8. Before vs After Performance

Measured against the live SQLite production database (`database/booksphere.db`) for User 2 (who has multiple ratings and reviews on record):

| Metric | Before R1 | After R1 | Delta |
| :--- | :---: | :---: | :---: |
| **Fresh Recommendation Time** | 10.45 ms | 10.17 ms | -0.28 ms (Identical) |
| **Warm (Cache Hit) Time** | 8.81 ms | 8.91 ms | +0.10 ms (Identical) |
| **SQL Query Count (Fresh)** | 15 queries | 15 queries | 0 additional queries |
| **SQL Query Count (Warm)** | 0 queries | 0 queries | 0 queries |
| **Peak PHP Memory** | 0.4453 MB | 0.4460 MB | +0.0007 MB |
| **Rank #1 Recommendation** | Book ID 2 (*1984*, rated 5★) | Book ID 14 (*Pride and Prejudice*) | **Fixed (Book 2 Excluded)** |
| **Rank #2 Recommendation** | Book ID 15 (*The Martian*, rated 5★) | Book ID 20 (*One Hundred Years of Solitude*) | **Fixed (Book 15 Excluded)** |

Zero database queries were added because `$ratings` and `$reviewedIds` were already queried during profile construction.

---

## 9. Exclusion Verification

| Signal / State | Exclusion Status | Verified In | Result |
| :--- | :---: | :---: | :---: |
| **1-Star Rating** | **EXCLUDED** | `RecommendationRatedExclusionTest::Case A` | PASS |
| **2-Star Rating** | **EXCLUDED** | `RecommendationRatedExclusionTest::Case B` | PASS |
| **3-Star Rating** | **EXCLUDED** | `RecommendationRatedExclusionTest::Case C` | PASS |
| **4-Star Rating** | **EXCLUDED** | `RecommendationRatedExclusionTest::Case D` | PASS |
| **5-Star Rating** | **EXCLUDED** | `RecommendationRatedExclusionTest::Case E` | PASS |
| **Written Review** | **EXCLUDED** | `RecommendationRatedExclusionTest::Case F` | PASS |
| **Review + Wishlist** | **EXCLUDED** | `RecommendationRatedExclusionTest::Case G` | PASS |
| **Review + Library** | **EXCLUDED** | `RecommendationRatedExclusionTest::Case H` | PASS |
| **Rating + Recent View** | **EXCLUDED** | `RecommendationRatedExclusionTest::Case I` | PASS |
| **Library (Finished)** | **EXCLUDED** | `RecommendationLibraryExclusionTest` | PASS |
| **Library (Reading)** | **EXCLUDED** | `RecommendationLibraryExclusionTest` | PASS |
| **Library (Want to read)** | **EXCLUDED** | `RecommendationLibraryExclusionTest` | PASS |
| **Wishlist** | **EXCLUDED** | `PersonalizationTest` & `RecommendationRatedExclusionTest` | PASS |
| **Recently Viewed** | **EXCLUDED** | `PersonalizationTest` & `RecommendationRatedExclusionTest` | PASS |

---

## 10. Full Regression Result

The complete BookSphere automated test suite was executed across all 55 test files:

- **Total Test Suites**: 55 files
- **Passed**: 55 files (100%)
- **Failed**: 0 files (0%)

Key suites verified:
- `RecommendationArchitectureTest.php` (86 / 86 checks PASS)
- `RecommendationDashboardTest.php` (64 / 64 checks PASS)
- `RecommendationLibraryExclusionTest.php` (17 / 17 checks PASS)
- `RecommendationLibraryIntegrationTest.php` (149 / 149 checks PASS)
- `RecommendationOptimizationTest.php` (57 / 57 checks PASS)
- `RecommendationRatedExclusionTest.php` (26 / 26 checks PASS)
- `PersonalizationTest.php` (62 / 62 checks PASS)
- `AuthTest.php` (PASS)
- `LibraryTest.php` (PASS)
- `ReviewTest.php` & `ReviewIntegrationTest.php` (PASS)
- `Community*.php` suites (all PASS)
- `Search*.php` suites (all PASS)
- `GoogleBooks*.php` suites (all PASS)
- `SecurityAuditTest.php` (PASS)

---

## 11. Database Integrity

Ran SQLite PRAGMA checks against the production SQLite database (`database/booksphere.db`):
- `PRAGMA integrity_check`: `[{"integrity_check": "ok"}]` (OK)
- `PRAGMA foreign_key_check`: `[]` (0 violations)

No catalogue data, user data, reviews, or database schemas were altered.

---

## 12. Scope Confirmation

As required by the Phase R1 specification:
- **Scoring unchanged**: Hybrid weights and scoring calculations remain identical (`category: 40, author: 25, wishlist: 10, rating: 10, review_score: 10, community: 5, trending: 0, popularity: 0`).
- **Candidate generation unchanged**: SQL candidate generation (`hybridCandidates`) was not altered.
- **Author-follow logic unchanged**: `author_follows` table handling was not touched (deferred to R2).
- **Diversity reranking not implemented**: Deferred to later phases.
- **Similarity algorithms unchanged**: Book-to-book and metadata similarities remain identical.
- **Cold-start logic unchanged**: Users with 0 signals continue to receive the popularity fallback shelf.
- **No unrelated modules changed**: Changes were strictly confined to `PersonalizationProfile.php`, `RecommendationService.php`, `RecommendationDashboardPresenter.php`, and the new regression test.

---

## 13. Final Status

# **PASS**

All Phase R1 criteria are met:
1. `RecommendationRatedExclusionTest` passes 26/26 checks.
2. Full 55-suite regression passes with 0 failures.
3. Database integrity is OK with 0 foreign-key violations.
4. User isolation and cache integrity are strictly preserved.
5. Execution stopped. Standing by for review before Phase R2.
