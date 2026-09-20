# BookSphere — Digital Book Recommendation & Social Reading Platform

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%208.2-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net)
[![Database](https://img.shields.io/badge/Database-SQLite%203-003B57?style=flat-square&logo=sqlite&logoColor=white)](https://www.sqlite.org)
[![Tests](https://img.shields.io/badge/Tests-52%2F52%20Passing-brightgreen?style=flat-square)]()
[![Recommendation Engine](https://img.shields.io/badge/Recommendations-Hybrid%207--Factor-blueviolet?style=flat-square)]()
[![License](https://img.shields.io/badge/License-MIT-green.svg?style=flat-square)](LICENSE)

> **BookSphere** is a high-performance, enterprise-grade digital library, personalized book discovery, and social reading platform. Designed with clean **PHP MVC** principles and zero heavy framework dependencies, it demonstrates production-ready software engineering, algorithmic recommendation models, relational indexing, and real-time community engagement.

---

## Table of Contents
1. [Project Overview & Purpose](#project-overview--purpose)
2. [Technology Stack](#technology-stack)
3. [Key Features](#key-features)
4. [Recommendation Engine](#recommendation-engine)
5. [Database Architecture](#database-architecture)
6. [User Workflows](#user-workflows)
7. [Local Installation (Windows Quickstart)](#local-installation-windows-quickstart)
8. [Configuration & Secrets](#configuration--secrets)
9. [Automated Testing](#automated-testing)
10. [Performance Benchmarks](#performance-benchmarks)
11. [Security Architecture](#security-architecture)
12. [Known Data Notes](#known-data-notes)
13. [Documentation Index](#documentation-index)

---

## 1. Project Overview & Purpose

Modern readers face an abundance of literature with fragmented reading tools. Readers typically juggle separate applications for catalog browsing, reading tracking, community discussions, and book discovery.

**BookSphere solves this problem by unifying the entire reading lifecycle into one seamless, blazingly fast platform:**
- **Catalogue & Search**: Comprehensive metadata across 535 curated books with faceted filtering and instant autocomplete.
- **Personal Library**: Reading progress tracking across 5 distinct states (`want_to_read`, `currently_reading`, `finished`, `on_hold`, `dropped`) with streaks and milestones.
- **Hybrid Recommendation Engine**: Algorithmic book suggestions that balance category affinities, author relationships, reading history, and community review quality—without letting popularity drown out personal tastes.
- **Social Community**: Discussion hubs, reader-to-reader following, author subscriptions, and threaded conversations.
- **Administrative Suite**: Deep-dive telemetry, catalogue management, review moderation queues, and live Google Books API volume imports.

---

## 2. Technology Stack

| Layer | Technology | Architectural Rationale |
| :--- | :--- | :--- |
| **Runtime** | **PHP 8.2+** | Native PHP MVC architecture with strict typing, readonly properties, and minimal memory footprint (~2 MB per request). |
| **Database** | **SQLite 3** | In-process relational database (`database/booksphere.db`) with Write-Ahead Logging (`WAL`), foreign keys enabled, and composite indexing. |
| **Frontend** | **HTML5 / CSS3 / Vanilla JS** | Pure CSS design system with dark-mode aesthetic, zero Tailwind/Bootstrap bloat, deferred non-blocking JavaScript, and SVG icons. |
| **Autoloading** | **Composer (PSR-4)** | Used strictly for generating PSR-4 class maps. No third-party runtime frameworks (Laravel, Symfony) are required. |
| **External APIs** | **Google Books API** | Volume search, automated metadata enrichment, and cover synchronization guarded by a circuit breaker. |
| **Caching** | **Filesystem Cache** | Atomic file-caching (`database/cache/recommendations/`) delivering sub-millisecond warm recommendation responses (~0.31 ms). |
| **Testing** | **Standalone PHP Suites** | 52 automated regression test suites verifying domain services, database integrity, and recommendation formulas. |

---

## 3. Key Features

### 📖 Book Catalogue & Discovery
- **Faceted Browsing**: Browse 535 books across 17 categories with multi-field filtering (genre, author, publication year, language).
- **Responsive Views**: Seamlessly toggle between Grid Card View and Dense Table View.
- **Instant Autocomplete**: Debounced search suggestions querying titles and authors in under 3 ms.
- **SVG Fallback Covers**: Missing remote book covers automatically render crisp, zero-latency vector SVG placeholders (`/assets/images/cover-placeholder.svg`).

### 📚 Personal Library & Reading Progress
- **Smart Shelves**: Organize reading into *Want to Read*, *Currently Reading*, *Finished*, *On Hold*, and *Dropped*.
- **Progress Tracking**: Update progress (0–100%) with automated transition to *Finished* upon reaching 100%.
- **Continue Reading**: Instant resume shelf directly on the reader dashboard highlighting active books.
- **Reading Streaks & Favourites**: Track consecutive active days and highlight personal favourites.

### ⭐ Reviews & Community Ratings
- **Verified Reviews**: One review per user per book with 1–5 star ratings, review title, and formatted text body.
- **Real-Time Recalculation**: Book average ratings and review counts update atomically upon every review change.
- **Community Upvoting & Reporting**: Mark reviews as helpful or flag violations for administrator moderation.

### 👥 Reader Community & Social Network
- **Discussion Feed**: Publish book discussions, ask questions, and share reading reviews.
- **Interactive Threads**: Like posts, post comments, and view reader profiles.
- **Author & Reader Following**: Follow authors for notification alerts upon new catalogue releases; follow readers to curate a customized social feed.

### 🛡️ Administration & Operations
- **System Dashboard**: High-level telemetry covering active readers, catalogue distribution, and storage utilization.
- **Moderation Queues**: Centralized workflows for reviewing flagged reviews and community posts.
- **Google Books Integration**: Search Google Books directly from the admin panel and import titles, authors, and cover art with one click.

---

## 4. Recommendation Engine

The BookSphere recommendation engine uses a **hybrid multi-factor scoring model** configured via [`config/recommendations.php`](file:///d:/PROJECTS/booksphere/config/recommendations.php):

### Hybrid Scoring Weights
```text
┌────────────────────────────────────────────────────────────┐
│ Factor                  Weight  Rationale                  │
├────────────────────────────────────────────────────────────┤
│ Category Affinity         40%   User favourite genres      │
│ Author Affinity           25%   Followed & high-rated      │
│ Wishlist Similarity       10%   Books saved on wishlist    │
│ Reading History (Rating)  10%   Similar to 4/5-star books  │
│ Review Score              10%   Community rating quality   │
│ Social Engagement          5%   Discussion post activity   │
│ Popularity                 0%   Guaranteed non-dominance   │
└────────────────────────────────────────────────────────────┘
```

### Key Architectural Rules
1. **Candidate Pool Bounding**: Scores a maximum of 50 candidate books per request, ensuring fresh calculation finishes in **2.44–6.58 ms**.
2. **Library Exclusion**: Books currently present in the user's library across **all 5 statuses** (`want_to_read`, `currently_reading`, `finished`, `on_hold`, `dropped`) are strictly excluded.
3. **Wishlist & View Pruning**: Wishlist items and recently viewed books are excluded to ensure novel discoveries.
4. **Cold-Start Fallback**: New users with zero reading history receive community favourites and top-rated books tagged with transparent explanation badges.
5. **Per-User File Caching**: Results are cached atomically in `database/cache/recommendations/personal_{userId}.json` (~0.31 ms response time) and automatically invalidated upon library, review, or rating mutations.

---

## 5. Database Architecture

BookSphere uses **SQLite 3** configured with enterprise pragmas:
- **Location**: `database/booksphere.db`
- **Concurrency**: Write-Ahead Logging (`PRAGMA journal_mode = WAL;`)
- **Foreign Keys**: Enforced on all relations (`PRAGMA foreign_keys = ON;`)
- **Busy Timeout**: 5,000 ms lock buffer (`PRAGMA busy_timeout = 5000;`)
- **Schema**: 31 tables, 37 migrations, zero unindexed full table scans on customer routes.
- **Current Counts**: 535 Books (529 published, 6 drafts), 889 Authors, 17 Categories, 58 Users.

For complete schema details and ER diagrams, see [docs/DATABASE.md](docs/DATABASE.md).

---

## 6. User Workflows

```
┌─────────────────────────────────────────────────────────────────────────┐
│                              New Reader                                 │
│ Register ──> Login ──> Dashboard ──> Browse Books ──> Add to Library    │
│                     ──> Rate & Review ──> Receive Personalized Recs     │
└─────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────┐
│                            Existing Reader                              │
│ Login ──> Dashboard (Continue Reading) ──> Update Progress (0-100%)      │
│       ──> Explore Recommendations ──> Engage in Community Discussions   │
└─────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────┐
│                             Administrator                               │
│ Admin Login ──> System Overview ──> Analytics & Distribution Charts     │
│             ──> Moderate Reported Content ──> Import via Google Books   │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## 7. Local Installation (Windows Quickstart)

### Prerequisites
1. **PHP 8.2 or higher** with extensions enabled in `php.ini`:
   ```ini
   extension=pdo_sqlite
   extension=sqlite3
   extension=curl
   extension=mbstring
   extension=fileinfo
   extension=openssl
   ```
2. **Composer** installed.

### Installation Steps

```powershell
# 1. Clone repository
git clone https://github.com/Joyal-S/Booksphere.git
cd Booksphere

# 2. Configure environment
copy .env.example .env

# 3. Generate autoload classmap
composer install

# 4. Verify SQLite database
php -r "require 'bootstrap/constants.php'; require 'vendor/autoload.php'; echo 'Integrity: ' . BookSphere\App\Core\Database::instance()->query('PRAGMA integrity_check')[0]['integrity_check'] . PHP_EOL;"

# 5. Start development server
php -S localhost:8000 -t public
```

### Accessing BookSphere
Open your browser to: **`http://localhost:8000`**

#### Pre-Configured Test Accounts
- **Administrator**: `admin@booksphere.test` / `Password123!`
- **Standard Reader**: `reader@booksphere.test` / `Password123!`

---

## 8. Configuration & Secrets

Application settings are managed in `.env` (loaded by `BookSphere\App\Core\Environment`):
- `APP_ENV`: `development` | `production`
- `APP_DEBUG`: `true` for local debugging; `false` in production.
- `APP_URL`: Base application URL (default: `http://localhost:8000`).
- `DB_PATH`: Path to SQLite database file (`database/booksphere.db`).
- `GOOGLE_BOOKS_API_KEY`: Optional Google Books API key for higher rate limits.

> [!IMPORTANT]
> The `.env` file contains environment-specific settings and must never be committed to source control. Production deployments must keep `APP_DEBUG=false`.

---

## 9. Automated Testing

BookSphere features a comprehensive suite of **52 automated test files** covering all domain repositories, recommendation algorithms, and controller workflows.

### Run All Test Suites
```powershell
php scratch/run_all_tests.php
```
*Expected Result*:
```text
Summary: 52 test suites | 52 PASSED | 0 FAILED
```

### Run Recommendation Exclusion Test
```powershell
php tests/RecommendationLibraryExclusionTest.php
```
*Expected Result*:
```text
RESULT: Checks: 17 | Failed: 0
```

---

## 10. Performance Benchmarks

Audited and verified in **Phase 9**:

| Page / Subsystem | Measured Response Time | Baseline Target | Status |
| :--- | :---: | :---: | :---: |
| **Landing Page** (`/`) | **53.29 ms** | ~60 ms | **Faster** |
| **Login Screen** (`/login`) | **51.92 ms** | ~60 ms | **Faster** |
| **Reader Dashboard** (`/`) | **93.48 ms** (77.9 ms median) | ~93 ms | **On Target** |
| **Catalogue Browse** (`/books`) | **78.51 ms** | ~79 ms | **On Target** |
| **Book Detail View** (`/books/1`) | **81.99 ms** | ~79 ms | **On Target** |
| **Personal Library** (`/library`) | **57.34 ms** | ~110 ms | **Faster** |
| **Recommendations** (`/recommendations`) | **65.42 ms** | ~68 ms | **Faster** |
| **Social Community** (`/community`) | **90.78 ms** (71.0 ms median) | ~59 ms | **Fast & Responsive** |
| **Admin Overview** (`/admin`) | **86.45 ms** | ~90 ms | **Faster** |
| **Recommendation Engine (Fresh)** | **2.44 – 6.58 ms** | 5.5–6.8 ms | **Optimal** |
| **Recommendation Cache (Warm)** | **0.125 – 0.317 ms** | ~0.31 ms | **Sub-millisecond** |

---

## 11. Security Architecture

1. **Authentication**: Bcrypt hashing (`PASSWORD_BCRYPT`) with timing attack mitigation.
2. **CSRF Protection**: All mutating HTTP requests require a cryptographic token validated by `CsrfMiddleware`.
3. **SQL Injection Defense**: 100% prepared statements with parameterized PDO queries.
4. **XSS Mitigation**: Context-aware output escaping via native `e()` helper.
5. **Rate Limiting**: Throttling on authentication and submission endpoints backed by the SQLite `rate_limits` table.
6. **Secure HTTP Headers**: Clickjacking prevention (`X-Frame-Options: SAMEORIGIN`) and MIME-sniffing prevention (`X-Content-Type-Options: nosniff`).

---

## 12. Known Data Notes

The following catalogue attributes were audited in Phase 8 and are **intentionally preserved**:
- **7 Synthetic Test Books**: Maintained to ensure deterministic test assertions across automated suites.
- **4 Case-Variant Author Pairs**: Preserved to validate fuzzy search and author grouping logic.
- **6 Suspicious Author Names**: Retained to ensure resilience against unexpected character sets.
- **6 Books Without Remote Covers**: Intentionally kept to continuously validate SVG vector fallback rendering.

---

## 13. Documentation Index

Detailed academic and technical documentation packages:
- 🎓 **[Academic Project Report Package](docs/academic/README.md)** — Complete university-compliant academic report (Preliminary Pages, Chapters 1–10, Appendix, Glossary, Diagram Specifications, Screenshot Checklist).
- 🛠️ **[Technical Developer Documentation](docs/technical/)** — Modular technical specifications:
  - [Architecture](docs/technical/ARCHITECTURE.md)
  - [Database Schema (31 Tables)](docs/technical/DATABASE.md)
  - [Feature Inventory](docs/technical/FEATURES.md)
  - [Recommendation Engine V2](docs/technical/RECOMMENDATION_ENGINE.md)
  - [Security Architecture](docs/technical/SECURITY.md)
  - [Testing Guide](docs/technical/TESTING.md)
  - [Performance Benchmarks](docs/technical/PERFORMANCE.md)
  - [Administration Manual](docs/technical/ADMIN.md)
  - [Configuration Guide](docs/technical/CONFIGURATION.md)
  - [File Structure](docs/technical/FILE_STRUCTURE.md)
  - [API & Integrations](docs/technical/API_AND_INTEGRATIONS.md)
  - [Production Deployment](docs/technical/DEPLOYMENT.md)
  - [Troubleshooting Guide](docs/technical/TROUBLESHOOTING.md)
  - [System Limitations](docs/technical/LIMITATIONS.md)

---

## License
BookSphere is released under the **MIT License**.
