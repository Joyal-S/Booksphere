# BookSphere — Community Feature Architecture & Safety Audit Report
**Phase C1 — Complete (Audit Only, Zero Code/DB Changes)**

---

## 1. Baseline Test Results & Application State

### Automated Test Suite Execution
- **Total Test Files Executed**: 32 test suites in `tests/`
- **Passed Test Suites**: 31 / 32
  - `AdminAnalyticsTest.php` — PASSED
  - `AuthTest.php` — PASSED
  - `BookAnalyticsTest.php` — PASSED
  - `BrowseTest.php` — PASSED
  - `CachingAuditTest.php` — PASSED
  - `ChartsReportsTest.php` — PASSED
  - `EmailNotificationTest.php` — PASSED
  - `FollowTest.php` — PASSED
  - `GoogleBooksBulkImportTest.php` — PASSED
  - `GoogleBooksCoverTest.php` — PASSED
  - `GoogleBooksImportTest.php` — PASSED
  - `GoogleBooksSearchTest.php` — PASSED
  - `GoogleBooksSyncTest.php` — PASSED
  - `LibraryTest.php` — PASSED
  - `LoggingAuditTest.php` — PASSED
  - `NotificationApiTest.php` — PASSED
  - `NotificationCenterTest.php` — PASSED
  - `NotificationTest.php` — PASSED
  - `PerformanceAuditTest.php` — PASSED
  - `PersonalizationTest.php` — PASSED
  - `RateLimitingTest.php` — PASSED
  - `RecommendationArchitectureTest.php` — PASSED
  - `RecommendationDashboardTest.php` — PASSED
  - `RecommendationLibraryIntegrationTest.php` — PASSED
  - `RecommendationOptimizationTest.php` — PASSED
  - `ReviewIntegrationTest.php` — PASSED
  - `ReviewTest.php` — PASSED
  - `SearchHistoryTest.php` — PASSED
  - `SearchTest.php` — PASSED
  - `SecurityAuditTest.php` — PASSED
  - `UserAnalyticsTest.php` — PASSED
- **Pre-Existing Failures**: 1 suite (`LandingTest.php`: 25 assertions passed, 4 assertions failed on minor tagline/footer copy checks). Per Phase C1 instructions, these pre-existing failures were not modified.
- **Runtime State**: SQLite database active at `storage/database.sqlite`. Local development server active at `http://localhost:8000`.

---

## 2. Existing Architecture Summary

BookSphere is constructed on a lightweight, modular, zero-dependency custom PHP MVC architecture (`declare(strict_types=1);`):

- **Core MVC Structure**:
  - `app/Core/Router.php`: Regex & exact-match fast-path routing with middleware pipelines.
  - `app/Core/Database.php`: SQLite PDO wrapper using parameterized SQL statements exclusively.
  - `app/Core/Request.php` & `app/Core/Response.php`: HTTP abstraction layer supporting HTML views and JSON dual-mode responses (`X-Requested-With: fetch`).
  - `app/Core/Session.php`: Native PHP session wrapper handling flash messages, CSRF tokens, and user auth state.
- **Service & Repository Pattern**: Controllers delegate business logic to decoupled services (`ReviewService`, `LibraryService`, `NotificationService`, `FollowService`, etc.) and repositories (`BookRepository`, `SearchRepository`, `BookAnalyticsRepository`).
- **Shared Helpers**: `app/Helpers/helpers.php` provides global helper functions (`auth()`, `auth_user()`, `auth_id()`, `e()`, `csrf_token()`, `db()`, `config()`, `format_rating()`).
- **View Templates**: Plain PHP view templates located in `app/Views/` organized into domain subfolders (`books/`, `reviews/`, `library/`, `search/`, `admin/`, `partials/`, `components/`).

---

## 3. User Identity & Authentication System Findings

- **User Model (`app/Models/User.php`)**: Manages the `users` table (`id`, `full_name`, `email`, `password`, `role`, `remember_token`, `created_at`, `updated_at`).
- **Session Auth**: Logged-in state is tracked via `session()->get('user_id')`. The helper `auth_id()` returns the integer ID of the currently logged-in user, and `auth_user()` returns the active user array.
- **Middleware**:
  - `AuthMiddleware`: Enforces active session authentication; redirects unauthenticated users to `/login`.
  - `AdminMiddleware`: Enforces `role === 'admin'`; redirects non-admin users with 403 / flash notice.
- **Community Authentication Strategy**:
  - All Community write operations (creating posts, commenting, liking, reporting) must be gated behind `AuthMiddleware`.
  - Content ownership must be bound directly to `auth_id()`.
  - Foreign key constraints on community tables must reference `users(id)` with `ON DELETE CASCADE`.

---

## 4. Database Findings

- **Database Engine**: SQLite 3 (`storage/database.sqlite`).
- **Migration Architecture**: Incremental PHP migration files in `database/migrations/` (currently 35 migrations from `0001` to `0035`). Migrations return `['up' => '...', 'down' => '...']`.
- **Existing Schema Overview**:
  - `users` (id, full_name, email, role, created_at, updated_at)
  - `books` (id, title, status, deleted_at, ...)
  - `authors` & `categories` (catalogue entities)
  - `reviews` & `review_helpful_votes`, `review_reports` (reviews & moderation)
  - `user_library` (reading shelf status & progress)
  - `author_follows` (user-author follow relationships)
  - `notifications` & `email_preferences`, `email_logs`, `email_queue` (notification pipeline)
  - `rate_limits` & `search_history` (rate limiting & search)
- **Community Isolation Mandate**: Community will be built strictly using **NEW database tables** (`community_posts`, `community_comments`, `community_likes`, `community_reports`). Existing tables (`users`, `books`, `reviews`) will remain untouched.

---

## 5. Existing Social & Engagement Functionality

The codebase already contains robust social & moderation mechanisms that Community can reuse logically:

- **Helpful Votes (`review_helpful_votes`)**: Demonstrates idempotent voting with a `UNIQUE(review_id, user_id)` constraint.
- **Report & Moderation System (`review_reports`)**: Demonstrates report lifecycle management (`pending`, `reviewed`, `dismissed`, `resolved`) and fixed report reasons (`Spam`, `Harassment`, `Offensive Content`, `False Information`, `Duplicate`, `Other`).
- **Notification Pipeline (`NotificationDispatcher` / `NotificationService`)**: In-app and email notification dispatcher.
- **Author Following (`author_follows`)**: Handles follow/unfollow toggle relationships.

---

## 6. Existing Routing Structure & Community Route Namespace

BookSphere's router evaluates exact route matches first before parameterized patterns.

### Proposed Isolated Community Route Namespace:
- `GET  /community` — Main Community feed / discussion index
- `GET  /community/create` — New discussion post form
- `POST /community/posts` — Submit discussion post
- `GET  /community/posts/{id}` — View discussion post & comments
- `GET  /community/posts/{id}/edit` — Edit discussion post form
- `POST /community/posts/{id}/edit` — Submit post update
- `POST /community/posts/{id}/delete` — Delete post
- `POST /community/posts/{id}/like` — Toggle post like/upvote
- `POST /community/posts/{id}/comments` — Add comment to post
- `POST /community/comments/{id}/delete` — Delete comment
- `POST /community/posts/{id}/report` — Report discussion post/comment
- `GET  /admin/community/reports` — Admin moderation queue for community reports
- `POST /admin/community/reports/{id}/resolve` — Resolve community report
- `POST /admin/community/reports/{id}/dismiss` — Dismiss community report

---

## 7. Security Requirements for Community

1. **Authentication & Authorization**:
   - `AuthMiddleware` on all post/comment write actions.
   - Ownership verification: Users can only edit or delete their own posts/comments (unless role is `admin`).
2. **CSRF Protection**:
   - `CsrfMiddleware` enforced on all POST/PATCH/DELETE endpoints.
3. **XSS Prevention**:
   - All user-submitted text (post titles, body, comments) must be escaped via `e()` in PHP view templates.
4. **SQL Injection Protection**:
   - All database queries must use prepared statements with bound parameters (`?`).
5. **Rate Limiting**:
   - Post creation throttled via `RateLimiter` (`community_post` bucket: max 5 per minute).
   - Comment creation throttled via `RateLimiter` (`community_comment` bucket: max 15 per minute).
6. **Input Validation**:
   - Post title: 5–150 chars. Body: 10–5000 chars. Comment: 1–1000 chars.
7. **Moderation Controls**:
   - Admin-only moderation actions (`AdminMiddleware`) to hide/unhide posts or resolve reports.

---

## 8. UI Integration Points

Community will seamlessly adopt BookSphere's existing UI design system:

- **Layout Shell**: `app/Views/layouts/app.php`
- **Sidebar**: Add a "Community" nav item in `app/Views/partials/sidebar.php` (using Font Awesome icon `fa-comments`).
- **Reusable View Components**:
  - `section-header.php` (Page header & action button)
  - `stat-card.php` (Community activity metrics)
  - `empty-state.php` (No posts/comments placeholder)
  - `alert.php` (Success/error notifications)
  - `pagination.php` (Feed pagination)
- **CSS Design Tokens**: Color palette (`--primary`, `--surface`, `--canvas`, `--text`, `--border`), typography (Inter), and border radii (`--radius-md`) from `public/assets/css/app.css`.

---

## 9. Proposed Community Module Architecture

```
app/
├── Controllers/
│   ├── CommunityController.php        # Handles feed, post CRUD, comments, likes, reports
│   └── AdminCommunityController.php   # Handles admin moderation queue & actions
├── Models/
│   ├── CommunityPost.php             # SQL data access for community_posts
│   ├── CommunityComment.php          # SQL data access for community_comments
│   ├── CommunityLike.php             # SQL data access for community_likes
│   └── CommunityReport.php           # SQL data access for community_reports
├── Services/
│   └── CommunityService.php          # Domain orchestration & business logic
├── Policies/
│   └── CommunityPolicy.php           # Fine-grained authorization checks
└── Views/
    └── community/
        ├── index.php                 # Feed / Discussions list
        ├── show.php                  # Post detail & comments view
        ├── create.php                # Create post form
        ├── edit.php                  # Edit post form
        └── partials/
            ├── _post_card.php        # Reusable post summary card
            └── _comment_item.php     # Reusable comment item
```

---

## 10. Proposed Database Tables (Design Only — Not Created)

### 1. `community_posts`
```sql
CREATE TABLE community_posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    book_id    INTEGER DEFAULT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    status     TEXT    NOT NULL DEFAULT 'active', -- active | hidden | deleted
    created_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    updated_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    FOREIGN KEY (book_id) REFERENCES books (id) ON DELETE SET NULL,
    CHECK (status IN ('active', 'hidden', 'deleted'))
);
CREATE INDEX idx_community_posts_user ON community_posts (user_id);
CREATE INDEX idx_community_posts_book ON community_posts (book_id);
CREATE INDEX idx_community_posts_status ON community_posts (status);
```

### 2. `community_comments`
```sql
CREATE TABLE community_comments (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id    INTEGER NOT NULL,
    user_id    INTEGER NOT NULL,
    body       TEXT    NOT NULL,
    status     TEXT    NOT NULL DEFAULT 'active', -- active | hidden | deleted
    created_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    updated_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    FOREIGN KEY (post_id) REFERENCES community_posts (id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CHECK (status IN ('active', 'hidden', 'deleted'))
);
CREATE INDEX idx_community_comments_post ON community_comments (post_id);
CREATE INDEX idx_community_comments_user ON community_comments (user_id);
```

### 3. `community_likes`
```sql
CREATE TABLE community_likes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id    INTEGER NOT NULL,
    user_id    INTEGER NOT NULL,
    created_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    FOREIGN KEY (post_id) REFERENCES community_posts (id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX idx_community_likes_unique ON community_likes (post_id, user_id);
CREATE INDEX idx_community_likes_post ON community_likes (post_id);
```

### 4. `community_reports`
```sql
CREATE TABLE community_reports (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id      INTEGER DEFAULT NULL,
    comment_id   INTEGER DEFAULT NULL,
    reported_by  INTEGER NOT NULL,
    reason       TEXT    NOT NULL DEFAULT 'Other',
    description  TEXT    NOT NULL DEFAULT '',
    status       TEXT    NOT NULL DEFAULT 'pending', -- pending | reviewed | dismissed | resolved
    created_at   TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    updated_at   TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    FOREIGN KEY (post_id)     REFERENCES community_posts    (id) ON DELETE CASCADE,
    FOREIGN KEY (comment_id)  REFERENCES community_comments (id) ON DELETE CASCADE,
    FOREIGN KEY (reported_by) REFERENCES users            (id) ON DELETE CASCADE,
    CHECK (reason IN ('Spam', 'Harassment', 'Offensive Content', 'False Information', 'Duplicate', 'Other')),
    CHECK (status IN ('pending', 'reviewed', 'dismissed', 'resolved'))
);
CREATE INDEX idx_community_reports_status ON community_reports (status);
```

---

## 11. Proposed Routes

(Refer to Section 6 for full detailed route list)

---

## 12. Proposed Permissions

- **Guest User**: Can view public community feed & public post details. Cannot create posts, comment, like, or report.
- **Authenticated User**: Can create posts, comment on posts, toggle likes on posts, edit/delete their own posts & comments, report posts/comments.
- **Admin User**: All authenticated user capabilities plus ability to view moderation queue (`/admin/community/reports`), resolve/dismiss reports, and hide/unhide any community post or comment.

---

## 13. Reusable Existing Components

- `app/Views/components/section-header.php`
- `app/Views/components/stat-card.php`
- `app/Views/components/empty-state.php`
- `app/Views/components/alert.php`
- `app/Views/components/pagination.php`
- `app/Services/NotificationDispatcher.php`
- `app/Core/RateLimiter.php`
- `app/Middleware/CsrfMiddleware.php` & `AuthMiddleware.php` & `AdminMiddleware.php`

---

## 14. Risks and Severity

| Risk Item | Severity | Description & Mitigation |
| :--- | :--- | :--- |
| **Notification Type CHECK Constraint** | **HIGH** | The `notifications` table in migration `0023` has a `CHECK (type IN (...))` constraint. Sending community notifications requires extending the migration or handling types safely. |
| **Post/Comment Spam & Flooding** | **MEDIUM** | Unrestricted writing could impact DB size or feed UX. Mitigated via strict `RateLimiter` thresholds and `AuthMiddleware`. |
| **Orphaned References on Deletion** | **MEDIUM** | Deleting users or books could leave dangling posts. Mitigated via foreign key `ON DELETE CASCADE` / `ON DELETE SET NULL`. |
| **Route Name Collision** | **LOW** | Potential overlap with existing endpoints. Mitigated by using a dedicated `/community` namespace. |

---

## 15. Regression-Prevention Strategy

- **Database Isolation**: All new tables will be added via incremental migrations (`0036_create_community_tables.php`). No ALTER operations on existing tables.
- **Service Isolation**: `CommunityService` will operate independently without modifying existing core services.
- **Route Isolation**: All Community routes live under `/community` and `/admin/community`.
- **Test Strategy**: Dedicated unit & integration tests (`tests/CommunityTest.php`) will cover post/comment CRUD, likes, security policies, and moderation.

---

## 16. Recommended Implementation Order for Phase C2 Onward

1. **Phase C2 — Database Foundation**: Create migration `0036_create_community_tables.php` for `community_posts`, `community_comments`, `community_likes`, and `community_reports`.
2. **Phase C3 — Models & Core Service Layer**: Implement `CommunityPost`, `CommunityComment`, `CommunityLike`, `CommunityReport`, `CommunityService`, and `CommunityPolicy`.
3. **Phase C4 — Public & User Community Controllers & Views**: Implement `CommunityController` routes (`/community`, `/community/posts/{id}`), create post & comment forms, and post list feed.
4. **Phase C5 — Social Engagement (Likes, Book Linking & Notifications)**: Add book-linked discussions, post like toggles, and notification triggers.
5. **Phase C6 — Moderation & Admin Queue**: Implement `AdminCommunityController` and `/admin/community/reports` moderation dashboard.
6. **Phase C7 — Testing, QA & Final Polish**: Build `tests/CommunityTest.php` suite and verify 100% regression safety.

---

### Final Status Checklist

- **PHASE C1 — COMPLETE**
- **AUDIT ONLY**
- **NO FUNCTIONAL CHANGES**

- **Baseline Test Result**: 31 / 32 test suites PASSED (1 pre-existing failure in `LandingTest.php` untouched).
- **Files Modified**: 1 documentation file created ([docs/PHASE_C1_COMMUNITY_AUDIT.md](file:///d:/PROJECTS/booksphere/docs/PHASE_C1_COMMUNITY_AUDIT.md)). 0 application code files modified.
- **Database Changes**: NONE (0 tables added/modified).
- **Routes Added**: NONE (0 routes added/modified).
- **Community Implementation Status**: NOT STARTED.
- **Recommended Next Phase**: **Phase C2 — Database Foundation**
