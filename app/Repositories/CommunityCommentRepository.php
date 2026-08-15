<?php

declare(strict_types=1);

namespace BookSphere\App\Repositories;

/**
 * CommunityCommentRepository
 *
 * Data-access layer for the community_comments table (migration 0036).
 * Prepared statements everywhere. Joins author name on every list read.
 */
final class CommunityCommentRepository
{
    /** Base projection: comment row + author display name. */
    private const SELECT =
        'c.*,
         u.full_name AS author_name';

    // ------------------------------------------------------------------ //
    // Writes                                                               //
    // ------------------------------------------------------------------ //

    /**
     * Insert a comment and return its id.
     *
     * @param array<string,mixed> $data  post_id, user_id, body
     */
    public function create(array $data): int
    {
        db()->execute(
            'INSERT INTO community_comments
                (post_id, user_id, body, status, created_at, updated_at)
             VALUES (?, ?, ?, \'active\', ?, ?)',
            [
                (int)    $data['post_id'],
                (int)    $data['user_id'],
                (string) $data['body'],
                $this->now(),
                $this->now(),
            ],
        );

        return (int) db()->lastInsertId();
    }

    /**
     * Update a comment's body.
     *
     * @param array<string,mixed> $data  body
     */
    public function update(int $id, array $data): bool
    {
        return db()->execute(
            'UPDATE community_comments
             SET body = ?, updated_at = ?
             WHERE id = ?',
            [(string) $data['body'], $this->now(), $id],
        ) > 0;
    }

    /**
     * Update the moderation status of a comment (admin action).
     */
    public function updateStatus(int $id, string $status): bool
    {
        return db()->execute(
            'UPDATE community_comments SET status = ?, updated_at = ? WHERE id = ?',
            [$status, $this->now(), $id],
        ) > 0;
    }

    /**
     * Hard-delete a comment (cascades any reports on it via FK).
     */
    public function delete(int $id): bool
    {
        return db()->execute('DELETE FROM community_comments WHERE id = ?', [$id]) > 0;
    }

    // ------------------------------------------------------------------ //
    // Reads                                                                //
    // ------------------------------------------------------------------ //

    /**
     * Find a single comment by id, joined with author name.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $rows = db()->query(
            'SELECT ' . self::SELECT . '
             FROM community_comments c
             JOIN users u ON u.id = c.user_id
             WHERE c.id = ?',
            [$id],
        );

        return $rows[0] ?? null;
    }

    /**
     * Active comments for a post, oldest first (chronological thread).
     *
     * @return array<int,array<string,mixed>>
     */
    public function findByPost(int $postId, int $limit = 100, int $offset = 0): array
    {
        return db()->query(
            'SELECT ' . self::SELECT . '
             FROM community_comments c
             JOIN users u ON u.id = c.user_id
             WHERE c.post_id = ? AND c.status = \'active\'
             ORDER BY c.created_at ASC, c.id ASC
             LIMIT ? OFFSET ?',
            [$postId, $limit, max(0, $offset)],
        );
    }

    /**
     * Count active comments on a post.
     */
    public function countByPost(int $postId): int
    {
        return (int) (db()->query(
            "SELECT COUNT(*) AS n FROM community_comments
             WHERE post_id = ? AND status = 'active'",
            [$postId],
        )[0]['n'] ?? 0);
    }

    /**
     * Active comments by a specific user on active posts, newest first.
     * Joined with parent post title and optional book title.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findActiveByUser(int $userId, int $limit = 20, int $offset = 0): array
    {
        return db()->query(
            "SELECT c.*,
                    u.full_name AS author_name,
                    p.title     AS post_title,
                    p.book_id   AS book_id,
                    b.title     AS book_title
             FROM community_comments c
             JOIN users u           ON u.id = c.user_id
             JOIN community_posts p ON p.id = c.post_id
             LEFT JOIN books b      ON b.id = p.book_id
             WHERE c.user_id = ? AND c.status = 'active' AND p.status = 'active'
             ORDER BY c.created_at DESC, c.id DESC
             LIMIT ? OFFSET ?",
            [$userId, $limit, max(0, $offset)],
        );
    }

    /**
     * Count active comments by a specific user on active posts.
     */
    public function countActiveByUser(int $userId): int
    {
        return (int) (db()->query(
            "SELECT COUNT(*) AS n
             FROM community_comments c
             JOIN community_posts p ON p.id = c.post_id
             WHERE c.user_id = ? AND c.status = 'active' AND p.status = 'active'",
            [$userId],
        )[0]['n'] ?? 0);
    }

    /** Current UTC timestamp. */
    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
