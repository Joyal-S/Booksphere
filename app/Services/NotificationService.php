<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\Core\Logger;
use BookSphere\App\Exceptions\NotificationException;
use BookSphere\App\Models\Notification;

/**
 * NotificationService
 *
 * The business logic of the Notification module (Phase 9.2,
 * blueprint Task 3) - the orchestrator of the user-facing side: the
 * center reads, the state changes and the preference toggles.
 * CREATION is owned by the NotificationDispatcher (this service
 * delegates to it, never touching the tables directly); CONTENT is
 * owned by the NotificationFormatter.
 *
 *     - notifyFor()         -> ONE recipient (the dispatcher's
 *                               single path; returns 0 when the
 *                               recipient opted out)
 *     - fanOut()            -> an arbitrary recipient list (one
 *                               INSERT ... SELECT)
 *     - fanOutForAuthor()   -> every follower of an author (one
 *                               INSERT ... SELECT FROM author_follows)
 *     - page()              -> one page of the center (the same
 *                               paginate() payload shape as
 *                               LibraryRepository::paginate():
 *                               items, total, page, pages,
 *                               per_page, has_prev, has_next)
 *     - unreadCount()       -> the badge number (the covering
 *                               (user_id, is_read) index)
 *     - markRead() / markAllRead() / delete() / deleteAll() -> the
 *       owner-scoped state changes, idempotent, each logged
 *     - preferences() / updatePreference() -> the seven opt-out
 *       toggles (unknown category -> NotificationException::
 *       invalidPreference; the repository's allowlist is the last
 *       line of defence)
 *     - types()             -> the catalog keys (single source of
 *                               truth: NotificationFormatter::TEMPLATES)
 *     - prune()             -> the reserved retention sweep (a
 *                               future cron / console)
 *
 * Tabs: 'all' | 'unread' | 'read' - an unknown tab falls back to
 * 'all' (never an error). Pagination bounds: page clamped to
 * [1, pages], perPage to [1, 50].
 *
 * Dependencies:
 *     - Notification model (facade) for the rows.
 *     - NotificationDispatcher for every creation.
 *     - Logger (optional, defaults to the application log).
 */
final class NotificationService
{
    /** The center's tabs. */
    public const TABS = ['all', 'unread', 'read'];

    /**
     * The center page's TYPE GROUPS (Phase 9.4, blueprint Task 3): the
     * user-facing filter chips in front of the low-level type keys. A
     * group maps to the exact catalog members that belong to it; the
     * keys come from NotificationFormatter::TEMPLATES, so a group can
     * never invent a type the database CHECK constraint rejects.
     *
     *     follow         -> following an author + a new release
     *     library        -> shelf milestones + wishlist reminders
     *     review         -> a helpful vote + a reply (community)
     *     recommendation -> a fresh personalized shelf
     *     system         -> announcements, admin alerts, account notices
     *
     * Every one of the ten catalog types appears in exactly one group,
     * so no filter chip can ever hide a whole class of notifications.
     */
    public const FILTER_GROUPS = [
        'follow'         => ['author_followed', 'author_new_release'],
        'library'        => ['library_milestone', 'wishlist_reminder'],
        'review'         => ['review_reacted', 'review_replied'],
        'recommendation' => ['recommendation_ready'],
        'system'         => ['system_announcement', 'admin_alert', 'account_notice'],
    ];

    /**
     * The preference categories (migration 0024 - six opt-out
     * toggles). The single source of truth for the toggle surface;
     * the repository mirrors them in its own allowlist as the last
     * line of defence.
     */
    public const PREFERENCE_CATEGORIES = [
        'author_followed',
        'author_activity',
        'community',
        'recommendations',
        'wishlist_reminders',
        'system_announcements',
    ];

    /** The pagination bounds of the center. */
    public const PER_PAGE_DEFAULT = 25;
    public const PER_PAGE_MAX     = 50;

    private readonly Logger $logger;

    public function __construct(
        private readonly Notification $notifications,
        private readonly NotificationDispatcher $dispatcher,
        ?Logger $logger = null,
    ) {
        $this->logger = $logger ?? new Logger(root_path('storage/logs/application.log'));
    }

    // --- Creation (delegated to the dispatcher) ---------------------------

    /**
     * The type catalog keys - the single source of truth (the map is
     * described once, in NotificationFormatter::TEMPLATES; the
     * database CHECK constraint in migration 0023 mirrors it).
     *
     * @return array<int, string>
     */
    public function types(): array
    {
        return array_keys(NotificationFormatter::TEMPLATES);
    }

    /**
     * Create one notification for one recipient (system / admin
     * path). Honors the recipient's preference unless $force.
     * Returns the new row id, or 0 when the preference suppressed it.
     */
    public function notifyFor(int $userId, string $type, array $context, bool $force = false): int
    {
        return $this->dispatcher->notify($type, $context, $userId, $force) ?? 0;
    }

    /**
     * The batched fan-out to an arbitrary recipient list.
     *
     * @param array<int> $recipientUserIds
     */
    public function fanOut(string $type, array $context, array $recipientUserIds): int
    {
        return $this->dispatcher->fanOut($type, $context, $recipientUserIds);
    }

    /**
     * The batched fan-out to every follower of one author (the
     * author_new_release path).
     */
    public function fanOutForAuthor(string $type, array $context, int $authorId): int
    {
        return $this->dispatcher->fanOutForAuthor($type, $context, $authorId);
    }

    // --- Center reads -----------------------------------------------------

    /**
     * One page of the notification center: the paginate() payload in
     * the exact shape the library grid ships (items, total, page,
     * pages, per_page, has_prev, has_next). An unknown tab falls
     * back to 'all'; page and perPage are clamped to their bounds.
     *
     * $types is an optional type-group member list (Phase 9.4, the
     * "Follow / Library / Review / ..." chips): it is intersected
     * against the catalog, so a tampered or unknown value is simply
     * dropped - an empty result means "no type filter".
     *
     * @param array<int, string> $types
     * @return array<string, mixed>
     */
    public function page(int $userId, string $tab = 'all', int $page = 1, int $perPage = 25, array $types = []): array
    {
        $tab     = in_array($tab, self::TABS, true) ? $tab : 'all';
        $perPage = max(1, min(self::PER_PAGE_MAX, $perPage));

        $valid  = $this->types();
        $types  = array_values(array_unique(array_intersect($types, $valid)));

        $total = $this->notifications->countForUser($userId, $tab, $types);
        $pages = max(1, (int) ceil($total / $perPage));
        $page  = max(1, min($pages, $page));

        return [
            'items'    => $this->notifications->forUser($userId, $tab, ($page - 1) * $perPage, $perPage, $types),
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $perPage,
            'has_prev' => $page > 1,
            'has_next' => $page < $pages,
        ];
    }

    /**
     * The owner-scoped single read the read/delete endpoints gate on
     * first: the notification row when it belongs to the user, null
     * otherwise. The IDOR shield of the API - a foreign row answers
     * null exactly like a missing one, so one user can never probe
     * another user's notification ids.
     *
     * @return array<string, mixed>|null
     */
    public function findOwnedBy(int $id, int $userId): ?array
    {
        return $this->notifications->findOwnedBy($id, $userId);
    }

    /**
     * The badge number: the user's unread rows (a COUNT over the
     * (user_id, is_read) covering index).
     */
    public function unreadCount(int $userId): int
    {
        return $this->notifications->unreadCount($userId);
    }

    // --- State changes -----------------------------------------------------

    /**
     * Mark one notification read (owner-scoped, idempotent - a row
     * already read answers false, never an error).
     */
    public function markRead(int $id, int $userId): bool
    {
        $changed = $this->notifications->markRead($id, $userId);

        if ($changed) {
            $this->logger->info('notification.read', ['id' => $id, 'user_id' => $userId]);
        }

        return $changed;
    }

    /**
     * Mark every notification of the user read. Returns the number
     * of rows actually changed.
     */
    public function markAllRead(int $userId): int
    {
        $changed = $this->notifications->markAllRead($userId);

        if ($changed > 0) {
            $this->logger->info('notification.read_all', ['user_id' => $userId, 'changed' => $changed]);
        }

        return $changed;
    }

    /**
     * Delete one notification (owner-scoped; a foreign or missing
     * row answers false - no existence leak).
     */
    public function delete(int $id, int $userId): bool
    {
        $changed = $this->notifications->deleteOwnedBy($id, $userId);

        if ($changed) {
            $this->logger->info('notification.deleted', ['id' => $id, 'user_id' => $userId]);
        }

        return $changed;
    }

    /**
     * Mark one notification UNREAD again - the other half of the
     * read-state toggle the center offers (Phase 9.4). Owner-scoped
     * and idempotent like markRead(); a row already unread answers
     * false, never an error.
     */
    public function markUnread(int $id, int $userId): bool
    {
        $changed = $this->notifications->markUnread($id, $userId);

        if ($changed) {
            $this->logger->info('notification.unread', ['id' => $id, 'user_id' => $userId]);
        }

        return $changed;
    }

    /**
     * Delete a SET of the user's notifications in one round trip
     * (the Phase 9.4 bulk action). Foreign ids are simply not
     * touched - the repository's owner scoping is the gate.
     *
     * @param array<int> $ids
     */
    public function deleteMany(array $ids, int $userId): int
    {
        $changed = $this->notifications->deleteMany($ids, $userId);

        if ($changed > 0) {
            $this->logger->info('notification.bulk_deleted', ['user_id' => $userId, 'changed' => $changed]);
        }

        return $changed;
    }

    /**
     * Delete the user's whole notification history. Returns the
     * number of rows removed.
     */
    public function deleteAll(int $userId): int
    {
        $changed = $this->notifications->deleteAll($userId);

        if ($changed > 0) {
            $this->logger->info('notification.history_cleared', ['user_id' => $userId, 'changed' => $changed]);
        }

        return $changed;
    }

    // --- Preferences -------------------------------------------------------

    /**
     * The user's seven preference toggles (defaults, all on, when no
     * row exists).
     *
     * @return array<string, int> Category -> 0/1
     */
    public function preferences(int $userId): array
    {
        return $this->notifications->preferences($userId);
    }

    /**
     * Toggle one preference category. An unknown category raises
     * NotificationException::invalidPreference (the repository's
     * allowlist stays as the last line of defence).
     */
    public function updatePreference(int $userId, string $category, bool $enabled): void
    {
        if (!in_array($category, self::PREFERENCE_CATEGORIES, true)) {
            throw NotificationException::invalidPreference($category);
        }

        $this->notifications->updatePreference($userId, $category, $enabled);
        $this->logger->info('notification.preference_changed', [
            'user_id'  => $userId,
            'category' => $category,
            'enabled'  => $enabled,
        ]);
    }

    // --- Retention -----------------------------------------------------------

    /**
     * The reserved retention sweep: delete every notification older
     * than the given number of days (a future cron / console runs
     * this; the (created_at) index covers the WHERE).
     */
    public function prune(int $days): int
    {
        $changed = $this->notifications->prune($days);

        if ($changed > 0) {
            $this->logger->info('notification.pruned', ['days' => $days, 'changed' => $changed]);
        }

        return $changed;
    }
}
