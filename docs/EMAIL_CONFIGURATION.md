# Email Configuration (Phase 9.5)

> **Module:** Email Notification System — **Doc type:** operations guide
>
> Everything the email channel needs to run is configured in
> `config/email.php`, which reads every value from environment
> variables. There is no hardcoded host, port or credential in the
> repository; the `.env` file (gitignored) is the single source of
> truth per machine. `.env.example` carries the full commented list.

## 1. The master switch

Email is **off by default**:

```dotenv
EMAIL_ENABLED=false
```

With `false` (or unset) the module is a complete no-op: no SMTP
connection is ever attempted, no queue row is written, no log entry is
made, and the application behaves exactly as before the phase. The
Settings page still renders (with an informational banner) and the
toggles still save — they just don't drive anything until this switch
is on.

## 2. Environment variables

| Variable | Default | Meaning |
|---|---|---|
| `EMAIL_ENABLED` | `false` | Master switch of the whole module |
| `EMAIL_FROM_ADDRESS` | `no-reply@booksphere.test` | Sender address on every message |
| `EMAIL_FROM_NAME` | `BookSphere` | Display name of the sender |
| `EMAIL_TRANSPORT` | `log` | `log` = write to the email log file, `smtp` = deliver over the network |
| `SMTP_HOST` | `localhost` | SMTP server host |
| `SMTP_PORT` | `587` | SMTP server port |
| `SMTP_ENCRYPTION` | `starttls` | `none` · `tls` (implicit TLS on connect) · `starttls` (upgrade after EHLO) |
| `SMTP_AUTH` | `false` | Whether to AUTH LOGIN with the credentials below |
| `SMTP_USERNAME` | *(empty)* | SMTP username (only from env, never committed) |
| `SMTP_PASSWORD` | *(empty)* | SMTP password (only from env, never committed) |
| `SMTP_TIMEOUT` | `10` | Per-socket timeout in seconds (connect + reads) |
| `SMTP_VERIFY_PEER` | `true` | Verify the SMTP server's TLS certificate (`true` recommended; set `false` only for local test relays) — **added in the Phase 9.6 QA audit**, see § 3.2 |
| `EMAIL_QUEUE_ENABLED` | `false` | Buffer emails in the `email_queue` table instead of sending inline |
| `EMAIL_QUEUE_BATCH` | `25` | Rows processed per `processQueue()` run |
| `APP_URL` | `http://localhost:8000` | Absolute base URL for links inside emails |

The log file lives at `storage/logs/email.log` (delivery successes,
failures and skips — never exposed to end-users).

## 3. Transport modes

### 3.1 `log` (default — no network)

Every message is appended to `storage/logs/email.log` as a complete
RFC-style message (headers + body) and counted as **sent**. This is
how the full pipeline — preferences, dedupe, templates, queue,
auditing — is exercised on any machine without an SMTP server, and it
is the safe default for development and tests.

### 3.2 `smtp` (real delivery)

Set `EMAIL_TRANSPORT=smtp` and point the `SMTP_*` values at a real
server. The transport is dependency-free: a plain socket speaking
EHLO → (STARTTLS / implicit TLS) → AUTH LOGIN → MAIL FROM → RCPT TO →
DATA (dot-stuffed) → QUIT, with `=?UTF-8?B?...?=` encoded subject and
display name headers. The client never throws: every failure is
reported through `Mailer::lastError()` and recorded in the log.

**TLS verification is ON by default** (Phase 9.6 QA fix): the stream
is opened with `verify_peer` and `verify_peer_name` set from
`SMTP_VERIFY_PEER`, so a MitM cannot silently intercept email in
transit. Set `SMTP_VERIFY_PEER=false` only for local test relays that
present self-signed certificates.

## 4. The queue (worker pattern)

With `EMAIL_QUEUE_ENABLED=true`, generation and delivery are
separated: a triggered notification writes one row to `email_queue`
(status `pending`) plus a `queued` audit row, and the request ends
immediately. A worker delivers the backlog:

```text
php -r "require 'bootstrap/constants.php'; require 'vendor/autoload.php';
(new \BookSphere\App\Services\EmailNotificationService(
  new \BookSphere\App\Models\User(),
  new \BookSphere\App\Models\EmailPreference(),
  new \BookSphere\App\Models\EmailLog(),
  new \BookSphere\App\Models\EmailQueue(),
  new \BookSphere\App\Mail\Mailer(config('email'), new \BookSphere\App\Core\Logger(root_path('storage/logs/app.log'))),
  config('email'),
  new \BookSphere\App\Core\Logger(root_path('storage/logs/app.log')),
))->processQueue();"
```

`processQueue()` delivers up to `EMAIL_QUEUE_BATCH` pending rows,
marks each `sent` (or `failed` with the error), flips the audit row
and returns `['queued' => …, 'sent' => …, 'failed' => …]`. It is
idempotent: a second run with an empty queue is a no-op. Production
should schedule it (cron / task scheduler); nothing in the app
requires it to be run at all.

## 5. Failure behavior (by design)

- The **SMTP transport never throws** — connection refused, TLS
  failure, rejected recipient and every other error path returns
  `false` plus a human-readable `lastError()`.
- `Mailer::send()` catches every `Throwable` — a crash in the
  transport can never propagate into a controller.
- The service wraps the whole dispatch in `try/catch (\Throwable)`:
  a failure is logged (`email.send_failed`, `email.send_exception`)
  and audited as a `failed` row with the error text — **the request
  that triggered the notification never breaks.**
- Missing recipients, opted-out subjects and re-fired events are
  audited (`skipped`) or logged, never escalated to the user.

## 6. Going live checklist

1. Set `EMAIL_ENABLED=true` with `EMAIL_TRANSPORT=log` and trigger a
   follow — confirm the message lands in `storage/logs/email.log`.
2. Switch to `smtp` with test credentials; confirm `email.send_failed`
   / `email.sent` entries in `storage/logs/app.log`.
3. Turn on the queue, run `processQueue()` manually once, watch the
   `email_queue` rows flip to `sent`.
4. Check the Settings page (`/settings`) toggles save for a signed-in
   user (fetch) and without JavaScript (form flash).
