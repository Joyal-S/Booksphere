<?php

declare(strict_types=1);

namespace BookSphere\App\Models;

use BookSphere\App\Core\Database;
use PDO;

/**
 * CommunityReputation Model
 *
 * Calculates bounded Community Reputation scores and evaluates badge eligibility
 * dynamically from existing indexed tables (Phase C7-D).
 * Zero persistent storage required.
 */
final class CommunityReputation
{
    private readonly PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::instance()->pdo();
    }

    /**
     * Get complete reputation payload for a user.
     *
     * @return array{
     *   score: int,
     *   breakdown: array<string, int>,
     *   badges: array<int, array<string, string>>,
     *   primary_badge: array<string, string>|null
     * }
     */
    public function getUserReputation(int $userId): array
    {
        if ($userId <= 0) {
            return [
                'score'         => 0,
                'breakdown'     => ['posts_pts' => 0, 'comments_pts' => 0, 'likes_pts' => 0],
                'badges'        => [],
                'primary_badge' => null,
            ];
        }

        // 1. Active Posts Count
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM community_posts WHERE user_id = ? AND status = 'active'");
        $stmt->execute([$userId]);
        $activePosts = (int) $stmt->fetchColumn();

        // 2. Active Comments Count
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM community_comments WHERE user_id = ? AND status = 'active'");
        $stmt->execute([$userId]);
        $activeComments = (int) $stmt->fetchColumn();

        // 3. Likes Received on Active Posts
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM community_likes l JOIN community_posts p ON p.id = l.post_id WHERE p.user_id = ? AND p.status = 'active'");
        $stmt->execute([$userId]);
        $likesReceived = (int) $stmt->fetchColumn();

        // 4. Distinct Books Discussed
        $stmt = $this->db->prepare("SELECT COUNT(DISTINCT book_id) FROM community_posts WHERE user_id = ? AND status = 'active' AND book_id IS NOT NULL");
        $stmt->execute([$userId]);
        $distinctBooks = (int) $stmt->fetchColumn();

        // Anti-Spam Bounded Score Calculation
        $postsPts    = min(150, $activePosts * 10);
        $commentsPts = min(100, $activeComments * 2);
        $likesPts    = $likesReceived * 5;
        $totalScore  = $postsPts + $commentsPts + $likesPts;

        // Badge Evaluations
        $allBadges = [
            'first_discussion' => [
                'id'          => 'first_discussion',
                'name'        => 'First Discussion',
                'icon'        => 'fa-seedling',
                'description' => 'Started their first community discussion.',
                'eligible'    => $activePosts >= 1,
            ],
            'book_discusser' => [
                'id'          => 'book_discusser',
                'name'        => 'Book Discusser',
                'icon'        => 'fa-book-open',
                'description' => 'Discussed multiple books across the catalog.',
                'eligible'    => $distinctBooks >= 2,
            ],
            'active_reader' => [
                'id'          => 'active_reader',
                'name'        => 'Active Reader',
                'icon'        => 'fa-comments',
                'description' => 'Regular contributor to reading discussions.',
                'eligible'    => ($activePosts >= 3 || $activeComments >= 10),
            ],
            'helpful_contributor' => [
                'id'          => 'helpful_contributor',
                'name'        => 'Helpful Contributor',
                'icon'        => 'fa-award',
                'description' => 'Shared insights that fellow readers appreciated.',
                'eligible'    => $likesReceived >= 5,
            ],
            'community_regular' => [
                'id'          => 'community_regular',
                'name'        => 'Community Regular',
                'icon'        => 'fa-star',
                'description' => 'Recognized, trusted member of the community.',
                'eligible'    => ($totalScore >= 50 && $activePosts >= 2),
            ],
        ];

        $earnedBadges = [];
        foreach ($allBadges as $badge) {
            if ($badge['eligible']) {
                unset($badge['eligible']);
                $earnedBadges[] = $badge;
            }
        }

        // Primary badge selection priority: Helpful Contributor > Community Regular > Active Reader > Book Discusser > First Discussion
        $primaryBadge = null;
        $priorityKeys = ['helpful_contributor', 'community_regular', 'active_reader', 'book_discusser', 'first_discussion'];
        foreach ($priorityKeys as $key) {
            if (isset($allBadges[$key]) && $allBadges[$key]['eligible']) {
                $p = $allBadges[$key];
                unset($p['eligible']);
                $primaryBadge = $p;
                break;
            }
        }

        return [
            'score'     => $totalScore,
            'breakdown' => [
                'posts_pts'    => $postsPts,
                'comments_pts' => $commentsPts,
                'likes_pts'    => $likesPts,
            ],
            'badges'        => $earnedBadges,
            'primary_badge' => $primaryBadge,
        ];
    }
}
