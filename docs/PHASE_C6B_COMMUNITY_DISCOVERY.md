# BookSphere — Community Feature
## PHASE C6-B: COMMUNITY DISCOVERY — COMPLETE

---

### 1. Core Objective

Phase C6-B enhances the **Community Discovery** experience at `/community`, enabling readers to easily discover interesting, active, popular, and trending discussions across BookSphere instead of relying solely on a basic chronological list.

This phase introduces:
- **Discovery Modes**: Recent, Popular, and Trending discussions.
- **Book-Specific Discovery**: Dedicated filter & route (`/community/book/{id}`) for discussions linked to specific books.
- **Discovery Filters**: Quick mode switches (`sort`), book filters (`book_id`), and pagination controls.
- **Moderation Safety**: Server-side filtering enforcing `p.status = 'active'` across all discovery modes.
- **Author Profile Integration**: Direct navigation links to public user profiles (`/community/user/{id}`) built in Phase C6-A.
- **Professional Empty & Bounded Pagination States**: Graceful empty messages and bounded pagination parameters (`page`, `per_page`) preserving state across transitions.

---

### 2. Discovery Modes & Ranking Formulas

| Mode | URL Parameter | Primary Objective | Scoring / Order Formula |
|---|---|---|---|
| **Recent** | `?sort=recent` | Newly created active discussions first | `ORDER BY p.created_at DESC, p.id DESC` |
| **Popular** | `?sort=popular` | Overall engagement signal weighted by likes and comments | `ORDER BY (like_count * 2 + comment_count * 3) DESC, p.created_at DESC, p.id DESC` |
| **Trending** | `?sort=trending` | Discussions actively gaining engagement relative to their age | `ORDER BY ((like_count * 2 + comment_count * 4 + 1) / (JULIANDAY('now') - JULIANDAY(p.created_at) + 1.0)) DESC, p.created_at DESC, p.id DESC` |

#### Formula Rationale & Design
- **Popular Ranking**: Combines total likes (2x weight) and total comments (3x weight). Uses creation timestamp as a secondary sort tiebreaker to prevent stale order locks.
- **Trending Gravity Decay**: Calculates a transparent activity score that decays with post age (`JULIANDAY('now') - JULIANDAY(p.created_at)` in days). A +1.0 day baseline prevents division by zero for brand new posts while giving heavy recency advantage to active new discussions.

---

### 3. Book-Specific Discovery

- **Route & Parameter**: `GET /community/book/{id}` and `GET /community?book_id={id}`.
- **Book Details Integration**: Book details page (`/books/{id}`) contains a dedicated "Community Discussions" callout card linking directly to `/community/book/{id}`.
- **Feed Filter**: Selecting a book from the "Filter by Book" dropdown on `/community` updates the URL with `book_id` while preserving the active discovery sort mode (`sort`).

---

### 4. Moderation & Privacy Enforcement

- **Server-Side Filtering**: Every discovery query executed via `CommunityPostRepository::findDiscoveryPosts()` strictly applies `WHERE p.status = 'active'`.
- **Hidden/Moderated Content Protection**: Content marked as `hidden` or `deleted` in Phase C5 moderation is never returned by the discovery API or view layer, regardless of query parameters.
- **Author Link Privacy**: Post author cards link to `/community/user/{id}` exposing only public profile details (display name, avatar initial, member since) and excluding private account data.

---

### 5. Security & Input Sanitization

- **Untrusted Input Validation**:
  - `sort`: Validated against whitelist `['recent', 'popular', 'trending']`. Any invalid value (e.g. SQL injection attempts) silently falls back to `'recent'`.
  - `book_id`: Validated as a positive integer. Nonexistent book IDs return a `CommunityException::bookNotFound` (HTTP 404).
  - `page` / `per_page`: `page` forced `>= 1`, `per_page` bounded strictly between 1 and 50.
- **SQL Injection Prevention**: All filter values and pagination bounds are bound via PDO parameterized statements (`?`).
- **XSS Defense**: HTML output in views is escaped via `e()`.

---

### 6. Database & Performance Audit

- **Schema Changes**: **NONE**. Reused the existing database schema from Phase C2 (`0036_create_community_tables.php`).
- **Indexes Utilized**:
  - `idx_community_posts_status` on `community_posts(status)`
  - `idx_community_posts_book` on `community_posts(book_id)`
  - `idx_community_posts_created` on `community_posts(created_at)`
  - `idx_community_likes_post` on `community_likes(post_id)`
  - `idx_community_comments_post` on `community_comments(post_id)`
- **Query Optimization**: Inline SQL subqueries for `like_count` and `comment_count` execute via single-pass indexed lookup without N+1 query overhead.

---

### 7. Files Modified & Created

#### Files Created
- [`tests/CommunityC6BTest.php`](file:///d:/PROJECTS/booksphere/tests/CommunityC6BTest.php)
- [`docs/PHASE_C6B_COMMUNITY_DISCOVERY.md`](file:///d:/PROJECTS/booksphere/docs/PHASE_C6B_COMMUNITY_DISCOVERY.md)

#### Files Modified
- [`app/Controllers/CommunityController.php`](file:///d:/PROJECTS/booksphere/app/Controllers/CommunityController.php)
- [`app/Services/CommunityService.php`](file:///d:/PROJECTS/booksphere/app/Services/CommunityService.php)
- [`app/Repositories/CommunityPostRepository.php`](file:///d:/PROJECTS/booksphere/app/Repositories/CommunityPostRepository.php)
- [`app/Views/community/index.php`](file:///d:/PROJECTS/booksphere/app/Views/community/index.php)

#### Shared Files Modified
- NONE

---

### 8. Final Verification Report

PHASE C6-B — COMPLETE

Recent:
PASS

Popular:
PASS

Trending:
PASS

Book discovery:
PASS

Filters:
PASS

Pagination:
PASS

Moderation filtering:
PASS

Security:
PASS

Community tests:
PASS (All 9 Community test files passing: CommunityTest, CommunityFeedTest, CommunityPostDetailsTest, CommunityHttpTest, CommunityC4CTest, CommunityC4DTest, CommunityC5Test, CommunityC6ATest, CommunityC6BTest)

Full BookSphere test suite:
PASS (40 / 41 test suites passing, 1 pre-existing failure in LandingTest.php)

Database changes:
NONE

Regression:
ZERO NEW REGRESSIONS

Browser verification:
DEFERRED — local browser MCP unavailable

Files modified:
- app/Controllers/CommunityController.php
- app/Services/CommunityService.php
- app/Repositories/CommunityPostRepository.php
- app/Views/community/index.php

Shared files modified:
NONE

Known issues:
- LandingTest.php pre-existing failure remains unchanged.

Next recommended phase:
C6-C — Community Search & Filtering
