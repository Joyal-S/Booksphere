# Phase 9.1 — Follow Authors & Notification System: Architecture Blueprint

> **Status:** Design only. Nothing in this phase is implemented.
> **Next phase:** Phase 9.2 implements this blueprint (migrations, models,
> services, controllers, routes, views, JS and tests) exactly as specified.
> **Constraint:** no new frameworks or dependencies; every decision follows
> the existing project conventions — the Stack (Controller → Service → Model
> facade → Repository → PDO → SQLite), a module-owned exception + policy +
> request set, and one shared service instance per module wired in
> `routes/web.php`.

---

## Conventions this design reuses (do not re-invent)

| Concern | Existing pattern | Blueprint rule |
|---|---|---|
| Data access | `UserLibrary`/`Review` facade + `LibraryRepository`/`ReviewRepository` | `AuthorFollow` / `Notification` facades over dedicated repositories |
| Business rules | `LibraryService`, `ReviewService` (single service, guards, lifecycle, logger) | `FollowService` + `NotificationService` (+ formatter / dispatcher) |
| Module failure type | `LibraryException`, `ReviewException` with `::static` factories | `FollowException`, `NotificationException` |
| Fine authorization | `LibraryPolicy`, `ReviewPolicy` (guest→`canAccess`, owner, admin-view) | `FollowPolicy`, `NotificationPolicy` |
| Request rules | `StoreLibraryRequest::validate($data)->errors()` | `FollowRequest`, `NotificationReadRequest` |
| Write throttle | `RateLimiter(session())` on write routes | follow/unfollow + every read toggle |
| Timestamps | `strftime('%Y-%m-%dT%H:%M:%SZ','now')` + repository `now()` (`gmdate`) | identical |
| Routing | literal-first `get`/`post` in `routes/web.php`, `SecureHeaders`, `AuthMiddleware`, `CsrfMiddleware` | plus tiny `patch()`/`delete()` Router mirrors |
| JSON vs form | one route answers both: `X-Requested-With: fetch` → `Response::json()`, plain POST → redirect (+ flash) | identical on every endpoint below |

> **Router note (small, self-contained Phase 9.2 task).** `Router.php`
> currently exposes only `get()` and `post()` (both funnel into a private
> `register()`). The blueprint uses real `PATCH`/`DELETE` semantics, so Phase
> 9.2 adds two 6-line public methods `patch()` and `delete()` that mirror
> `post()` exactly, plus an HTML `_method` override in `Request::method()`
> (when `method() === 'POST'` and `input('_method')` is `PATCH`/`DELETE`).
> This is additive: every existing `post()` route keeps working unchanged.

---

## Task 1 — Database design

**Which tables does the project need?** Exactly four — two core and two
supporting:

- **`author_follows`** (core) — the follow relationship.
- **`notifications`** (core) — the persistent in-app notification history.
- **`notification_preferences`** (supporting) — per-user, per-category
  opt-outs (today: silence a type; later: gate email/push).
- **`notification_deliveries`** (supporting) — the channel outbox that
  makes future email/push purely additive (created empty in 9.2 so the
  module's own tables never need an ALTER).

### Relationships (ERD)

```
  users 1 ──────── n author_follows n ──────── 1 authors
  users 1 ──────── n notifications            (type TEXT, no FK)
  users 1 ──────── 1 notification_preferences
  notifications 1 ── n notification_deliveries n ── 1 users
```

`authors` has **no soft-delete column** (migration 0003: id, name,
biography, photo, created_at), so a removed author is a hard delete and
`ON DELETE CASCADE` on `author_id` cleans the follow rows. `users` is also
hard-deleted by the auth module, so `ON DELETE CASCADE` on `user_id` is the
only sane rule for all four tables — no orphan rows, ever.

### Table 1 — `author_follows`

| Column | Type | Notes |
|---|---|---|
| `id` | INTEGER PRIMARY KEY AUTOINCREMENT | |
| `user_id` | INTEGER NOT NULL | FK `users.id` ON DELETE CASCADE |
| `author_id` | INTEGER NOT NULL | FK `authors.id` ON DELETE CASCADE |
| `created_at` | TEXT NOT NULL DEFAULT `now()` | UTC ISO-8601 |

**Constraints**
- `UNIQUE (user_id, author_id)` — **the duplicate-prevention rule**: a user
  can follow an author once. Two simultaneous POSTs race past the service
  guard only to be stopped here; the service translates the constraint
  violation into `FollowException::duplicate()`.
- `CHECK (user_id != author_id)` is impossible across two tables, so **"you
  cannot follow yourself" is a service rule** (Task 10).

**Indexes**
- `idx_author_follows_user` on `(user_id)` — every "who does this user
  follow" read.
- `idx_author_follows_author` on `(author_id)` — "who follows this author",
  the follower list and the `COUNT(*)` follower statistic.
- The `UNIQUE(user_id, author_id)` index doubles as a covering index for the
  user-prefixed pair lookups.

**Queries it powers (joins, no N+1)**
- Following list → `WHERE f.user_id = ? JOIN authors a ON a.id = f.author_id`.
- Follower list → `WHERE f.author_id = ? JOIN users u ON u.id = f.user_id`.
- Follower count → `SELECT COUNT(*) FROM author_follows WHERE author_id = ?`.
- Follow state → `SELECT id FROM author_follows WHERE user_id=? AND author_id=?`.
- Unfollow → `DELETE WHERE user_id=? AND author_id=?`.

### Table 2 — `notifications`

Rows are immutable after insert except the read flag (`read_at` covers that),
so there is **no `updated_at`**.

| Field | Type | Notes |
|---|---|---|
| `id` | INTEGER PRIMARY KEY AUTOINCREMENT | |
| `user_id` | INTEGER NOT NULL | FK `users.id` ON DELETE CASCADE — the recipient |
| `type` | TEXT NOT NULL | one of the catalog keys (Task 5); validated in the service, `CHECK (type IN (…))` as the last line |
| `title` | TEXT NOT NULL | short line produced by the formatter |
| `message` | TEXT NOT NULL DEFAULT '' | expanded copy; may embed another user's full_name — **always `e()` on render** |
| `icon` | TEXT NOT NULL | Font Awesome 6.5.2 class, e.g. `fa-solid fa-user-plus` |
| `color` | TEXT NOT NULL | accent token — `primary` / `info` / `success` / `warning` / `danger`; the view maps the token to a CSS class |
| `action_url` | TEXT | relative path the row opens, e.g. `/books/7`; NULL = no jump |
| `is_read` | INTEGER NOT NULL DEFAULT 0 | `CHECK (is_read IN (0,1))` |
| `read_at` | TEXT | NULL until marked read |
| `created_at` | TEXT NOT NULL DEFAULT `now()` | UTC ISO-8601 |

**Indexes**
- `idx_notifications_user` on `(user_id)` — every per-user read.
- `idx_notifications_user_read` on `(user_id, is_read)` — the unread tab,
  the unread count AND the badge (one covering index).
- `idx_notifications_created` on `(created_at)` — history pagination and the
  global prune sweep.

**Bulk creation without N+1** (e.g. "an author you follow released a book"):
one statement per event —

```sql
INSERT INTO notifications (user_id, type, title, message, icon, color, action_url, created_at)
SELECT f.user_id, 'author_new_release', ?, ?, ?, ?, ?, ?
FROM author_follows f
WHERE f.author_id = ?
```
— as many rows as followers, one round trip.

### Table 3 — `notification_preferences` (supporting)

One row per user, upserted with the standard
`INSERT … ON CONFLICT (user_id) DO UPDATE` pattern (exactly like
`library/preferences`, migration 0018).

| Field | Type | Notes |
|---|---|---|
| `user_id` | INTEGER PRIMARY KEY | FK `users.id` ON DELETE CASCADE |
| `author_followed` | INTEGER NOT NULL DEFAULT 1 | `CHECK IN (0,1)` — your own confirmation ping |
| `author_activity` | INTEGER NOT NULL DEFAULT 1 | new book / new review bump of a followed author |
| `community` | INTEGER NOT NULL DEFAULT 1 | "helpful" / reply on your review |
| `recommendations` | INTEGER NOT NULL DEFAULT 1 | "your shelf refreshed" ping |
| `wishlist_reminders` | INTEGER NOT NULL DEFAULT 1 | wishlist nudges (later phase) |
| `system_announcements` | INTEGER NOT NULL DEFAULT 1 | admin broadcasts |
| `updated_at` | TEXT NOT NULL | |

**Rule:** a preference only gates *auto-generated* notifications (bulk
fan-out). Explicit transactional rows (`system_announcement`) still deliver.

### Table 4 — `notification_deliveries` (supporting, empty in 9.2)

The reserved channel outbox. Created in 9.2 with zero rows so that email and
push arrivals are purely additive later — the module's own tables never get
ALTERed.

| Field | Type | Notes |
|---|---|---|
| `id` | INTEGER PRIMARY KEY AUTOINCREMENT | |
| `notification_id` | INTEGER NOT NULL | FK `notifications.id` ON DELETE CASCADE |
| `user_id` | INTEGER NOT NULL | FK `users.id` ON DELETE CASCADE |
| `channel` | TEXT NOT NULL | `email ` \| `push` \| `in_app` |
| `status` | TEXT NOT NULL | `pending` \| `sent` \| `failed` |
| `sent_at` | TEXT | |
| `error` | TEXT | retry / failure note |

Indexes `(notification_id)` and `(user_id, status)`.

**Migration numbering (the next four integers, in order):**
1. `0022_create_author_follows_table`
2. `0023_create_notifications_table`
3. `0024_create_notification_preferences_table`
4. `0025_create_notification_deliveries_table`

> **Shipping rule for 9.2:** 0022–0024 ship with data; 0025 ships empty —
> the outbox exists from the start so a later email phase is purely additive.

---

## Task 2 — Models

Every model is a **thin facade** (no business logic, no SQL in the class) —
the exact shape of `UserLibrary.php` / `Review.php` (facade → repository →
PDO). Models return plain associative arrays, like the whole project.

### `AuthorFollow` (facade over `AuthorFollowRepository`)

- **Responsibilities:** the public API of the follow data layer — nothing
  more. All rules live in `FollowService`.
- **Methods (each one delegates to the repository):**
  - `create(array $data): int` — insert a follow row.
  - `delete(int $id): bool` — delete by row id.
  - `find(int $id): ?array` — one row by id.
  - `exists(int $userId, int $authorId): bool` — duplicate / state check.
  - `isFollowing(int $userId, int $authorId): bool` — alias of `exists`
    under the name the author page reads.
  - `deleteForPair(int $userId, int $authorId): bool` — unfollow by pair
    (no `SELECT` first — the DELETE itself is the answer).
  - `findForUser(int $userId, int $limit = 50): array` — the user's followed
    authors, joined with the author display columns (name, photo, book
    count).
  - `findFollowersOf(int $authorId, int $limit = 50): array` — the author's
    followers, joined with the user display columns (id, full_name).
  - `followerCount(int $authorId): int` — the author follower statistic.
- **Relationship helpers (belongsTo, resolved on demand, like
  `UserLibrary::book()`):**
  - `author(array $follow): ?array` — the followed author row.
  - `user(array $follow): ?array` — the following user row.
- **Validation:** none (the service owns it); the repository contract
  enforces `int` ids and prepared statements.
- **Dependencies:** `AuthorFollowRepository` (SQL), `Author`, `User` (for the
  relationship helpers only).

### `Notification` (facade over `NotificationRepository`)

- **Responsibilities:** the public API of the notification data layer.
- **Methods:**
  - `create(array $data): int` — one row (system / admin announcements).
  - `createForRecipients(array $rows): void` — batched rows (the
    `INSERT … SELECT` fan-out of Task 1).
  - `find(int $id): ?array` — one row by id.
  - `findOwnedBy(int $id, int $userId): ?array` — row scoped to its owner
    (the only lookup the controller may use — no IDOR surface).
  - `forUser(int $userId, string $tab = 'all', int $offset = 0, int $limit = 50): array`
    — one page of the center (`all` / `unread` / `read` tabs).
  - `countForUser(int $userId, string $tab = 'all'): int` — pagination
    denominator.
  - `unreadCount(int $userId): int` — the badge number.
  - `markRead(int $id, int $userId): bool` — set `is_read=1`, stamp
    `read_at` (idempotent).
  - `markAllRead(int $userId): int` — the bulk toggle, returns the number
    actually changed.
  - `deleteOwnedBy(int $id, int $userId): bool` — owner-scoped delete.
  - `deleteAll(int $userId): int` — "clear history" (deletes rows, keeps
    unread count at zero naturally).
- **Relationship helper:** `user(array $row): ?array` — the recipient row.
- **Validation:** none (service); `type` is validated service-side and by the
  table CHECK.
- **Dependencies:** `NotificationRepository`, `User` (relationship helper).

### Reused models (no changes)

- `Author` — `findById()` (exists checks for follow targets), `all()`.
- `User` — `findById()` (relationship helpers, admin broadcast recipients).

> **Deliberate choice:** notifications store **formatted content**
> (`title`, `message`, `icon`, `color`, `action_url`) at write time, not
> template references. Rendering therefore never needs a JOIN on the author
> or book tables and cannot break when a source row is later edited or
> deleted (history stays truthful).

---

## Task 3 — Services

One orchestrator per module plus two dedicated collaborators:

```
  FollowService            NotificationService
       │                          │
       │ emits events             │ reads/writes rows + preferences
       ▼                          ▼
  NotificationDispatcher ──► NotificationFormatter (pure, no deps)
       │                          │
       ▼                          ▼
  NotificationRepository / AuthorFollowRepository (via facades)
```

### `FollowService` (module orchestrator)

- **Responsibilities:** every follow rule and the follow-facing statistics.
- **Methods:**
  - `follow(int $userId, int $authorId): int` — rules: author must exist
    (`FollowException::authorNotFound`), user must not follow themselves
    (`FollowException::cannotFollowSelf`), no duplicate row
    (`FollowException::duplicate`); creates the row, logs
    `follow.created`, returns the new row id.
  - `unfollow(int $userId, int $authorId): bool` — idempotent (removing a
    non-existent follow is a silent `false`, safe for double-clicks); logs
    `follow.deleted`.
  - `isFollowing(int $userId, int $authorId): bool` — the author page's
    button state.
  - `followingList(int $userId, int $limit = 50): array` — for
    `/profile/following`.
  - `followersList(int $authorId, int $limit = 50): array` — for the
    author page followers modal.
  - `followerCount(int $authorId): int` — the statistic shown on the author
    page (backed by `idx_author_follows_author`).
  - `authorExists(int $authorId): bool` — shared guard.
- **Dependencies:** `AuthorFollow` facade, `Author` facade, `Logger`
  (optional, default application log — the standard constructor pattern).
- **Notification hook:** `follow()` also tells the dispatcher
  `notify('author_followed', ['user_id' => $userId, 'author_id' => …])` —
  the *actor* receives the confirmation row (if not opted out).

### `NotificationService` (module orchestrator)

- **Responsibilities:** the notification center's reads and state changes,
  type allow-listing, preference gating, retention rules.
- **Methods:**
  - `notifyFor(int $userId, string $type, array $context, bool $force = false): int`
    — one recipient (system, admin). Honors the preference table unless
    `$force` (announcements). Returns the new row id.
  - `fanOut(string $type, array $context, array $recipientUserIds): int` —
    the batched `INSERT … SELECT` path for events with many recipients.
  - `page(int $userId, string $tab, int $page = 1, int $perPage = 25): array`
    — the paginate() payload (`items`, `total`, `pages`, …) in the exact
    shape of `LibraryRepository::paginate()`.
  - `unreadCount(int $userId): int` — the badge.
  - `markRead(int $id, int $userId): bool` — idempotent; logs
    `notification.read`.
  - `markAllRead(int $userId): int` — bulk; logs `notification.read_all`.
  - `delete(int $id, int $userId): bool` / `deleteAll(int $userId): int`.
  - `preferences(int $userId): array` — the seven toggles (defaults when no
    row exists).
  - `updatePreference(int $userId, string $category, bool $enabled): void` —
    upsert; unknown category ignored.
  - `types(): array` — the type catalog keys the controller / formatter can
    enumerate (single source of truth).
- **Dependencies:** `Notification` facade, `NotificationFormatter`,
  `NotificationDispatcher`, `Logger`.

### `NotificationDispatcher` (creation orchestration)

- **Responsibilities:** the single door through which *any* module creates
  notifications, so no service ever talks to the notification tables
  directly:
  1. resolve the type against the catalog (unknown type →
     `NotificationException::invalidType`),
  2. ask the formatter for `[title, message, icon, color, action_url]`,
  3. filter recipients through `notification_preferences`,
  4. write the rows (single or batched), `INSERT`ing `is_read = 0`,
  5. enqueue channel rows in `notification_deliveries` (no-op in 9.2 — the
     table is empty; the hook is a 5-line stub so email/push plug in later),
  6. log `notification.created` with type + recipient count.
- **Dependencies:** `Notification` facade, `NotificationFormatter`,
  `NotificationRepository` (via the facade), `Logger`.
- **Interaction contract:** every other service calls
  `$dispatcher->notify(type, context)` or `$dispatcher->fanOut(type, context,
  recipients)` — never the repository. This keeps the event surface stable:
  the recommendation engine, the review module, the library module and the
  future email/push phase all speak to one dispatcher.

### `NotificationFormatter` (pure, deterministic)

- **Responsibilities:** type key + context array → content array. No I/O, no
  state — a plain function table, easy to unit-test exhaustively.
- **Method:** `format(string $type, array $context): array` returning
  `['title', 'message', 'icon', 'color', 'action_url']`.
- **Per-type templates** live in a `const TEMPLATES` map (the Task 5
  catalog); a missing type raises `NotificationException::invalidType()`.
  All user-supplied names enter the message only through `{placeholders}`
  that the view escapes at render time.

### Interactions between the services

```
ReviewService (review approved / helpful mark)
        │  ReviewService::afterWrite() style hook (already logs)
        ▼
NotificationDispatcher::notify('review_reacted', {review_id, book_id, actor_name})
        │  → Formatter::format() → content
        │  → preferences filter → INSERT notifications (is_read = 0)
        ▼
NotificationService::page() / unreadCount() / markRead()   ← NotificationController

FollowService::follow()
        │  same dispatcher door → 'author_followed' row for the actor
        ▼
AuthorController page reads FollowService::followerCount() / isFollowing()
```

> **Why three classes and not one?** `NotificationService` owns the
> *user-facing* center; `NotificationDispatcher` owns *creation events*;
> `NotificationFormatter` owns *content*. Any of the three can grow (email
> channel, new types, filters) without touching the other two — that is the
> "no major refactoring" guarantee.
---

## Task 4 — Controllers

Controllers stay thin (translate request → DTO, ask the policy, call the
service, answer). All write endpoints answer both fetch (JSON) and plain
forms (redirect), exactly like the review engagement endpoints
(`X-Requested-With: fetch` → `Response::json()`).

### `AuthorController` additions (follow/unfollow on the author page)

- `show()` — extended to pass `followed` (bool) and `followers` (int:
  `FollowService::followerCount()`) for the logged-in user.
- `follow(Request $request, array $params)` — `POST /authors/{id}/follow`.
  Policy `FollowPolicy::canManage(?authorId)`; service `follow()`; JSON
  `{following: true}` or redirect back to the author page.
- `unfollow(Request $request, array $params)` — `DELETE /authors/{id}/follow`
  (form fallback accepts `POST` + `_method=DELETE`). Same gate, returns
  `{following: false}`.

> The page itself still renders `AuthorController::show` — the follow button
> lives on it; the two writes are thin siblings.

### `NotificationController` (new — the notification center)

- `index(Request $request, array $params)` — `GET /notifications?tab=all|unread|read&page=N`.
  Renders `notifications/index` with the `NotificationService::page()`
  payload.
- `unreadCount(Request $request)` — `GET /notifications/unread-count`.
  `Response::json(['count' => int])` for the navbar badge (fetch).
- `markRead(Request $request, array $params)` — `PATCH /notifications/{id}`
  (POST fallback). Owner-scoped (`findOwnedBy`), idempotent.
- `markAllRead(Request $request)` — `PATCH /notifications/read-all`
  (POST fallback). Owner-scoped bulk.
- `destroy(Request $request, array $params)` — `DELETE /notifications/{id}`
  (POST fallback).
- `clearHistory(Request $request)` — `DELETE /notifications` (POST
  fallback) — deletes the user's whole history.
- `updatePreference(Request $request)` — `POST /notifications/preferences`
  — the seven toggles (checkbox form).

JSON responses for the toggles: `{ ok: true, unread: n }` (the badge re-fills
after every mutation); plain POSTs redirect back to the referrer with a flash.

### `UserController` additions (following/followers lists)

- `following(Request $request)` — `GET /profile/following` — the user's
  followed authors, rendered via `FollowService::followingList()`.
- `followers(Request $request)` — `GET /profile/followers` — the user's
  followers (owner-scoped; a personal list like the library — admins may
  **view** but not manage).

> No dedicated `FollowController` / `NotificationAdminController` yet: the
> follow surface is small and stays in `AuthorController`; the admin
> broadcast is a Phase 9.3+ concern (design reserves the
> `system_announcement` type and the `AdminMiddleware` route, listed in
> Task 6).

---

## Task 5 — Notification types

Each type guarantees `title`, `message`, `icon`, `color`, `action_url`,
`created_at`, `is_read` — the seven properties the `notifications` row stores
(`created_at`/`is_read` are DB columns, the rest come from the formatter).
Icons are Font Awesome 6.5.2 classes; colors are the app's tone tokens.

| # | `type` key | Trigger | Title pattern | Message pattern | Icon | Color | Action |
|---|---|---|---|---|---|---|---|
| 1 | `author_followed` | any user follows an author | "Following {Author}" | "You started following {Author}." | `fa-solid fa-user-plus` | `primary` | `/authors/{id}` |
| 2 | `author_new_release` | a followed author's book is added | "{Author} published" | "{Book} by {Author} is here." | `fa-solid fa-book` | `info` | `/books/{id}` |
| 3 | `review_reacted` | someone marks your review helpful | "Review appreciated" | "{Actor} found your review of {Book} helpful." | `fa-solid fa-thumbs-up` | `success` | `/books/{id}/reviews` |
| 4 | `review_replied` (reserved) | a reply arrives on your review (future replies module) | "New reply on your review" | "{Actor} replied to your review of {Book}." | `fa-solid fa-comment` | `info` | `/reviews/{id}` |
| 5 | `recommendation_ready` | the personalised shelf is refreshed | "Your picks are ready" | "New recommendations based on your library." | `fa-solid fa-wand-magic-sparkles` | `success` | `/recommendations` |
| 6 | `wishlist_reminder` | a book on the user's wishlist is back / due (later phase) | "Wishlist reminder" | "{Title} is waiting in your wishlist." | `fa-solid fa-bell` | `warning` | `/library` |
| 7 | `library_milestone` | the user finishes a book | "Library milestone" | "You finished {Title}. Well read!" | `fa-solid fa-trophy` | `success` | `/library` |
| 8 | `system_announcement` | admin broadcast to all users | (admin-supplied) | (admin-supplied) | `fa-solid fa-bullhorn` | `danger` | (admin-supplied / null) |
| 9 | `admin_alert` (reserved) | future admin/monitoring system alerts | "System alert" | "Something needs your attention." | `fa-solid fa-triangle-exclamation` | `danger` | null |
| 10 | `account_notice` (reserved) | security / account lifecycle pings | "Account notice" | (context) | `fa-solid fa-shield-halved` | `warning` | `/profile` |

**Rules**
- The catalog is the single source of truth: `NotificationFormatter::TEMPLATES
  = NotificationService::types()` — one map, described once.
- `review_reacted`, `review_replied`, `wishlist_reminder`,
  `library_milestone`, `author_new_release` are *recipient fan-out* types
  (many users); `author_followed` and `system_announcement` target one or
  the whole population; `admin_alert`/`account_notice` are future-reserved
  (defined now so the schema CHECK list never changes).
- Titles/messages are **templates** — the formatter substitutes
  `{Author}`, `{Book}`, `{Actor}`, `{Title}` etc. from the event context at
  write time.
- `system_announcement` bypasses preferences (Task 7); all other types honour
  per-category opt-outs.

---

## Task 6 — Routing plan

All routes: `SecureHeadersMiddleware` first (project-wide), `AuthMiddleware`
for signed-in users, `CsrfMiddleware` on every state-changing verb (follow,
unfollow, read, bulk read, delete, preference).

| Method | Path | Controller action | Purpose | Auth / Authorization |
|---|---|---|---|---|
| GET | `/notifications` | `NotificationController@index` | Notification center (paginated, `?tab=all\|unread\|read`) | Auth; own rows only |
| GET | `/notifications/unread-count` | `NotificationController@unreadCount` | navbar badge (JSON) | Auth |
| PATCH | `/notifications/{id}` | `NotificationController@markRead` | mark one read | Auth + `NotificationPolicy::canManage` |
| PATCH | `/notifications/read-all` | `NotificationController@markAllRead` | mark all read | Auth |
| DELETE | `/notifications/{id}` | `NotificationController@destroy` | delete one | Auth + `canManage` |
| DELETE | `/notifications` | `NotificationController@clearHistory` | clear history | Auth |
| POST | `/notifications/preferences` | `NotificationController@updatePreference` | set a category toggle | Auth |
| POST | `/authors/{id}/follow` | `AuthorController@follow` | follow an author | Auth; `FollowPolicy::canFollow` |
| DELETE | `/authors/{id}/follow` | `AuthorController@unfollow` | unfollow | Auth; `FollowPolicy::canUnfollow` |
| GET | `/profile/following` | `UserController@following` | my followed authors | Auth; owner only |
| GET | `/profile/followers` | `UserController@followers` | my followers | Auth; owner (+ admin view) |
| GET | `/authors/{id}` | `AuthorController@show` *(unchanged)* | author page + follow button state | Auth |

**Reserved for later phases (not registered in 9.2):**
- `POST /admin/notifications/announce` — admin broadcast (AdminController).
- `GET /authors/{id}/followers` — public follower list modal (optional).

> **Method notes.** The `PATCH`/`DELETE` verbs are the clean intent; the
> Router extensions from the Task-1 box make them work, and the `_method`
> form-field fallback keeps every toggle functional without JavaScript —
> identical to the review engagement no-JS fallback.

**Route-matching safety (same rules as the existing tables):** `/notifications`
and `/notifications/{id}` are two segment depths apart; the literal
`unread-count`, `read-all` and `preferences` paths sit in the router's
exact-match fast path, so `/{id}` can never capture them — exactly how
`/reviews/search` avoids `/reviews/{id}`.
---

## Task 7 — Authorization

Coarse gate = middleware in the route table; fine gate = policy inside the
controller (the project's two-layer rule, e.g. `LibraryPolicy` behind
`AuthMiddleware`). Policies stay **read-only**.

### `FollowPolicy`

| Ability | Rule |
|---|---|
| Can follow an author | any authenticated user (`canFollow` → `auth_check()`); the service additionally rejects the author missing / self-follow / duplicate |
| Can unfollow | the row's owner only (`canUnfollow(array $follow)` → `user_id === actor`); admins are **not** exempt for writes — a follow is private, like the library |
| View author follower statistic | any authenticated user (the author page shows `followerCount()`) |
| View a user's followers / following list | the owner, or an admin (read-only oversight — same stance as `LibraryPolicy::canView`) |

### `NotificationPolicy`

| Ability | Rule |
|---|---|
| Create notifications | **no user role** — creation is an internal service/dispatcher-only ability (the "who can create" answer: only code paths inside `NotificationDispatcher`) |
| Delete notifications | the owner (via `findOwnedBy` — the controller never sees unowned rows), or an admin (deleting broadcast rows) |
| Mark one read | owner only (`canManage` → `user_id === actor`) |
| Mark all read / clear history | owner only — even admins cannot mutate another user's read state (private data, library precedent) |
| View the center | any authenticated user (own rows) |
| Admin broadcasts | `AdminMiddleware` (coarse) + `NotificationPolicy::canBroadcast` (fine) — reserved for Phase 9.3 |

### Permission matrix

| Action | Guest | User (owner) | Other user | Admin |
|---|---|---|---|---|
| Follow an author | ✗ 403 | ✔ | ✔ | ✔ |
| Unfollow | ✗ 403 | ✔ (own row) | ✗ 403 | ✗ 403 (writes) |
| See author follower count | ✗ | ✔ | ✔ | ✔ |
| See my followers/following | ✗ | ✔ | ✗ 404 | ✔ view only |
| View notification center | ✗ | ✔ | ✗ 404 (own rows only) | ✔ own rows |
| Mark read / bulk read / delete own | ✗ | ✔ | ✗ 404 | ✗ 403 (others' rows) |
| Clear history | ✗ | ✔ | ✗ 404 | ✗ |
| Create a notification (via UI) | ✗ | ✗ | ✗ | ✗ — dispatcher only |
| Broadcast announcement | ✗ | ✗ | ✗ | ✔ (Phase 9.3) |

> IDOR rule (same as every module): **the acting user id always comes from
> the session** (`auth()?->id()`), never from the request body; the
> repository owner-scopes every read (`findOwnedBy`, `deleteOwnedBy`).

---

## Task 8 — Sequence diagrams

The project does not use Mermaid in its docs, so these are plain-text
sequence diagrams (matching the existing phase docs' style). If a later
phase introduces Mermaid, these map 1:1.

### 8.1 — User follows an author

```
Browser            AuthorController   FollowPolicy   FollowService   AuthorFollowRepo   NotificationDispatcher   notifications
  |  POST /authors/7/follow  |            |             |              |                  |                       |
  |─────────────────────────►|  auth_check│             |              |                  |                       |
  |                          |───────────►|  ✔          |              |                  |                       |
  |                          |  follow(user=3, author=7) |            |                  |                       |
  |                          |──────────────────────────►|            |                  |                       |
  |                          |                     authorExists(7)─────► (findById)      |                       |
  |                          |                     self-follow check  |                  |                       |
  |                          |                     duplicate check────► exists(3,7)      |                       |
  |                          |                     create(...)───────► INSERT            |                       |
  |                          |                     notify('author_followed')             |                       |
  |                          |──────────────────────────────────────────────────────────►|                       |
  |                          |                                                      format() (pure)          |
  |                          |                                                      INSERT is_read=0 ──────►|
  |  { following: true }     |            |             |              |                  |                       |
  |◄─────────────────────────|            |             |              |                  |                       |
```

### 8.2 — User unfollows an author

```
Browser       AuthorController   FollowPolicy      FollowService      AuthorFollowRepo
  |  DELETE /authors/7/follow  |         |              |                |
  |───────────────────────────►|  canUnfollow(row) ✔  |                |
  |                            |──────────────────────► deleteForPair(3,7) ──► DELETE WHERE user_id=3 AND author_id=7
  |                            |                      |  (idempotent)        |
  |  { following: false }      |                      |                |
  |◄───────────────────────────|                      |                |
```

### 8.3 — Notification creation (review event, fan-out)

```
ReviewService (approves a review on book 5)   NotificationDispatcher   NotificationFormatter   notifications
  |  dispatcher->notify('author_new_release', {author_id: 1, book_id: 5, ...})
  |──────────────────────────────────────────►|
  |                                           |  type in catalog? ✔
  |                                           |───────────────────────► format(...) -> title,message,icon,color,action_url
  |                                           |◄─────────────────────── content
  |                                           |  preferences filter (author_activity=1)
  |                                           |  INSERT..SELECT FROM author_follows WHERE author_id=1
  |                                           |──────────────────────────────────────────────────────────► 4 rows, is_read=0
  |                                           |  deliveries outbox (stub, no rows in 9.2)
  |                                           |  log 'notification.created'
```

### 8.4 — Notification retrieval (center page)

```
Browser          NotificationController   NotificationPolicy   NotificationService   NotificationRepo
  |  GET /notifications?tab=unread&page=2   |           |              |                 |
  |────────────────────────────────────────►|  canAccess ✔           |                 |
  |                                         |───────────────────────► page(user=3,'unread',2)│
  |                                         |                    countForUser(...) ─────► COUNT(*) (user_id,is_read idx)
  |                                         |                    page rows ─────────────► SELECT ... LIMIT 25 OFFSET 25
  |                                         |◄─────────────────────── payload (items,total,pages,has_next)
  |  HTML page with items + pagination bar  |           |              |                 |
  |◄────────────────────────────────────────|           |              |                 |
```

### 8.5 — Notification marked read

```
Browser           NotificationController   NotificationPolicy   NotificationService   NotificationRepo
  |  PATCH /notifications/42              |           |              |                  |
  |───────────────────────────────────────►|  findOwnedBy(42,3)     |                  |
  |                                        |───────────────────────► markRead(42,3) ────► UPDATE ... SET is_read=1, read_at=now
  |  { ok:true, unread:2 }                |           |              |                  |
  |◄───────────────────────────────────────|           |              |                  |
```

---

## Task 9 — Performance

| Concern | Design |
|---|---|
| **Indexes** | `UNIQUE(user_id, author_id)` for follows; `(author_id)` for follower stats; `(user_id, is_read)` covering index for unread count + tab; `(user_id)` and `(created_at)` for the center. Every list query is index-backed; no full scans. |
| **Pagination** | Offset pagination with `LIMIT ? OFFSET ?` + `COUNT(*)` denominator — the exact `paginate()` shape the library grid already ships (page clamped to `[1, pages]`, `perPage` clamped to `[1, 50]`). SQLite scale (thousands of rows per user) makes keyset overkill; the `(user_id, created_at)` index covers a future keyset switch without a schema change. |
| **Retrieval strategy** | One page read = one count + one `LIMIT` SELECT. The center defaults to the `unread` tab (fresh content first); the badge endpoint reuses the same `unreadCount()` path so the navbar never runs a second query shape. |
| **Unread-count optimization** | A `COUNT(*)` on the `(user_id, is_read)` covering index is a single index scan; further, the badge value is cached per user with the project's existing file-cache precedent (`PersonalizationCache` — directory + TTL pattern from `config/recommendations.php`), invalidated on every read/delete write. The endpoint stays correct without the cache; the cache only skips the count. |
| **N+1 prevention** | Follow lists JOIN their display rows (`JOIN authors` / `JOIN users`) in one query; notification lists carry their content inline (no related-table JOIN at all — see the "formatted at write time" choice in Task 2). Bulk creation is one `INSERT … SELECT`. No per-row queries anywhere. |
| **Caching opportunities** | (1) per-user unread badge (file cache, TTL 60 s); (2) author follower counts — small, derived from one index scan, cacheable only if the author directory grows cold reads; start without it (the count query is already index-served). Cache writes are the **only** cache invalidation triggers: follow/unfollow and every read write. |
| **Write path** | All writes are single-row or batched `INSERT … SELECT`; no transactions span more than one statement (SQLite serializes writers anyway — the app's existing pattern). `RateLimiter` bounds abusive toggle spam. |
| **Retention** | `NotificationService::prune(int $days)` (reserved, run by a future cron/console): `DELETE FROM notifications WHERE created_at < now - N days` for a user — keeps the center bounded without schema changes. |
| **Future WebSocket compatibility** | The badge contract is already a **pull** JSON endpoint (`/notifications/unread-count`). A later realtime layer (SSE then WS) can poll or push that same endpoint's payload; the `created_at` column doubles as the `since` cursor for incremental fetches. No architectural change needed — the notification rows are append-only and immutable, which is exactly what realtime needs. |

---

## Task 10 — Error handling

Module exceptions with static factories (the `LibraryException` /
`ReviewException` pattern). The controller catches, maps to an HTTP status
and a safe message; **PDO errors are NOT wrapped** — they bubble to the app
`ErrorHandler`, which logs them once (the documented project rule).

### `FollowException`

| Factory | When | HTTP |
|---|---|---|
| `authorNotFound(int $authorId)` | the follow target does not exist (deleted author) | 404 |
| `cannotFollowSelf(int $userId)` | `user_id === author_id` impossible in one request? the rule applies to the follow-then-`account_notice` future; kept for parity and defensive checks | 400 |
| `duplicateFollow(int $userId, int $authorId)` | follow row already exists (service guard or UNIQUE index race) | 409 |
| `permissionDenied(string $action)` | policy bypass attempt (defence in depth) | 403 |

### `NotificationException`

| Factory | When | HTTP |
|---|---|---|
| `notificationNotFound(int $id)` | the row is missing **or not owned by the actor** (same 404 — no existence leak) | 404 |
| `invalidType(string $type)` | an unknown type key reaches the dispatcher/formatter | 422 |
| `invalidPreference(string $category)` | a preference toggle outside the seven categories | 422 |
| `permissionDenied(string $action)` | admin-only broadcast attempted by a non-admin | 403 |

### Case-by-case responses

| Case | Response |
|---|---|
| Duplicate follow | `409` — "You already follow this author." (JSON `{error: …}` or flash) |
| Following yourself | `400` — validated before any row write |
| Deleted author | `404` — author page / follow target gone; the CASCADE already cleaned the rows |
| Deleted user | rows cascade-deleted; `findOwnedBy` returns null → 404; never a 500 |
| Notification not found / not owned | `404` — indistinguishable on purpose (IDOR guard) |
| Unauthorized access (guest on a protected route) | middleware `401/redirect` per the app's `AuthMiddleware` behaviour |
| Not allowed (policy) | `403` via `Response::error(403, …)` / JSON `{error}` |
| Database failure | not caught — app `ErrorHandler` logs + friendly 500 (project-wide rule) |
| Validation failure | field errors array from `FollowRequest` / `NotificationReadRequest`, rendered by the existing `form-errors` partial; JSON endpoints return `{errors: {…}}` with 422 |
| Rate limit hit | `429` via the existing `RateLimiter` convention |

---

## Task 11 — Testing plan

Follows the existing self-running CLI suite pattern (`php tests/XTest.php`,
throwaway migrated+seeded SQLite database, PASS/FAIL lines — the 10 suites
currently hold 1272 checks). New suites:

### `tests/FollowTest.php` (Phase 9.2)
- **Schema:** author_follows columns, UNIQUE (user_id, author_id) rejects a
  second row, cascade deletes (user removed / author removed), both indexes.
- **Repository:** create / exists / deleteForPair / findForUser (joined
  author rows) / findFollowersOf / followerCount.
- **Service (unit):** follow happy path; `authorNotFound`; `duplicateFollow`;
  `cannotFollowSelf`; unfollow idempotence (second call returns false);
  follower statistics correctness after multiple follows.
- **Policy:** guest / owner / other user / admin matrix (rows 1–4 of the
  Task 7 table).
- **Controller (functional):** POST follow → `{following:true}`; DELETE →
  `{following:false}`; plain POST fallback redirect; unauthenticated → 401.
- **Edge:** double-click race (UNIQUE constraint catches the second insert);
  following after the author was deleted (404); 1000 follows for one author
  (count query stays fast).
- **Security:** tampered `user_id` in the body is ignored (session wins);
  unfollow of another user's row → 403/404.
- **Performance:** followerCount on an indexed author_id with 10k rows
  (assert query time sane — the suite logs it).

### `tests/NotificationTest.php` (Phase 9.2)
- **Schema:** notifications columns, CHECK (type IN catalog), CHECK
  (is_read IN (0,1)), `(user_id, is_read)` index present, cascade on user
  delete.
- **Formatter (unit):** every catalog type formats with title/message/icon/
  color/action_url; unknown type → `invalidType`; `{placeholder}` substitution.
- **Dispatcher (unit):** single create; fan-out `INSERT … SELECT` row count;
  preference gating (author_activity=0 silences fan-out, `force` bypasses);
  `is_read=0` on insert.
- **Service:** `page()` tabs (all/unread/read counts agree with
  `unreadCount`), pagination bounds, markRead idempotence, markAllRead
  count, delete/clear history.
- **Controller (functional):** center page render; unread-count JSON; PATCH
  mark read; PATCH read-all; DELETE one / DELETE all; preference toggle; all
  with the no-JS redirect fallback.
- **Edge:** empty history (0 badge, empty page); 500+ rows page arithmetic;
  reading a deleted notification → 404; owner-scoped lookup of a foreign
  row → 404.
- **Security:** another user's notification id → 404 (no existence leak);
  cross-user bulk read attempt → 404/403; guest → 401.
- **Performance:** unreadCount with 10k rows on the covering index;
  `insert-select` fan-out to 1k followers in one statement.
- **Regression:** the full existing suite set still passes (1272 checks
  baseline) — run `php tests/*.php` and assert 0 failures.

---

## Deliverables checklist (this document)

| Deliverable | Where |
|---|---|
| Database architecture | Task 1 (4 tables, DDL-level specs) |
| Table relationships | Task 1 (ERD + FK/cascade rules) |
| Model architecture | Task 2 (facades + method contracts) |
| Service architecture | Task 3 (4 classes + interaction diagram) |
| Controller architecture | Task 4 (3 controllers) |
| Route plan | Task 6 (12 routes + reserved) |
| Permission matrix | Task 7 |
| Notification architecture | Tasks 3 + 5 (types catalog) |
| Sequence diagrams | Task 8 (5 diagrams) |
| Testing strategy | Task 11 |
| Folder / file structure | Below |

### Folder / file structure (all NEW files; no existing file modified)

```
app/
  Controllers/
    NotificationController.php          (new)
    AuthorController.php                (edit: show() + follow/unfollow)
    UserController.php                  (edit: following/followers)
  Models/
    AuthorFollow.php                    (new)
    Notification.php                    (new)
  Repositories/
    AuthorFollowRepository.php          (new)
    NotificationRepository.php          (new)
  Services/
    FollowService.php                   (new)
    NotificationService.php             (new)
    NotificationDispatcher.php          (new)
    NotificationFormatter.php           (new)
  Policies/
    FollowPolicy.php                    (new)
    NotificationPolicy.php              (new)
  Exceptions/
    FollowException.php                 (new)
    NotificationException.php           (new)
  Requests/
    FollowRequest.php                   (new)
    NotificationReadRequest.php         (new)
  DTO/
    FollowDTO.php                       (new)
    NotificationDTO.php                 (new)
  Views/
    notifications/
      index.php                         (new — center)
      partials/_item.php                (new — one row: icon, color, title, message, time, action)
    authors/partials/_follow-button.php (new)
    profile/partials/_following.php     (new)
    profile/partials/_followers.php     (new)
public/assets/
  css/notifications.css                 (new — token-based, mirrors module css)
  js/notifications.js                   (new — fetch toggles + badge refresh, no-JS fallback)
database/migrations/
  0022_create_author_follows_table.php
  0023_create_notifications_table.php
  0024_create_notification_preferences_table.php
  0025_create_notification_deliveries_table.php
routes/web.php                          (edit — new registrations only)
tests/
  FollowTest.php                        (new)
  NotificationTest.php                  (new)
docs/
  PHASE_9_2_IMPLEMENTATION.md           (generated from this blueprint)
```

### Phase 9.2 suggested implementation order

1. Router `patch()`/`delete()` + `_method` override (+ its tests).
2. Migrations 0022–0025 (in order) + schema checks.
3. Repositories → facades → exceptions → policies → requests/DTOs.
4. Formatter → Dispatcher → NotificationService → FollowService (unit tests
   after each layer).
5. Controllers + routes + wiring in `routes/web.php` (shared instances).
6. Views + CSS + JS (center, follow button, profile lists, badge).
7. `FollowTest.php` + `NotificationTest.php` + full regression run.
