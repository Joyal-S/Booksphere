# Phase 12.6 QA Audit (Verification & Stabilization)

> **Phase:** 12.6 — **Doc type:** QA / verification report
>
> The verification and stabilization pass over every Phase 12 module
> (12.1 user analytics → 12.2 book analytics → 12.3 recommendation
> metrics → 12.4 admin dashboard → 12.5 charts & reports). This phase
> added **no new analytics features**; its output is the correctness,
> security and accessibility fixes below plus this evidence trail.

## 1. Test report (final run)

All 27 suites executed locally against throwaway databases, each
exiting non-zero on failure. Lint passed via `php -l` on every changed
file.

| # | Suite | Checks | Result |
|---|---|---|---|
| 1 | AuthTest | 73 | PASS |
| 2 | LandingTest | 29 | PASS |
| 3 | BrowseTest | 69 | PASS |
| 4 | SearchTest | 94 | PASS |
| 5 | SearchHistoryTest | 47 | PASS |
| 6 | LibraryTest | 278 | PASS |
| 7 | ReviewTest | 371 | PASS |
| 8 | ReviewIntegrationTest | 109 | PASS |
| 9 | FollowTest | 127 | PASS |
| 10 | PersonalizationTest | 62 | PASS |
| 11 | NotificationTest | 88 | PASS |
| 12 | NotificationCenterTest | 83 | PASS |
| 13 | NotificationApiTest | 59 | PASS |
| 14 | EmailNotificationTest | 86 | PASS |
| 15 | RecommendationArchitectureTest | 86 | PASS |
| 16 | RecommendationOptimizationTest | 57 | PASS |
| 17 | RecommendationDashboardTest | 64 | PASS |
| 18 | RecommendationLibraryIntegrationTest | 149 | PASS |
| 19 | GoogleBooks Search/Import/Covers/Bulk/Sync | 38 / 61 / 58 / 38 / 87 | PASS |
| 20 | UserAnalyticsTest | 65 | PASS |
| 21 | BookAnalyticsTest | 69 | PASS |
| 22 | AdminAnalyticsTest | 37 | PASS |
| 23 | ChartsReportsTest | 59 | PASS |
| | **Total** | **~2174** | **0 failures** |

The counts marked bold are *above* the Phase 12.5 baseline — the 12.6
regression checks added in this pass:

* `RecommendationLibraryIntegrationTest` +2 — strict-attribution
  probes (backdated saves are never attributed; post-recommendation
  actions are).
* `BookAnalyticsTest` +1 — a fresh approved in-window review surfaces
  as `recent_reviews` (guards the binding-count regression).
* `ChartsReportsTest` +1 — a formula-like cell in the CSV export is
  neutralized with an apostrophe.

## 2. Audit findings and fixes (all applied)

Four audit passes ran at the start of 12.6 (recommendation
attribution, security, performance/SQL, frontend/a11y). Findings:

| # | Severity | Finding | Fix | Where |
|---|---|---|---|---|
| 1 | HIGH | **"Recommendation accuracy" had no attribution rule** — any shelf/rating/wishlist row counted as "acted on", even when it predated the recommendation | strict attribution: an action counts only when `created_at >=` the recommendation's `generated_at`; actions backdated before the served recommendation never count; profile label now says "only counted after the recommendation" | `RecommendationRepository::recommendationLogs()`, `RecommendationService::profileRecommendationInsights()`, `app/Views/profile/show.php`, `config/recommendations.php`, `docs/PHASE_8_5_LIBRARY_RECOMMENDATIONS.md` |
| 2 | HIGH | **`recentActivity()` bound 3 params against 6 placeholders.** The unbound `?`s became NULL, so `b.status = NULL` silently zeroed every `recent_reviews` / `recent_interests` / `recent_finishes` count. | All six placeholders are now bound (status, since × 3 subqueries); dead `str_replace` no-op removed | `app/Repositories/BookAnalyticsRepository.php` |
| 3 | MED | **`.print-hidden { display: none }` was a global rule** — admin report toolbar buttons were hidden ON SCREEN, not only when printing | the rule lives only inside `@media print` now | `public/assets/css/charts.css` |
| 4 | MED | **CSV formula injection (admin export)** — `user`/`title`/`signal`/`reason` cells starting with `= + - @` would be evaluated as live spreadsheet formulas | `csvSafe()` prefixes such cells with an apostrophe | `app/Controllers/AdminController.php` |
| 5 | MED | **Chart cards had no numeric text alternative on the dashboards** — the summary sentences were built but only embedded in the JSON config, never rendered as `$chartSummary` | all three dashboards now pass the sentence to the card (`analytics/show.php`, `book_analytics/index.php`, `admin/index.php`) | `app/Views/…` |
| 6 | LOW | `'empty'` trend badge on charts without data | removed (`$chartTrend` is always `''`; the chart-empty state explains itself) | `app/Views/…` |
| 7 | LOW | **stat-card rendered a permanent "0"** for no-JS / reduced-motion visitors | the server renders the FINAL formatted value as the baseline text; the animation only counts up from it | `app/Views/components/stat-card.php` |
| 8 | LOW | **Print layout depended on JavaScript** (`document.body.classList.add('report-print')`) — a no-JS print lost the report chrome rules | the controllers pass `bodyClass => 'report-print'`, the master layout renders it, inline scripts removed | `layouts/master.php`, both controllers, both report views |
| 9 | LOW | Report toolbar `sm` controls below touch height | `min-height: 2.125rem` for the report toolbar controls | `charts.css`, `admin/analytics-report.php` |
| 10 | LOW | Month-label spans capped at `3.4rem` could clip "Sep 2026" | `min-width` + `flex-shrink: 0` (they grow instead of clipping) | `analytics/show.php`, `book_analytics/index.php` |
| 11 | INFO | Dark-mode fallback hexes in `charts.js` — inert (tokens always exist), left in place, noted | — | `public/assets/js/charts.js` |

## 3. Performance

| Area | Baseline (SQL / page) | After |
|---|---|---|
| GET /analytics | 13 statements | unchanged (no unused queries; the profile read is a single annotated row set) |
| GET /book-analytics | 25 statements | unchanged (one scan per metric; see 12.2 doc) |
| GET /admin | 44 statements | unchanged — the page composes the 12.2 book payload + 12.3 + 12.4 dashboard + `ReviewService::adminAnalytics()` ratings; overlaps are minimal; caching is explicitly Phase 13, and no new indexes were justified beyond the ones Phase 12.5 shipping (`idx_recommendation_logs_user_generated` already serving the range reports) |
| Recommendation exports | `logsForRange()` | single indexed scan, bounded `LIMIT 5000`, newest first |

The `recentActivity()` fix (finding 2) also restored the trending
signal the book-analytics "trending" ranking is supposed to read — the
metric now moves with real data instead of being provably zero.

## 4. HTTP verification (live, `php -S`)

Flow checked against a running server with a real session/CSRF flow
(`riya@booksphere.test` regular user, `admin@booksphere.test` admin):

| Check | Result |
|---|---|
| guest GET /analytics, /book-analytics, /admin, /analytics/report, /admin/analytics/report | 302 → login (all five) |
| user GET /analytics, /book-analytics | 200, charts + accessible summaries |
| user GET /analytics/report | 200, `<body class="report-print">`, no inline class script |
| user GET /admin | 403 |
| admin GET /admin | 200, "Platform at a glance" + signal summary |
| admin GET /admin/analytics/report?range=30d | 200, class + range label |
| range=custom&since=banana&until=2020… | 302 + flash (rejected, never trusted) |
| ?format=csv | 200 `text/csv`, header + 43 data rows, no live formula cells |

## 4. Areas verified as passing (not fixed, confirmed)

- Math/consistency: summary + shelf + months + genres + distribution
  all cross-checked against raw SQL in the suites; the accuracy
  figure stays `acted <= recommended` and `percent = round(acted/recommended*100)` even with the attribution rule.
- Date/range validation and the `-30 day` `strtotime` long-form rule.
- Dark mode: chart colors resolve from the `data-bs-theme` tokens at
  render time (MutationObserver re-paints on toggling).
- Print: both reports print without navbar/sidebar/footer via
  `@media print` (server-rendered class, JS no longer needed).
- No click/conversion tracking anywhere (deliberately absent, never
  fabricated) — the flagship limitation note is on the admin report.

## 5. Verdict

✅ **Phase 12 COMPLETE — Ready for Phase 13.**