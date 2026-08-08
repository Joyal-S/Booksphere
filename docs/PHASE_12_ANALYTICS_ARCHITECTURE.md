# Phase 12 Analytics Architecture & Handbook

> **Phase:** 12 (12.1–12.5, verified in 12.6) — **Doc type:** architecture
> reference / handbook.
>
> The analytics stack: user analytics (12.1), book/catalogue analytics
> (12.2), recommendation metrics (12.3), the admin dashboard (12.4)
> and the charts & reports layer (12.5). This document is the
> consolidated reference the individual phase docs would have been —
> it describes the data flow, every metric's definition, the security
> and validation rules, the exports, the configuration surface and
> troubleshooting.

## 1. Architecture map

```
Controllers (route + guard)
   │    UserAnalyticsController::index/report     (auth)
   │    BookAnalyticsController::index            (auth)
   │    AdminController::index/analyticsReport    (admin)
   ▼
Services  (pure composition, config-driven, no SQL)
   │    UserAnalyticsService::build(userId)
   │    BookAnalyticsService::build()
   │    AdminAnalyticsService::dashboard(?since)   ← composes 12.2 + 12.3
   │    RecommendationMetrics                      ← recommendation counters
   ▼
Repositories (the only SQL layer; visibility + approval filters live here)
   │    UserAnalyticsRepository / BookAnalyticsRepository
   │    RecommendationRepository / ReviewRepository
   ▼
Presenters (12.5): ChartPresenter — re-FORMATS payloads into Chart.js
JSON (never computes a metric). Summary sentences shared with the card.
```

Rules that hold everywhere:

1. **One payload, many surfaces.** The dashboards, the charts and the
   print reports all read the SAME service payload; charts only
   reshape it. A dashboard never recomputes a metric.
2. **Never fabricate a number.** Every count is real rows; "not enough
   data" states are explicit and honest; nothing is estimated.
3. **No tracking.** There is deliberately no click or conversion
   tracking anywhere. CTR-style metrics are absent rather than fake.
4. **No caching yet.** Caching and background jobs are Phase 13 —
   every request recomputes (levels are small and indexed reads).

## 2. Metrics and their definitions

### 12.1 User analytics (`UserAnalyticsService`, per user)
- Shelves: `shelved/reading/wishlist/completed` from `user_library` +
  wishlist; completion rate = finished ÷ shelved; active reading days.
- Monthly activity: finished books and writes by calendar month
  (zeros are real zeros).
- Genres: membership share of the user's finished books.
- Rating history: the user's own distribution of 1–5 star reviews.
- Generated-at: UTC stamp.

### 12.2 Book analytics (`BookAnalyticsService`, global)
- Overview: visible catalogue (`status=published, deleted_at IS NULL`),
  covers, reviews, average rating (min count from config).
- Rankings: highest rated / most reviewed / most wishlisted / most
  read / most engaged / popular / trending — visibility-filtered,
  limited by config; popularity and trending scores are normalized
  into [0, 1] by the config weights.
- Shelves & metadata: shelves across users, languages, page ranges,
  publishers, years.
- Activity: trailing months + the recent window (see **Bug note**
  below), `older` sums.
- Empty flag: guidance state required when no book is visible.

### 12.3 Recommendation metrics (`RecommendationMetrics`)
- Total/all-time counts over `recommendation_logs`, per-surface
  (`signal`) counts, users/books served, scores by surface.
- The profile "Recommendation Accuracy" figure lives in
  `RecommendationService::profileRecommendationInsights()`, NOT in the
  metrics service.

### 12.4 Admin dashboard
- `AdminAnalyticsService::dashboard(?since)`: the 12.2 payload, the
  12.3 counters, top/slept surfaces and the engine block (cache
  state, config, signal amounts).

## 3. The attribution rule (Recommendation Accuracy)

**Strict attribution (Phase 12.6).** A recommendation counts as
"acted on" only when the user's action (library record, rating/review,
wishlist save) was created **at or after** the recommendation was
served (`action.created_at >= recommendation_logs.generated_at`).

- Actions that predate the served recommendation are never
  attributed — an accuracy figure must not include actions the
  recommendation could not have caused.
- The window is `recommendations.library.accuracy.window_days` (30).
- The profile tile wording says "only counted after the
  recommendation".

## 4. Security & validation

- **Auth**: `/analytics*` + `/book-analytics` behind `AuthMiddleware`;
  `/admin*` behind `AdminMiddleware` (verified live: guests 302,
  non-admin /admin 403).
- **Dates**: report ranges whitelisted presets (`7d/30d/90d/year`) +
  a custom pair validated with `^\d{4}-\d{2}-\d{2}$` plus
  `until >= since`; invalid input → flash + redirect, never SQL.
- **strtotime rule**: relative dates always use the LONG forms
  (`-30 days`, `-90 days`, `-1 year`); the short `-30d` form returns
  different results across builds and is banned here.
- **CSV export**: streamed, `text/csv`, `Content-Disposition`
  attachment; every free-text cell passes `AdminController::csvSafe()`
  — cells starting `= + - @` (or tab/CR) get an apostrophe prefix so
  spreadsheets treat them as text (no formula injection).
- **Placeholders**: all analytics SQL is parameterized; the
  binding-count regression (see Bug note) is covered by a suite check.
- **Limits**: export bounded (`logsForRange($since, $until, 5000)`),
  ranking lists capped by config, per-user logs pruned on write
  (retention 200).

## 5. Charts & reports (12.5)

- `ChartPresenter` static shapers: `doughnut/line/bar/hbar` — each
  returns an HTML-safe JSON string (JSON_HEX escapes) plus the
  **summary sentence** the caller reuses as the accessible text.
- `components/chart-card.php`: canvas (`role=img` + aria-label),
  JSON config in `<script type=application/json>`, summary paragraph.
- `charts.js`: renders at load, resolves colors from the SAME theme
  tokens the page uses (dark mode reacts to `data-bs-theme` changes
  through a MutationObserver), honours `prefers-reduced-motion`, and
  destroys its own charts on re-render (no duplicate canvases).
- `charts.css`: `chart-frame` height, print layout under
  `body.report-print` inside `@media print` ONLY (`.print-hidden`
  must never be a screen rule).
- **Print:** both report pages render `body class="report-print"` on
  the server (no JS required); Ctrl-P omits the app chrome.

## 6. Configuration surface

| File | Keys |
|---|---|
| `config/analytics.php` | activity months, recent-day window per 12.1 |
| `config/book_analytics.php` | enabled, limits, ratings minimum, popularity/trending weights & normalizers, activity window |
| `config/recommendations.php` | engine weights, section limits, logs retention, accuracy window |
| `.env` / `.env.example` | `BOOK_ANALYTICS_TRENDING_WINDOW_DAYS` and the other opt-ins |

## 7. Troubleshooting

- **"Charts always empty"**: the seed catalogue must have data; the
  chart card shows the explicit "Not enough data" state until real
  activity exists (dashboards chart rows are zero-suppressed by
  design).
- **Trending numbers look frozen**: `recentActivity()` counts only
  the configured trailing window and the visible catalogue — check
  the window and that books are `published` + un-deleted.
- **Accuracy never rises**: remember the attribution rule — add the
  book to the library AFTER the recommendation shelf was served.
  Check the accuracy window (30 days) and the per-user log retention.
- **CSV opens as one column in some editors**: the export is UTF-8
  `text/csv` without BOM; Excel versions treat it fine, LibreOffice
  may need the default "comma" delimiter.
- **Print doesn't strip chrome**: the page must be one of the two
  report pages (body class `report-print` is server-rendered); normal
  dashboards print with chrome by design.

## 8. Known limits (by design)

- No click / conversion / CTR tracking and never will be fabricated.
- No caching of analytics (Phase 14).
- Charts fall back to a hex only when a theme token is somehow
  missing (tokens are defined in app.css; the fallback is inert).