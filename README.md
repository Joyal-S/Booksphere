# BookSphere

**BookSphere – Intelligent Book Discovery & Recommendation System**

A PHP MVC web application that helps readers discover books and get
personalised recommendations. The codebase is intentionally simple and
well-commented so it is easy to follow for an MCA student.

> **Current phase: 8.5 – The Personal Library inside the Recommendation
> Engine.**
> The Book module is feature complete (CRUD, cover
> upload, search, filters, sorting, pagination, grid/table view).
> Phases 6.1–6.5 delivered the Recommendation Engine: six explainable
> algorithms (strategy pattern, SQL scoring), per-user personalised
> shelves with a file cache and invalidation, the dashboard UI with
> insights, and production readiness (indexes, rate limiting, admin
> monitoring). **Phase 7.1** added the Reviews & Ratings backend: one
> review per user per book (rating 1–5, title, 20–2000 char body),
> automatic average-rating and review-count sync on every write, a
> moderation-ready status enum, owner-or-admin policy, write audit
> logging, and a recommendation-cache hook. **Phase 7.2** delivered
> the complete review CRUD on the book page: the "Write Review" form
> (rating, title, body), the "You have already reviewed this book."
> panel, the single-review page, the edit workflow with the
> "Edited" badge, the shared delete confirmation modal, exact
> success flashes, and automatic book-stat sync on every
> create / edit / delete. **Phase 7.3** replaced the rating
> dropdown with a reusable interactive star-rating component
> (hover, keyboard, no-JS fallback), added the animated rating
> distribution bars to the book pages, and built truthful rating
> analytics everywhere: the dashboard's real Top Rated Books, the
> profile's rating activity and the admin's catalogue analytics
> (average, distribution, highest/lowest rated, unrated books,
> per-category averages) – all aggregated from the reviews table,
> never the seeded sample columns. **Phase 7.4** turned every review
> list into a professional, Goodreads-style surface: one shared list
> presenter, server-side search (title / body / reviewer name),
> five sort orders, star / edited / "my reviews only" filters,
> pagination (10/20/50) with a truthful pager, loading skeletons and
> contextual empty states, read-more cards with reviewer profile
> pages, a community search / timeline, the platform review
> statistics page, real review shelves on the dashboard and the
> profile, and the same professional section embedded on the book
> detail page. **Phase 7.5** added community engagement: the Helpful
> vote with a truthful per-card count, the six-reason report flow
> with a moderation queue for admins (pending / reviewed / resolved /
> dismissed tabs), hide / unhide moderation, the report-modal,
> one-click actions and per-user reputation on profiles.
> **Phase 7.6** made the Reviews module the single ratings source
> across the whole platform: the dashboard gained the Community
> Favourite Books shelf and the My Highest Rated Book card; new
> author and category pages (directory + detail) present real
> aggregated ratings, top rated / most reviewed books, community
> favourites and top reviewers; the profile gained favourite
> genres, the most-reviewed category and a monthly review-activity
> timeline; the admin analytics grew to the full platform picture
> (totals, active reviewers, most active reviewers, most reviewed
> categories, author averages); and the recommendation engine
> gained its seventh hybrid factor – review_score (community rating
> quality, config-driven weight 10) – so the recommendation shelves
> now rank with review quality too. **Phase 7.7** is the quality
> gate: the module was audited end-to-end and hardened without new
> features – the `UpdateReviewRequest` TypeError fixed, the
> self-report exception, status/rule constants, query deduplication
> and the batched helpful-vote read (N+1 fix), the unique report
> constraint (migration 0016), rate-limited review writes (429),
> and the shared avatar / date / distribution partials – 812/812
> automated checks. **Phase 8.1** laid the Personal Library backend on
> the same seam: the `user_library` table (migration 0017 – one
> record per user per book, `UNIQUE (user_id, book_id)`,
> CHECK-constrained statuses and 0–100 progress), the layered stack
> (DTO → service → repository), the five-shelf status lifecycle with
> automatic `started_reading_at` / `finished_reading_at` stamps,
> favourites independent of status, auto-finish at 100%, duplicate /
> missing-book guards, the owner-only policy, the declarative form
> rules, the write audit log and the Phase 8.5 recommendation hooks –
> 149/149 checks. **Phase 8.2** turned that backend into the complete
> library UI: the "My Library" page (six status sections + favourites,
> shelf tabs with live counters, a server-side / live search over
> title, author and category, shared remove modal, loading skeletons),
> the library statistics page (stat cards for totals, shelves,
> favourites, average progress and books added this month), the book
> detail Add / Update Library panel, fetch-driven favourite / status /
> progress interactions with no reload, and the dashboard's
> "Continue Reading" shelf (books currently reading, newest activity
> first, with progress and a Resume button) – 178/178 checks.
> **Phase 8.3** rebuilt the library page as the premium dashboard:
> the personal greeting with streak / total / progress chips, the
> quick statistics row (from the composed `libraryDashboard()`
> payload), the quick actions, the Continue Reading section, the
> reading summary (favourite genre / author, average rating given,
> average progress), the reading streak (a real consecutive-day
> library-activity count), and a combined search / filter / sort /
> view / paginate book grid – eight sort orders, shelf / category /
> author / rating / favourite / recency filters, a persisted grid vs
> list view switch, per-user preferences (migration 0018), loading
> skeletons, optional recommendation badges and a self-refreshing
> statistics row – 227/227 checks.
> **Phase 8.4** turned the library into an intelligent organization
> system: the Smart Collections rail (All + the five shelves +
> Favourites, each with book count / average rating / last updated
> from one aggregation), a search that now reaches book
> descriptions, two new sort orders (Most Reviewed – counted from
> the platform's own approved reviews; Most Recommended – the
> engine's suggestion set first, a ratings-count fallback without
> it), the bulk actions (select → move to a shelf / favourite /
> un-favourite / remove, owner-gated SQL, confirmation modal, CSRF),
> a quick-action menu on every card and row (View Details / Move To
> / Favourite / Share placeholder / Remove), real review counts on
> the cards, and the dashboard + profile integration: both now show
> the user's OWN recently added / favourite books and library
> numbers through the same shared LibraryService – 274/274 checks.
> **Phase 8.5** connected the Personal Library to the Recommendation
> Engine: the library is now the engine's richest weighted signal
> (favourite categories / authors, reading history, want-to-read,
> rating, popularity – all configured in config, scored on the shared
> 0-100 scale), every shelf is explainable (reasons name the real
> category/author), remains per-user-per-section cached and is logged
> to a new recommendation_logs audit table (migration 0019) that
> powers the profile's Recommendation Accuracy figure. New surfaces:
> the dashboard's Recommended for You / Because You Read / Trending
> shelves (the last placeholders removed), the book page's six
> deduplicated "You may also like" sections, the library page's five
> personal sections, and the profile's Reading Preferences & Insights
> block – 147 new integration checks (1233 total).

---

## Project

- **Language:** PHP 8.3 (PSR-12 style)
- **Database:** SQLite (PDO, prepared statements, WAL mode)
- **Frontend:** Bootstrap 5.3, Font Awesome, JavaScript ES6
- **Pattern:** MVC (Controller → Service → Model → Repository → PDO)
- **Autoloading:** Composer, PSR-4, namespace `BookSphere\App\`
- **Done so far:** foundation, database, authentication, UI framework,
  Book CRUD, cover upload, search, filters, pagination, Phase 5.6
  quality pass (no new features added), Phases 6.1–6.5 (recommendation
  engine: six algorithms, personalisation, dashboard, production
  readiness), Phase 7.1 (reviews & ratings backend: ratings, review
  write/update/delete, automatic book rating sync, policy, logging),
  Phase 7.2 (complete review CRUD: write/edit/delete workflows,
  single-review page, shared modal), Phase 7.3 (interactive star
  rating component, rating distribution bars, dashboard / profile /
  admin rating analytics),   Phase 7.4 (professional review lists:
  server-side search, sort, filters, pagination, statistics, review
  cards with read-more, reviewer pages, community timeline, platform
  statistics page, real dashboard / profile review shelves),
  Phase 7.5 (community engagement: helpful votes with truthful
  counts, the six-reason report flow, the admin moderation queue
  with hide/unhide, per-user reputation),   Phase 7.6 (reviews &
  ratings across the platform: author and category pages with real
  aggregations, the dashboard's community favourites shelf and
  highest-rated-book card, the enriched profile with favourite
  genres and a review-activity timeline, the extended admin
  analytics, and the review-score factor in the recommendation
  engine), Phase 7.7 (production-readiness pass: the
  UpdateReviewRequest TypeError fix, self-report exception, status
  and rule constants, query deduplication, the batched helpful-vote
  read, the unique report constraint, rate-limited review writes,
  and the shared avatar / date / distribution partials),
  Phase 8.1 (Personal Library backend: the user_library table with
  the unique-per-user-per-book rule and status / progress CHECK
  constraints, the DTO → service → repository stack, the five-shelf
  status lifecycle with automatic timestamps, favourites independent
  of status, duplicate / missing-book guards, the owner-only policy,
  form rules, audit logging and recommendation hooks),
  Phase 8.2 (Personal Library CRUD UI: the My Library page with
  status sections, live counters and search, the library statistics
  page, the book-detail Add / Update Library panel, fetch-driven
  favourites / status / progress, and the dashboard's Continue
  Reading shelf,
  Phase 8.3 (the premium My Library dashboard: the hero header with
  streak / progress chips, the statistics row, the quick actions,
  the Continue Reading section, the reading summary, the reading
  streak, the combined search / filter / sort / view / paginate grid
  with the grid-vs-list switch, the per-user preferences table, and
  the self-refreshing counters),
  Phase 8.4 (smart library organization: the Smart Collections rail
  with occupancy numbers, description-reaching search, the Most
  Reviewed / Most Recommended sorts, the bulk move / favourite /
  delete actions, the per-card quick action menu, real review counts
  on the cards, and the dashboard + profile "My Library" blocks fed
  by the shared LibraryService)

## Folder structure

```
BookSphere/
├── app/                    # Application source code
│   ├── Controllers/        # Thin: request in, view/redirect out
│   ├── Core/               # Minimal framework (Router, Database, View, ...)
│   ├── DTO/                # Immutable value objects (RecommendationContext/Result)
│   ├── Exceptions/         # Domain exceptions (RecommendationException)
│   ├── Helpers/            # Global helpers (e(), db(), session(), config(), ...)
│   ├── Middleware/         # Auth, Admin, CSRF, security headers
│   ├── Models/             # Thin facades over the repositories
│   ├── Policies/           # Fine authorization gates (RecommendationPolicy)
│   ├── Presenters/         # View-models (ReviewListPresenter)
│   ├── Repositories/       # All SQL lives here (Book/RecommendationRepository)
│   ├── Requests/           # Declarative form rules (BookRequest)
│   ├── Services/           # Business logic (BookService, RecommendationService, ...)
│   ├── Strategies/         # Recommendation algorithms (Phase 6.2) behind one interface
│   └── Views/              # Templates, partials and components
├── bootstrap/              # Boots the application on every request
├── config/                 # app.php, database.php, media.php, recommendations.php
├── database/               # SQLite files, migrations/ (0016 applied), seeds/
├── docs/                   # Blueprint, phase analysis, architecture guide
├── public/                 # Document root (the only exposed folder)
│   └── assets/             # css, js, images, fonts
├── routes/                 # URL → controller mapping (web.php)
├── tests/                  # CLI test suites (Browse, RecommendationArchitecture, Personalization, Dashboard, Optimization)
└── vendor/                 # Composer packages (auto-generated, do not edit)
```

## Requirements

- PHP **8.3** with `pdo_sqlite`
- Composer 2
- A web server with the document root set to `public/` (Apache, nginx or
  the PHP built-in server)

## Installation

```bash
# 1. Install dependencies and generate the autoloader
composer install

# 2. Prepare environment variables
copy .env.example .env          # Windows
# cp .env.example .env          # Linux / macOS

# 3. Create the schema and seed sample data
php database/migrate.php
php database/seeds/001_seed_categories.php   # and the other seed files

# 4. Start the development server (document root = public/)
php -S localhost:8000 -t public
```

Open <http://localhost:8000> and log in. Seeded accounts: an admin
(`admin@booksphere.test`) and a regular user.

## Running the tests

```bash
php tests/BrowseTest.php                       # Book module (69 checks)
php tests/RecommendationArchitectureTest.php   # Recommendations (86 checks)
php tests/PersonalizationTest.php              # Personal shelf (62 checks)
php tests/RecommendationDashboardTest.php      # Dashboard UI (64 checks)
php tests/RecommendationOptimizationTest.php   # Phase 6.5 (53 checks)
php tests/ReviewTest.php                       # Reviews & Ratings (369 checks)
php tests/ReviewIntegrationTest.php            # Reviews across pages (109 checks)
php tests/LibraryTest.php                      # Personal Library (274 checks)
```

The Browse suite covers search, filters, sorting, pagination, injection
resistance, performance (2,500+ rows) and controller/view smoke tests
(admin vs regular user). The recommendation suites cover the Phase 6.2
algorithms (context sanitization, the strategy registry, weighted
scoring with mirror-score and window/threshold checks, exclusions,
repository reads, policy, every `/recommendations` route), Phase 6.3
personalisation (hybrid scoring, cache round-trip and invalidation),
Phase 6.4 dashboard (sections, DTOs, controller, views) and Phase 6.5
production readiness (indexes via `EXPLAIN QUERY PLAN`, score
normalization, freshness, duplicate removal, rate limiting, admin
metrics + flush). The Review suite covers the Phase 7.1 backend:
schema (migration 0014 + unique constraint), validation rules, every
repository query, the service business rules (duplicate prevention,
automatic average/count sync, is_edited), the policy matrix, the
model facade and relationships, the controller wiring and the write
audit trail – plus the Phase 7.2 CRUD inventory: createReview /
updateReview / deleteReview, the canUserReview / userHasReviewed
rule reads, the recalculateBookRating / recalculateReviewCount
restore methods, the single-review page, the exact success messages,
the shared delete modal and the book detail page integration (write
form, "already reviewed" status, review list, CSRF token) – the
Phase 7.3 interactive star rating: the reusable component in both
modes (display stars with half-stars and counts, input radio-group
with roving tabindex and a no-JS hidden input), the rating breakdown
rows, the analytics aggregations (highest/lowest rated, unrated
books, per-category averages, catalogue average + distribution,
per-user profile stats, the admin analytics payload) and the live
render of the distribution bars on the book page, the dashboard's
real Top Rated Books shelf, the admin analytics page and the
profile's rating activity block – and the Phase 7.4 professional
review lists: the paginated list with a shared WHERE builder (one
COUNT + one SELECT), the sort allowlist with the SQL-injection
fallback, the rating / edited / user filters, the server-side search
over title / body / reviewer name, the truthful statistics and
distribution, the service normalization gate, the list presenter
payloads, the controller renders of My Reviews / search /
statistics / per-user / book pages and the shared components (card,
toolbar, empty states, skeleton, search box, stats panel) – plus
the Phase 7.7 hardening checks: the unique report constraint from
migration 0016 (the database rejects a second report by the same
user), the batched helpful-vote read, the UpdateReviewRequest
validation regression, the self-report exception, the configured
write throttles and the live 429 gate (subprocess probe), and the
shared date / avatar / distribution helpers. The Library suite
covers the Phase 8.1 backend (schema migration 0017, repository CRUD,
the five-shelf status lifecycle with its timestamps, progress bounds
and auto-finish, favourite / status independence, delete idempotence,
statistics, the model facade and relationships, the DTO, the request
rules, the owner-only policy, the Phase 8.5 recommendation hooks, the
controller JSON endpoints and the UNIQUE / CHECK database defence)
plus the Phase 8.2 UI reads: statusCounts and the generic shelf
buckets, the library search over title / author / category,
bookDetailsState, the search / toggleFavourite / updateProgress
controller endpoints, and the dashboard Continue Reading shelf (sorted
by last updated, rendered with the resume cards, empty state
included) – plus the Phase 8.3 dashboard checks: the user_preferences
schema (migration 0018, CHECK + FK defence), the filtered grid reads
(filter / countFiltered / paginate / filterOptions / the SORTS
orderings), the reading summary aggregates, the reading streak, the
viewPreference merge-and-persist rules (junk values ignored), the
libraryDashboard composition, and the filter / sort / view-mode /
continue-reading controller endpoints – plus the Phase 8.4 checks:
the collections payload (all seven ids guaranteed, counts, rounded
average ratings, the empty-library default map), the recently added
/ updated orderings, the description search (a seed word that exists
ONLY in a description), the Most Reviewed / Most Recommended
orderings including the engine-suggestion CASE, every bulk operation
(status move without lifecycle stamps, junk ids ignored, the
foreign-record IDOR skip, the favourite round-trip), the bulk
controller endpoint (affected count, empty selection, junk action,
junk status) and the rendered dashboard + profile integration.
**1053 checks total.** An HTTP smoke test lives in
`tools/smoke_recommendations.php` (21 checks incl. the 429 rate-limit
response). The Phase 8.5 suite (`tests/RecommendationLibraryIntegrationTest.php`)
adds 147 checks: the config accessors, the library scoring mirrors,
the 0019 recommendation_logs schema, the sixteen library/log
repository reads, the four service surfaces (library / book / library
page / profile with the accuracy figure), the guest and no-library
behaviour, the retention pruning, the per-section cache and the
rendered dashboard / book / library / profile blocks — and caught
three real bugs (an un-applied section limit, a guest-logging crash,
cross-section duplicates). **1233 checks total.** See
`docs/MANUAL_TEST_CHECKLIST.md` for the manual test plan.

## Documentation

- `docs/PHASE_0_BLUEPRINT.md` – the project blueprint (design tokens, goals)
- `docs/PHASE_5_6_ANALYSIS.md` – Phase 5.6 analysis report (findings)
- `docs/PHASE_6_1_RECOMMENDATION_ARCHITECTURE.md` – Phase 6.1 report:
  analysis, architecture diagram, files, routes, extension points,
  testing checklist, Phase 6.2 recommendations
- `docs/PHASE_6_2_ALGORITHMS.md` – Phase 6.2 report: the six scoring
  algorithms, where the scoring lives, files created/modified, routes,
  test results, performance notes, Phase 6.3 future work
- `docs/PHASE_6_3_PERSONALIZATION.md` – Phase 6.3 report: hybrid score,
  favourites, recent views, cache + invalidation, tests
- `docs/PHASE_6_5_OPTIMIZATION.md` – Phase 6.5 report: indexes, cache
  invalidation + degradation, freshness, dedupe, score normalization,
  rate limiting, admin metrics page + flush tool, test results
- `docs/PHASE_7_1_REVIEWS_RATINGS.md` – Phase 7.1 report: the reviews
  database foundation (migration 0014), the layered backend
  (DTO → service → repository), validation, policy, automatic book
  rating sync, security, testing checklist, Phase 7.2 preparation
- `docs/PHASE_7_2_REVIEWS_CRUD.md` – Phase 7.2 report: the complete
  review CRUD (write/edit/delete workflows on the book page), the
  review lifecycle, controller/service/repository flows, average
  rating calculation, security, testing checklist, Phase 7.3
  preparation
- `docs/PHASE_7_3_RATING_SYSTEM.md` – Phase 7.3 report: the reusable
  star-rating component (display + input modes, accessibility), the
  rating distribution bars, the truthful rating analytics
  (dashboard / profile / admin), files created/modified, test
  results, security and accessibility notes
- `docs/PHASE_7_4_REVIEW_UX.md` – Phase 7.4 report: the professional
  review lists (search, sort, filters, pagination, statistics), the
  shared list presenter pipeline, the review card component, the new
  routes and pages, files created/modified, test results, security
  and accessibility notes
- `docs/PHASE_7_7_PRODUCTION_READINESS.md` – Phase 7.7 report: the
  production-readiness audit of the Reviews module – fixes
  (request TypeError, self-report, constants, query dedup, batched
  votes), hardening (unique report index, write throttles), the
  shared partials/helpers, the 24 new checks and the full 812-check
  regression result
- `docs/PHASE_8_2_PERSONAL_LIBRARY_CRUD.md` – Phase 8.2 report: the
  Personal Library CRUD UI on the Phase 8.1 backend – the My Library
  page (sections, tabs, counters, search), the statistics page, the
  book-detail Add / Update panel, fetch-driven favourites / status /
  progress, the dashboard Continue Reading shelf, files, routes,
  security, the 178-check result and Phase 8.3 preparation notes
- `docs/PHASE_8_3_LIBRARY_DASHBOARD.md` – Phase 8.3 report: the
  premium My Library dashboard – the hero header with the streak /
  progress chips, the statistics row, the quick actions, the
  Continue Reading section, the reading summary and streak, the
  combined search / filter / sort / view / paginate grid (one shared
  WHERE builder and fragment), the per-user preferences (migration
  0018), the self-refreshing counters, files, routes, security, the
  227-check result and Phase 8.4 preparation notes
- `docs/PHASE_8_4_SMART_LIBRARY.md` – Phase 8.4 report: Smart Library
  Organization – the Smart Collections rail, the description search,
  the Most Reviewed / Most Recommended sorts, the bulk actions, the
  quick-action menu, the dashboard + profile integration, files,
  routes, security, the 274-check result and Phase 8.5 preparation
  notes
- `docs/PHASE_8_5_LIBRARY_RECOMMENDATIONS.md` – Phase 8.5 report: the
  Personal Library inside the Recommendation Engine – the configured
  library weights, the explainable library shelves, the six book /
  five library-page / dashboard / profile sections, the
  recommendation_logs audit table and the Recommendation Accuracy
  figure, the per-section cache, files, security, the 147-check result
  and the notes for the next phase
- `docs/ARCHITECTURE.md` – folder structure, Book module architecture,
  request flow diagram, developer notes, future extension points
- `docs/MANUAL_TEST_CHECKLIST.md` – step-by-step manual test plan
