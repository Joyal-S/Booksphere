# BOOKSPHERE — PHASE 14.8: BUG DISCOVERY REPORT

================================================================================
**DOCUMENT:** PHASE 14.8 BUG DISCOVERY REPORT (FIRST PASS)  
**DATE:** 2026-08-15  
**SYSTEM:** BookSphere  
**STATUS:** DISCOVERY PASS COMPLETE — READY FOR FIX PHASE  
================================================================================

---

## 1. DISCOVERY SCOPE & SUMMARY

| Metric | Result | Notes |
|---|---|---|
| **Total Features Tested** | 26 | Public, Core, Search, Library, Community, Analytics, Admin, etc. |
| **Total Pages Tested** | 22 | Landing, Login, Register, Forgot, Reset, Dashboard, Books, Book Details, Categories, Authors, Library, Recommendations, Reviews, Review Detail, Community, Post Detail, Profile, Notifications, Analytics, Book Analytics, Reading Report, Admin Console |
| **Total Workflows Tested** | 18 | Auth round-trip, search & suggestions, library shelf updates, reviews & rating calculations, community posting/commenting/liking, notifications mark-as-read, dark/light theme switching, analytics calculation |
| **Total Bugs Discovered** | **7** | Classified below with root cause, evidence, and affected files |
| **Critical Bugs** | **0** | No data loss, fatal runtime crash, or critical exploit found |
| **High Severity Bugs** | **1** | Shared icon rendering defects & Pro-only/compound class conflicts |
| **Medium Severity Bugs** | **2** | Double HTML entity escaping across views + Service caching defect |
| **Low Severity Bugs** | **3** | Component pagination, threshold, and report dash double escaping |
| **Cosmetic Bugs** | **1** | Review search placeholder double-escaped ellipsis |

---

## 2. BUG INVENTORY & DETAILED CLASSIFICATION

### BUG-001: Global FontAwesome Icon System & Component Class Conflicts
- **ID:** `BUG-001`
- **Title:** FontAwesome Icon Invisibility and Conflicting Icon Classes
- **Category:** Frontend / UI / Design System
- **Severity:** `HIGH`
- **Affected Page(s):** `/dashboard`, `/analytics`, `/book-analytics`, `/books`, `/library`, `/pages/landing`, `/recommendations`
- **Affected Feature(s):** Navigation sidebar, stat cards, metric containers, brand badges, genre chips
- **Steps to Reproduce:**
  1. Open `/analytics` or `/book-analytics` or `/recommendations`.
  2. Observe empty square/circular containers where icons should render (e.g. `fa-book-open-cover` on Book Analytics, compound `fa-solid fa-brands` on landing page, invalid `fa-wand-sparkles` on genre cards).
  3. Inspect CSS rules: `app.css` enforces `i.fa-solid { font-family: "Font Awesome 6 Free" !important; font-weight: 900 !important; }` but omitted `.fa-regular, .far` protection, causing regular and brand icons inside solid components to fail glyph resolution.
- **Expected Result:** All icons render crisp vector glyphs consistently across all pages, themes, and network conditions.
- **Actual Result:** Certain icons rendered as blank colored boxes due to Pro-only class names (`fa-book-open-cover`), compound font-family collisions (`fa-solid fa-brands`), or invalid icon names (`fa-wand-sparkles`).
- **Root Cause:**
  1. `app/Views/book_analytics/index.php` referenced `fa-book-open-cover` (FontAwesome Pro only; Free equivalent is `fa-book-open` or `fa-book-bookmark`).
  2. `app/Views/recommendations/components/genre-card.php` referenced invalid `fa-wand-sparkles` (correct FA6 name is `fa-wand-magic-sparkles`).
  3. `app/Views/pages/landing.php` passed full class names like `'fa-brands fa-html5'` to components that prepended `fa-solid`, causing `!important` free-solid font overrides.
  4. `public/assets/css/app.css` lacked explicit protection for `.fa-regular, .far`.
- **Evidence:** Browser DOM inspection and visual screen snapshots confirming empty colored containers on `/analytics`, `/book-analytics`, and `/recommendations`.
- **Files Involved:**
  - `app/Views/book_analytics/index.php`
  - `app/Views/recommendations/components/genre-card.php`
  - `app/Views/pages/landing.php`
  - `public/assets/css/app.css`
  - `app/Views/partials/head.php`
- **Fix Strategy:**
  1. Replace `fa-book-open-cover` with `fa-book-open` in `book_analytics/index.php`.
  2. Replace `fa-wand-sparkles` with `fa-wand-magic-sparkles` in `recommendations/components/genre-card.php`.
  3. Clean compound icon classes in `pages/landing.php` and component wrappers.
  4. Ensure `app.css` includes complete FontAwesome Free solid, regular, and brand protection rules.
- **Status:** READY FOR FIX

---

### BUG-002: Double HTML Entity Escaping in Analytics & Reading Reports
- **ID:** `BUG-002`
- **Title:** Literal HTML Entities ("&amp;", "&mdash;", "&middot;") Rendered on Analytics Pages
- **Category:** Frontend / Views / Text Rendering
- **Severity:** `MEDIUM`
- **Affected Page(s):** `/analytics`, `/book-analytics`, `/analytics/report`, `/admin/analytics/report`
- **Affected Feature(s):** Chart titles, section subtitles, empty dashes, eyebrow labels
- **Steps to Reproduce:**
  1. Log in as a user and navigate to `/analytics` or `/analytics/report`.
  2. Observe chart title text rendering `"Finished &amp; rated"` instead of `"Finished & rated"`.
  3. Observe empty rating stat rendering `"&mdash;"` instead of `"—"`.
  4. Observe subtitle rendering `"Surfaces &middot; in range"` instead of `"Surfaces · in range"`.
- **Expected Result:** Clean typographic formatting with real Unicode characters (`—`, `·`, `&`).
- **Actual Result:** Literal HTML entities displayed to the user (`&amp;`, `&mdash;`, `&middot;`).
- **Root Cause:** Raw strings in controller/view setup files contained pre-encoded HTML entities (e.g. `$chartTitle = 'Finished &amp; rated'`, `$dash = '&mdash;'`). These variables were subsequently escaped by component templates using `<?= e($chartTitle) ?>` (`htmlspecialchars()`), producing `&amp;amp;`, `&amp;mdash;`, etc.
- **Evidence:** Screen inspection on `/analytics` and `/analytics/report`.
- **Files Involved:**
  - `app/Views/analytics/show.php`
  - `app/Views/analytics/report.php`
  - `app/Views/book_analytics/index.php`
  - `app/Views/admin/analytics-report.php`
- **Fix Strategy:** Replace pre-encoded HTML entity strings with unescaped text and UTF-8 characters (`—`, `–`, `·`, `&`) so that `e()` performs clean, single escaping.
- **Status:** READY FOR FIX

---

### BUG-003: BookAnalyticsService Instance Stale Cache Persistence
- **ID:** `BUG-003`
- **Title:** `BookAnalyticsService` Indefinite DTO Memoization Without State-Aware Invalidation
- **Category:** Backend / Services / Analytics
- **Severity:** `MEDIUM`
- **Affected Page(s):** `/book-analytics`
- **Affected Feature(s):** Catalogue analytics snapshot generation
- **Steps to Reproduce:**
  1. Instantiate `BookAnalyticsService`.
  2. Call `build()` when catalog has 0 published books (returns empty payload).
  3. Mutate catalog state (publish books).
  4. Call `build()` again on the same service instance.
- **Expected Result:** `build()` recomputes and returns the fresh analytics payload with updated numbers.
- **Actual Result:** `build()` returns the memoized stale DTO from step 2.
- **Root Cause:** `$this->cachedDto` cached the initial computation indefinitely without expiration or checking if clearCache() was invoked.
- **Evidence:** `tests/BookAnalyticsTest.php` failure on `restoring the books flips empty back to false`.
- **Files Involved:**
  - `app/Services/BookAnalyticsService.php`
- **Fix Strategy:** Remove unconditional permanent memoization from `build()` or ensure state-aware execution.
- **Status:** READY FOR FIX

---

### BUG-004: Search Result Pagination Summary Double Escaping
- **ID:** `BUG-004`
- **Title:** Search Results Pagination String Displays Literal "&ndash;" and "&middot;"
- **Category:** Frontend / Search
- **Severity:** `LOW`
- **Affected Page(s):** `/search`
- **Affected Feature(s):** Search results pagination bar
- **Steps to Reproduce:**
  1. Navigate to `/search?q=book`.
  2. Inspect pagination summary at the bottom of the results.
  3. Summary displays `"Showing 1&ndash;20 of 50 &middot; Page 1 of 3"`.
- **Expected Result:** Summary displays `"Showing 1–20 of 50 · Page 1 of 3"`.
- **Actual Result:** Literal `&ndash;` and `&middot;` printed on screen.
- **Root Cause:** `app/Views/search/partials/_results.php` constructed `$pagination['summary']` with HTML entities, which was then escaped by `app/Views/books/components/pagination.php` via `<?= e($summary) ?>`.
- **Evidence:** Source inspection of `app/Views/search/partials/_results.php` lines 127-128.
- **Files Involved:**
  - `app/Views/search/partials/_results.php`
- **Fix Strategy:** Use UTF-8 `–` and `·` in string concatenation.
- **Status:** READY FOR FIX

---

### BUG-005: Admin Recommendations Threshold Double Escaping
- **ID:** `BUG-005`
- **Title:** Admin Recommendation Telemetry Displays Literal "&ge;" and "&middot;"
- **Category:** Admin / Recommendations
- **Severity:** `LOW`
- **Affected Page(s):** `/admin/recommendations`
- **Affected Feature(s):** Strategy confidence threshold summary
- **Steps to Reproduce:**
  1. Log in as admin and visit `/admin/recommendations`.
  2. Inspect strategy limit descriptions.
- **Expected Result:** Displays `"high ≥ 70 · medium ≥ 40"`.
- **Actual Result:** Displays `"high &ge; 70 &middot; medium &ge; 40"`.
- **Root Cause:** `app/Views/admin/recommendations.php` calls `e('high &ge; ' . ...)` and `e(implode(' &middot; ', $parts))`.
- **Evidence:** `app/Views/admin/recommendations.php` lines 162, 178.
- **Files Involved:**
  - `app/Views/admin/recommendations.php`
- **Fix Strategy:** Replace `&ge;` with `≥` and `&middot;` with `·`.
- **Status:** READY FOR FIX

---

### BUG-006: Admin Reports Table Empty Description Dash Double Escaping
- **ID:** `BUG-006`
- **Title:** Admin Reports Moderation Table Displays Literal "&mdash;"
- **Category:** Admin / Moderation
- **Severity:** `LOW`
- **Affected Page(s):** `/admin/reports`
- **Affected Feature(s):** Report reason description fallback
- **Steps to Reproduce:**
  1. Log in as admin and visit `/admin/reports`.
  2. View report item with an empty optional description.
- **Expected Result:** Displays em-dash `—`.
- **Actual Result:** Displays literal `&mdash;`.
- **Root Cause:** `app/Views/admin/reports.php` calls `e($report['description'] !== '' ? $report['description'] : '&mdash;')`.
- **Evidence:** `app/Views/admin/reports.php` line 79.
- **Files Involved:**
  - `app/Views/admin/reports.php`
- **Fix Strategy:** Replace `'&mdash;'` with `'—'`.
- **Status:** READY FOR FIX

---

### BUG-007: Review Search Input Placeholder Ellipsis Double Escaping
- **ID:** `BUG-007`
- **Title:** Review Search Input Placeholder Displays Literal "&hellip;"
- **Category:** Frontend / Reviews
- **Severity:** `COSMETIC`
- **Affected Page(s):** `/reviews/search`, `/reviews`
- **Affected Feature(s):** Review search input placeholder
- **Steps to Reproduce:**
  1. Navigate to `/reviews/search`.
  2. Inspect placeholder of search input box.
- **Expected Result:** `"Search reviews by title, body or reviewer…"`
- **Actual Result:** `"Search reviews by title, body or reviewer&hellip;"`
- **Root Cause:** `app/Views/components/review-search.php` has `'placeholder' => 'Search reviews by title, body or reviewer&hellip;'` which is passed into `<?= e($search['placeholder']) ?>`.
- **Evidence:** `app/Views/components/review-search.php` line 31.
- **Files Involved:**
  - `app/Views/components/review-search.php`
- **Fix Strategy:** Replace `'&hellip;'` with `'…'`.
- **Status:** READY FOR FIX

---
