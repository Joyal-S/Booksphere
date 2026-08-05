<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\Core\Logger;
use BookSphere\App\Models\Notification;

/**
 * NotificationDispatcher
 *
 * The single door through which ANY module creates notifications
 * (Phase 9.2, blueprint Task 3) - no service ever talks to the
 * notification tables directly. The stable event surface every
 * future caller (the recommendation engine, the review module, the
 * library module, the admin broadcast, the email/push phase) speaks
 * to:
 *
 *     1. resolve the type against the catalog (an unknown type
 *        raises NotificationException::invalidType through the
 *        formatter)
 *     2. ask the formatter for the content row (title / message /
 *        icon / color / action_url) - formatted at write time so
 *        history stays truthful
 *     3. gate the recipients through notification_preferences: the
 *        per-type category opt-out (force bypasses the gate -
 *        announcements always deliver)
 *     4. write the rows - a single create for one recipient, one
 *        INSERT ... SELECT for a fan-out (no N+1)
 *     5. enqueue the channel rows in notification_deliveries (a
 *        no-op in 9.2 - the outbox ships empty so email/push are
 *        purely additive later)
 *     6. log notification.created with the type and the recipient
 *        count
 *
 * The preference category of each type is fixed here (the closed
 * TYPE_PREFERENCES map): author_followed gates on the
 * author_followed toggle, author_new_release and library_milestone
 * on author_activity, review_reacted and review_replied on
 * community, recommendation_ready on recommendations,
 * wishlist_reminder on wishlist_reminders. system_announcement,
 * admin_alert and account_notice have no opt-out - they always
 * deliver.
 *
 * Dependencies:
 *     - Notification model (facade) for the rows.
 *     - NotificationFormatter (pure) for the content.
 *     - Logger (optional, defaults to the application log).
 */
final class NotificationDispatcher
{
    /**
     * type -> preference category gate. The seven categories of
     * migration 0024; a type absent from this map delivers to
     * everyone (system_announcement, admin_alert, account_notice).
     */
    private const TYPE_PREFERENCES = [
        'author_followed'      => 'author_followed',
        'author_new_release'   => 'author_activity',
        'review_reacted'       => 'community',
        'review_replied'       => 'community',
        'recommendation_ready' => 'recommendations',
        'wishlist_reminder'    => 'wishlist_reminders',
        'library_milestone'    => 'author_activity',
    ];

    private readonly Logger $logger;

    public function __construct(
        private readonly Notification $notifications,
        private readonly NotificationFormatter $formatter,
        ?Logger $logger = null,
    ) {
        $this->logger = $logger ?? new Logger(root_path('storage/logs/application.log'));
    }

    /**
     * Create ONE notification for ONE recipient (the system / admin
     * path - and the actor's own confirmation ping after a follow).
     *
     * Returns the new row id, or NULL when the recipient's
     * preference for the type's category is off (unless $force).
     */
    public function notify(string $type, array $context, int $userId, bool $force = false): ?int
    {
        $content = $this->content($type, $context);

        // The per-user preference check of the single-recipient path.
        if (!$force && !$this->preferenceAllows($userId, $type)) {
            return null;
        }

        $id = $this->notifications->create($content + ['user_id' => $userId]);

        $this->outbox($id, $userId);
        $this->logger->info('notification.created', ['type' => $type, 'recipients' => 1, 'id' => $id]);

        return $id;
    }

    /**
     * The batched fan-out to an arbitrary recipient list: one
     * INSERT ... SELECT FROM users WHERE id IN (...) statement,
     * with the preference gate applied inside the SQL (unless
     * $force). Returns the number of rows created.
     *
     * @param array<int> $recipientUserIds
     */
    public function fanOut(string $type, array $context, array $recipientUserIds, bool $force = false): int
    {
        if ($recipientUserIds === []) {
            return 0;
        }

        $content = $this->content($type, $context);

        return $this->notifications->fanOutByUsers(
            $content,
            array_values($recipientUserIds),
            $force ? null : $this->preferenceCategory($type),
        );
    }

    /**
     * The author fan-out: "an author you follow released a book".
     * One INSERT ... SELECT FROM author_follows creates a row for
     * EVERY follower of the author in a single statement - the
     * flagship bulk path of the blueprint (Task 1). The preference
     * gate applies inside the SQL unless $force.
     */
    public function fanOutForAuthor(string $type, array $context, int $authorId, bool $force = false): int
    {
        $content = $this->content($type, $context);

        $created = $this->notifications->fanOutByAuthor(
            $content,
            $authorId,
            $force ? null : $this->preferenceCategory($type),
        );

        if ($created > 0) {
            $this->logger->info('notification.created', [
                'type'      => $type,
                'recipients' => $created,
                'author_id' => $authorId,
            ]);
        }

        return $created;
    }

    // --- Internals -------------------------------------------------------

    /**
     * Resolve the content row for a type + context (the formatter
     * raises NotificationException::invalidType for unknown types).
     * The catalog type rides along so every create / fan-out path
     * (which all reference $content['type']) receives it.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed> type / title / message / icon /
     *                              color / action_url
     */
    private function content(string $type, array $context): array
    {
        return [
            'type' => $type,
        ] + $this->formatter->format($type, $context);
    }

    /**
     * The preference category a type gates on (null = no gate).
     */
    private function preferenceCategory(string $type): ?string
    {
        return self::TYPE_PREFERENCES[$type] ?? null;
    }

    /**
     * Whether a recipient's preferences allow a single-recipient
     * notification of the type. Types without a mapped category are
     * always allowed.
     */
    private function preferenceAllows(int $userId, string $type): bool
    {
        $category = $this->preferenceCategory($type);

        if ($category === null) {
            return true;
        }

        $preferences = $this->notifications->preferences($userId);

        return (int) ($preferences[$category] ?? 1) === 1;
    }

    /**
     * The reserved channel-outbox hook: queue a pending delivery row
     * per channel. A NO-OP in Phase 9.2 by design (migration 0025
     * ships empty so email/push are purely additive later) - kept as
     * a stub so the write path of a later phase plugs in here
     * without touching the dispatcher's flow.
     */
    private function outbox(int $notificationId, int $userId): void
    {
        // Reserved: the email / push phases enqueue their channels here.
        unset($notificationId, $userId);
    }
}
