# BookSphere — Intelligent Book Discovery & Recommendation System

**Phase:** 0 — Project Planning & System Design  
**Audience:** MCA project evaluators, developers, administrators, and users  
**Status:** Approved blueprint; no product implementation is included in this document.

## 1. Project Overview

### Introduction

BookSphere is a web application that helps readers discover books, organise their reading, record opinions, and receive explainable recommendations. It combines a curated catalogue with optional Google Books metadata import, while giving administrators clear content and user-management tools.

### Problem statement

Readers often switch between search engines, note-taking tools, wishlists, and social platforms to decide what to read. Generic results can be noisy, personal lists become fragmented, and small catalogue owners lack simple analytics and editorial controls.

### Proposed solution

BookSphere provides a single responsive experience for catalogue discovery, filtering, ratings and reviews, wishlists, reading lists, profile-based recommendations, and administrative curation. Recommendations start with transparent, deterministic signals—category, author, tags, ratings, and reading history—so they are understandable in a viva and useful before large-scale data exists.

### Objectives and scope

| Objective | In scope | Not in the first release |
|---|---|---|
| Discovery | Search, browse, details, categories, authors, filters | Full-text semantic/vector search |
| Personal organisation | Wishlist, reading states, notes, progress | Collaborative list editing |
| Trust | Ratings, reviews, moderation | Social following and messaging |
| Curation | CRUD, import, users, moderation, analytics | Multi-tenant publisher workflows |
| Quality | Secure PHP MVC, SQLite, accessible responsive UI | Native mobile applications |

### Technologies and expected outcomes

PHP 8.3, Composer, PDO, SQLite, HTML5/CSS3, Bootstrap 5.3, JavaScript ES6, GSAP, Chart.js, and Font Awesome. The outcome is a maintainable portfolio application with a documented architecture, predictable recommendation logic, strong validation, and useful dashboards.

### Future enhancements

Email notifications, OAuth, APIs for mobile clients, collaborative lists, recommendation feedback learning, ISBN barcode scanning, multilingual metadata, and a migration path from SQLite to a server database only when actual scale requires it.

## 2. Requirements

### Functional requirements

| Area | Requirement |
|---|---|
| Accounts | Register, verify credentials, log in/out, reset password, update profile and preferences. |
| Catalogue | Browse, paginate, search, filter, sort, view details, categories, authors, and availability/status. |
| Reading | Add/remove wishlist items; set Want to Read, Reading, Completed; record progress and private notes. |
| Community | Rate once per book, create/edit own review, report inappropriate reviews. |
| Recommendations | Display personalised and fallback recommendations with an explanation. |
| Administration | Manage books, authors, categories, users, reviews, imports, and dashboard analytics. |
| Import | Search Google Books, preview metadata, select records, map and deduplicate by ISBN. |

### Non-functional requirements

Maintainable PSR-12 code; responsive layouts from 320 px upward; page-level authorisation; prepared statements only; graceful errors; no application dependence on JavaScript for core actions; and audit-friendly administrative actions.

### System, browser, and quality targets

| Area | Target |
|---|---|
| Server | PHP 8.3, `pdo_sqlite`, Composer 2, Apache/Nginx with `public/` document root. |
| Browser | Current and previous major Chrome, Edge, Firefox, Safari; no Internet Explorer support. |
| Performance | Typical catalogue page under 1.5 s locally; indexed searches; 12–24 items/page; images lazy loaded. |
| Security | OWASP-aligned input, CSRF, output escaping, secure sessions, hashing, rate limiting for auth. |
| Accessibility | WCAG 2.2 AA intention: keyboard access, semantic landmarks, labels, focus, contrast, alternatives. |

## 3. User Roles

| Role | Permissions and features | Restrictions |
|---|---|---|
| Guest | Landing page, browse/search public books, read approved reviews, register/login. | Cannot save lists, rate, review, or access account/admin pages. |
| Registered user | All guest features; profile, preferences, wishlist, reading list, rating/review, recommendations, report review. | Cannot change catalogue, users, moderation state, imports, or analytics. |
| Administrator | All user features; manage catalogue/taxonomy/users, moderate reviews/reports, Google import, analytics, system settings. | Cannot access passwords or impersonate users; destructive actions require confirmation. |

## 4. Feature List

| Module | Planned features |
|---|---|
| Authentication | Register, login, logout, password reset, role guard, session management. |
| Books | Catalogue, details, authors, categories, tags, cover upload, pagination, filters, sorting. |
| Recommendations | Genre/author/tag/history scoring, cold-start fallback, “because you liked…” explanation. |
| Wishlist | Add/remove, list, move to reading list. |
| Reading list | Status, progress, private notes, started/completed dates. |
| Reviews and ratings | One rating/user/book, approved reviews, edit/delete own review, report review. |
| Search | AJAX suggestions, debounced search, keyboard navigation, full search results and filters. |
| Profile/settings | Avatar, name, password, reading preferences, theme, notification choices. |
| Admin | Dashboard, CRUD, review moderation, user status, taxonomy, audit log. |
| Analytics | Catalogue and user totals, ratings, review/recommendation trends, import activity. |
| Google Books import | Query, preview, ISBN deduplication, selected-record import, image cache. |
| Notifications | In-app success/error messages; email notifications are a future extension. |

## 5. User Flows

```text
Guest → Home → Search/Browse → Book Details → Register → Login
Registered User → Login → Dashboard → Recommendations → Book Details
  → Add Wishlist OR Set Reading Status → Reading List → Update Progress → Complete → Rate/Review → Logout
Administrator → Login → Admin Dashboard → Manage Books/Authors/Categories
  → Google Books Import → Preview → Resolve Duplicates → Import → Analytics → Logout
User → Review → Report Review → Administrator → Moderation Queue → Approve/Hide → User sees updated state
```

Error paths: unauthenticated protected-page request → login with return URL; invalid form → same form with errors and retained safe input; missing resource → 404; unexpected failure → 500 with correlation id in logs.

## 6. Information Architecture

```text
Home
├─ Login / Register / Forgot Password / Reset Password
├─ Books
│  ├─ Search Results
│  ├─ Categories → Category Books
│  ├─ Authors → Author Detail
│  └─ Book Detail → Reviews
├─ User Dashboard
│  ├─ Recommendations
│  ├─ Wishlist
│  ├─ Reading List
│  ├─ Profile
│  └─ Settings
├─ Admin
│  ├─ Dashboard
│  ├─ Books / Authors / Categories / Tags
│  ├─ Users
│  ├─ Reviews and Reports
│  ├─ Google Books Import
│  └─ Analytics / Audit Log
└─ 404 / 500
```

## 7. Database Design

No SQL is defined in Phase 0. All tables use integer primary keys, UTC ISO-8601 timestamps, foreign keys, and indexed lookup fields.

| Table | Purpose | Key relationships and indexes |
|---|---|---|
| users | Account identity, role, status, password hash. | unique email; index role/status. |
| user_profiles | Display name, avatar, bio, preferences. | 1:1 users; unique user_id. |
| password_resets | Single-use, expiring reset tokens. | user_id; unique token hash; expiry index. |
| categories | Controlled genres/categories. | unique name/slug. |
| authors | Author biographies and metadata. | unique normalized name/slug. |
| books | Core catalogue metadata, ISBN, description, cover, publication details. | unique nullable ISBN-13; indexes title, author sort, category. |
| book_authors | Normalised many-to-many books/authors. | composite unique(book_id, author_id); both FK indexes. |
| book_categories | Optional many-to-many secondary classification. | composite unique keys. |
| tags / book_tags | Flexible discovery labels. | unique tag slug; composite mapping index. |
| ratings | User score for a book. | unique(user_id, book_id); index book_id. |
| reviews | Written review and moderation state. | user_id/book_id FKs; index book_id/status/created_at. |
| review_reports | User reports against reviews. | unique(reporter_id, review_id); status index. |
| wishlists | Saved books. | unique(user_id, book_id); user sort index. |
| reading_lists | Per-user book status, progress, notes, dates. | unique(user_id, book_id); user/status index. |
| recommendation_events | Records generated recommendations and action feedback. | user/date and book/date indexes. |
| imported_books | Google source identifiers and import metadata. | unique provider/provider_book_id; ISBN index. |
| audit_logs | Administrative change trace. | actor/date and entity/type indexes. |

Relationships: users own profiles, ratings, reviews, lists and reports; books have many authors/categories/tags and many user interactions; reviews can have many reports; administrator actions write audit logs. Media files live outside the database, with safe relative paths stored on books/profiles.

## 8. Application Architecture

### Folder responsibilities

```text
app/Core        Request, response, router, database connection, session, exceptions
app/Controllers HTTP orchestration only; validate intent, call service, choose response
app/Models      PDO repositories and query mapping only
app/Services    Business rules, transactions, recommendation and import logic
app/Middleware  Authentication, role checks, CSRF, rate limiting
app/Helpers     Escaping, pagination, validation, URL/date utilities
app/Views       Escaped PHP templates and reusable partials; no SQL/business rules
config          Environment-safe configuration defaults
database        Versioned migrations, seeders, documentation assets
public          Front controller, compiled/static assets, public cache
routes          HTTP route definitions
storage         SQLite database, logs, private temporary files
```

### Request lifecycle

`Browser → public/index.php → Core Request/Router → middleware chain → controller → service → model/PDO → service result → controller → view or JSON response → browser`.

The router resolves method/path, middleware rejects unauthorised or forged requests, controllers remain thin, services own rules and transactions, models issue prepared SQL, and views receive pre-shaped data. Exceptions are centrally logged and rendered as safe error responses.

### Standards

PSR-12, `declare(strict_types=1)`, constructor dependency injection, singular class names, noun models, verb services, explicit return types, PHPDoc on public APIs, and no globals. One responsibility per class; no repository abstraction unless query complexity genuinely warrants it.

## 9. UI/UX Design System

| Token | Light | Dark | Use |
|---|---|---|---|
| Primary | `#5B4BDB` | `#8B80FF` | Primary actions, focus indicators |
| Canvas | `#F8FAFC` | `#0F172A` | App background |
| Surface | `#FFFFFF` | `#182235` | Cards, navigation, modals |
| Text | `#172033` | `#E6EDF7` | Main text |
| Muted | `#64748B` | `#A7B2C4` | Secondary text |
| Success/warning/danger | `#16803C` / `#A55C00` / `#C43131` | Contrast-adjusted equivalents | States |

Typography: system sans stack; 12/14/16/20/24/32/40 px scale; 1.5–1.65 body line height; headings 600–750 weight. Spacing uses a 4 px base (4, 8, 12, 16, 24, 32, 48). Radius: 8 px controls, 12 px cards, 16 px hero. Shadows are subtle, reserved for elevation.

Buttons have primary, secondary, quiet, destructive, disabled and loading states. Forms show labels, descriptions, inline errors, and visible focus. Tables collapse to labelled rows on small screens. Cards show cover, title, author, metadata and one primary action. Navigation is a top bar for guests and collapsible sidebar/top bar for signed-in users.

Motion: GSAP only for entrance hierarchy, small layout transitions, and feedback; 150–300 ms; respect `prefers-reduced-motion`; never animate essential content or create non-dismissable movement. Mobile first: single column under 768 px, condensed navigation, touch targets at least 44 px.

Accessibility: semantic landmarks, one H1, logical heading order, native buttons/links, focus restoration after modals, ARIA only when native semantics are insufficient, contrast ≥4.5:1, accessible names for icons, and no colour-only status.

## 10. Figma Design Plan

| Screen | Purpose, layout, interaction, responsive behaviour |
|---|---|
| Landing/Home | Value proposition, featured books/categories, search; stacked hero and cards on mobile. |
| Login/Register/Forgot | Focused single-column authentication forms with password visibility and clear errors. |
| Catalogue | Search, filters, sort and paginated book grid; filter drawer on mobile. |
| Book details | Cover/metadata/actions, description, reviews and related books; sticky desktop action panel. |
| Wishlist/Reading list | Tabs or segmented controls, status actions, progress; cards become compact rows on mobile. |
| Recommendations | Explanation-labelled cards, refresh/filter interactions, empty cold-start preference prompt. |
| Profile/Settings | Avatar, account and preference forms; save feedback and destructive section separation. |
| Admin dashboard | KPI cards, chart cards, recent activity; two-column desktop, one-column mobile. |
| Manage books/users | Searchable data table, create/edit modal/page, confirmations and audit feedback. |
| Import/Analytics | Import query/preview/selection steps; labelled charts and downloadable future reports. |
| 404/empty/loading | Clear recovery action; skeletons preserve final layout without deceptive animation. |

All screens receive annotated desktop (1440 px), tablet (768 px), and mobile (375 px) frames, light/dark variants, component instances, interactive states, and accessibility notes in Figma.

## 11. Component Library

Foundation components: `AppShell`, `Navbar`, `Sidebar`, `Footer`, `Button`, `IconButton`, `Input`, `Select`, `Textarea`, `Checkbox`, `FormField`, `Badge`, `Alert`, `Toast`, `Modal`, `Dropdown`, `Avatar`, `Pagination`, `EmptyState`, and `LoadingSkeleton`.

Domain components: `BookCard`, `BookMeta`, `SearchBar`, `FilterPanel`, `RecommendationCard`, `ReviewCard`, `RatingControl`, `ReadingStatusControl`, `ProgressIndicator`, `StatisticsCard`, `ChartCard`, `DataTable`, and `ImportPreview`. Components expose documented states: default, hover, focus, disabled, loading, error, empty, and dark mode.

## 12. Icon System

Use Font Awesome, always paired with accessible text or an `aria-label`: Dashboard `fa-chart-line`; Books `fa-book-open`; Wishlist `fa-heart`; Reading List `fa-bookmark`; Recommendations `fa-wand-magic-sparkles`; Search `fa-magnifying-glass`; Settings `fa-gear`; Analytics `fa-chart-column`; Admin `fa-shield-halved`; Users `fa-users`; Logout `fa-right-from-bracket`.

## 13. Project Roadmap

| Phase | Objective / deliverables | Dependencies | Testing and completion |
|---|---|---|---|
| 0 | This blueprint, IA, schema design, UI plan. | None | Stakeholder sign-off; no source code. |
| 1 | Bootstrap MVC, config, routing, migrations, layout/design tokens. | 0 | Routes, migration rollback/re-run, responsive shell. |
| 2 | Authentication, profiles, roles, security middleware. | 1 | Auth, CSRF, session, authorisation tests. |
| 3 | Catalogue, taxonomy, search, uploads, admin CRUD. | 1–2 | Pagination, validation, upload, permission tests. |
| 4 | Wishlist, reading list, ratings and reviews. | 2–3 | Ownership, uniqueness and moderation tests. |
| 5 | Recommendation engine and explanation UI. | 3–4 | Deterministic score/cold-start test dataset. |
| 6 | Google import, analytics, refinement and deployment docs. | 3–5 | Import dedupe, charts, security/performance audit. |

## 14. GitHub Structure

Repository layout follows Section 8, plus `docs/`, `tests/`, `.github/`, and `.env.example`. Use trunk-based development with short-lived `feature/`, `fix/`, and `docs/` branches; protect `main`; require review and passing checks. Conventional commits: `feat:`, `fix:`, `docs:`, `refactor:`, `test:`, `chore:`. Version releases with SemVer. README: overview, screenshots, stack, setup, test, architecture link, roles, demo account policy, contribution and licence.

## 15. Testing Strategy

| Test type | Coverage |
|---|---|
| Functional | Critical user/admin flows, validation, pagination, list state, ratings, imports. |
| Security | CSRF, XSS escaping, injection attempts, access control, upload MIME/size/path, session fixation. |
| Responsive | 320/375/768/1024/1440 px layouts and touch behaviour. |
| Performance | Indexed query plans, pagination, image dimensions/cache, import batch limits. |
| Browser | Target browser matrix and dark mode. |
| Accessibility | Keyboard-only traversal, labels, headings, contrast, screen-reader spot checks. |

Automated tests will cover services/models and route-level security; manual acceptance checklists will cover visual and assistive-technology behaviours.

## 16. Documentation Plan

`README.md`, Installation Guide, Architecture Guide, Database Dictionary and ER Diagram, User Manual, Admin Manual, Deployment Guide, API Documentation (for JSON endpoints), Class Diagram, Request/Recommendation Sequence Diagrams, Testing Report, and this Phase 0 Blueprint.

## 17. Coding Standards and Checklists

PHP uses PSR-12; classes PascalCase; methods/properties camelCase; folders plural only where they contain peer classes; migration files timestamped snake_case; table/column names snake_case; route names dot-separated. Public methods receive concise PHPDoc. Comments explain reasoning, not syntax. Never interpolate request data into SQL or HTML.

**Security checklist:** prepared statements, server-side validation, escaping by context, CSRF on mutations, password hash/verify, session ID regeneration at login, secure cookie flags, rate limiting, least-privilege routes, validated uploads with generated names, no secrets in Git.

**Performance checklist:** indexes verified with query plans, pagination, selected columns only, eager aggregate/join queries to prevent N+1, lazy images, cached remote covers, debounced/cancellable AJAX search, and bounded import batches.

## Architectural Decisions

SQLite and a single-deploy PHP MVC application minimise operational complexity and are appropriate for an MCA project. The service layer protects controllers from accumulating rules; direct PDO models keep data access transparent. Deterministic recommendations are chosen before machine learning because they are testable, explainable, and work with limited user data.

## Extension Points

An interface-backed recommendation provider, a Google Books client, notification provider, image cache, and export/report services can be introduced behind existing services when their phases begin. None is implemented in Phase 0.
