<?php

declare(strict_types=1);

namespace BookSphere\App\Models;

use BookSphere\App\Core\Database;
use PDO;
use PDOException;

/**
 * CommunityFollow Model
 *
 * Model facade for the community_follows table (Phase C7-B).
 * Manages user-to-user social relationships in the Community module.
 */
final class CommunityFollow
{
    private readonly PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::instance()->pdo();
    }

    /**
     * Create a follow relationship between follower and target user.
     * IDEMPOTENT: returns 0 if relationship already exists.
     */
    public function follow(int $followerId, int $followingId): int
    {
        if ($followerId <= 0 || $followingId <= 0 || $followerId === $followingId) {
            return 0;
        }

        if ($this->isFollowing($followerId, $followingId)) {
            return 0;
        }

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO community_follows (follower_id, following_id) VALUES (?, ?)'
            );
            $stmt->execute([$followerId, $followingId]);
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            if ((string) ($e->getCode() ?? '') === '23000') {
                return 0;
            }
            throw $e;
        }
    }

    /**
     * Remove a follow relationship between follower and target user.
     */
    public function unfollow(int $followerId, int $followingId): bool
    {
        if ($followerId <= 0 || $followingId <= 0) {
            return false;
        }

        $stmt = $this->db->prepare(
            'DELETE FROM community_follows WHERE follower_id = ? AND following_id = ?'
        );
        $stmt->execute([$followerId, $followingId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Check whether followerId is following followingId.
     */
    public function isFollowing(int $followerId, int $followingId): bool
    {
        if ($followerId <= 0 || $followingId <= 0) {
            return false;
        }

        $stmt = $this->db->prepare(
            'SELECT 1 FROM community_follows WHERE follower_id = ? AND following_id = ? LIMIT 1'
        );
        $stmt->execute([$followerId, $followingId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Total number of followers for a user.
     */
    public function followerCount(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM community_follows WHERE following_id = ?'
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Total number of users a user is following.
     */
    public function followingCount(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM community_follows WHERE follower_id = ?'
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Get array of user IDs followed by userId.
     *
     * @return array<int>
     */
    public function getFollowingIds(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $stmt = $this->db->prepare(
            'SELECT following_id FROM community_follows WHERE follower_id = ? ORDER BY id DESC'
        );
        $stmt->execute([$userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Paginated list of users following target userId.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findFollowers(int $userId, int $limit = 20, int $offset = 0): array
    {
        if ($userId <= 0) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT u.id, u.full_name, u.created_at, f.created_at AS followed_at
            FROM community_follows f
            JOIN users u ON u.id = f.follower_id
            WHERE f.following_id = ?
            ORDER BY f.id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(3, max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Paginated list of users being followed by target userId.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findFollowing(int $userId, int $limit = 20, int $offset = 0): array
    {
        if ($userId <= 0) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT u.id, u.full_name, u.created_at, f.created_at AS followed_at
            FROM community_follows f
            JOIN users u ON u.id = f.following_id
            WHERE f.follower_id = ?
            ORDER BY f.id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(3, max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
