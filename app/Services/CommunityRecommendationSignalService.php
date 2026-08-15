<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\Core\Database;
use PDO;

/**
 * CommunityRecommendationSignalService
 *
 * Dedicated signal provider for the Recommendations module (Phase C6-E).
 * Converts a user's active Community interactions (posts created,
 * post likes, and comments on book-linked discussions) into
 * recommendation-safe signal weights.
 *
 * Responsibilities:
 * - Aggregate user's active Community interactions per book_id in a
 *   single query (zero N+1 queries).
 * - Enforce moderation safety (only status = 'active' content counts).
 * - Apply anti-manipulation caps (maximum 5.0 signal points per book).
 * - Maintain strict privacy (returns raw aggregated numerical weights,
 *   never usernames or post content).
 */
final class CommunityRecommendationSignalService
{
    /** Signal weights for Community interactions. */
    public const WEIGHT_LIKE    = 3.0;
    public const WEIGHT_POST    = 2.0;
    public const WEIGHT_COMMENT = 1.0;

    /** Maximum total Community signal points allowed per book. */
    public const SIGNAL_CAP_PER_BOOK = 5.0;

    private readonly PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::instance()->pdo();
    }

    /**
     * Get aggregated Community interaction weights for a user, grouped by book_id.
     *
     * @param int $userId
     * @return array<int, float> Map of book_id => signal weight (0.0 to 5.0)
     */
    public function getUserBookSignals(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        // 1. Posts authored by user linked to active books (2.0 pts per post)
        $postsStmt = $this->db->prepare("
            SELECT book_id, COUNT(*) AS cnt
            FROM community_posts
            WHERE user_id = :user_id
              AND status = 'active'
              AND book_id IS NOT NULL
            GROUP BY book_id
        ");
        $postsStmt->execute(['user_id' => $userId]);
        $postRows = $postsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 2. Posts liked by user (3.0 pts per like)
        $likesStmt = $this->db->prepare("
            SELECT p.book_id, COUNT(*) AS cnt
            FROM community_likes l
            JOIN community_posts p ON p.id = l.post_id
            WHERE l.user_id = :user_id
              AND p.status = 'active'
              AND p.book_id IS NOT NULL
            GROUP BY p.book_id
        ");
        $likesStmt->execute(['user_id' => $userId]);
        $likeRows = $likesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 3. Comments created by user on active posts (1.0 pt per comment)
        $commentsStmt = $this->db->prepare("
            SELECT p.book_id, COUNT(*) AS cnt
            FROM community_comments c
            JOIN community_posts p ON p.id = c.post_id
            WHERE c.user_id = :user_id
              AND c.status = 'active'
              AND p.status = 'active'
              AND p.book_id IS NOT NULL
            GROUP BY p.book_id
        ");
        $commentsStmt->execute(['user_id' => $userId]);
        $commentRows = $commentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $totals = [];

        foreach ($postRows as $row) {
            $bookId = (int) $row['book_id'];
            $cnt    = (int) $row['cnt'];
            $totals[$bookId] = ($totals[$bookId] ?? 0.0) + ($cnt * self::WEIGHT_POST);
        }

        foreach ($likeRows as $row) {
            $bookId = (int) $row['book_id'];
            $cnt    = (int) $row['cnt'];
            $totals[$bookId] = ($totals[$bookId] ?? 0.0) + ($cnt * self::WEIGHT_LIKE);
        }

        foreach ($commentRows as $row) {
            $bookId = (int) $row['book_id'];
            $cnt    = (int) $row['cnt'];
            $totals[$bookId] = ($totals[$bookId] ?? 0.0) + ($cnt * self::WEIGHT_COMMENT);
        }

        // Apply anti-manipulation cap per book
        $signals = [];
        foreach ($totals as $bookId => $rawWeight) {
            if ($rawWeight > 0) {
                $signals[$bookId] = min($rawWeight, self::SIGNAL_CAP_PER_BOOK);
            }
        }

        return $signals;
    }
}
