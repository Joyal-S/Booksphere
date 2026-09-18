# Recommendation Engine V2 — R3 Report

## 1. Objective

Phase R3 focuses exclusively on **Explanation Accuracy**: ensuring that every recommendation explanation displayed across BookSphere is truthful, directly supported by an actual signal that contributed to that recommendation's score, and free of false or misleading claims.

Specifically, Phase R3 resolves:
1. **False Author-Follow Explanations**: Ensuring `"Because you follow [Author]."` is shown only when the user explicitly follows that author (`followedAuthorIds`) and the candidate matches that author, distinguishing followed authors from favourite authors derived from ratings.
2. **False Collaborative Claims**: Eliminating `"Popular among readers of your highly rated books"` which misleadingly claimed user-to-user collaborative filtering when no collaborative reader graph exists in personalized hybrid scoring. Replacing it with the truthful content-based description: `"Shares categories with books you rated highly."`.
3. **Imprecise Category Explanations**: Naming only the candidate book's actual matching favourite categories in `"You enjoy [Category] books."`, rather than blindly printing the user's top-2 favourite categories overall.
4. **Dashboard Section 4 Guard**: Preventing Section 4 ("Because you follow") from surfacing books by authors the user merely rated and falsely claiming they are followed.

All changes were implemented under strict constraints:
- Recommendation scoring formulas, weights, and candidate generation remain **100% unchanged**.
- Database schema and catalogue data remain **100% untouched**.
- Phase R1 exclusions (hard exclusions for rated, reviewed, library, wishlist, viewed books) remain **100% intact**.
- Phase R2 author-follow scoring (+25 points) remains **100% active**.
- Zero additional SQL queries introduced (**0 N+1 queries**).

---

## 2. Explanation Audit

A forensic scan of the entire codebase was conducted across all recommendation entry points, presenters, strategies, and views:
- `RecommendationService::getRecommendationReason()` (personalized hybrid shelf)
- `RecommendationService::libraryReason()` (personal library shelves)
- `RecommendationService::decorateCommunityItems()` (anchor & library co-saves)
- `RecommendationDashboardPresenter::follow()` (dashboard Section 4)
- `RecommendationDashboardPresenter::trendingNearInterests()` (dashboard Section 5)
- `RecommendationDashboardPresenter::recentlyAdded()` (dashboard Section 6)
- `PopularBooksStrategy`, `HighestRatedStrategy`, `TrendingBooksStrategy`, `SameCategoryStrategy`, `SameAuthorStrategy`, `RecentlyAddedStrategy`
- Views: `recommendation-card.php`, `index.php`, `_section-*.php`

Data flow for personalized explanations:
```
User Signals (Activity & Follows)
    ↓
PersonalizationProfile (followedAuthorIds, favouriteCategories, favouriteAuthors)
    ↓
Candidate Pool Generation (hybridCandidates)
    ↓
Signal Extraction ($signals: category, author, wishlist, viewed, rating, review_score, community, trending, popularity)
    ↓
Scoring (calculateHybridScore)
    ↓
Matched Factor Identification (matchedFactors)
    ↓
Explanation Builder (getRecommendationReason($matched, $profile, $signals, $candidateAuthorIds, $candidateCategoryIds))
    ↓
PersonalizedRecommendationItem DTO (score, confidence, reason, matched)
    ↓
Presenter & View (Card & "Why this book?" drawer display reason verbatim)
```

---

## 3. Signal → Explanation Matrix

| Signal Key | Actual Scoring Condition | Weight | Pre-R3 Explanation | Pre-R3 Truth Status | Post-R3 Explanation | Post-R3 Truth Status |
| :--- | :--- | :---: | :--- | :--- | :--- | :--- |
| **`author`** | Candidate author $\in$ `$profile->followedAuthorIds` | 25 | `"Because you follow [Top Favourites]."` *(Named top favourite authors regardless of whether they wrote the book or were actually followed)* | ⚠️ **POTENTIALLY MISLEADING** | `"Because you follow [Candidate's Followed Author]."` *(Names only candidate author(s) actually followed)* | ✅ **100% TRUTHFUL** |
| **`category`** | Candidate category $\in$ `$profile->favouriteCategoryIds()` | 40 | `"You enjoy [Top Favourites] books."` *(Named user's top-2 categories overall, even if book only matched category #3)* | ⚠️ **IMPRECISE** | `"You enjoy [Candidate's Matched Category] books."` *(Names only candidate categories that matched)* | ✅ **100% TRUTHFUL** |
| **`rating`** | Candidate category $\in$ `$ratingCategoryIds` (categories of $\ge 4\star$ books) | 10 | `"Popular among readers of your highly rated books."` *(Claimed collaborative reader graph; none exists)* | ❌ **FALSE COLLABORATIVE CLAIM** | `"Shares categories with books you rated highly."` *(Accurately describes content category overlap)* | ✅ **100% TRUTHFUL** |
| **`wishlist`** | Candidate category $\in$ `$wishlistCategoryIds` or `$viewedCategoryIds` | 10 | `"Similar to books in your wishlist."` / `"Similar to books you recently viewed."` / `"Similar to books in your wishlist or recently viewed."` | ✅ **TRUTHFUL** | Preserved unchanged | ✅ **100% TRUTHFUL** |
| **`review_score`** | Candidate has approved reviews (`ratings_count > 0`) | 10 | `"Highly rated by the community."` | ✅ **TRUTHFUL** | Preserved unchanged | ✅ **100% TRUTHFUL** |
| **`community`** | User interacted with book in forum (`communitySignals > 0`) | 5 | `"Based on books you discussed in the community."` | ✅ **TRUTHFUL** | Preserved unchanged | ✅ **100% TRUTHFUL** |
| **`trending`** | Book has recent review/wishlist momentum (`trending_score > 0`) | 0 | `"Gaining momentum this month."` | ✅ **TRUTHFUL** (when trending score $> 0$) | Preserved unchanged (shown only when trending signal $> 0$) | ✅ **100% TRUTHFUL** |
| **Fallback** | No personal or community factors matched | 0 | `"A community favourite - a starting point for your profile."` | ✅ **TRUTHFUL** | Preserved unchanged | ✅ **100% TRUTHFUL** |

---

## 4. Problems Found

1. **Misleading Collaborative Claims in Rating Signal**:
   - `getRecommendationReason()` line 516 previously output: `"Popular among readers of your highly rated books."`.
   - The underlying signal was simply: `count(array_intersect($categoryIds[$id] ?? [], $ratingCategoryIds))`.
   - No user-to-user collaborative filtering, co-reading graph, or reader behaviour analysis was ever performed. This was a purely content-based category match against the categories of books the user rated $\ge 4$ stars.
2. **False Author-Follow Attribution**:
   - Previously, if `'author'` was in `$matched`, `getRecommendationReason()` extracted the first two names from `$profile->favouriteAuthors`.
   - `$profile->favouriteAuthors` contains authors from ratings as well as follows. If a user followed Author A but had rated books by Author B with higher weight, Author B was named under `"Because you follow"`.
   - Furthermore, the candidate book might be written by Author A, but the reason would claim `"Because you follow Author B"`.
3. **Imprecise Category Explanation**:
   - `getRecommendationReason()` took `$profile->favouriteCategories[0]` and `[1]` regardless of which category the candidate book actually belonged to. If a book belonged to Science Fiction, but the user's top categories overall were Fantasy and Romance, the card would state: `"You enjoy Fantasy and Romance books."`.
4. **Dashboard Section 4 ("Because you follow") Bleed**:
   - In `RecommendationDashboardPresenter::follow()`, Section 4 iterated over `$profile->favouriteAuthors` instead of `$profile->followedAuthorIds`. A user who followed 0 authors but had rated books would see Section 4 populated with `"New release from an author you follow."` for authors they never followed.
   - `_section-follow.php` empty state had misleading copy: `"Read and rate a few books and this shelf starts surfacing new releases from the authors you clearly enjoy."`.

---

## 5. Changes Made

### 1. `app/Services/RecommendationService.php`
- **Updated Method Signature**:
  ```php
  public function getRecommendationReason(
      array $matched,
      PersonalizationProfile $profile,
      array $signals = [],
      array $candidateAuthorIds = [],
      array $candidateCategoryIds = [],
  ): string
  ```
- **Truthful Author Follow Attribution**:
  Filters candidate authors against `$profile->followedAuthorIds`. If candidate author IDs are provided, only candidate authors the user explicitly follows are named. If called without candidate IDs (e.g. standalone test calls), falls back strictly to `$profile->followedAuthorIds`. Never names an author the user only rated.
- **Truthful Category Attribution**:
  Intersects candidate category IDs with `$profile->favouriteCategoryIds()`. Names only the specific categories of the candidate book that the user enjoys (up to 2). Falls back to favourite categories only when candidate IDs are not provided.
- **Truthful Rating Explanation**:
  Replaced `'Popular among readers of your highly rated books.'` with `'Shares categories with books you rated highly.'`.
- **Zero-Query Candidate Wiring in `scoreCandidates()`**:
  Passed `$authorIds[$id] ?? []` and `$categoryIds[$id] ?? []` directly into `$this->getRecommendationReason()`. These arrays are already loaded in memory by batch queries; **0 additional SQL queries** are incurred.

### 2. `app/Presenters/RecommendationDashboardPresenter.php`
- **Guarded Section 4 (`follow()`)**:
  Updated `follow()` to check: `if ($profile === null || $profile->followedAuthorIds === []) { return []; }`.
  Iterates strictly over `$profile->followedAuthorIds`, ensuring only explicitly followed authors generate books on this shelf.

### 3. `app/Views/recommendations/_section-follow.php`
- **Truthful Empty State**:
  Updated empty-state copy from `"Read and rate a few books..."` to `"Follow authors you love and this shelf starts surfacing their newest releases."`.

---

## 6. Author Follow Explanation Verification

- **Verification Check 1**: When User A follows Author Austen (8801) and is recommended a book by Austen (8804), the item carries `'author'` in `$matched` and the reason states:
  `"Because you follow R3 Followed Author Austen."`
- **Verification Check 2**: When User A rates a book by Author Tolstoy (8802) 5 stars without following Tolstoy, another Tolstoy book (8803) enters the pool. The item does **not** carry `'author'` in `$matched` and its reason does **not** claim `"Because you follow"`.
- **Verification Check 3**: When User A follows Austen but not Tolstoy, and a book by Tolstoy is recommended via category, the explanation says:
  `"You enjoy R3 Mystery Category books. Shares categories with books you rated highly."` — it never claims `"Because you follow"`.
- **Verification Check 4**: User B (who follows nobody) receives 0 author-follow explanations and User B's dashboard Section 4 is empty (`[]`).

---

## 7. Collaborative Explanation Verification

- **Forensic Check**: Scanned all recommendation outputs across the application for collaborative terminology (`"readers of"`, `"popular among readers"`, `"similar users"`).
- **Result**:
  - Main personalized shelf now outputs: `"Shares categories with books you rated highly."` for the `rating` signal.
  - Zero instances of `"Popular among readers of your highly rated books"` remain in the entire codebase.
  - Real collaborative filtering is preserved where genuine user-to-user co-saving is performed:
    - `coSavedBooks()` (`readers_also_enjoyed`): `"Readers who saved this book also enjoyed it."` (actual `user_library` co-save query).
    - `coSavedForLibrary()` (`people_also_saved`): `"People who saved books from your library also liked this."` (actual `user_library` co-save query).
  - No unsupported collaborative claims exist on any shelf.

---

## 8. Explanation Test Results

Dedicated test suite [`tests/RecommendationExplanationAccuracyTest.php`](file:///d:/PROJECTS/booksphere/tests/RecommendationExplanationAccuracyTest.php) was executed:

```
========================================================================
1 & 2. AUTHOR FOLLOW VS RATED/FAVOURITE AUTHOR EXPLANATION
========================================================================
  PASS  User A follows Austen (8801)
  PASS  User A does NOT follow Tolstoy (8802)
  PASS  User A has Tolstoy in favouriteAuthors from rating
  PASS  Scenario 1: Followed author book carries "Because you follow R3 Followed Author Austen."
  PASS  Scenario 1: Followed author book matched author factor
  PASS  Scenario 2: Unfollowed rated author book does NOT say "Because you follow"
  PASS  Scenario 2: Unfollowed rated author book does NOT carry author matched factor

========================================================================
3. CATEGORY MATCH EXPLANATION PRECISION
========================================================================
  PASS  Scenario 3: SciFi book explanation names "R3 SciFi Category"
  PASS  Scenario 3: SciFi book explanation does NOT claim Mystery category

========================================================================
4. WISHLIST & RECENTLY VIEWED EXPLANATIONS
========================================================================
  PASS  Wishlist-only reason says "Similar to books in your wishlist."
  PASS  Viewed-only reason says "Similar to books you recently viewed."
  PASS  Both wishlist and viewed reason says "Similar to books in your wishlist or recently viewed."

========================================================================
5. RATING-DERIVED RECOMMENDATION EXPLANATION
========================================================================
  PASS  Rating explanation is "Shares categories with books you rated highly."
  PASS  Rating explanation does NOT contain misleading collaborative claim "Popular among readers"

========================================================================
6. REVIEW-DERIVED RECOMMENDATION EXPLANATION
========================================================================
  PASS  Review score explanation is "Highly rated by the community."

========================================================================
7. COMMUNITY-DERIVED RECOMMENDATION EXPLANATION
========================================================================
  PASS  Community explanation is "Based on books you discussed in the community."

========================================================================
8. TRENDING RECOMMENDATION EXPLANATION
========================================================================
  PASS  Trending explanation is "Gaining momentum this month."
  PASS  No trending signal does NOT output trending explanation

========================================================================
9 & 12. POPULARITY FALLBACK & COLD-START HONESTY
========================================================================
  PASS  No factors matched returns "A community favourite - a starting point for your profile."
  PASS  Cold-start reason makes no personal claims ("You enjoy", "Because you follow", "wishlist", "rated highly")

========================================================================
10. COMPLETE ABSENCE OF UNSUPPORTED COLLABORATIVE CLAIMS
========================================================================
  PASS  Personalized recommendations never contain "Popular among readers of your highly rated books"
  PASS  Personalized recommendations never contain "readers of your"

========================================================================
11. MULTIPLE SIGNALS COMBINED
========================================================================
  PASS  Multi-signal reason combines category and author follow

========================================================================
13. PHASE R1 EXCLUSIONS PRESERVED
========================================================================
  PASS  Rated Book 8802 is strictly excluded from recommendations

========================================================================
14. PHASE R2 AUTHOR-FOLLOW SCORING ACTIVE
========================================================================
  PASS  Followed author book received >= 25 score boost

========================================================================
15. USER ISOLATION
========================================================================
  PASS  User B does not have Austen in followedAuthorIds
  PASS  User B recommendations never claim to follow Austen
  PASS  User B follow shelf is empty (follows 0 authors)
  PASS  User A follow shelf is populated with followed author releases
  PASS  User A follow shelf picks all say "New release from an author you follow."

------------------------------------------------------------------------
TOTAL: 30 passed, 0 failed
------------------------------------------------------------------------
```

---

## 9. R1 Regression

Executed [`tests/RecommendationRatedExclusionTest.php`](file:///d:/PROJECTS/booksphere/tests/RecommendationRatedExclusionTest.php):
- **Checks Passed**: 26 / 26 (100% PASS)
- **Status**: Books rated 1★ through 5★, reviewed books, wishlist books, library books, and recently viewed books remain strictly excluded from all recommendation shelves.

---

## 10. R2 Regression

Executed [`tests/RecommendationAuthorFollowTest.php`](file:///d:/PROJECTS/booksphere/tests/RecommendationAuthorFollowTest.php):
- **Checks Passed**: 32 / 32 (100% PASS)
- **Status**: Author follow scoring signal (+25 points), unfollow invalidation, multi-author follows, and priority of hard exclusions over author follows remain fully functional.

---

## 11. Full Regression

Executed the complete application regression test suite across all 57 test files:
- **Test Suites Executed**: 57
- **Test Suites Passed**: 57 (100% PASS)
- **Test Suites Failed**: 0
- **Database Integrity**:
  - `PRAGMA integrity_check`: `ok`
  - `PRAGMA foreign_key_check`: `0` violations

---

## 12. Performance

Benchmarked using `scratch/measure_perf.php` and `scratch/benchmark_r3.php` on User 2 (active profile):

| Metric | Before R3 | After R3 | Delta |
| :--- | :---: | :---: | :---: |
| **Fresh recommendation latency (avg)** | 11.59 ms | 10.38 ms | -1.21 ms |
| **Warm recommendation latency (avg)** | 10.28 ms | 10.05 ms | -0.23 ms |
| **Fresh SQL query count** | 16 queries | 16 queries | **0 queries** |
| **Warm SQL query count** | 0 queries | 0 queries | **0 queries** |
| **Peak memory usage** | 0.4479 MB | 0.4477 MB | -0.0002 MB |

**Query Analysis**: Zero additional queries were introduced. All candidate author and category matching operates entirely on in-memory batch collections already loaded by `hybridCandidates()`.

---

## 13. Scoring Preservation

All recommendation scoring formulas, weights, and normalization logic remain **strictly identical**:
- `category`: 40 (weight cap: 2)
- `author`: 25 (weight cap: 1)
- `wishlist`: 10 (weight cap: 3)
- `rating`: 10 (weight cap: 3)
- `review_score`: 10 (weight cap: 1.0)
- `community`: 5 (weight cap: 5.0)
- `trending`: 0
- `popularity`: 0

The list of recommended books and their numeric scores before and after R3 for User 2 are identical:
`[8818, 8828, 8831, 8836, 8838, 14, 20, 13, 8811, 8812]`.
R3 changes explanation truthfulness only, preserving scoring and ranking completely.

---

## 14. Scope Confirmation

| Constraint | Confirmation |
| :--- | :--- |
| **Candidate generation** | **UNCHANGED** — `RecommendationRepository::hybridCandidates()` was not modified. |
| **Diversity reranking** | **NOT IMPLEMENTED** — No diversity reranking attempted (deferred to R5). |
| **Rating model** | **UNCHANGED** — Rating thresholds ($\ge 4\star$) and weights remain identical. |
| **Content similarity** | **UNCHANGED** — Category overlap formulas unchanged. |
| **Cold-start logic** | **UNCHANGED** — Fallback candidate selection unchanged. |
| **Database schema** | **UNCHANGED** — 0 migrations added or altered. |
| **Catalogue data** | **UNCHANGED** — 0 rows altered in production database. |
| **R1 exclusions** | **PRESERVED** — 26/26 tests passing. |
| **R2 author-follow integration** | **PRESERVED** — 32/32 tests passing. |

---

## 15. Final Status

# **PASS**

All acceptance criteria for Phase R3 have been satisfied:
- All 30 checks in `RecommendationExplanationAccuracyTest.php` pass.
- All 32 checks in `RecommendationAuthorFollowTest.php` pass.
- All 26 checks in `RecommendationRatedExclusionTest.php` pass.
- All 64 checks in `RecommendationDashboardTest.php` pass.
- Full regression across all 57 test suites in BookSphere is 100% green.
- No unsupported explanation claims remain in the application.
- User isolation is fully verified.
- Database integrity is confirmed (`ok`, 0 FK violations).
- Zero performance regression (0 additional queries).

**DO NOT proceed automatically to Phase R4. Awaiting user review.**
