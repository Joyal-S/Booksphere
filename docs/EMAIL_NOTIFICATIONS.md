# Email Notifications (Phase 9.5)

> **Module:** Email Notification System — **Doc type:** developer guide
>
> How the optional email channel works: the dispatcher hook, the
> service pipeline, the type catalog, the preference gate, the dedupe
> key, the templates, and how to add a new email type.

## 1. Where email sits in the stack

Email is a **read-only, additive listener** on the existing
`NotificationDispatcher`. Every in-app notification that fires
(`author_followed`, `review_reacted`, `review_replied`,
`recommendation_ready`) is pinged; the dispatcher forwards the same
event to the email stack when one is present. Sending email is never
the responsibility of a controller, service or repository — they only
ever trigger the dispatcher.

```
FollowService ─┐
ReviewService ─┤
LibraryService ┼─► NotificationDispatcher::notify(type, context, user, content)
               │     ├─► notifications table (in-app, unchanged)
               │     └─► EmailNotificationService::dispatch(type, context, userId, content)
               │            ├─ enabled?  transport?  recipient exists?
               │            ├─ preference gate (5 toggles)
               │            ├─ dedupe key (email_logs UNIQUE)
               │            ├─ queue or inline
               │            └─ Mailer → SmtpTransport | LogEmailTransport
               ▼
          email_logs (audit)   email_queue (pending when queued)
```

The email service constructor accepts **null**: a dispatcher built
without it behaves exactly as in phases 9.2–9.4.

## 2. The type catalog

`app/Mail/EmailType.php` is the single source of type strings:

`FOLLOW`, `REVIEW`, `REPLY`, `RECOMMENDATION`, `AUTHOR_RELEASE`,
`NEWSLETTER`, `WELCOME`, `PASSWORD_RESET`, `EMAIL_VERIFICATION`.

The notification types they pair with (in
`EmailNotificationService::TYPE_MAP`) are:

| Dispatcher event | Email type | Preference key |
|---|---|---|
| `author_followed` | `follow` | `follow` |
| `author_new_release` | `author_release` | `follow` |
| `review_reacted` | `review` | `review` |
| `review_replied` | `reply` | `reply` |
| `recommendation_ready` | `recommendation` | `recommendations` |

`library_milestone` intentionally has **no** email type — library
events stay in-app only.

## 3. The dispatch pipeline

`EmailNotificationService::dispatch($notifyType, $context, $userId, $content)`
steps, each of which can stop the send without raising:

1. **Switched on** — `config('email.enabled')`; otherwise a silent no-op.
2. **Mapped** — the notify type must exist in `TYPE_MAP`; unknown
   types (e.g. `library_milestone`) stop silently.
3. **Recipient** — the user exists and has a valid, non-empty email;
   otherwise `email.recipient_missing` is logged (not fatal).
4. **Preference gate** — the subject's preference key (`email_preferences`
   row, default 1/ON). Opted-out → an audit row with status `skipped`.
5. **Dedupe** — the key is
   `sha256(type . '|' . userId . '|' . json_encode(context))`, stored
   in a `UNIQUE (user_id, type, dedupe_key)` column of `email_logs`.
   A re-fired identical event inserts nothing and logs
   `email.dedupe_skipped` — the same event can never email twice.
6. **Delivery** — queued (a `queued` audit row + a `pending` queue
   row) or inline through the Mailer. Inline success → `sent` audit +
   `email.sent` log; inline failure → the audit row flips to `failed`
   with the error text + `email.send_failed`.

**Queue ordering (Phase 9.6 QA fix):** the audit row is claimed
**first** (`recordAttempt(..., 'queued', $key)` returns 1 or 0). A
collision (return 0 — a re-fired identical event) becomes
`email.dedupe_skipped`, the send is cancelled and **no queue row is
ever enqueued**. The `email_queue` insert then uses the same
`ON CONFLICT (user_id, type, dedupe_key) DO NOTHING` as a second line
of defence. Previously the queue wrote first and audited afterwards,
so a race could enqueue a duplicate that the worker would actually
send; now the audit is authoritative and the queue can never drift
from it.

Every dispatch is wrapped in `try/catch (\Throwable)` — the most
broken SMTP configuration in the world cannot break a request.

## 6. Preferences (the Settings page)

Each user row in `email_preferences` holds five 0/1 toggles
(`follow`, `review`, `reply`, `recommendations`, `newsletter`),
migrated with `DEFAULT 1` and CHECK-constrained. The Settings page
(`GET /settings`), only visible behind AuthMiddleware, writes them
through `POST /settings/email-preferences`:
- A **fetch** caller (`X-Requested-With: fetch`) gets `{ok, preferences}` JSON.
- A **no-JS** form gets a redirect + flash.
- Toggle keys are validated against `PREFERENCE_KEYS` (unknown keys →
  422) and, a second barrier, the repository's column allowlist
  (a tampered key cannot touch the schema).
- Checkbox semantics: present = on, absent = off (an unchecked box is
  not submitted) — so the controller uses `$request->input($key) !== null`.
- The session user id is the only identity; there is no user id in the
  URL to tamper with.

## 7. Templates

Emails are plain `emails` view fragments — no external template
engine:

- `emails/layout` — the table-based, largely inline-CSS shell
  (responsive ≤600px, light/accessible, `aria-labelledby`).
- `emails/partials/_header` — brand + tagline, links `app_url`.
- `emails/partials/_cta` — a filled primary button (skipped when the
  CTA URL is empty).
- `emails/partials/_footer` — the Settings (unsubscribe) link, year
  + copyright.
- `emails/partials/_body-*` — one per email type. Content (titles,
  messages, URLs, names) is escaped with `e()` before it ever reaches
  the HTML, and the subject is escaped/encoded on the wire, so
  injection (CR/LF, HTML) is rejected twice.

`subjectFor()` uses the in-app formatted title for the transactional
types (`following …`, `review …`), with fixed subjects for the
account-emails (`welcome`, `password_reset`, `email_verification`).

## 8. Adding a new email type

1. Add a constant to `EmailType` and a body partial
   `_body-<type>.php` (mirror `_body-follow.php`).
2. Map it in `TYPE_MAP` (notifyType → email type → preference key) if
   it is driven by an in-app notification.
3. Add a `SUBJECT_TEMPLATES` / `ACTION_LABELS` entry if it uses a
   non-default subject or button label.
4. Test with `php tests/EmailNotificationTest.php` (the suite renders
   every type in `EmailType::all()`).

## 9. Verification

`tests/EmailNotificationTest.php` covers all of the above end to end:
config defaults + `EMAIL->` validation, preferences save/tamper,
template rendering of every catalog type, the log/SMTP/queue/SMTP
failure paths, the dispatcher hook (via the real follow flow) and the
Settings page (fetch, no-JS and guest). It exits non-zero on the
first failure and prints the check list.