<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/constants.php';
require_once __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Services\CommunityRecommendationSignalService;
use BookSphere\App\Services\RecommendationScoring;
use BookSphere\App\Services\RecommendationService;
use BookSphere\App\Services\RecommendationFactory;
use BookSphere\App\Repositories\RecommendationRepository;
use BookSphere\App\Strategies\PopularBooksStrategy;
use BookSphere\App\Strategies\HighestRatedStrategy;
use BookSphere\App\Strategies\TrendingBooksStrategy;
use BookSphere\App\Strategies\SameCategoryStrategy;
use BookSphere\App\Strategies\RecentlyAddedStrategy;
use BookSphere\App\Strategies\SameAuthorStrategy;

(new Environment(root_path('.env')))->load();
Database::instance();

$passed = 0;
$failed = 0;

$assert = function (string $name, bool $condition) use (&$passed, &$failed): void {
    if ($condition) {
        echo "  ✓ {$name}\n";
        $passed++;
    } else {
        echo "  ✗ {$name}\n";
        $failed++;
    }
};

echo "========================================================================\n";
echo "PHASE C6-E: COMMUNITY + RECOMMENDATION INTEGRATION TEST SUITE\n";
echo "========================================================================\n\n";

$db = Database::instance()->pdo();
$signalService = new CommunityRecommendationSignalService($db);

// --- Section 1: Signal Weights & Abuse Cap -------------------------------
echo "--- Section 1: Signal Weights & Anti-Manipulation Cap ---\n";

$userId = 9991;
$modUserId = 9992;

// Cleanup previous test rows
$db->exec("DELETE FROM community_comments WHERE user_id IN ({$userId}, {$modUserId})");
$db->exec("DELETE FROM community_likes WHERE user_id IN ({$userId}, {$modUserId})");
$db->exec("DELETE FROM community_posts WHERE user_id IN ({$userId}, {$modUserId})");
$db->exec("DELETE FROM users WHERE id IN ({$userId}, {$modUserId})");

// Insert test users to satisfy FK constraints
$db->exec("INSERT INTO users (id, full_name, email, password) VALUES ({$userId}, 'Test User 9991', 'user9991@test.com', 'hash')");
$db->exec("INSERT INTO users (id, full_name, email, password) VALUES ({$modUserId}, 'Test User 9992', 'user9992@test.com', 'hash')");

// 1.1 Cold start user (no activity)
$coldSignals = $signalService->getUserBookSignals($userId);
$assert('Cold-start user has empty community signals', $coldSignals === []);

// 1.2 Create active post linked to book 1
$db->exec("INSERT INTO community_posts (user_id, book_id, title, body, status, created_at) VALUES ({$userId}, 1, 'Post on Book 1', 'Discussion body', 'active', datetime('now'))");
$postId = (int) $db->lastInsertId();

$signalsAfterPost = $signalService->getUserBookSignals($userId);
$assert('Community post contributes 2.0 signal points to linked book', ($signalsAfterPost[1] ?? 0.0) === 2.0);

// 1.3 Add like on another active post linked to book 2
$db->exec("INSERT INTO community_posts (user_id, book_id, title, body, status, created_at) VALUES (1, 2, 'Post on Book 2', 'Discussion body', 'active', datetime('now'))");
$post2Id = (int) $db->lastInsertId();
$db->exec("INSERT INTO community_likes (post_id, user_id, created_at) VALUES ({$post2Id}, {$userId}, datetime('now'))");

$signalsAfterLike = $signalService->getUserBookSignals($userId);
$assert('Community like contributes 3.0 signal points to linked book', ($signalsAfterLike[2] ?? 0.0) === 3.0);

// 1.4 Add comment on post 2
$db->exec("INSERT INTO community_comments (post_id, user_id, body, status, created_at) VALUES ({$post2Id}, {$userId}, 'Great discussion!', 'active', datetime('now'))");

$signalsAfterComment = $signalService->getUserBookSignals($userId);
// 3.0 (like) + 1.0 (comment) = 4.0 points, capped at 5.0
$assert('Community comment adds 1.0 signal point (total 4.0)', ($signalsAfterComment[2] ?? 0.0) === 4.0);

// 1.5 Anti-manipulation cap test: add 5 more comments to exceed cap
for ($i = 0; $i < 5; $i++) {
    $db->exec("INSERT INTO community_comments (post_id, user_id, body, status, created_at) VALUES ({$post2Id}, {$userId}, 'Spam comment {$i}', 'active', datetime('now'))");
}
$signalsAfterSpam = $signalService->getUserBookSignals($userId);
$assert('Anti-manipulation cap bounds max community signal to 5.0 points', ($signalsAfterSpam[2] ?? 0.0) === 5.0);

echo "\n";

// --- Section 2: Moderation Safety --------------------------------------
echo "--- Section 2: Moderation Safety ---\n";

// Create hidden post
$db->exec("INSERT INTO community_posts (user_id, book_id, title, body, status, created_at) VALUES ({$modUserId}, 3, 'Hidden Post', 'Body', 'hidden', datetime('now'))");

$modSignals = $signalService->getUserBookSignals($modUserId);
$assert('Hidden post contributes 0 community signal points', !isset($modSignals[3]));

// Add comment on hidden post
$db->exec("INSERT INTO community_comments (post_id, user_id, body, status, created_at) VALUES ({$postId}, {$modUserId}, 'Hidden comment', 'hidden', datetime('now'))");
$modSignals2 = $signalService->getUserBookSignals($modUserId);
$assert('Hidden comment contributes 0 community signal points', !isset($modSignals2[1]));

echo "\n";

// --- Section 3: Recommendation Scoring Formula & Weights ------------
echo "--- Section 3: Recommendation Scoring Formula & Weights ---\n";

$baseSignals = [
    'category'     => 1,
    'author'       => 0,
    'wishlist'     => 0,
    'rating'       => 0,
    'review_score' => 0,
    'community'    => 0.0,
    'trending'     => 0,
    'popularity'   => 0,
];

$scoreCold = RecommendationScoring::hybridScore($baseSignals);
// category: 40 * 1/2 = 20 points
$assert('Base score without community signal equals 20.0', $scoreCold === 20.0);

$signalsWithCommunity = array_merge($baseSignals, ['community' => 5.0]);
$scoreWithCommunity = RecommendationScoring::hybridScore($signalsWithCommunity);
// community: 5 * 5.0/5.0 = 5 points -> total 25.0
$assert('Community signal adds 5.0 weight points to total score (25.0)', $scoreWithCommunity === 25.0);

$assert('Explicit author match (25.0 pts) remains stronger than max community signal (5.0 pts)',
    RecommendationScoring::hybridScore(array_merge($baseSignals, ['author' => 1])) > $scoreWithCommunity
);

echo "\n";

// --- Section 4: Privacy & Reason Phrasing -----------------------------
echo "--- Section 4: Privacy & Reason Phrasing ---\n";

$bookRepo = new \BookSphere\App\Repositories\BookRepository();
$repo     = new RecommendationRepository($bookRepo);
$factory  = new RecommendationFactory(
    new PopularBooksStrategy($repo),
    new HighestRatedStrategy($repo),
    new TrendingBooksStrategy($repo),
    new SameCategoryStrategy($repo),
    new RecentlyAddedStrategy($repo),
    new SameAuthorStrategy($repo),
);
$recService = new RecommendationService($factory, $repo, null, null, null, $signalService);

$profile = new \BookSphere\App\DTO\PersonalizationProfile(
    userId: 1,
    favouriteCategories: [],
    favouriteAuthors: [],
    wishlistBookIds: [],
    highlyRatedBookIds: [],
    reviewedBookIds: [],
    recentlyViewedBookIds: [],
    builtAt: gmdate('Y-m-d\TH:i:s\Z'),
);

$reason = $recService->getRecommendationReason(['community'], $profile, ['community' => 5.0]);
$assert('Recommendation reason is generic and private', $reason === 'Based on books you discussed in the community.');
$assert('Reason does not expose database IDs or usernames', !str_contains($reason, 'user') && !str_contains($reason, 'Rahul') && !str_contains($reason, 'id'));

echo "\n";

// --- Cleanup -----------------------------------------------------------
$db->exec("DELETE FROM community_comments WHERE user_id IN ({$userId}, {$modUserId})");
$db->exec("DELETE FROM community_likes WHERE user_id IN ({$userId}, {$modUserId})");
$db->exec("DELETE FROM community_posts WHERE user_id IN ({$userId}, {$modUserId})");
$db->exec("DELETE FROM users WHERE id IN ({$userId}, {$modUserId})");

// --- Summary -----------------------------------------------------------
echo "------------------------------------------------------------------------\n";
echo "TEST SUMMARY: {$passed} passed, {$failed} failed\n";
echo "------------------------------------------------------------------------\n\n";

if ($failed > 0) {
    echo "RESULT: FAIL ✗\n";
    exit(1);
}

echo "RESULT: PASS ✓\n";
exit(0);
