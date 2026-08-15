# BookSphere — Community Feature
## PHASE C6-A: COMMUNITY PROFILES — COMPLETE

---

### 1. Core Objective

Phase C6-A introduces a dedicated, public **Community Profile** experience for users at `GET /community/user/{id}`. Members can discover and explore another user's public Community activity (discussions authored and comments posted) without modifying or exposing any private account, authentication, library, review, recommendation, or dashboard data.

---

### 2. Routes & Architecture

- **`GET /community/user/{id}`**: Safely extended existing `userPosts` route handler in [`app/Controllers/CommunityController.php`](file:///d:/PROJECTS/booksphere/app/Controllers/CommunityController.php).
  - Browser requests: renders the [`app/Views/community/profile.php`](file:///d:/PROJECTS/booksphere/app/Views/community/profile.php) view.
  - JSON callers (`X-Requested-With: fetch` / `Accept: application/json`): returns structured JSON profile payload.
  - Nonexistent user IDs: returns HTTP 404 (`Response::error(404, 'User not found.')`).

---

### 3. Public Information Exposed & Privacy Protection

#### Public Profile Attributes (EXPOSED)
- Display name (`full_name`)
- Avatar initial circle (derived from `full_name`)
- Member since date (e.g. `Joined Jan 2024` from `created_at`)
- Total active discussions count (`stats.posts`)
- Total active comments count (`stats.comments`)
- Active discussion posts feed
- Active comments feed

#### Private Account Attributes (STRICTLY EXCLUDED)
- Email address (`email`)
- Password / hash (`password`)
- Remember token (`remember_token`)
- Session information
- Internal database metadata
- Submitted moderation reports
- Moderated / hidden content (`status = 'hidden'`)

---

### 4. Activity & Moderation Integration

- **Discussions (Posts)**:
  - Fetched via `CommunityPostRepository::findActiveByUser(userId, limit, offset)`.
  - Filters strictly by `p.status = 'active'`. Hidden/moderated posts are excluded server-side.
  - Displays title, body snippet, linked book badge (if present), relative timestamp, like count, and comment count.
- **Comments**:
  - Fetched via `CommunityCommentRepository::findActiveByUser(userId, limit, offset)`.
  - Filters strictly by `c.status = 'active' AND p.status = 'active'`. Hidden comments or comments on hidden posts are excluded server-side.
  - Displays comment snippet, parent post title link (`/community/post/{post_id}`), linked book badge (if present), and timestamp.

---

### 5. Own Profile vs. Other User Profile

- When the authenticated user views their own profile (`auth_id() === $userId`), a `"Your Community Profile"` indicator badge is rendered in the profile header.
- When viewing another user's profile, the page is strictly read-only. Visitors cannot edit the profile, edit/delete posts, or access private data. Ownership enforcement remains governed by `CommunityPolicy`.

---

### 6. Bounded Pagination & Performance

- Bounded queries: per-page limit (default 10, max 50).
- Post pagination (`?page=N`) and comment pagination (`?tab=comments&comment_page=N`).
- Server-side SQL queries use parameterized PDO statements with indexed `WHERE user_id = ? AND status = 'active'` constraints. Zero N+1 queries.

---

### 7. Security Audit & Testing

#### Security Controls
- **XSS Protection**: All post titles, body snippets, and comment texts are HTML-escaped using `e()`.
- **IDOR & Authorization**: Visiting a profile does not elevate privileges. Policy gates (`canEdit`, `canDelete`) run server-side.
- **Nonexistent Users**: Invalid user IDs return 404 without database exception exposure.

#### Automated Test Suite
- Created [`tests/CommunityC6ATest.php`](file:///d:/PROJECTS/booksphere/tests/CommunityC6ATest.php) (**27 Passed assertions**):
  1. Public Profile Data & Privacy (exposes name/avatar/member_since; excludes email/password/remember_token).
  2. Nonexistent user 404 handling.
  3. Moderation filtering (hidden posts and comments excluded from public profile).
  4. Associated book & parent post links.
  5. Bounded pagination.
  6. Read-only authorization for visitors.

---

### 8. Database Changes

**NONE.** Reused existing `users`, `community_posts`, and `community_comments` tables without schema modifications or migrations.

---

### 9. Files Created & Modified

#### Files Created
- [`app/Views/community/profile.php`](file:///d:/PROJECTS/booksphere/app/Views/community/profile.php)
- [`tests/CommunityC6ATest.php`](file:///d:/PROJECTS/booksphere/tests/CommunityC6ATest.php)
- [`docs/PHASE_C6A_COMMUNITY_PROFILES.md`](file:///d:/PROJECTS/booksphere/docs/PHASE_C6A_COMMUNITY_PROFILES.md)

#### Files Modified
- [`app/Exceptions/CommunityException.php`](file:///d:/PROJECTS/booksphere/app/Exceptions/CommunityException.php)
- [`app/Repositories/CommunityPostRepository.php`](file:///d:/PROJECTS/booksphere/app/Repositories/CommunityPostRepository.php)
- [`app/Models/CommunityPost.php`](file:///d:/PROJECTS/booksphere/app/Models/CommunityPost.php)
- [`app/Repositories/CommunityCommentRepository.php`](file:///d:/PROJECTS/booksphere/app/Repositories/CommunityCommentRepository.php)
- [`app/Models/CommunityComment.php`](file:///d:/PROJECTS/booksphere/app/Models/CommunityComment.php)
- [`app/Services/CommunityService.php`](file:///d:/PROJECTS/booksphere/app/Services/CommunityService.php)
- [`app/Controllers/CommunityController.php`](file:///d:/PROJECTS/booksphere/app/Controllers/CommunityController.php)

#### Shared Files Modified
- NONE

#### Known Issues
- `LandingTest.php` pre-existing failure remains unchanged.

---

### 10. Final Verification Report

PHASE C6-A — COMPLETE

Community profile:
PASS

Public activity:
PASS

Moderation filtering:
PASS

Authorization:
PASS

Security:
PASS

Pagination:
PASS

Community tests:
PASS (8 community test suites passing 100%)

Full BookSphere test suite:
PASS (39 / 40 test suites passing, 1 pre-existing failure in LandingTest.php)

Database changes:
NONE

Regression:
ZERO NEW REGRESSIONS

Browser verification:
DEFERRED — local browser MCP unavailable

Files modified:
- app/Exceptions/CommunityException.php
- app/Repositories/CommunityPostRepository.php
- app/Models/CommunityPost.php
- app/Repositories/CommunityCommentRepository.php
- app/Models/CommunityComment.php
- app/Services/CommunityService.php
- app/Controllers/CommunityController.php

Shared files modified:
NONE

Known issues:
- LandingTest.php pre-existing failure remains unchanged.

Next recommended phase:
C6-B — Community Discovery
