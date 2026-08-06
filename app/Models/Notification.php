<?php

declare(strict_types=1);

namespace BookSphere\App\Models;

use BookSphere\App\Repositories\NotificationRepository;

/**
 * Notification
 *
 * The domain representation of one in-app notification and the
 * public API of the Notification module's data layer - a THIN
 * FACADE over NotificationRepository, following the exact pattern of
 * the AuthorFollow and UserLibrary models: no business logic, no
 * SQL, just one predictable interface for the service and the views.
 *
 * Entity columns (the notifications table, migration 0023):
 *
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT
 *     user_id     INTEGER NOT NULL  (FK users.id, ON DELETE CASCADE -
 *                                    the recipient)
 *     type        TEXT    NOT NULL  (the catalog key, CHECK-constrained)
 *     title       TEXT    NOT NULL  (the formatter's short line)
 *     message     TEXT    NOT NULL DEFAULT '' (may embed a user's
 *                                    full_name - always e() at render)
 *     icon        TEXT    NOT NULL  (a Font Awesome 6.5.2 class)
 *     color       TEXT    NOT NULL  (primary | info | success |
 *                                    warning | danger - the app's
 *                                    accent tokens)
 *     action_url  TEXT              (the relative path the row opens;
 *                                    NULL = no jump)
 *     is_read     INTEGER NOT NULL DEFAULT 0 (0/1, CHECK constrained)
 *     read_at     TEXT              (NULL until marked read)
 *     created_at  TEXT    NOT NULL  (UTC ISO-8601)
 *
 * Rows are immutable after insert except the read flag - there is
 * deliberately no updated_at. The content is stored FORMATTED at
 * write time (blueprint Task 2), so rendering never joins the source
 * tables and history stays truthful when a source row is later
 * edited or deleted.
 *
 * Dependencies:
 *     - NotificationRepository (the actual PDO/prepared-statement SQL).
 *     - User model (for the relationship helper).
 *
 * How it fits inside MVC:
 *     Controller -> Service (business rules) -> Notification (facade)
 *     -> NotificationRepository (SQL) -> PDO -> SQLite.
 */
final class Notification
{
    public function __construct(private readonly NotificationRepository $repository = new NotificationRepository()) {}

    // --- CRUD ---------------------------------------------------------

    /**
     * Create ONE notification row (the single-recipient path of the
     * dispatcher: system / admin announcements) and return its id.
     *
     * @param array<string, mixed> $data Normalized column values:
     *                                    user_id, type, title, message,
     *                                    icon, color, action_url
     */
    public function create(array $data): int
    {
        return $this->repository->create($data);
    }

    /**
     * The batched fan-out for one author: one row per follower of the
     * author, created in a single INSERT ... SELECT statement.
     *
     * @param array<string, mixed> $content  The formatted row
     * @return int The number of notifications created
     */
    public function fanOutByAuthor(array $content, int $authorId, ?string $preferenceCategory = null): int
    {
        return $this->repository->fanOutByAuthor($content, $authorId, $preferenceCategory);
    }

    /**
     * The batched fan-out for an arbitrary recipient list.
     *
     * @param array<string, mixed> $content  The formatted row
     * @param array<int>           $userIds  The recipient ids
     * @return int The number of notifications created
     */
    public function fanOutByUsers(array $content, array $userIds, ?string $preferenceCategory = null): int
    {
        return $this->repository->fanOutByUsers($content, $userIds, $preferenceCategory);
    }

    /**
     * Find a single notification row by id.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->repository->find($id);
    }

    /**
     * Find a notification row scoped to its recipient - the ONLY
     * lookup the controller may use. A foreign row answers exactly
     * like a missing row (null): the IDOR guard.
     *
     * @return array<string, mixed>|null
     */
    public function findOwnedBy(int $id, int $userId): ?array
    {
        return $this->repository->findOwnedBy($id, $userId);
    }

    /**
     * One page of the notification center: 'all' | 'unread' | 'read'
     * tabs, newest first. $types optionally narrows the page to a
     * type-group member list (the Phase 9.4 filter chips).
     *
     * @param array<int, string> $types
     * @return array<int, array<string, mixed>>
     */
    public function forUser(int $userId, string $tab = 'all', int $offset = 0, int $limit = 50, array $types = []): array
    {
        return $this->repository->forUser($userId, $tab, $offset, $limit, $types);
    }

    /**
     * The total row count behind a tab (the pagination denominator).
     * $types narrows the count exactly like forUser().
     *
     * @param array<int, string> $types
     */
    public function countForUser(int $userId, string $tab = 'all', array $types = []): int
    {
        return $this->repository->countForUser($userId, $tab, $types);
    }

    /**
     * The badge number: the user's unread rows.
     */
    public function unreadCount(int $userId): int
    {
        return $this->repository->unreadCount($userId);
    }

    /**
     * Mark one notification read (idempotent, owner-scoped).
     */
    public function markRead(int $id, int $userId): bool
    {
        return $this->repository->markRead($id, $userId);
    }

    /**
     * Mark one notification UNREAD again (idempotent, owner-scoped).
     */
    public function markUnread(int $id, int $userId): bool
    {
        return $this->repository->markUnread($id, $userId);
    }

    /**
     * Mark every notification of the user read. Returns the number
     * of rows actually changed.
     */
    public function markAllRead(int $userId): int
    {
        return $this->repository->markAllRead($userId);
    }

    /**
     * Delete one notification, owner-scoped.
     */
    public function deleteOwnedBy(int $id, int $userId): bool
    {
        return $this->repository->deleteOwnedBy($id, $userId);
    }

    /**
     * Delete a SET of the user's notifications in one round trip
     * (foreign ids are simply not touched). Returns the number of
     * rows actually removed.
     *
     * @param array<int> $ids
     */
    public function deleteMany(array $ids, int $userId): int
    {
        return $this->repository->deleteMany($ids, $userId);
    }

    /**
     * Delete the user's whole notification history. Returns the
     * number of rows removed.
     */
    public function deleteAll(int $userId): int
    {
        return $this->repository->deleteAll($userId);
    }

    /**
     * The retention sweep: delete every notification older than the
     * given number of days. Returns the number of rows removed.
     */
    public function prune(int $days): int
    {
        return $this->repository->prune($days);
    }

    // --- Preferences ---------------------------------------------------

    /**
     * The user's seven preference toggles, or the defaults (all on)
     * when no row exists.
     *
     * @return array<string, int> Category -> 0/1
     */
    public function preferences(int $userId): array
    {
        return $this->repository->preferences($userId);
    }

    /**
     * Upsert one preference toggle (INSERT ... ON CONFLICT DO UPDATE).
     */
    public function updatePreference(int $userId, string $category, bool $enabled): void
    {
        $this->repository->updatePreference($userId, $category, $enabled);
    }

    // --- Relationships ------------------------------------------------

    /**
     * The recipient of a notification row (belongsTo).
     *
     * @param array<string, mixed> $row A notification row
     * @return array<string, mixed>|null
     */
    public function user(array $row): ?array
    {
        return (new User())->findById((int) ($row['user_id'] ?? 0));
    }
}
