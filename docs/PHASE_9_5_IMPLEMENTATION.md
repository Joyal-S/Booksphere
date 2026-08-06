# Phase 9.5 — Email Notification System: Implementation

> **Phase:** 9.5 — **Module:** Email Notifications (optional channel)
> **Builds on:** Phases 9.1–9.4 (follow, notification backend, center
> UI) — **Next:** Phase 9.6 (reserved, e.g. admin broadcasts)
>
> This document is the implementation record of the email channel:
> what was actually built, the files that carry it and the
> verification that proves it works.

## 1. Project analysis (what Phase 9.5 started from)

Phases 9.1–9.4 shipped the follow module and the in-app notification
system: the `author_followed` / `review_reacted` / `review_replied` /
`recommendation_ready` / `library_milestone` events run through a
single shared `NotificationDispatcher`, the `notification_deliveries`
outbox was left as the reserved plug-in point for external channels,
and the app had **no mail stack at all** — no mailer, no templates,
no SMTP client, no settings page (`/settings` was a "coming soon"
placeholder).

Phase 9.5 built the reserved outbox channel: a dependency-free email
stack (validation → transport → mailer → service → templates) that
sits **behind** the dispatcher and stays strictly optional. With
`EMAIL_ENABLED=false` (the default) the module is a no-op and the
application is byte-for-byte equivalent to 9.4 in behaviour; with it
on, every dispatcher event that maps to an email type is delivered
inline or queued, audited in `email_logs`, deduped against
re-fires, preference-gated by the new Settings page, and — critically
— can never break the request that triggered it.

## 2. Architecture (what the phase added on top of 9.4)

```
NotificationDispatcher::notify(type, context, userId, content)   [unchanged gate]
   │  (optional, nullable) EmailNotificationService
   ▼
EmailNotificationService::dispatch()
   ├─ enabled? (config)            → silent no-op when off
   ├─ TYPE_MAP lookup              → library_milestone etc. never email
   ├─ recipient exists + valid     → email.recipient_missing warning
   ├─ preference gate (5 toggles)  → 'skipped' audit row when off
   ├─ dedupe sha256(type|user|context) → UNIQUE email_logs insert
   ├─ queue (pending)  OR  inline Mailer
   └─ audit: sent / queued / skipped / failed (+ error text)
Mailer → SmtpTransport (streams only) | LogEmailTransport (file)
   └─ never throws; lastError() + log entries
SettingsController → email_preferences (fetch JSON / no-JS flash)
```

### Key wiring (routes/web.php)

The email stack is built **once**, before every service that can
trigger a notification, and injected as the dispatcher's **4th
constructor argument** (nullable). All four hook sites (follow,
review, library, recommendation) keep using the same dispatcher — an
event can never email twice through duplicate wiring, and a dispatcher
built without the email service behaves exactly as in 9.2–9.4.

## 3. Files created (Phase 9.5)

| File | Purpose |
|---|---|
| `config/email.php` | the whole channel config (enabled, from, transport, smtp, queue, log_file, app_url) — every value env-driven |
| `app/Exceptions/EmailException.php` | `invalidEmail` / `invalidPreference` (header injection, malformed address, unknown toggle) |
| `app/Mail/EmailMessage.php` | validated immutable message VO (FILTER_VALIDATE_EMAIL, CR/LF injection rejection, ≤200-char subject, `toLine()`) |
| `app/Mail/EmailTransport.php` | the transport contract (`send(): bool`, `lastError(): ?string`) |
| `app/Mail/LogEmailTransport.php` | default transport: appends the full message to `storage/logs/email.log`, never throws |
| `app/Mail/SmtpTransport.php` | dependency-free SMTP client: EHLO / STARTTLS / TLS / AUTH LOGIN / MAIL FROM / RCPT TO / DATA (dot-stuffing) / QUIT, base64-encoded headers, per-socket timeouts, never throws |
| `app/Mail/Mailer.php` | the facade: picks the transport from config, catches every Throwable, logs email.sent / email.send_failed / email.send_exception, exposes lastError()/transportName() |
| `app/Mail/EmailType.php` | the email-type catalog constants + `all()` (follow, review, reply, recommendation, author_release, newsletter, welcome, password_reset, email_verification) |
| `database/migrations/0026_create_email_preferences_table.php` | one row per user, five 0/1 toggles (CHECK-constrained, DEFAULT 1), FK CASCADE |
| `database/migrations/0027_create_email_logs_table.php` | the audit trail: status sent/failed/skipped/queued, recipient + subject snapshot, `UNIQUE(user_id, type, dedupe_key)`, INDEX(user_id, created_at) |
| `database/migrations/0028_create_email_queue_table.php` | the worker queue: pending/sent/failed + attempts + error + sent_at, INDEX(status, created_at) |
| `app/Repositories/EmailPreferenceRepository.php` | preferences SQL with a column allowlist (tampered keys cannot reach the schema) |
| `app/Repositories/EmailLogRepository.php` | record() (ON CONFLICT DO NOTHING dedupe) + updateStatus() (queued→sent/failed flip) + countForUser() |
| `app/Repositories/EmailQueueRepository.php` | enqueue / pending / markSent / markFailed / pendingCount |
| `app/Models/EmailPreference.php` | facade over the preference repository |
| `app/Models/EmailLog.php` | facade over the audit repository (row + updateStatus) |
| `app/Models/EmailQueue.php` | facade over the queue repository |
| `app/Services/EmailNotificationService.php` | the pipeline: dispatch() gates, dedupe, queue/inline, processQueue(), preferences()/updatePreference() validation, subjectFor()/htmlFor(), absoluteUrl() — never throws |
| `app/Controllers/SettingsController.php` | GET /settings (the page) + POST /settings/email-preferences (the five toggles, dual-answer JSON/flash, 422 on unknown key) |
| `app/Views/pages/settings.php` | the real Settings page: intro + email section + disabled banner + five toggle rows + save |
| `app/Views/emails/layout.php` | the email shell: table-based, inline CSS, responsive ≤600px, accessible headings |
| `app/Views/emails/partials/_header.php` | brand wordmark + tagline, links the app URL |
| `app/Views/emails/partials/_cta.php` | the filled CTA button (skipped when there is no action URL) |
| `app/Views/emails/partials/_footer.php` | the Settings (unsubscribe) link + year + copyright |
| `app/Views/emails/partials/_body-follow.php` | "you started following …" body |
| `app/Views/emails/partials/_body-review.php` | "someone appreciated your review" body |
| `app/Views/emails/partials/_body-reply.php` | review-reply body (future-ready: no producer yet) |
| `app/Views/emails/partials/_body-recommendation.php` | "your picks are ready" body |
| `app/Views/emails/partials/_body-author_release.php` | new-release body (future-ready) |
| `app/Views/emails/partials/_body-newsletter.php` | digest body (future-ready) |
| `app/Views/emails/partials/_body-welcome.php` | welcome body (future-ready) |
| `app/Views/emails/partials/_body-password_reset.php` | reset body (future-ready) |
| `app/Views/emails/partials/_body-email_verification.php` | verification body (future-ready) |
| `public/assets/css/settings.css` | the Settings page styling on the shared design tokens |
| `public/assets/js/settings.js` | progressive enhancement: fetch save + aria-live status + save spinner, native form untouched |
| `tests/EmailNotificationTest.php` | the end-to-end suite (86 checks, 10 sections) |
| `docs/EMAIL_CONFIGURATION.md` | the operations guide (.env, transports, queue worker, live checklist) |
| `docs/EMAIL_NOTIFICATIONS.md` | the developer guide (pipeline, type catalog, adding types) |

## 4. Files modified (Phase 9.5)

| File | Change |
|---|---|
| `routes/web.php` | build the shared `$emailService` once, pass it as the dispatcher's 4th argument; register `GET /settings` + `POST /settings/email-preferences` (Auth + CSRF) |
| `app/Services/NotificationDispatcher.php` | constructor gains `?EmailNotificationService $email = null`; `notify()` forwards every event to `$this->email?->dispatch(...)` after the in-app outbox |
| `app/Controllers/PageController.php` | `settings()` placeholder action removed (moved to SettingsController) |
| `app/Views/partials/head.php` | register `settings.css` |
| `app/Views/partials/scripts.php` | register `settings.js` |
| `.env` / `.env.example` | the documented EMAIL_*/SMTP_* variables (EMAIL_ENABLED=false default) |
| `app/Mail/SmtpTransport.php` | (during testing) fixed a missing return on the STARTTLS crypto-failure path |
| `app/Repositories/EmailLogRepository.php` | `updateStatus()` added for the queued→sent/failed flip; `record()` uses `db()->execute()`'s affected-rows return |
| `app/Mail/Mailer.php` | `lastError()` passthrough |

## 5. Routes added (Phase 9.5)

| Method | Path | Action | Middleware |
|---|---|---|---|
| GET | `/settings` | the Settings page (email toggles) | Auth |
| POST | `/settings/email-preferences` | save the five toggles | Auth + CSRF |

## 6. How each workflow flows

**A follow emails its follower:** `FollowService::follow()` →
`NotificationDispatcher::notify('author_followed', …)` → in-app
notification (unchanged) → `EmailNotificationService::dispatch()` →
enabled? mapped? recipient? → preference `follow` on? → dedupe insert
→ log transport writes the styled message to `storage/logs/email.log`
and the `email_logs` row reads `sent`. Re-firing the identical event
inserts nothing (`email.dedupe_skipped`).

**Settings save (fetch):** the page posts the five checkboxes with
`X-Requested-With: fetch`; presence = on (an unchecked box is simply
absent); the controller validates keys against `PREFERENCE_KEYS`,
persists through the service, answers `{ok, preferences}`. The no-JS
form answers a redirect + flash "Email preferences saved."

**The queue:** with `EMAIL_QUEUE_ENABLED=true` the dispatch writes a
`pending` row + `queued` audit and returns; `processQueue()` (worker
or CLI) delivers the batch, flipping both rows to `sent` (or `failed`
with the error). A second run with nothing left is a no-op.

## 7. Verification

### Suite added

| Suite | Checks | Focus |
|---|---|---|
| `tests/EmailNotificationTest.php` | 86 | config defaults + message validation; preferences (defaults/upsert/unknown key/schema-tamper); template rendering of all 9 catalog types + escaping; log-transport pipeline (sent audit/file, dedupe, opt-out skip, milestone never emails, missing recipient, disabled no-op); queue generation/delivery; SMTP failure as 'failed' audit; a full SMTP handshake against a tiny in-process server (EHLO/MAIL/RCPT/DATA/QUIT); the dispatcher hook through the real follow flow (incl. broken-SMTP resilience and the bare 9.2 dispatcher); the Settings page (render, fetch JSON save, no-JS flash probe, guest gate probe); env-override probe |

The suite runs on its own throwaway database
(`database/email_notification_test.db`), exits non-zero on failure and
prints a per-check PASS/FAIL list.

### Regression

All existing suites were re-run (Auth, Browse, Landing, Library,
Personalization, Recommendation Architecture/Dashboard/Library
Integration/Optimization, Reviews, Review Integration, Follow,
Notification, Notification API, Notification Center) with the three
new migrations in the schema and the dispatcher's new nullable
argument in place:

```text
Full run: 0 failures — the email channel changed nothing in 9.1–9.4.
```

## 7a. Phase 9.6 QA hardening (backported to this module)

The Phase 9.6 verification audit fixed four things here:

| File | Change |
|---|---|
| `database/migrations/0029_add_phase9_qa_indexes.php` | adds `idx_email_queue_dedupe UNIQUE(user_id, type, dedupe_key)` + `idx_email_queue_user (user_id)` on `email_queue` and drops the redundant `idx_notifications_user (user_id)` (replaced by the composite covering indexes) |
| `app/Mail/SmtpTransport.php` | **TLS certificate verification ON by default** — the stream context now sets `verify_peer` / `verify_peer_name` from a new `smtp.verify_peer` config key (`SMTP_VERIFY_PEER`, default `true`), closing a MitM hole |
| `app/Services/EmailNotificationService.php` | **the queue claims the audit row FIRST** (`recordAttempt()` now returns 1/0); a re-fired event on a colliding dedupe key is dropped (`email.dedupe_skipped`) and **no queue row is enqueued** — previously a race could enqueue a second row the worker would send |
| `app/Repositories/EmailQueueRepository.php` | `enqueue()` uses `ON CONFLICT (user_id, type, dedupe_key) DO NOTHING` as a second line of defence |

`EMAIL_CONFIGURATION.md` and `EMAIL_NOTIFICATIONS.md` document both
changes; the `.env` / `.env.example` pair define `SMTP_VERIFY_PEER`.
Details and the full verification report live in
`docs/PHASE_9_6_QA_AUDIT.md`.

## 8. Preparation notes for the next phases

- **Queue worker:** `processQueue()` is production-ready; it needs
  only a scheduler (cron / console) binding.
- **Author releases:** `author_release` maps to the existing `follow`
  preference and renders today — a producer is purely additive.
- **Account emails:** `welcome`, `password_reset`,
  `email_verification` templates and subjects exist; they need only
  producers (auth flows) and any per-type config (verify URLs).
- **Newsletter:** the digest template + `newsletter` toggle exist; a
  scheduler would be the producer.
- **Admin broadcasts (9.3):** the dispatcher already gates
  `system_announcement` / `admin_alert`; mapping them to an email
  type in `TYPE_MAP` (respecting the forced/opt-out semantics) is a
  one-line add.
