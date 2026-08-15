# BookSphere ? Community Feature
## PHASE C3-B: HTTP LAYER ? CONTROLLERS + ROUTES ? COMPLETE

---

### 1. Core Objective

Phase C3-B exposes the completed Phase C3-A `CommunityService` and policies through a safe, isolated HTTP layer (Controllers and Routes).

In strict compliance with project directives:
- **UI Views added**: NONE (No HTML templates or CSS redesigns)
- **Sidebar/Header modified**: NO (Navigation untouched)
- **Existing BookSphere features modified**: NO
- **Existing database tables modified**: NO
- **Route conflicts**: ZERO (All endpoints scoped strictly under `/community`)

---

### 2. Created Architecture Components

#### Controller: [app/Controllers/CommunityController.php](file:///d:/PROJECTS/booksphere/app/Controllers/CommunityController.php)
- **Role**: Thin HTTP orchestration controller translating requests, verifying policy gates, calling `CommunityService`, and returning structured JSON or standard HTTP error responses.
- **Payload Parsing**: Supports both HTML form/URL-encoded inputs and `application/json` request bodies.
- **Error Exception Handling**: Translates `CommunityException` to explicit HTTP status codes:
  - `400 Bad Request`: General invalid input or target state.
  - `403 Forbidden`: Permission denied (editing/deleting foreign content, liking/reporting own content).
  - `404 Not Found`: Non-existent post, comment, or linked book.
  - `409 Conflict`: Duplicate / conflicting operation.
  - `422 Unprocessable Entity`: Validation failure (empty title, body length violation, invalid reason enum).

---

### 3. Registered Routes ([routes/web.php](file:///d:/PROJECTS/booksphere/routes/web.php))

| HTTP Method | Route Path | Action | Middleware | Access Level |
| :--- | :--- | :--- | :--- | :--- |
| `GET` | `/community` | `CommunityController::index` | `SecureHeaders` | Public |
| `GET` | `/community/post/{id}` | `CommunityController::show` | `SecureHeaders` | Public |
| `GET` | `/community/posts/{id}/comments` | `CommunityController::comments` | `SecureHeaders` | Public |
| `GET` | `/community/book/{id}` | `CommunityController::bookPosts` | `SecureHeaders` | Public |
| `GET` | `/community/user/{id}` | `CommunityController::userPosts` | `SecureHeaders` | Public |
| `POST` | `/community/posts` | `CommunityController::storePost` | `Auth`, `Csrf` | Authenticated |
| `PATCH` / `POST` | `/community/posts/{id}` | `CommunityController::updatePost` | `Auth`, `Csrf` | Authenticated (Author/Admin) |
| `DELETE` / `POST` | `/community/posts/{id}` | `CommunityController::destroyPost` | `Auth`, `Csrf` | Authenticated (Author/Admin) |
| `POST` | `/community/posts/{id}/comments` | `CommunityController::storeComment` | `Auth`, `Csrf` | Authenticated |
| `PATCH` / `POST` | `/community/comments/{id}` | `CommunityController::updateComment` | `Auth`, `Csrf` | Authenticated (Author/Admin) |
| `DELETE` / `POST` | `/community/comments/{id}` | `CommunityController::destroyComment` | `Auth`, `Csrf` | Authenticated (Author/Admin) |
| `POST` | `/community/posts/{id}/like` | `CommunityController::like` | `Auth`, `Csrf` | Authenticated (Non-Author) |
| `DELETE` / `POST` | `/community/posts/{id}/like` | `CommunityController::unlike` | `Auth`, `Csrf` | Authenticated |
| `POST` | `/community/posts/{id}/report` | `CommunityController::reportPost` | `Auth`, `Csrf` | Authenticated (Non-Author) |
| `POST` | `/community/comments/{id}/report` | `CommunityController::reportComment` | `Auth`, `Csrf` | Authenticated (Non-Author) |

---

### 4. Security, Auth, CSRF & Validation Enforcements

1. **Authentication**: `AuthMiddleware` verifies session identity via `auth()->id()`. Browser-supplied user IDs are ignored; identity comes strictly from session state.
2. **CSRF Protection**: All state-changing routes (`POST`, `PATCH`, `DELETE`) are wrapped with `CsrfMiddleware`, requiring valid `_token` in POST body or `X-CSRF-TOKEN` header.
3. **Authorization & IDOR Shield**: Ownership is verified via `CommunityPolicy`. Users cannot edit/delete another user's post or comment, nor can they like or report their own content.
4. **Server Validation**:
   - Post Title: Required, max 120 chars.
   - Post Body: Required, 10?10,000 chars.
   - Comment Body: Required, 1?2,000 chars.
   - Book ID: Optional; if supplied, must reference an existing book row.
   - Report Reason: Validated against C2 database schema ENUM (`Spam`, `Harassment`, `Offensive Content`, `False Information`, `Duplicate`, `Other`).

---

### 5. Dedicated Test Suite ([tests/CommunityHttpTest.php](file:///d:/PROJECTS/booksphere/tests/CommunityHttpTest.php))

A new 23-check HTTP test runner was created and executed:
- **Public Access**: Feed list, single post show, post comments list, book-linked posts, user posts.
- **Auth & CSRF**: Unauthenticated guest redirection, invalid CSRF token 419 error.
- **Post CRUD**: Create post (201), reject empty title (422), reject invalid book (404), update own post (200), reject editing foreign post (403), delete post (200).
- **Comment CRUD**: Create comment (201), reject empty comment (422), edit comment (200), delete comment (200).
- **Likes**: Author self-like rejection (403), other user like (200), duplicate like idempotence (200), unlike (200).
- **Reports**: Report post (201), reject invalid reason (422).

**Test Results**: **`23 PASS / 0 FAIL`** ?

---

### 6. Test Suite & Regression Summary

- **Baseline Test Suite**: 31 PASS / 1 FAIL (`LandingTest.php` ? pre-existing text mismatch, unchanged).
- **Phase C3-A Core Suite**: 66 PASS / 0 FAIL (`CommunityTest.php`).
- **Phase C3-B HTTP Suite**: 23 PASS / 0 FAIL (`CommunityHttpTest.php`).
- **Full Test Suite Status**: 33 PASS / 1 FAIL (`LandingTest.php` ? PRE-EXISTING FAILURE).
- **NEW REGRESSIONS**: **ZERO** ?

---

### 7. Final Status

```
PHASE C3-B ? COMPLETE
HTTP LAYER ? CONTROLLERS + ROUTES COMPLETE
ZERO NEW REGRESSIONS
```

> **STOP. DO NOT automatically proceed to C4.**
> **The next phase is: C4 ? Community UI / Core Experience**
> **Awaiting explicit user instruction before proceeding.**
