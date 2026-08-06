<?php

declare(strict_types=1);

namespace BookSphere\App\Repositories;

/**
 * AuthorFollowRepository
 *
 * The data-access layer of the Follow Authors module (Phase 9.2).
 * Every SQL query that touches the author_follows table lives here
 * and here only - prepared statements everywhere (the db() helper
 * binds parameters; no value ever lands in the SQL text), explicit
 * column lists, and every list query joined with its display rows in
 * ONE statement (no N+1 lookups - the same approach as
 * LibraryRepository::SELECT and ReviewRepository::SELECT).
 *
 * Responsibilities:
 *
 *     - create / delete (by row id) / deleteForPair (unfollow by
 *       user+author - no SELECT first, the DELETE itself is the
 *       answer)
 *     - find / exists / isFollowing - the duplicate and state checks
 *     - findForUser - the user's followed authors, joined with the
 *       author display columns (name, photo, book count)
 *     - findFollowersOf - one author's followers, joined with the
 *       user display columns (id, full_name)
 *     - followerCount - the author's follower statistic (backed by
 *       idx_author_follows_author, a pure index scan)
 *
 * Rules inherited from the schema (migration 0022):
 *     - UNIQUE (user_id, author_id): one follow per user per author.
 *     - ON DELETE CASCADE: user or author deletion removes the rows.
 *
 * Dependencies:
 *     - db() helper (Core\Database singleton) - the shared PDO
 *       connection, exactly like the other repositories.
 *
 * How it fits inside MVC:
 *     Controller -> Service (business rules) -> AuthorFollow model
 *     (facade) -> AuthorFollowRepository (SQL) -> PDO -> SQLite.
 */
final class AuthorFollowRepository
{
    /**
     * The base SELECT of the follow list reads: the follow row plus
     * the author display columns every list / card needs. The book
     * count is the author's catalogue size (books, not follows) -
     * the "N books by this author" hint of the following list.
     */
    private const SELECT_AUTHOR = 'f.*,
        a.name AS author_name,
        a.photo AS author_photo,
        (SELECT COUNT(*) FROM book_authors ba
         WHERE ba.author_id = a.id) AS author_book_count';

    /**
     * Insert a follow row and return its id.
     *
     * @param array<string, mixed> $data Normalized column values:
     *                                    user_id, author_id
     */
    public function create(array $data): int
    {
        db()->execute(
            'INSERT INTO author_follows (user_id, author_id, created_at)
             VALUES (?, ?, ?)',
            [
                $data['user_id'],
                $data['author_id'],
                $this->now(),
            ],
        );

        return (int) db()->lastInsertId();
    }

    /**
     * Hard delete a follow row by its id.
     */
    public function delete(int $id): bool
    {
        return db()->execute('DELETE FROM author_follows WHERE id = ?', [$id]) > 0;
    }

    /**
     * Remove the follow row of one user for one author - the unfollow
     * of the module. No SELECT first: the DELETE itself is the
     * answer, and removing a non-existent row simply affects zero
     * rows (the idempotence of the service layer relies on this).
     */
    public function deleteForPair(int $userId, int $authorId): bool
    {
        return db()->execute(
            'DELETE FROM author_follows WHERE user_id = ? AND author_id = ?',
            [$userId, $authorId],
        ) > 0;
    }

    /**
     * Find a single follow row by primary key.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $rows = db()->query('SELECT * FROM author_follows WHERE id = ?', [$id]);

        return $rows[0] ?? null;
    }

    /**
     * Whether a user already follows an author (the duplicate and
     * button-state check, backed by the UNIQUE covering index).
     */
    public function exists(int $userId, int $authorId): bool
    {
        $rows = db()->query(
            'SELECT id FROM author_follows WHERE user_id = ? AND author_id = ?',
            [$userId, $authorId],
        );

        return $rows !== [];
    }

    /**
     * The follow row of one user for one author - the row the
     * FollowPolicy::canUnfollow gate looks at before an unfollow.
     *
     * @return array<string, mixed>|null
     */
    public function findForPair(int $userId, int $authorId): ?array
    {
        $rows = db()->query(
            'SELECT * FROM author_follows WHERE user_id = ? AND author_id = ?',
            [$userId, $authorId],
        );

        return $rows[0] ?? null;
    }

    /**
     * The user's followed authors, newest first - joined with the
     * author display columns (name, photo, book count) so the list
     * renders without an extra query per row. The offset supports
     * the paginated page read (Phase 9.6: without it a list with
     * more than $limit rows silently truncated, and the page's
     * "You follow N authors" lead was only the visible slice).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findForUser(int $userId, int $limit = 50, int $offset = 0): array
    {
        return db()->query(
            'SELECT ' . self::SELECT_AUTHOR . '
             FROM author_follows f
             JOIN authors a ON a.id = f.author_id
             WHERE f.user_id = ?
             ORDER BY f.created_at DESC, f.id DESC
             LIMIT ? OFFSET ?',
            [$userId, $limit, max(0, $offset)],
        );
    }

    /**
     * The total number of authors one user follows - the honest
     * "You follow N authors" lead of the following page (Phase 9.6:
     * the lead used to count only the returned page).
     */
    public function countForUser(int $userId): int
    {
        $rows = db()->query(
            'SELECT COUNT(*) AS count FROM author_follows WHERE user_id = ?',
            [$userId],
        );

        return (int) ($rows[0]['count'] ?? 0);
    }

    /**
     * The followers of one author, newest first - joined with the
     * user display columns (id, full_name) so the list renders
     * without an extra query per row. The offset supports the
     * paginated page read (Phase 9.6).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findFollowersOf(int $authorId, int $limit = 50, int $offset = 0): array
    {
        return db()->query(
            'SELECT f.id, f.user_id, f.created_at, u.full_name
             FROM author_follows f
             JOIN users u ON u.id = f.user_id
             WHERE f.author_id = ?
             ORDER BY f.created_at DESC, f.id DESC
             LIMIT ? OFFSET ?',
            [$authorId, $limit, max(0, $offset)],
        );
    }

    /**
     * The total number of people following one author - the honest
     * "N people follow this author" lead of the followers page.
     */
    public function countFollowersOf(int $authorId): int
    {
        $rows = db()->query(
            'SELECT COUNT(*) AS count FROM author_follows WHERE author_id = ?',
            [$authorId],
        );

        return (int) ($rows[0]['count'] ?? 0);
    }

    /**
     * The follower count of one author - the statistic shown on the
     * author page. A COUNT over the (author_id) index is a pure
     * index scan, so it stays fast at any catalogue size.
     */
    public function followerCount(int $authorId): int
    {
        $rows = db()->query(
            'SELECT COUNT(*) AS count FROM author_follows WHERE author_id = ?',
            [$authorId],
        );

        return (int) ($rows[0]['count'] ?? 0);
    }

    /**
     * The current UTC timestamp in the database's stored format.
     */
    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
