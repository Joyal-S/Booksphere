# BookSphere ? Community Feature
## PHASE C3-A: MODELS + DATA ACCESS + SERVICE LAYER ? COMPLETE

---

### 1. Core Objective

Phase C3-A establishes the backend domain, data access, policy, and service layer foundation for the BookSphere Community module. 

In strict compliance with the project directives:
- **Routes added**: NONE
- **Controllers added**: NONE
- **Views added**: NONE
- **Sidebar changed**: NO
- **Existing database tables modified**: NO
- **Existing application features modified**: NO
- **Existing core files modified**: NONE

---

### 2. Architecture & Design Decisions Followed (C1 & C2 Audit Alignment)

1. **Architecture Pattern**: `Controller -> Service -> Model (facade) -> Repository (SQL) -> PDO -> SQLite`.
2. **Model Layer**: Thin facades exposing domain operations without direct SQL or business rules.
3. **Repository Layer**: Encapsulates all SQL with prepared statements, single-statement JOINs (preventing N+1 queries), explicit column projections, and ISO-8601 UTC timestamps (`gmdate('Y-m-d\TH:i:s\Z')`).
4. **Service Layer**: Owns domain rules, validation, remapping of PDO constraint violations (e.g. duplicate likes SQLSTATE `23000`), and logging. Accepts explicit `actorId` for testability and non-session contexts.
5. **Policy Layer**: Read-only authorization gates leveraging `$actorId ?? auth()?->id()` and role checks.
6. **Exception Layer**: Single domain exception (`CommunityException`) with static named factory methods.

---

### 3. Models Created

All models are located in `app/Models/`:

| Model Class | Repositories / Facades | Key Features & Relationships |
| :--- | :--- | :--- |
| `CommunityPost.php` | `CommunityPostRepository` | CRUD, `findActive`, `findByBook`, `findByUser`, `author()` (User), `book()` (Book, nullable) |
| `CommunityComment.php` | `CommunityCommentRepository` | CRUD, `findByPost`, `author()` (User), `post()` (CommunityPost) |
| `CommunityLike.php` | `CommunityLikeRepository` | `create`, `delete`, `exists`, `count` |
| `CommunityReport.php` | `CommunityReportRepository` | `create`, `updateStatus`, `findPending`, `countPending`, `findByPost`, `findByComment` |

---

### 4. Data-Access / Repository Classes Created

All repositories are located in `app/Repositories/`:

| Repository Class | Target Table | Projection & Features |
| :--- | :--- | :--- |
| `CommunityPostRepository.php` | `community_posts` | Single-query JOIN for author name, book title, active `comment_count`, and `like_count`. Prepared statements everywhere. |
| `CommunityCommentRepository.php` | `community_comments` | JOIN author display name. Chronological sorting (`created_at ASC`). |
| `CommunityLikeRepository.php` | `community_likes` | Idempotent delete for pairs, index-backed `exists()` and `count()`. |
| `CommunityReportRepository.php` | `community_reports` | Moderation queue queries (`findPending`, `countPending`), status updates. |

---

### 5. Service & Policy Classes Created

| Class | Location | Primary Responsibilities |
| :--- | :--- | :--- |
| `CommunityService.php` | `app/Services/` | Domain logic for posts, comments, likes, reports; input validation; remapping SQL exceptions; audit logging. |
| `CommunityPolicy.php` | `app/Policies/` | Fine-grained authorization gates (`canViewFeed`, `canCreatePost`, `canEdit`, `canDelete`, `canComment`, `canLike`, `canReport`, `canModerate`). |
| `CommunityException.php` | `app/Exceptions/` | Static factory exceptions (`postNotFound`, `commentNotFound`, `bookNotFound`, `permissionDenied`, `invalidInput`, `duplicateLike`, `invalidTarget`, `invalidReason`). |

---

### 6. Summary of Implemented Service Methods

#### Posts
- `createPost(int $actorId, array $data): int`
- `getPost(int $postId): array`
- `listPosts(int $page = 1, int $perPage = 20): array`
- `listPostsForBook(int $bookId, int $page = 1, int $perPage = 20): array`
- `listPostsByUser(int $userId, int $page = 1, int $perPage = 20): array`
- `updatePost(int $actorId, int $postId, array $data): bool`
- `deletePost(int $actorId, int $postId): bool`

#### Comments
- `createComment(int $actorId, int $postId, array $data): int`
- `listComments(int $postId, int $limit = 100): array`
- `updateComment(int $actorId, int $commentId, array $data): bool`
- `deleteComment(int $actorId, int $commentId): bool`

#### Likes
- `likePost(int $actorId, int $postId): int` *(Silently idempotent: returns 0 if already liked)*
- `unlikePost(int $actorId, int $postId): bool` *(Idempotent: returns false if like did not exist)*
- `hasUserLikedPost(int $actorId, int $postId): bool`
- `getLikeCount(int $postId): int`

#### Reports
- `reportPost(int $actorId, int $postId, array $data): int`
- `reportComment(int $actorId, int $commentId, array $data): int`
- `pendingReports(int $limit = 50): array`
- `pendingReportCount(): int`

---

### 7. Validation Rules (Server-Side Authoritative)

- **Post Title**: Required, non-empty after trim, max 120 characters (`TITLE_MAX`).
- **Post Body**: Required, min 10 characters (`BODY_MIN`), max 10,000 characters (`BODY_MAX`).
- **Comment Body**: Required, min 1 character (`COMMENT_MIN`), max 2,000 characters (`COMMENT_MAX`).
- **Book Link**: Optional; if supplied, must reference an existing book in the catalogue.
- **Status**: Must belong to `['active', 'hidden', 'deleted']`.
- **Report Reasons**: Must be one of `['Spam', 'Harassment', 'Offensive Content', 'False Information', 'Duplicate', 'Other']`.
- **Report Statuses**: Must be one of `['pending', 'reviewed', 'dismissed', 'resolved']`.

---

### 8. Ownership & Authorization Rules

- **Post / Comment Edit & Delete**: Allowed only if `$actorId` is the author of the content OR `$actorId` is an admin.
- **Likes**: Users cannot like their own posts (`CommunityPolicy::canLike`).
- **Reports**: Users cannot report their own posts/comments (`CommunityPolicy::canReport`).
- **Moderation**: Queue access and status updates restricted to admins (`CommunityPolicy::canModerate`).

---

### 9. Focused Test Suite (tests/CommunityTest.php)

A focused test suite covering 66 checks was built and executed:
- **Repositories & Models**: CRUD verification for all 4 entities, display column joins, and primary key lookups.
- **Relationships**: Post -> Author, Post -> Book, Comment -> Post, Comment -> Author.
- **Service Posts**: Create valid post, reject empty title, long title, short body, non-existent book ID, list posts, list by book, list by user, update own post, reject update by other user, admin update override, delete own post, reject delete by other user.
- **Service Comments**: Create valid comment, reject empty/long comment, list comments, update own, reject update by other, delete own, reject delete by other.
- **Service Likes**: Initial false state, like post, `hasUserLikedPost` true, like count increase, duplicate like idempotence (returns 0 without count increase), unlike post, count decrease, unlike non-existent (returns false).
- **Service Reports**: Report valid post, report valid comment, reject invalid post/comment target, reject invalid reason enum, pending reports count.
- **Policy Matrix**: Feed view, create post, author edit, non-author edit rejection, self-like rejection, self-report rejection, admin override.

**Result**: `66 PASS / 0 FAIL` ?

---

### 10. Test Suite Results & Regression

- **Baseline Test Result**: 31 PASS / 1 FAIL (`LandingTest.php` ? pre-existing text mismatch, unchanged since C1).
- **Focused Community Test Suite**: 66 PASS / 0 FAIL.
- **Final Full Test Suite Run**: 32 PASS (including `CommunityTest.php`) / 1 FAIL (`LandingTest.php` ? PRE-EXISTING FAILURE).
- **NEW REGRESSIONS**: **ZERO** ?

---

### 11. Known Limitations & Next Steps

- **No HTTP Controllers or Routes**: Backend logic is complete but intentionally unreachable via web request until Phase C3-B.
- **No Views or Navigation**: Sidebar, header, and frontend components remain unchanged.
- **Notifications Hook**: Social notifications (e.g. pinging post author on comment/like) deferred to Phase C5 per blueprint architecture.

---

### 12. Final Status

```
PHASE C3-A ? COMPLETE
MODELS + DATA ACCESS + SERVICE LAYER COMPLETE
ZERO NEW REGRESSIONS
```

> **STOP. DO NOT automatically start C3-B.**
> **The next phase is: C3-B ? COMMUNITY HTTP LAYER**
> **Awaiting explicit user instruction before proceeding.**
