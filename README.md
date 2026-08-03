# BookSphere

**BookSphere – Intelligent Book Discovery & Recommendation System**

A PHP MVC web application that helps readers discover books and get
personalised recommendations. The codebase is intentionally simple and
well-commented so it is easy to follow for an MCA student.

> **Current phase: 7.1 – Reviews & Ratings (backend).** The Book
> module is feature complete (CRUD, cover upload, search, filters,
> sorting, pagination, grid/table view). Phases 6.1–6.5 delivered
> the Recommendation Engine: six explainable algorithms (strategy
> pattern, SQL scoring), per-user personalised shelves with a
> file cache and invalidation, the dashboard UI with insights, and
> production readiness (indexes, rate limiting, admin monitoring).
> **Phase 7.1** adds the Reviews & Ratings backend: one review per
> user per book (rating 1–5, title, 20–2000 char body), automatic
> average-rating and review-count sync on every write, a
> moderation-ready status enum, owner-or-admin policy, write audit
> logging, and a recommendation-cache hook — 425/425 automated
> checks + 21/21 live smoke checks. Phase 7.2 (the review UI on the
> book page) is next.

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
  write/update/delete, automatic book rating sync, policy, logging)

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
│   ├── Repositories/       # All SQL lives here (Book/RecommendationRepository)
│   ├── Requests/           # Declarative form rules (BookRequest)
│   ├── Services/           # Business logic (BookService, RecommendationService, ...)
│   ├── Strategies/         # Recommendation algorithms (Phase 6.2) behind one interface
│   └── Views/              # Templates, partials and components
├── bootstrap/              # Boots the application on every request
├── config/                 # app.php, database.php, media.php, recommendations.php
├── database/               # SQLite files, migrations/ (0013 applied), seeds/
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
php tests/ReviewTest.php                       # Reviews & Ratings (91 checks)
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
audit trail. **425 checks total.** An HTTP smoke test lives in
`tools/smoke_recommendations.php` (21 checks incl. the 429 rate-limit
response). See `docs/MANUAL_TEST_CHECKLIST.md` for the manual test
plan.

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
- `docs/ARCHITECTURE.md` – folder structure, Book module architecture,
  request flow diagram, developer notes, future extension points
- `docs/MANUAL_TEST_CHECKLIST.md` – step-by-step manual test plan
