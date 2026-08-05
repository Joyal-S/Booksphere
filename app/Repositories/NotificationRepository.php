<?php

declare(strict_types=1);

namespace BookSphere\App\Repositories;

/**
 * NotificationRepository
 *
 * The data-access layer of the Notification module (Phase 9.2).
 * Every SQL query that touches the module's three tables -
 * notifications, notification_preferences and notification_deliveries
 * - lives here and here only: prepared statements everywhere, the
 * owner-scoping that makes IDOR impossible (findOwnedBy /
 * deleteOwnedBy), and the batched INSERT ... SELECT fan-out that
 * creates one notification per recipient in a single round trip.
 *
 * Responsibilities:
 *
 *     - create - ONE notification row (system / admin announcements,
 *       the single-recipient path of the dispatcher)
 *     - fanOutByAuthor - the "an author you follow released a book"
 *       path: INSERT ... SELECT FROM author_follows, one statement
 *       for any number of followers, with the per-category
 *       preference opt-out applied inside the SQL
 *     - fanOutByUsers - the arbitrary-recipient-list path (e.g. a
 *       shelf-refresh ping to a list of user ids): INSERT ... SELECT
 *       FROM users WHERE id IN (...)
 *     - find / findOwnedBy - the only lookups the controller may
 *       use; findOwnedBy scopes to the recipient so a foreign row is
 *       indistinguishable from a missing one (no existence leak)
 *     - forUser / countForUser - one page of the center
 *       (all / unread / read tabs) + its COUNT denominator
 *     - unreadCount - the badge number (a COUNT over the
 *       (user_id, is_read) covering index)
 *     - markRead / markAllRead / deleteOwnedBy / deleteAll - the
 *       owner-scoped state changes
 *     - preferences / updatePreference - the seven-category opt-out
 *       row (upsert, the INSERT ... ON CONFLICT pattern of the
 *       library preferences)
 *     - enqueueDelivery - the reserved channel outbox hook (a no-op
 *       in 9.2: the table ships empty so email/push are purely
 *       additive later)
 *
 * Rules inherited from the schema (migrations 0023-0025):
 *     - CHECK (type IN (catalog)) - the ten blueprint types are the
 *       only values the database accepts
 *     - CHECK (is_read IN (0, 1)) and the 0/1 preference toggles
 *     - ON DELETE CASCADE on every foreign key
 *     - notifications have no updated_at: rows are immutable after
 *       insert except the read flag
 *
 * Dependencies:
 *     - db() helper (Core\Database singleton), exactly like the
 *       other repositories.
 *
 * How it fits inside MVC:
 *     Controller -> Service (business rules) -> Notification model
 *     (facade) -> NotificationRepository (SQL) -> PDO -> SQLite.
 */
final class NotificationRepository
{
    /**
     * The seven preference categories -> their columns in the
     * notification_preferences table. The ONLY column names the
     * repository will ever write or gate on, so a tampered category
     * value can never reach the SQL text (the same key-filtering
     * approach as LibraryRepository::PREFERENCE_COLUMNS).
     */
    private const PREFERENCE_COLUMNS = [
        'author_followed'      => 'author_followed',
        'author_activity'      => 'author_activity',
        'community'            => 'community',
        'recommendations'      => 'recommendations',
        'wishlist_reminders'   => 'wishlist_reminders',
        'system_announcements' => 'system_announcements',
    ];

    /**
     * The column set of one notification row, written by every
     * create / fan-out statement. created_at is added per statement
     * (the fan-out needs it in the SELECT list).
     */
    private const COLUMNS = 'user_id, type, title, message, icon, color, action_url';

    /**
     * The base SELECT of the center reads.
     */
    private const SELECT = 'id, user_id, type, title, message, icon, color, action_url, is_read, read_at, created_at';

    /**
     * Insert ONE notification row and return its id.
     *
     * @param array<string, mixed> $data Normalized column values:
     *                                    user_id, type, title, message,
     *                                    icon, color, action_url
     */
    public function create(array $data): int
    {
        db()->execute(
            'INSERT INTO notifications (' . self::COLUMNS . ', created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['user_id'],
                $data['type'],
                $data['title'],
                $data['message'],
                $data['icon'],
                $data['color'],
                $data['action_url'] ?? null,
                $this->now(),
            ],
        );

        return (int) db()->lastInsertId();
    }

    /**
     * The author fan-out: "an author you follow did something". One
     * INSERT ... SELECT FROM author_follows creates a row for EVERY
     * follower in a single statement - no N+1, no transaction
     * spanning statements. The per-category opt-out is applied
     * inside the SQL (unless $force bypasses it - announcements
     * always deliver).
     *
     * @param array<string, mixed> $content  The formatted row: type,
     *                                       title, message, icon,
     *                                       color, action_url
     * @param string|null          $preferenceCategory The preference
     *                                       gate (null = no gate)
     * @return int The number of notifications created
     */
    public function fanOutByAuthor(array $content, int $authorId, ?string $preferenceCategory = null): int
    {
        $gate = $this->preferenceGate('f.user_id', $preferenceCategory);

        // created_at is deliberately left to its column DEFAULT: the
        // SELECT must return exactly one value per INSERT column, so
        // the fan-out lists only the stored content columns.
        return db()->execute(
            'INSERT INTO notifications (' . self::COLUMNS . ')
             SELECT f.user_id, ?, ?, ?, ?, ?, ?
             FROM author_follows f
             WHERE f.author_id = ?' . $gate,
            [
                $content['type'],
                $content['title'],
                $content['message'],
                $content['icon'],
                $content['color'],
                $content['action_url'] ?? null,
                $authorId,
            ],
        );
    }

    /**
     * The arbitrary-recipient fan-out: one INSERT ... SELECT FROM
     * users WHERE id IN (...) creates a row for every listed user in
     * a single statement. The same preference gate applies (unless
     * $force bypasses it).
     *
     * @param array<string, mixed> $content  The formatted row
     * @param array<int>           $userIds  The recipient ids
     * @param string|null          $preferenceCategory The preference
     *                                       gate (null = no gate)
     * @return int The number of notifications created
     */
    public function fanOutByUsers(array $content, array $userIds, ?string $preferenceCategory = null): int
    {
        if ($userIds === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($userIds), '?'));
        $gate         = $this->preferenceGate('u.id', $preferenceCategory);

        return db()->execute(
            'INSERT INTO notifications (' . self::COLUMNS . ')
             SELECT u.id, ?, ?, ?, ?, ?, ?
             FROM users u
             WHERE u.id IN (' . $placeholders . ')' . $gate,
            array_merge(
                [
                    $content['type'],
                    $content['title'],
                    $content['message'],
                    $content['icon'],
                    $content['color'],
                    $content['action_url'] ?? null,
                ],
                $userIds,
            ),
        );
    }

    /**
     * Find a single notification row by primary key.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $rows = db()->query(
            'SELECT ' . self::SELECT . ' FROM notifications WHERE id = ?',
            [$id],
        );

        return $rows[0] ?? null;
    }

    /**
     * Find a notification row scoped to its recipient - the ONLY
     * lookup the controller may use. A row that exists but belongs to
     * another user answers exactly like a missing row (null), so an
     * attacker can never probe whether a notification id exists.
     *
     * @return array<string, mixed>|null
     */
    public function findOwnedBy(int $id, int $userId): ?array
    {
        $rows = db()->query(
            'SELECT ' . self::SELECT . '
             FROM notifications
             WHERE id = ? AND user_id = ?',
            [$id, $userId],
        );

        return $rows[0] ?? null;
    }

    /**
     * One page of the notification center: 'all' | 'unread' | 'read'
     * tabs (optionally narrowed to a type group), newest first.
     *
     * @param array<int, string> $types The optional type-group members
     *                                   (e.g. ['author_followed',
     *                                   'author_new_release']); empty =
     *                                   no type filter.
     * @return array<int, array<string, mixed>>
     */
    public function forUser(int $userId, string $tab = 'all', int $offset = 0, int $limit = 50, array $types = []): array
    {
        return db()->query(
            'SELECT ' . self::SELECT . '
             FROM notifications
             WHERE user_id = ?' . $this->tabWhere($tab) . $this->typeWhere($types) . '
             ORDER BY created_at DESC, id DESC
             LIMIT ? OFFSET ?',
            array_merge([$userId], $this->tabParams($tab), $this->typeParams($types), [$limit, $offset]),
        );
    }

    /**
     * The total row count behind a tab (and optional type group) - the
     * pagination denominator of the center.
     *
     * @param array<int, string> $types See forUser().
     */
    public function countForUser(int $userId, string $tab = 'all', array $types = []): int
    {
        $rows = db()->query(
            'SELECT COUNT(*) AS count
             FROM notifications
             WHERE user_id = ?' . $this->tabWhere($tab) . $this->typeWhere($types),
            array_merge([$userId], $this->tabParams($tab), $this->typeParams($types)),
        );

        return (int) ($rows[0]['count'] ?? 0);
    }

    /**
     * The badge number: unread rows of the user. Served by the
     * (user_id, is_read) covering index - a single index scan, no
     * table read.
     */
    public function unreadCount(int $userId): int
    {
        $rows = db()->query(
            'SELECT COUNT(*) AS count
             FROM notifications
             WHERE user_id = ? AND is_read = 0',
            [$userId],
        );

        return (int) ($rows[0]['count'] ?? 0);
    }

    /**
     * Mark one notification read (idempotent): sets is_read = 1 and
     * stamps read_at only when it was still unread. Owner-scoped.
     */
    public function markRead(int $id, int $userId): bool
    {
        return db()->execute(
            'UPDATE notifications
             SET is_read = 1, read_at = ?
             WHERE id = ? AND user_id = ? AND is_read = 0',
            [$this->now(), $id, $userId],
        ) > 0;
    }

    /**
     * Mark every notification of the user read. Returns the number
     * of rows actually changed.
     */
    public function markAllRead(int $userId): int
    {
        return db()->execute(
            'UPDATE notifications
             SET is_read = 1, read_at = ?
             WHERE user_id = ? AND is_read = 0',
            [$this->now(), $userId],
        );
    }

    /**
     * Mark one notification UNREAD again (the read-state toggle of the
     * surface, Phase 9.4): clears the read flag and its stamp, exactly
     * the mirror of markRead(). Idempotent and owner-scoped - a row
     * already unread answers false, never an error.
     */
    public function markUnread(int $id, int $userId): bool
    {
        return db()->execute(
            'UPDATE notifications
             SET is_read = 0, read_at = NULL
             WHERE id = ? AND user_id = ? AND is_read = 1',
            [$id, $userId],
        ) > 0;
    }

    /**
     * Delete one notification, owner-scoped.
     */
    public function deleteOwnedBy(int $id, int $userId): bool
    {
        return db()->execute(
            'DELETE FROM notifications WHERE id = ? AND user_id = ?',
            [$id, $userId],
        ) > 0;
    }

    /**
     * Delete the user's whole notification history. Returns the
     * number of rows removed.
     */
    public function deleteAll(int $userId): int
    {
        return db()->execute('DELETE FROM notifications WHERE user_id = ?', [$userId]);
    }

    /**
     * Delete a SET of the user's notifications in one statement (the
     * Phase 9.4 bulk action). Owner-scoped: ids that belong to another
     * user are simply not touched - a caller can never delete someone
     * else's rows. Returns the number of rows removed.
     *
     * @param array<int> $ids
     */
    public function deleteMany(array $ids, int $userId): int
    {
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        return db()->execute(
            'DELETE FROM notifications WHERE user_id = ? AND id IN (' . $placeholders . ')',
            array_merge([$userId], $ids),
        );
    }

    /**
     * The retention sweep (reserved for a future cron / console,
     * blueprint Task 9): delete every notification older than the
     * given number of days. Returns the number of rows removed.
     */
    public function prune(int $days): int
    {
        return db()->execute(
            'DELETE FROM notifications
             WHERE created_at < datetime(\'now\', ?)',
            ['-' . max(1, $days) . ' days'],
        );
    }

    /**
     * The user's preference row, or the defaults when no row exists.
     *
     * @return array<string, mixed> Keys: the seven categories, 0/1
     */
    public function preferences(int $userId): array
    {
        $rows = db()->query(
            'SELECT author_followed, author_activity, community,
                    recommendations, wishlist_reminders, system_announcements
             FROM notification_preferences
             WHERE user_id = ?',
            [$userId],
        );

        $row = $rows[0] ?? [];

        // Category -> 0/1, defaulting every missing toggle to 1. The
        // keys MUST survive (array_combine, not array_map alone - the
        // dispatcher's gate reads $preferences['author_followed']).
        return array_combine(
            array_keys(self::PREFERENCE_COLUMNS),
            array_map(
                fn (string $category): int => isset($row[$category])
                    ? (int) $row[$category]
                    : 1,
                array_keys(self::PREFERENCE_COLUMNS),
            ),
        ) ?: [];
    }

    /**
     * Upsert one preference toggle (the INSERT ... ON CONFLICT
     * (user_id) DO UPDATE pattern of the library preferences). The
     * category is allowlisted through PREFERENCE_COLUMNS, so an
     * unknown category is a silent no-op - the caller validates
     * first, this is the last line of defence.
     */
    public function updatePreference(int $userId, string $category, bool $enabled): void
    {
        $column = self::PREFERENCE_COLUMNS[$category] ?? null;

        if ($column === null) {
            return;
        }

        db()->execute(
            'INSERT INTO notification_preferences (user_id, ' . $column . ', updated_at)
             VALUES (?, ?, ?)
             ON CONFLICT (user_id) DO UPDATE SET
                ' . $column . ' = excluded.' . $column . ',
                updated_at = excluded.updated_at',
            [$userId, $enabled ? 1 : 0, $this->now()],
        );
    }

    /**
     * The reserved channel outbox hook: create a pending delivery row
     * for a channel. A NO-OP in Phase 9.2 - the table ships empty by
     * design (the blueprint Task 1 "purely additive" rule) - kept
     * here as the single place a future email/push phase plugs in.
     */
    public function enqueueDelivery(int $notificationId, int $userId, string $channel): void
    {
        db()->execute(
            'INSERT INTO notification_deliveries
                (notification_id, user_id, channel, status)
             VALUES (?, ?, ?, \'pending\')',
            [$notificationId, $userId, $channel],
        );
    }

    /**
     * The WHERE fragment of one tab. 'all' adds nothing; 'unread'
     * and 'read' filter on the (user_id, is_read) covering index.
     */
    private function tabWhere(string $tab): string
    {
        return match ($tab) {
            'unread' => ' AND is_read = 0',
            'read'   => ' AND is_read = 1',
            default  => '',
        };
    }

    /**
     * The bound parameters of a tab. The WHERE fragments use the
     * literal 0/1 (a closed choice), so no tab ever adds a
     * placeholder - the array stays empty for every tab.
     *
     * @return array<int, int>
     */
    private function tabParams(string $tab): array
    {
        return [];
    }

    /**
     * The WHERE fragment of a type group: AND type IN (?, ?). An empty
     * group adds nothing. The types are bound parameters (the service
     * has already intersected them against the catalog), so a value can
     * never reach the SQL text.
     *
     * @param array<int, string> $types
     */
    private function typeWhere(array $types): string
    {
        return $types === []
            ? ''
            : ' AND type IN (' . implode(', ', array_fill(0, count($types), '?')) . ')';
    }

    /**
     * The bound parameters of a type group.
     *
     * @param array<int, string> $types
     * @return array<int, string>
     */
    private function typeParams(array $types): array
    {
        return array_values($types);
    }

    /**
     * The preference gate of a fan-out: the NOT EXISTS fragment that
     * excludes recipients who opted out of the category. The column
     * comes from the PREFERENCE_COLUMNS allowlist, never from a
     * caller string; null (or an unknown category) means no gate -
     * announcements always deliver.
     *
     * @param string $alias The alias of the recipients table
     */
    private function preferenceGate(string $alias, ?string $category): string
    {
        $column = $category !== null ? (self::PREFERENCE_COLUMNS[$category] ?? null) : null;

        if ($column === null) {
            return '';
        }

        return " AND NOT EXISTS (
            SELECT 1 FROM notification_preferences p
            WHERE p.user_id = {$alias} AND p.{$column} = 0
        )";
    }

    /**
     * The current UTC timestamp in the database's stored format.
     */
    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
