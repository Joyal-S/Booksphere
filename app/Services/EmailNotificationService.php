<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\Core\Logger;
use BookSphere\App\Core\View;
use BookSphere\App\Exceptions\EmailException;
use BookSphere\App\Mail\EmailMessage;
use BookSphere\App\Mail\EmailType;
use BookSphere\App\Mail\Mailer;
use BookSphere\App\Models\EmailLog;
use BookSphere\App\Models\EmailPreference;
use BookSphere\App\Models\EmailQueue;
use BookSphere\App\Models\User;

/**
 * EmailNotificationService
 *
 * The orchestrator of the email notification pipeline (Phase 9.5) -
 * the ONLY class that decides whether, what and when an email is
 * built and sent. Nothing else in the application ever touches the
 * mail transports, the email tables or the templates directly.
 *
 * The pipeline of dispatch() (the dispatcher hook, called after an
 * in-app notification was created):
 *
 *     1. GATE  - is email enabled at all (config)? Is the
 *                notification type one that HAS an email (the
 *                TYPE_MAP)? The rest are in-app only by design.
 *     2. GATE  - does the recipient exist with a valid address?
 *     3. GATE  - the per-user preference toggle for the email's
 *                subject (a user can silence emails while keeping
 *                in-app notifications - separate tables).
 *     4. BUILD - the subject + the HTML from the shared templates
 *                (the in-app title/message travel into the email,
 *                so both channels always agree).
 *     5. DEDUPE- the (user_id, type, dedupe_key) unique row in
 *                email_logs: the SAME event can never email twice,
 *                whatever re-fires it.
 *     6. SEND  - queue mode: write a pending row in email_queue and
 *                log 'queued' (a worker delivers later - generation
 *                is fully separated from delivery). Inline mode:
 *                deliver through the Mailer right away.
 *
 * GRACEFUL FAILURE is the module's backbone: every step - a dead
 * SMTP server, a missing user, an invalid address, even an
 * unexpected exception - is logged (application log + the email_logs
 * audit trail, NEVER shown to end-users) and the caller's request
 * proceeds untouched. dispatch() and processQueue() never throw.
 *
 * The transactional emails (welcome, password reset, verification)
 * have no in-app event; their templates exist and render through the
 * same subjectFor()/htmlFor() builders, so the future features that
 * produce them only add a call site.
 *
 * Dependencies:
 *     - User model (recipient lookup).
 *     - EmailPreference / EmailLog / EmailQueue facades (the rows).
 *     - Mailer (the never-throw delivery door).
 *     - config('email') group, overridable per instance for tests.
 */
final class EmailNotificationService
{
    /** The five user-facing preference toggles (migration 0026). */
    public const PREFERENCE_KEYS = ['follow', 'review', 'reply', 'recommendations', 'newsletter'];

    /**
     * In-app notification type -> [email type, preference key]. A
     * type absent from this map is in-app only and never emails
     * (library milestones, wishlist reminders, system announcements,
     * admin alerts, account notices).
     */
    private const TYPE_MAP = [
        'author_followed'      => [EmailType::FOLLOW, 'follow'],
        'author_new_release'   => [EmailType::AUTHOR_RELEASE, 'follow'],
        'review_reacted'       => [EmailType::REVIEW, 'review'],
        'review_replied'       => [EmailType::REPLY, 'reply'],
        'recommendation_ready' => [EmailType::RECOMMENDATION, 'recommendations'],
    ];

    /**
     * The subject of an email type. "{title}" is the formatted in-app
     * title, so the email subject and the in-app headline can never
     * disagree about what happened. The transactional types have
     * fixed subjects.
     */
    private const SUBJECT_TEMPLATES = [
        EmailType::FOLLOW            => '{title}',
        EmailType::AUTHOR_RELEASE    => '{title}',
        EmailType::REVIEW            => '{title}',
        EmailType::REPLY             => '{title}',
        EmailType::RECOMMENDATION    => '{title}',
        EmailType::WELCOME           => 'Welcome to BookSphere',
        EmailType::PASSWORD_RESET    => 'Reset your BookSphere password',
        EmailType::EMAIL_VERIFICATION => 'Verify your BookSphere email address',
        EmailType::NEWSLETTER        => 'BookSphere — {title}',
    ];

    /**
     * The CTA button label of each email type.
     */
    private const ACTION_LABELS = [
        EmailType::FOLLOW            => 'View author',
        EmailType::AUTHOR_RELEASE    => 'View book',
        EmailType::REVIEW            => 'View review',
        EmailType::REPLY             => 'See the reply',
        EmailType::RECOMMENDATION    => 'See your picks',
        EmailType::WELCOME           => 'Start exploring',
        EmailType::PASSWORD_RESET    => 'Reset password',
        EmailType::EMAIL_VERIFICATION => 'Verify email',
        EmailType::NEWSLETTER        => 'Explore BookSphere',
    ];

    /**
     * @var array<string, mixed>
     */
    private readonly array $config;

    private readonly Logger $logger;

    /**
     * @param array<string, mixed>|null $config The config('email') group
     *                                          (defaults to the loaded config)
     */
    public function __construct(
        private readonly User $users,
        private readonly EmailPreference $preferences,
        private readonly EmailLog $logs,
        private readonly EmailQueue $queue,
        private readonly Mailer $mailer,
        ?array $config = null,
        ?Logger $logger = null,
    ) {
        $this->config = $config ?? (array) config('email', []);
        $this->logger = $logger ?? new Logger(root_path('storage/logs/application.log'));
    }

    // --- The dispatcher hook ---------------------------------------------

    /**
     * The ONLY entry point the notification stack calls: after an
     * in-app notification was created, ask whether this event also
     * deserves an email. NEVER throws and NEVER breaks the caller -
     * every failure is logged and forgotten.
     *
     * @param array<string, mixed> $context The event context (actor,
     *                                      author, book, ...)
     * @param array<string, mixed> $content The formatted in-app row
     *                                      (type, title, message, icon,
     *                                      color, action_url)
     */
    public function dispatch(string $type, array $context, int $userId, array $content): void
    {
        // Gate 1 - the master switch. Email is OPTIONAL: with the
        // feature off this method is a no-op, exactly as before 9.5.
        if (!$this->enabled()) {
            return;
        }

        $mapped = self::TYPE_MAP[$type] ?? null;

        // Gate 2 - the event has no email subject by design (milestones,
        // system pings). In-app only.
        if ($mapped === null) {
            return;
        }

        [$emailType, $preferenceKey] = $mapped;

        try {
            $user = $this->users->findById($userId);

            // Gate 3 - the recipient exists with a usable address.
            // A missing user (deleted mid-request) is logged, not fatal.
            if ($user === null) {
                $this->logger->warning('email.recipient_missing', ['type' => $type, 'user_id' => $userId]);

                return;
            }

            $address = (string) ($user['email'] ?? '');
            $name    = (string) ($user['full_name'] ?? '');

            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                $this->logger->warning('email.recipient_invalid', ['type' => $type, 'user_id' => $userId]);

                return;
            }

            // Gate 4 - the per-user email preference for the subject.
            if (!$this->preferenceAllows($userId, $preferenceKey)) {
                $this->recordAttempt($emailType, $userId, $address, $content, 'skipped');
                $this->logger->info('email.preference_skipped', [
                    'type'   => $type,
                    'email_type' => $emailType,
                    'user_id' => $userId,
                ]);

                return;
            }

            // The dedupe key: one hash per EVENT (type + context +
            // user). The same event re-fired by any path can never
            // email twice - the unique index is the final gate.
            $dedupeKey = hash('sha256', $type . '|' . $userId . '|' . (string) json_encode($context));

            $subject = $this->subjectFor($emailType, $content, $name);
            $html    = $this->htmlFor($emailType, $content, $name);

            // Queue mode: claim the audit slot FIRST (the unique
            // email_logs row - the same dedupe gate as the inline
            // path), then write the outbox row. A re-fired event
            // collides on the claim and is dropped here - it can
            // never produce a second pending row, so the worker can
            // never send the same event twice. The email_queue
            // UNIQUE(user_id, type, dedupe_key) index (migration
            // 0029) is the second line of defence.
            if ($this->queueEnabled()) {
                $claimed = $this->recordAttempt($emailType, $userId, $address, $content, 'queued', $dedupeKey);

                if ($claimed === 0) {
                    $this->logger->info('email.dedupe_skipped', ['email_type' => $emailType, 'user_id' => $userId]);

                    return;
                }

                $this->queue->enqueue([
                    'user_id'    => $userId,
                    'type'       => $emailType,
                    'to_address' => $address,
                    'to_name'    => $name,
                    'subject'    => $subject,
                    'html'       => $html,
                    'dedupe_key' => $dedupeKey,
                ]);

                $this->logger->info('email.queued', [
                    'email_type' => $emailType,
                    'user_id'    => $userId,
                    'subject'    => $subject,
                ]);

                return;
            }

            $this->deliver($emailType, $userId, $address, $name, $subject, $html, $dedupeKey);
        } catch (\Throwable $e) {
            // The last line of defence: an unexpected exception in any
            // step is contained here - the request never breaks.
            $this->logger->error('email.dispatch_failed', [
                'type'    => $type,
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Deliver ONE message inline: the dedupe gate runs FIRST (the
     * unique email_logs row), then the transport - on failure the
     * audit row flips to 'failed' with the transport's error.
     */
    private function deliver(
        string $emailType,
        int $userId,
        string $address,
        string $name,
        string $subject,
        string $html,
        string $dedupeKey,
    ): void {
        // Gate 5 - claim the audit slot. A collision (0) means the
        // event was already emailed - nothing is sent twice.
        $first = $this->logs->record([
            'user_id'    => $userId,
            'type'       => $emailType,
            'dedupe_key' => $dedupeKey,
            'to_address' => $address,
            'subject'    => $subject,
            'status'     => 'sent',
        ]);

        if ($first === 0) {
            $this->logger->info('email.dedupe_skipped', ['email_type' => $emailType, 'user_id' => $userId]);

            return;
        }

        $message = new EmailMessage($address, $name, $subject, $html);

        if ($this->mailer->send($message)) {
            $this->logger->info('email.sent', [
                'email_type' => $emailType,
                'user_id'    => $userId,
                'subject'    => $subject,
            ]);

            return;
        }

        // The transport refused the message: keep the audit truthful.
        $error = $this->mailer->lastError() ?? 'unknown transport error';
        $this->logs->updateStatus($userId, $emailType, $dedupeKey, 'failed', $error);
    }

    // --- The queue worker -------------------------------------------------

    /**
     * Deliver the oldest pending queue rows (the worker a future
     * cron / console invokes). One row at a time, each failure
     * contained and logged - a dead server never stops the loop.
     * Returns the sent / failed counts.
     *
     * @return array{queued: int, sent: int, failed: int}
     */
    public function processQueue(int $limit = 0): array
    {
        $batch = $limit > 0 ? $limit : (int) ($this->config['queue']['batch'] ?? 25);
        $rows  = $this->queue->pending($batch);

        $queued = count($rows);
        $sent   = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $id       = (int) ($row['id'] ?? 0);
            $userId   = (int) ($row['user_id'] ?? 0);
            $type     = (string) ($row['type'] ?? '');
            $dedupe   = (string) ($row['dedupe_key'] ?? '');

            try {
                $message = new EmailMessage(
                    (string) $row['to_address'],
                    (string) $row['to_name'],
                    (string) $row['subject'],
                    (string) $row['html'],
                );

                if ($this->mailer->send($message)) {
                    $this->queue->markSent($id);
                    $this->logs->updateStatus($userId, $type, $dedupe, 'sent');
                    $this->logger->info('email.queued_sent', [
                        'queue_id' => $id,
                        'type'     => $type,
                        'user_id'  => $userId,
                    ]);
                    $sent++;
                } else {
                    $error = $this->mailer->lastError() ?? 'unknown transport error';
                    $this->queue->markFailed($id, $error);
                    $this->logs->updateStatus($userId, $type, $dedupe, 'failed', $error);
                    $this->logger->error('email.queued_failed', [
                        'queue_id' => $id,
                        'type'     => $type,
                        'user_id'  => $userId,
                        'error'    => $error,
                    ]);
                    $failed++;
                }
            } catch (\Throwable $e) {
                $this->queue->markFailed($id, $e->getMessage());
                $this->logger->error('email.queued_exception', [
                    'queue_id' => $id,
                    'type'     => $type,
                    'user_id'  => $userId,
                    'error'    => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        return ['queued' => $queued, 'sent' => $sent, 'failed' => $failed];
    }

    // --- Preferences -------------------------------------------------------

    /**
     * The user's five email toggles (defaults: all on).
     *
     * @return array<string, int>
     */
    public function preferences(int $userId): array
    {
        return $this->preferences->preferences($userId);
    }

    /**
     * Toggle one email preference. An unknown key raises
     * EmailException::invalidPreference() (the repository's column
     * allowlist stays as the last line of defence).
     */
    public function updatePreference(int $userId, string $key, bool $enabled): void
    {
        if (!in_array($key, self::PREFERENCE_KEYS, true)) {
            throw EmailException::invalidPreference($key);
        }

        $this->preferences->updatePreference($userId, $key, $enabled);
        $this->logger->info('email.preference_changed', [
            'user_id' => $userId,
            'key'     => $key,
            'enabled' => $enabled,
        ]);
    }

    // --- Template builders -------------------------------------------------

    /**
     * Build the subject line of an email type. "{title}" is replaced
     * with the formatted in-app title; the transactional types have
     * fixed subjects.
     *
     * @param array<string, mixed> $content The formatted content row
     */
    public function subjectFor(string $emailType, array $content, string $recipientName = ''): string
    {
        $template = self::SUBJECT_TEMPLATES[$emailType] ?? '{title}';
        $title    = (string) ($content['title'] ?? '');

        return trim(str_replace(['{title}', '{recipient}'], [$title, $recipientName], $template));
    }

    /**
     * Build the full HTML body of an email type (the layout with the
     * brand header, the CTA and the footer) from the shared email
     * templates. All dynamic values are escaped with e() inside the
     * partials.
     *
     * @param array<string, mixed> $content The formatted content row
     */
    public function htmlFor(string $emailType, array $content, string $recipientName = ''): string
    {
        $data = [
            'appName'     => (string) ($this->config['from']['name'] ?? 'BookSphere'),
            'appUrl'      => (string) ($this->config['app_url'] ?? ''),
            'recipient'   => $recipientName,
            'title'       => (string) ($content['title'] ?? ''),
            'message'     => (string) ($content['message'] ?? ''),
            'actionUrl'   => $this->absoluteUrl((string) ($content['action_url'] ?? '')),
            'actionLabel' => self::ACTION_LABELS[$emailType] ?? 'Open BookSphere',
            'year'        => gmdate('Y'),
        ];

        $body = View::fragment('emails.partials._body-' . $emailType, $data);

        return View::fragment('emails.layout', $data + ['bodyHtml' => $body]);
    }

    // --- Internals -------------------------------------------------------

    /**
     * Whether the module is switched on (config('email.enabled')).
     */
    private function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    /**
     * Whether messages are queued instead of sent inline.
     */
    private function queueEnabled(): bool
    {
        return (bool) (($this->config['queue']['enabled'] ?? false));
    }

    /**
     * The preference gate of one email subject.
     */
    private function preferenceAllows(int $userId, string $key): bool
    {
        $preferences = $this->preferences->preferences($userId);

        return (int) ($preferences[$key] ?? 1) === 1;
    }

    /**
     * Record one attempt in the audit trail. The unique
     * (user_id, type, dedupe_key) index drops a replay silently
     * (returns 0), which is exactly the duplicate-send protection.
     *
     * @param array<string, mixed> $content
     * @return int 1 when the attempt row was claimed, 0 on a dedupe
     *             collision (the event was already recorded)
     */
    private function recordAttempt(string $emailType, int $userId, string $address, array $content, string $status, ?string $dedupeKey = null): int
    {
        return $this->logs->record([
            'user_id'    => $userId,
            'type'       => $emailType,
            'dedupe_key' => $dedupeKey,
            'to_address' => $address,
            'subject'    => $this->subjectFor($emailType, $content),
            'status'     => $status,
        ]);
    }

    /**
     * Turn a relative action path into the absolute URL an email can
     * link to; an empty path links to the app root.
     */
    private function absoluteUrl(string $actionUrl): string
    {
        $base = rtrim((string) ($this->config['app_url'] ?? ''), '/');

        if ($actionUrl === '') {
            return $base === '' ? '#' : $base;
        }

        return $base . '/' . ltrim($actionUrl, '/');
    }
}