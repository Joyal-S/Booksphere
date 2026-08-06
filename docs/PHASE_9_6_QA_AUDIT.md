# Phase 9.6 QA Audit (Verification & Stabilization)

> **Phase:** 9.6 — **Doc type:** QA / verification report
>
> The verification and stabilization pass over every Phase 9 module
> (9.1 blueprint → 9.2 follow/notifications → 9.4 notification center →
> 9.5 email/settings) plus the surrounding integrations (dashboard,
> author/category pages, profile). This phase added **no new user-facing
> features**; its output is the security + performance + accessibility
> fixes below and this evidence trail.

## 1. Test report (final run)

All 16 suites executed locally against throwaway databases, each
exiting non-zero on the first failure. Lint (`php -l`) on every
changed file, `node --check` on the changed module JS.

| # | Suite | Checks | Result |
|---|---|---|---|
| 1 | AuthTest | 73 | PASS |
| 2 | BrowseTest | 69 | PASS |
| 3 | LandingTest | 29 | PASS |
| 4 | PersonalizationTest | 62 | PASS |
| 5 | FollowTest | 127 | PASS |
| 6 | NotificationTest | 88 | PASS |
| 7 | NotificationCenterTest | 83 | PASS |
| 8 | NotificationApiTest | 59 | PASS |
| 9 | LibraryTest | 278 | PASS |
| 10 | EmailNotificationTest | 86 | PASS |
| 11 | ReviewTest | 371 | PASS |
| 12 | ReviewIntegrationTest | 109 | PASS |
| 13 | RecommendationArchitectureTest | 86 | PASS |
| 14 | RecommendationOptimizationTest | 57 | PASS |
| 15 | RecommendationLibraryIntegrationTest | 147 | PASS |
| 16 | RecommendationDashboardTest | 64 | PASS |
| | **Total** | **1788** | **0 failures** |

That run went green only after the suite bug below was fixed (a
regression the 9.6 pagination work introduced — the `AuthorFollow`
model facade never forwarded the new `countForUser()` /
`countFollowersOf()` / offset arguments, blowing up
`followersPage()` at runtime).

---

## 2. Security fixes (all applied)

| # | Finding | Fix | Where |
|---|---|---|---|
| 1 | **SMTP TLS verification disabled** — `SmtpTransport` opened TLS streams with `verify_peer => false, verify_peer_name => false`, leaving email open to man-in-the-middle | stream context now sets `verify_peer` / `verify_peer_name` from `smtp.verify_peer` config (`SMTP_VERIFY_PEER`, default `true`) | `app/Mail/SmtpTransport.php`, `config/email.php`, `.env` / `.env.example` |
| 2 | **Actor id accepted from input** — `FollowDTO` read `user_id` from the payload before falling back to the session, so a crafted form could make user A act as user B | the actor id is always the session value passed by the caller; only `author_id` comes from the form | `app/DTO/FollowDTO.php` |
| 3 | **CSRF token not always read from POST body** — `CsrfMiddleware` read a token that could be supplied via a query string | new `Request::post('_token')` reads the body only (trims strings, falls to the default) | `app/Core/Request.php`, `app/Middleware/CsrfMiddleware.php` |
| 4 | **Duplicate email race in queue mode** — a re-fired event could enqueue a second queue row (the UNIQUE held only in `email_logs`), which the worker would have sent | the queue path claims the audit row **first** (`recordAttempt()` returns 1/0); collision → `email.dedupe_skipped` and no enqueue; `email_queue` insert also `ON CONFLICT … DO NOTHING` | `app/Services/EmailNotificationService.php`, `app/Repositories/EmailQueueRepository.php` |
| 5 | **500 on follow race** — two simultaneous follows of the same pair hit the UNIQUE constraint and died with a generic exception | the UNIQUE violation (`23000`) is caught and re-raised as `FollowException::duplicateFollow()` → 409 with the proper message | `app/Services/FollowService.php` |
| 6 | **Open redirect via the notification back link** — the `back()` route accepted arbitrary referers | `safeBackPath()` accepts only root-relative paths (rejects `//host`, backslash, NUL, absolute URLs) and is a static, testable helper | `app/Controllers/NotificationController.php` |
| 7 | **`action_url` scheme hole** — a notification's `action_url` was escaped but could carry an arbitrary scheme | `NotificationFormatter::format()` passes every action through `safeActionPath()`; null action URLs are preserved | `app/Services/NotificationFormatter.php` |

---

## 3. Performance / code-quality fixes

| # | Finding | Fix | Where |
|---|---|---|---|
| 1 | `findForUser()` / `findFollowersOf()` hard-truncated at 50 rows while the lead text under-counted ("N followers" honest count vs 50 shown) | honest pagination: repo `count()` + `LIMIT/OFFSET`; service `followingPage()` / `followersPage()` with clamped page + `perPage` (1–50); controllers pass `$total` + `$pagination`; both views render the shared pager and the truthful lead | `AuthorFollowRepository`, `FollowService`, `AuthorController`, `UserController`, `views/authors/followers.php`, `views/profile/following.php` |
| 2 | Missing covering indexes for the notification center reads (and the duplicate-author query removed earlier) | migration **0029** adds `idx_notifications_user_created (user_id, created_at)`, `idx_notifications_user_read_created (user_id, is_read, created_at)`, `idx_email_queue_dedupe UNIQUE (user_id, type, dedupe_key)`, `idx_email_queue_user (user_id)`; drops the superseded `idx_notifications_user (user_id)` | `database/migrations/0029_add_phase9_qa_indexes.php` |
| 3 | Two author-name queries per follow row | single `findById()` reused for DTO + notification | `FollowService` |
| 4 | Stale `$logConfig` self-reference | removed | `EmailNotificationService` |

The per-row book-count subquery on the followed-authors list remains
(indexed, low severity) — noted, not worth the join cost at current
scale.

---

## 4. Accessibility / UI fixes

| # | Finding | Fix | Where |
|---|---|---|---|
| 1 | `.notif-bulk` toolbar overflowed under 360 px | `flex-wrap: wrap` + separate row/column gap | `notifications.css` |
| 2 | Pulse ring hard-coded `rgba(91,75,219,…)` (light-theme primary, wrong in dark mode) | ring derived from the `--primary` token via `color-mix` | `notifications.css` |
| 3 | `announce()` fell back to `window.alert()` on pages without the live region (a scripted dialog on unrelated pages) | removed; the bell badge has its own sr-only live region | `notifications.js`, `header.php` |
| 4 | Fragment / chip / pager swaps dropped keyboard focus and double-fetched the unread count | `loadList()` returns its payload; `runForm` / mark-all read the badge from the fragment (no second request); sequence-token guards stale responses; `aria-busy` set while in flight; `badge refresh` skip hidden tab + in-flight guard; focus moves to the hidden-visible `tabindex="-1"` list heading | `notifications.js`, `_list.php`, `center.php` |
| 5 | News-list was a `<div>` of articles (invalid list semantics) | `<ul class="notif-list">` of `<li class="notif-list-item">` wrapping the same `.notif-item` cards; `list-style: none` | `_list.php`, `notifications.css` |
| 6 | Active filter chips did not announce their state | `aria-current="page"` on the active chip | `_filters.php` |

Remaining low-severity notes (documented, not reworked): the followed /
followers empty states use the plain `.card-base` text instead of the
shared `empty-state` component — cosmetic only, both states read
correctly and pass the a11y checks.

---

## 5. Environment / operational fixes

- **Dev database drift:** the committed `database/booksphere.db` was
  stuck at migration 0021 — none of the Phase 9 tables existed. The
  dev DB was repaired by running the normal migration script
  (0022–0029 applied). This is a one-time operational fix, not a code
  change; the one-off helper scripts were removed after use.

---

## 6. Verification methodology

1. **Schema audit** — every migration 0001–0029 read end to end;
   UNIQUE / CHECK / FK-CASCADE / indexes verified against the test
   suites' schema assertions.
2. **Security audit** — all Phase 9 routes + services traced for
   SQLi / XSS / CSRF / IDOR / open-redirect / host-isolation /
   certificate handling. Prepared statements everywhere, `e()` on all
   user content, `findOwnedBy` gates on every row, CSRF on every
   mutation.
3. **Performance audit** — every hot read `EXPLAIN`-checked; the
   missing coverage indexes above were added as migration 0029.
4. **Accessibility audit** — keyboard / screen-reader paths walked
   through the center page and the bell dropdown (focus, live
   regions, landmarks, labels, contrast).
5. **Quality audit** — params, dead code, duplicated queries,
   pagination honesty, config caching, log-level discipline, secret
   hygiene (no secrets in logs or the repo).
6. **Regression** — the full 16-suite run above, executed after all
   code changes, all green.

---

## 7. Overall completion & recommendation

**Overall completion: 100%** of the 9.6 acceptance scope is met.

**Final recommendation: ✅ ACCEPT.** No open, blocking defect. The
three 9.6 test/documentation artifacts requested — a suite report,
this audit report, and the per-module docs — are all in place.
Remaining work (if any) is purely additive scope from Phases 9.3
(admin broadcasts), email producers (welcome/reset/verification), and
the newsletter — all explicitly deferred, not defects.