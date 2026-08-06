# Phase 9.2 — Follow Authors & Notification System: Implementation

> **Phase:** 9.2 — **Module:** Follow / Unfollow Authors + Notification Backend
> **Builds on:** Phase 9.1 (architecture blueprint) — **Next:** Phase 9.3 (admin
> broadcasts, reserved) / Phase 9.4 (notification center UI)
>
> This document is the implementation record generated from the
> `PHASE_9_1_FOLLOW_NOTIFICATIONS_ARCHITECTURE.md` blueprint: what was
> actually built, the files that carry it, the routes it registered and
> the verification that proves it works.

## 1. Project analysis (what Phase 9.2 started from)

The blueprint (`docs/PHASE_9_1_FOLLOW_NOTIFICATIONS_ARCHITECTURE.md`)
specified four new tables (author_follows, notifications,
notification_preferences, notification_deliveries), a layered stack
(DTO → service → repository → facade), the module exceptions/policies/
request rules, the `author_followed` notification hook and two CLI test
suites. It also required two Router capabilities the app did not have:
`PATCH`/`DELETE` verbs and the `_method` form-field override for the
no-JS unfollow.

Phase 9.2 turned that blueprint into the running backend: the follow
module (author page button, followers list, "Authors I follow" page)
and the complete notification infrastructure (formatter, dispatcher,
service, repository, migrations, JSON + no-JS API). **No notification
UI was built** — the center page, bell, badge and preferences surface
are deliberately reserved for Phase 9.4; this phase ships the API those
will read and write.

---

## 2. Architecture (what the phase added on top of 9.1)

```
Browser (follow.js — fetch, progressive enhancement)
   │  X-Requested-With: fetch / plain form (_method=DELETE)
   ▼
AuthorController ── FollowPolicy (matrix: guest / owner / other / admin)
   │
   ▼
FollowService (author exists · no self-follow · no duplicate · idempotent unfollow)
   │  author_followed ping
   ▼
NotificationDispatcher (single creation door, preference-gated)
   ├─► NotificationFormatter (template catalog → title/message/icon/color/action_url)
   ├─► NotificationRepository (INSERT/INSERT..SELECT) → notifications
   ▼
NotificationController / NotificationService  →  the backend API
   GET /notifications, PATCH …/read (+ unread), read-all, DELETE /{id}, DELETE /
```

### Key wiring (routes/web.php)

One **shared** `NotificationDispatcher` is built **before** the follow,
review, library and recommendation services, and every one of those
services receives it — so an event can never notify twice through
duplicate dispatcher wiring. The `author_followed` ping rides the same
dispatcher the review / library / recommendation hooks use.

---

## 3. Files created (Phase 9.2)

| File | Purpose |
|---|---|
| `app/Models/AuthorFollow.php` | Facade over `AuthorFollowRepository` (follow row, `author()`/`user()`) |
| `app/Models/Notification.php` | Facade over `NotificationRepository` (row + `user()` + fan-out + prunes) |
| `app/Repositories/AuthorFollowRepository.php` | follow SQL: create/find/exists/pair lists/count/unfollow (joined author display rows, no N+1) |
| `app/Repositories/NotificationRepository.php` | all 9.2 notification SQL: create, the two `INSERT..SELECT` fan-outs, owner-scoped reads/writes, preferences, outbox stub |
| `app/Services/FollowService.php` | follow business rules + the `author_followed` dispatcher hook |
| `app/Services/NotificationService.php` | orchestrator: page/unread/tabs, mark/read-all/unread, delete/delete-all/many, preferences, prune |
| `app/Services/NotificationDispatcher.php` | the module's single creation door (notify / fanOut / fanOutForAuthor, preference gate, `force` bypass) |
| `app/Services/NotificationFormatter.php` | the ten-type template catalog + `{placeholder}` substitution (pure, no I/O) |
| `app/Policies/FollowPolicy.php` | the canFollow/canUnfollow/followerCount/list matrix (guest, owner, other, admin) |
| `app/Exceptions/FollowException.php` | `authorNotFound` / `cannotFollowSelf` / `duplicateFollow` / `permissionDenied` |
| `app/Exceptions/NotificationException.php` | `notificationNotFound` / `invalidType` / `invalidPreference` / `permissionDenied` |
| `app/Requests/FollowRequest.php` | the follow write rules (author_id present, > 0, integer) |
| `app/DTO/FollowDTO.php` | structural sanitization of the follow payload (session id wins) |
| `app/Controllers/NotificationController.php` | the JSON API + no-JS redirect answers (index/read/unread/read-all/destroy/delete-all) |
| `app/Views/authors/followers.php` | the author page's follower list |
| `app/Views/components/follow-button.php` | the reusable Follow / Following button (CSRF + `_method=DELETE`) |
| `app/Views/profile/following.php` | the "Authors I follow" list page |
| `database/migrations/0022_create_author_follows_table.php` | author_follows (UNIQUE pair, both indexes, CASCADE FKs) |
| `database/migrations/0023_create_notifications_table.php` | notifications (CHECK-constrained type catalog, covering unread index, CASCADE) |
| `database/migrations/0024_create_notification_preferences_table.php` | the seven-category opt-out row (0/1 toggles, CASCADE) |
| `database/migrations/0025_create_notification_deliveries_table.php` | the channel outbox (shipped empty by design) |
| `public/assets/css/follow.css` | follow button, follower/following lists |
| `public/assets/js/follow.js` | fetch follow/unfollow + badge refresh, no-JS fallback |
| `tests/FollowTest.php` | the follow module suite (127 checks) |
| `tests/NotificationTest.php` | the notification backend suite (88 checks) |
| `tests/NotificationApiTest.php` | the notification API suite (59 checks) |

## 4. Files modified (Phase 9.2)

| File | Change |
|---|---|
| `app/Core/Request.php` | honour `_method` (PATCH / DELETE allow-list) in `method()` |
| `app/Core/Router.php` | add the GET/POST/PATCH/DELETE registrations + PATCH dispatch |
| `app/Controllers/AuthorController.php` | `show()` follow button + follower count; follow/unfollow/followers actions |
| `app/Controllers/UserController.php` | the profile "Authors I follow" page |
| `app/Views/authors/show.php` | render the follow button + followers link |
| `app/Views/profile/show.php` | render the "Following" block |
| `routes/web.php` | follow/unfollow/followers routes + the notification API routes |
| `app/Models/User.php` | support hard auth helpers needed by the follow/profile pages |
| `app/Services/ReviewService.php`, `LibraryService.php`, `RecommendationService.php` | each also receives the shared dispatcher (review_reacted / library_milestone / recommendation_ready pings) |

---

## 5. Routes added (Phase 9.2)

| Method | Path | Action | Middleware |
|---|---|---|---|
| POST | `/authors/{id}/follow` | follow | Auth + CSRF (+ throttle) |
| DELETE | `/authors/{id}/follow` | unfollow | Auth + CSRF (+ throttle) |
| GET | `/authors/{id}/followers` | followers list | Auth |
| GET | `/profile/following` | "Authors I follow" | Auth |
| GET | `/notifications` | one page (JSON feed) | Auth |
| PATCH | `/notifications/{id}/read` | mark one read | Auth + CSRF |
| PATCH | `/notifications/read-all` | mark everything read | Auth + CSRF |
| DELETE | `/notifications/{id}` | delete one | Auth + CSRF |
| DELETE | `/notifications` | clear history | Auth + CSRF |

The `/notifications` literal routes are exact-matched before the two
parameterized patterns, so no route can ever collide.

---

## 6. How each workflow flows

**Follow (fetch / no-JS):** `POST /authors/{id}/follow` →
`FollowPolicy` guest gate → `FollowService::follow()` (author exists →
no self-follow → no duplicate) → `AuthorFollowRepository` INSERT →
`NotificationDispatcher::notify('author_followed', …)` → JSON
`{following: true}` or redirect+flash.

**Unfollow:** the button posts a form carrying `_method=DELETE`; the
Router rewrites the method so the DELETE handler runs (idempotent —
unfollowing an already-unfollowed author answers a silent no-op).

**Notification fan-out:** a single `INSERT … SELECT FROM author_follows`
creates one row per follower with the per-category preference opt-out
applied inside the SQL — no N+1, no transaction across statements.

---

## 7. Verification

### Suites added

| Suite | Checks | Focus |
|---|---|---|
| `tests/FollowTest.php` | 127 | schema, repository, rules, notification hook, DTO, request, policy, model, router verbs, controller, probe answers, views, DB constraints, regression |
| `tests/NotificationTest.php` | 88 | schema/CHECKs/indexes, formatter templates, dispatcher gates/fan-out, service reads/each/report, IDOR shield, model, DB CASCADE, follow regression |
| `tests/NotificationApiTest.php` | 59 | controller JSON API, RESTful 404s, idempotent read/unread, pagination/unread-only, dual no-JS answer, auth, CASCADE |

Every suite runs with its own throwaway SQLite database and exits 0.

### Regression

The existing suites (Auth, Browse, Landing, Library, Personalization,
Recommendation Architecture/Dashboard/Library/Integration/Optimization,
Reviews, Review Integration) were re-run with the new tables in the
schema. Full run: **0 failures across all suites; the new tables and
the four new migrations changed nothing in the earlier modules.**

```text
Checks: 127 + 88 + 59 (new)  + regression suites → Failed: 0
```

---

## 7. Preparation notes for Phase 9.3 / 9.4

- **Phase 9.3 (admin broadcasts):** the dispatcher already gates
  `system_announcement` / `admin_alert` (no opt-out, `force` bypass),
  so a broadcast endpoint on `AdminMiddleware` + `NotificationService::
  notifyFor`/`fanOut` is purely additive.
- **Phase 9.4 (the center UI):** `NotificationController::center()` and
  `unreadCount()` already render / answer; the missing pieces are only
  the `notifications.*` views, `notifications.css`, `notifications.js`
  and the navbar bell/badge wiring — everything they read already
  exists as JSON.
- The `notification_deliveries` outbox rows are reserved for the
  email/push phases; `enqueueDelivery()` is the single plug-in point.