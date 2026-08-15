# PHASE C7-B: USER FOLLOWING

## Relationship Architecture & Database Safety

BookSphere's existing `author_follows` table (migration `0022`) links user accounts to catalog author entities (`authors` table). Because user-to-user relationships between Community members did not exist in prior tables, a dedicated, minimal `community_follows` migration was proposed and approved in accordance with database safety constraints.

### Approved Database Table (`community_follows`)
**File**: `database/migrations/0037_create_community_follows_table.php`

```sql
CREATE TABLE community_follows (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    follower_id  INTEGER NOT NULL,
    following_id INTEGER NOT NULL,
    created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    FOREIGN KEY (follower_id)  REFERENCES users (id) ON DELETE CASCADE,
    FOREIGN KEY (following_id) REFERENCES users (id) ON DELETE CASCADE,
    UNIQUE (follower_id, following_id)
);

CREATE INDEX idx_community_follows_follower  ON community_follows (follower_id);
CREATE INDEX idx_community_follows_following ON community_follows (following_id);
```

- **Foreign Keys**: `ON DELETE CASCADE` ensures deleting a user account automatically cleans up incoming and outgoing follow records without orphans.
- **Engine Uniqueness**: `UNIQUE(follower_id, following_id)` guarantees duplicate follows cannot exist at the database level.
- **Indexes**: `idx_community_follows_follower` powers Following Feed filtering (`follower_id = ?`) in `O(log N)` time. `idx_community_follows_following` powers follower list & count queries.

---

## Core Features & Endpoints

### 1. Follow & Unfollow
- **Follow Endpoint**: `POST /community/user/{id}/follow`
- **Unfollow Endpoint**: `DELETE /community/user/{id}/follow` & `POST /community/user/{id}/unfollow`
- **Controller Action**: `CommunityController::followUser()` and `unfollowUser()`
- **Authentication**: Strict requirement (`auth_check()`). Foreign follower ID input is strictly forbidden; identity is taken exclusively from session `auth()->id()`.
- **Rate Limiting**: Protected via `RateLimiter` (`'community_follow'`, limit 60 actions / 60 seconds).

### 2. Self-Follow Prevention
- **Service & Policy Enforcement**: Server-side check blocks `$followerId === $followingId` with HTTP 400 (`"You cannot follow yourself."`). `CommunityPolicy::canFollowUser()` returns false for self-follow.

### 3. Duplicate Follow Protection
- Handled idempotently at the service layer (`CommunityFollow::follow()`) and enforced as a last line of defense via the database `UNIQUE(follower_id, following_id)` constraint.

### 4. Community Profile Integration & Privacy
- **URL**: `GET /community/user/{id}`
- **Counters**: Real `Followers: X` and `Following: Y` counters linking to follower/following lists.
- **Button State**: Renders `[ Follow ]` or `[ Following ] / [ Unfollow ]` for authenticated visitors viewing another member's profile.
- **Self Profile**: Suppresses follow/unfollow action button on user's own profile (`$isOwnProfile`).
- **Privacy**: Preserves strictly public Community metadata. Exposes zero private fields (no email, password hash, or moderation report data).

### 5. Paginated Followers & Following Lists
- **Followers List**: `GET /community/user/{id}/followers` (`app/Views/community/followers.php`)
- **Following List**: `GET /community/user/{id}/following` (`app/Views/community/following.php`)
- **Pagination**: Paginated (20 per page default, max 50). Avoids unlimited queries and N+1 loads.

### 6. Following Feed Tab
- **URL**: `GET /community?feed=following`
- **Behavior**: Filters active discussions (`status = 'active'`) to users followed by the current user (`p.user_id IN (SELECT following_id FROM community_follows WHERE follower_id = ?)`).
- **Fallback**: Unauthenticated guests requesting `feed=following` fall back cleanly to `feed=all`.
- **Preserved Controls**: Preserves all discovery sort options (`recent`, `popular`, `trending`), search queries (`q=`), book filters (`book_id=`), and pagination.
- **Moderation Safety**: Hidden and deleted posts (`status != 'active'`) are strictly excluded.

### 7. Notifications Integration
- Dispatches in-app confirmation notification to the followed user (`$targetUserId`) via existing `NotificationDispatcher` (type `'author_followed'`), titling `"New Follower"` and directing to `/community/user/{followerId}`. Self-notification is prevented.

### 8. Recommendation Integration Safety
- Recommendation scoring (Phase C6-E) remains isolated in this phase (zero modifications to recommendation scoring code).

---

## Final Status & Report

```text
PHASE C7-B — COMPLETE

Existing relationship system reused:
NO (author_follows connects users to catalog authors; user-to-user community_follows was created via approved migration 0037)

Database changes:
APPROVED (0037_create_community_follows_table.php)

Follow:
PASS

Unfollow:
PASS

Self-follow prevention:
PASS

Duplicate protection:
PASS

Follower counts:
PASS

Following counts:
PASS

Follower list:
PASS

Following list:
PASS

Following feed:
PASS

Rate limiting:
PASS

Security:
PASS

Notifications:
IMPLEMENTED (Dispatches notification ping to followed user via existing NotificationDispatcher)

Recommendation integration:
NOT IMPLEMENTED (Isolated in Phase C7-B as specified)

Community tests:
RESULT: PASS (All 13 Community test files passing 100%)

Full BookSphere test suite:
RESULT: PASS (44 / 45 test suites passing, 1 pre-existing failure in LandingTest.php)

Regression:
ZERO NEW REGRESSIONS

Browser verification:
DEFERRED — local browser MCP unavailable

Files modified:
- database/migrations/0037_create_community_follows_table.php (NEW)
- app/Models/CommunityFollow.php (NEW)
- app/Models/CommunityPost.php
- app/Repositories/CommunityPostRepository.php
- app/Policies/CommunityPolicy.php
- app/Services/CommunityService.php
- app/Controllers/CommunityController.php
- app/Views/community/profile.php
- app/Views/community/index.php
- app/Views/community/followers.php (NEW)
- app/Views/community/following.php (NEW)
- routes/web.php
- tests/CommunityC7BTest.php (NEW)
- docs/PHASE_C7B_USER_FOLLOWING.md (NEW)

Shared files modified:
- routes/web.php

Known issues:
- LandingTest.php pre-existing failure remains unchanged.

Next recommended phase:
C7-C — Book Discussion Hubs

STOP.

Do NOT automatically proceed to C7-C.
```
