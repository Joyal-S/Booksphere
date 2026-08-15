<?php

declare(strict_types=1);

namespace BookSphere\App\Repositories;

/**
 * CommunityLikeRepository
 *
 * Data-access layer for the community_likes table (migration 0036).
 * The UNIQUE (post_id, user_id) index is the authoritative duplicate
 * guard; the service catches the 23000 SQLSTATE on a race.
 */
final class CommunityLikeRepository
{
    // ------------------------------------------------------------------ //
    // Writes                                                               //
    // ------------------------------------------------------------------ //

    /**
     * Insert a like row and return its id.
     * Callers must catch PDOException (SQLSTATE 23000) for the race.
     */
    public function create(int $postId, int $userId): int
    {
        db()->execute(
            'INSERT INTO community_likes (post_id, user_id, created_at) VALUES (?, ?, ?)',
            [$postId, $userId, $this->now()],
        );

        return (int) db()->lastInsertId();
    }

    /**
     * Remove the like row for a (post, user) pair.
     * Idempotent: removing a non-existent like returns false.
     */
    public function delete(int $postId, int $userId): bool
    {
        return db()->execute(
            'DELETE FROM community_likes WHERE post_id = ? AND user_id = ?',
            [$postId, $userId],
        ) > 0;
    }

    // ------------------------------------------------------------------ //
    // Reads                                                                //
    // ------------------------------------------------------------------ //

    /**
     * Whether a user has already liked a post (button-state check).
     */
    public function exists(int $postId, int $userId): bool
    {
        return db()->query(
            'SELECT id FROM community_likes WHERE post_id = ? AND user_id = ?',
            [$postId, $userId],
        ) !== [];
    }

    /**
     * Count of likes on a post.
     */
    public function count(int $postId): int
    {
        return (int) (db()->query(
            'SELECT COUNT(*) AS n FROM community_likes WHERE post_id = ?',
            [$postId],
        )[0]['n'] ?? 0);
    }

    /** Current UTC timestamp. */
    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
