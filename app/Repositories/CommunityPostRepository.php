<?php

declare(strict_types=1);

namespace BookSphere\App\Repositories;

/**
 * CommunityPostRepository
 *
 * Data-access layer for the community_posts table (migration 0036).
 * Every SQL query that touches community_posts lives here exclusively.
 * Prepared statements everywhere ? no user input ever lands in SQL text.
 *
 * Follows the identical pattern of AuthorFollowRepository and
 * ReviewRepository: explicit column lists, single-statement joins,
 * no N+1 fetches, and the gmdate() now() convention.
 *
 * Column projection note:
 *   The SELECT_FEED constant joins with users (author name) and
 *   books (optional book title) so feed renders need no extra queries.
 *   comment_count and like_count are inline subqueries ? fast because
 *   they hit idx_community_comments_post and idx_community_likes_post.
 */
final class CommunityPostRepository
{
    /** Base projection for every feed/list read. */
    private const SELECT_FEED =
        'p.*,
         u.full_name AS author_name,
         b.title     AS book_title,
         (SELECT COUNT(*) FROM community_comments c
          WHERE c.post_id = p.id AND c.status = \'active\') AS comment_count,
         (SELECT COUNT(*) FROM community_likes   l
          WHERE l.post_id = p.id) AS like_count';

    // ------------------------------------------------------------------ //
    // Writes                                                               //
    // ------------------------------------------------------------------ //

    /**
     * Insert a new post row and return its id.
     *
     * @param array<string,mixed> $data  user_id, title, body,
     *                                   book_id (nullable), status
     */
    public function create(array $data): int
    {
        db()->execute(
            'INSERT INTO community_posts
                (user_id, book_id, title, body, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                (int)  $data['user_id'],
                isset($data['book_id']) ? (int) $data['book_id'] : null,
                (string) $data['title'],
                (string) $data['body'],
                (string) ($data['status'] ?? 'active'),
                $this->now(),
                $this->now(),
            ],
        );

        return (int) db()->lastInsertId();
    }

    /**
     * Update a post's editable fields (title, body, status).
     *
     * @param array<string,mixed> $data  title, body, status
     */
    public function update(int $id, array $data): bool
    {
        return db()->execute(
            'UPDATE community_posts
             SET title = ?, body = ?, status = ?, updated_at = ?
             WHERE id = ?',
            [
                (string) $data['title'],
                (string) $data['body'],
                (string) $data['status'],
                $this->now(),
                $id,
            ],
        ) > 0;
    }

    /**
     * Update only the moderation status of a post (admin hide/unhide).
     */
    public function updateStatus(int $id, string $status): bool
    {
        return db()->execute(
            'UPDATE community_posts SET status = ?, updated_at = ? WHERE id = ?',
            [$status, $this->now(), $id],
        ) > 0;
    }

    /**
     * Hard-delete a post (cascades comments, likes, reports via FK).
     */
    public function delete(int $id): bool
    {
        return db()->execute('DELETE FROM community_posts WHERE id = ?', [$id]) > 0;
    }

    // ------------------------------------------------------------------ //
    // Reads                                                                //
    // ------------------------------------------------------------------ //

    /**
     * Find a single post by id, joined with author name and book title.
     * Returns null when the post does not exist or is hard-deleted.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $rows = db()->query(
            'SELECT ' . self::SELECT_FEED . '
             FROM community_posts p
             JOIN users u ON u.id = p.user_id
             LEFT JOIN books b ON b.id = p.book_id
             WHERE p.id = ?',
            [$id],
        );

        return $rows[0] ?? null;
    }

    /**
     * Paginated feed of active posts, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findActive(int $limit = 20, int $offset = 0): array
    {
        return db()->query(
            'SELECT ' . self::SELECT_FEED . '
             FROM community_posts p
             JOIN users u ON u.id = p.user_id
             LEFT JOIN books b ON b.id = p.book_id
             WHERE p.status = \'active\'
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT ? OFFSET ?',
            [$limit, max(0, $offset)],
        );
    }

    /**
     * Count of all active posts (feed pagination total).
     */
    public function countActive(): int
    {
        return (int) (db()->query(
            "SELECT COUNT(*) AS n FROM community_posts WHERE status = 'active'"
        )[0]['n'] ?? 0);
    }

    /**
     * Discovery query supporting sort modes (recent, popular, trending),
     * optional book_id filter, optional author_id filter, and search query.
     * Strictly active posts only.
     *
     * Sorting formulas:
     * - recent:   ORDER BY p.created_at DESC, p.id DESC
     * - popular:  ORDER BY (like_count * 2 + comment_count * 3) DESC, p.created_at DESC, p.id DESC
     * - trending: ORDER BY ((like_count * 2 + comment_count * 4 + 1) / (JULIANDAY('now') - JULIANDAY(p.created_at) + 1.0)) DESC, p.created_at DESC, p.id DESC
     *
     * @return array<int,array<string,mixed>>
     */
    public function findDiscoveryPosts(
        string $sort = 'recent',
        ?int $bookId = null,
        ?int $authorId = null,
        ?string $query = null,
        int $limit = 20,
        int $offset = 0,
        ?int $followerId = null
    ): array {
        $where = ["p.status = 'active'"];
        $params = [];

        if ($bookId !== null && $bookId > 0) {
            $where[] = 'p.book_id = ?';
            $params[] = $bookId;
        }

        if ($authorId !== null && $authorId > 0) {
            $where[] = 'p.user_id = ?';
            $params[] = $authorId;
        }

        if ($followerId !== null && $followerId > 0) {
            $where[] = 'p.user_id IN (SELECT following_id FROM community_follows WHERE follower_id = ?)';
            $params[] = $followerId;
        }

        if ($query !== null && trim($query) !== '') {
            $where[] = '(p.title LIKE ? OR p.body LIKE ? OR u.full_name LIKE ? OR b.title LIKE ?)';
            $like = '%' . trim($query) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $whereClause = implode(' AND ', $where);

        $orderBy = match ($sort) {
            'popular'  => 'ORDER BY (like_count * 2 + comment_count * 3) DESC, p.created_at DESC, p.id DESC',
            'trending' => 'ORDER BY ((like_count * 2 + comment_count * 4 + 1) / (JULIANDAY(\'now\') - JULIANDAY(p.created_at) + 1.0)) DESC, p.created_at DESC, p.id DESC',
            default    => 'ORDER BY p.created_at DESC, p.id DESC',
        };

        $params[] = $limit;
        $params[] = max(0, $offset);

        return db()->query(
            "SELECT " . self::SELECT_FEED . "
             FROM community_posts p
             JOIN users u ON u.id = p.user_id
             LEFT JOIN books b ON b.id = p.book_id
             WHERE {$whereClause}
             {$orderBy}
             LIMIT ? OFFSET ?",
            $params,
        );
    }

    /**
     * Count discovery posts matching filter and search query (for pagination).
     */
    public function countDiscoveryPosts(
        ?int $bookId = null,
        ?int $authorId = null,
        ?string $query = null,
        ?int $followerId = null
    ): int {
        $where = ["p.status = 'active'"];
        $params = [];

        if ($bookId !== null && $bookId > 0) {
            $where[] = 'p.book_id = ?';
            $params[] = $bookId;
        }

        if ($authorId !== null && $authorId > 0) {
            $where[] = 'p.user_id = ?';
            $params[] = $authorId;
        }

        if ($followerId !== null && $followerId > 0) {
            $where[] = 'p.user_id IN (SELECT following_id FROM community_follows WHERE follower_id = ?)';
            $params[] = $followerId;
        }

        if ($query !== null && trim($query) !== '') {
            $where[] = '(p.title LIKE ? OR p.body LIKE ? OR u.full_name LIKE ? OR b.title LIKE ?)';
            $like = '%' . trim($query) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $whereClause = implode(' AND ', $where);

        return (int) (db()->query(
            "SELECT COUNT(*) AS n
             FROM community_posts p
             JOIN users u ON u.id = p.user_id
             LEFT JOIN books b ON b.id = p.book_id
             WHERE {$whereClause}",
            $params,
        )[0]['n'] ?? 0);
    }

    /**
     * Active posts linked to a specific book, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findByBook(int $bookId, int $limit = 20, int $offset = 0): array
    {
        return db()->query(
            'SELECT ' . self::SELECT_FEED . '
             FROM community_posts p
             JOIN users u ON u.id = p.user_id
             LEFT JOIN books b ON b.id = p.book_id
             WHERE p.book_id = ? AND p.status = \'active\'
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT ? OFFSET ?',
            [$bookId, $limit, max(0, $offset)],
        );
    }

    /**
     * Count active posts for a book.
     */
    public function countByBook(int $bookId): int
    {
        return (int) (db()->query(
            "SELECT COUNT(*) AS n FROM community_posts
             WHERE book_id = ? AND status = 'active'",
            [$bookId],
        )[0]['n'] ?? 0);
    }

    /**
     * Posts authored by a specific user (all statuses ? used by the
     * owner's own profile; active-only filtering is a service concern).
     *
     * @return array<int,array<string,mixed>>
     */
    public function findByUser(int $userId, int $limit = 20, int $offset = 0): array
    {
        return db()->query(
            'SELECT ' . self::SELECT_FEED . '
             FROM community_posts p
             JOIN users u ON u.id = p.user_id
             LEFT JOIN books b ON b.id = p.book_id
             WHERE p.user_id = ?
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT ? OFFSET ?',
            [$userId, $limit, max(0, $offset)],
        );
    }

    /**
     * Active posts authored by a specific user (public community profile).
     *
     * @return array<int,array<string,mixed>>
     */
    public function findActiveByUser(int $userId, int $limit = 20, int $offset = 0): array
    {
        return db()->query(
            "SELECT " . self::SELECT_FEED . "
             FROM community_posts p
             JOIN users u ON u.id = p.user_id
             LEFT JOIN books b ON b.id = p.book_id
             WHERE p.user_id = ? AND p.status = 'active'
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT ? OFFSET ?",
            [$userId, $limit, max(0, $offset)],
        );
    }

    /**
     * Count all posts by a user.
     */
    public function countByUser(int $userId): int
    {
        return (int) (db()->query(
            'SELECT COUNT(*) AS n FROM community_posts WHERE user_id = ?',
            [$userId],
        )[0]['n'] ?? 0);
    }

    /**
     * Count active posts by a user.
     */
    public function countActiveByUser(int $userId): int
    {
        return (int) (db()->query(
            "SELECT COUNT(*) AS n FROM community_posts WHERE user_id = ? AND status = 'active'",
            [$userId],
        )[0]['n'] ?? 0);
    }


    // ------------------------------------------------------------------ //
    // Helpers                                                              //
    // ------------------------------------------------------------------ //

    /** Current UTC timestamp in the project ISO-8601 format. */
    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
