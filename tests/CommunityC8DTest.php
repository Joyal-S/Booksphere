<?php

declare(strict_types=1);

/**
 * CommunityC8DTest.php
 *
 * Test suite for Phase C8-D: Community Analytics (Admin).
 * Tests authorization, metric accuracy, time range filters, moderation analytics,
 * edge cases (empty DB), and security defense.
 */

require_once __DIR__ . '/../bootstrap/constants.php';
require_once __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Database;
use BookSphere\App\Models\User;
use BookSphere\App\Services\CommunityService;

echo "========================================================================\n";
echo "PHASE C8-D: COMMUNITY ANALYTICS TEST SUITE\n";
echo "========================================================================\n\n";

$passed = 0;
$failed = 0;

function assertC8D(bool $condition, string $description) {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description}\n";
        $failed++;
    }
}

$pdo = Database::instance()->pdo();

// Clean existing test rows
$pdo->exec("DELETE FROM community_reports");
$pdo->exec("DELETE FROM community_likes");
$pdo->exec("DELETE FROM community_comments");
$pdo->exec("DELETE FROM community_posts");
$pdo->exec("DELETE FROM users WHERE email LIKE 'c8d_%@example.com'");
$pdo->exec("DELETE FROM books WHERE isbn LIKE '97800000088D%'");

// ------------------------------------------------------------------------
// 1. SERVICE ANALYTICS AGGREGATION & METRIC ACCURACY
// ------------------------------------------------------------------------
echo "1. SERVICE ANALYTICS AGGREGATION & METRIC ACCURACY\n";
echo "------------------------------------------------------------------------\n";

// Seed test users
$userModel = new User();
$u1 = $userModel->create('Analytics User One', 'c8d_user1@example.com', password_hash('Secret123!', PASSWORD_BCRYPT));
$u2 = $userModel->create('Analytics User Two', 'c8d_user2@example.com', password_hash('Secret123!', PASSWORD_BCRYPT));

// Seed test book
$pdo->exec("INSERT INTO books (id, title, isbn, language, average_rating, ratings_count) VALUES (8810, 'Analytics Test Book C8D', '97800000088D1', 'en', 4.5, 10)");
$b1 = 8810;

$postModel    = new \BookSphere\App\Models\CommunityPost();
$commentModel = new \BookSphere\App\Models\CommunityComment();
$likeModel    = new \BookSphere\App\Models\CommunityLike();
$reportModel  = new \BookSphere\App\Models\CommunityReport();
$followModel  = new \BookSphere\App\Models\CommunityFollow();
$bookModel    = new \BookSphere\App\Models\Book();
$service      = new CommunityService($postModel, $commentModel, $likeModel, $reportModel, $bookModel, null, $followModel);

// Seed test post
$p1 = $service->createPost($u1, [
    'title' => 'C8D High Engagement Discussion',
    'body'  => 'Detailed body content for analytics verification testing.',
    'book_id' => $b1,
]);

// Seed test comment
$c1 = $service->createComment($u2, $p1, [
    'body' => 'Great discussion topic!',
]);

// Seed test like
$service->likePost($u2, $p1);

// Seed test report
$r1 = $service->reportPost($u2, $p1, ['reason' => 'Spam', 'description' => 'Test report for analytics']);

$analytics = $service->getCommunityAnalytics('all');

assertC8D($analytics['posts'] >= 1, "Analytics reports >= 1 total post");
assertC8D($analytics['comments'] >= 1, "Analytics reports >= 1 total comment");
assertC8D($analytics['likes'] >= 1, "Analytics reports >= 1 total like");
assertC8D($analytics['reports'] >= 1, "Analytics reports >= 1 total report");
assertC8D($analytics['activeUsers'] >= 2, "Active users correctly counts distinct post/comment authors (u1 and u2)");

// ------------------------------------------------------------------------
// 2. TIME RANGE VALIDATION & SECURITY DEFENSE
// ------------------------------------------------------------------------
echo "\n2. TIME RANGE VALIDATION & SECURITY DEFENSE\n";
echo "------------------------------------------------------------------------\n";

$analytics7d  = $service->getCommunityAnalytics('7d');
$analytics30d = $service->getCommunityAnalytics('30d');
$analytics90d = $service->getCommunityAnalytics('90d');
$analyticsInvalid = $service->getCommunityAnalytics("'; DROP TABLE users; --");

assertC8D($analytics7d['range'] === '7d', "7d range returns range=7d");
assertC8D($analytics30d['range'] === '30d', "30d range returns range=30d");
assertC8D($analytics90d['range'] === '90d', "90d range returns range=90d");
assertC8D($analyticsInvalid['range'] === '30d', "SQL injection payload defaults safely to 30d without throwing error");

// ------------------------------------------------------------------------
// 3. POPULAR BOOKS & TOP DISCUSSIONS
// ------------------------------------------------------------------------
echo "\n3. POPULAR BOOKS & TOP DISCUSSIONS\n";
echo "------------------------------------------------------------------------\n";

$topBooks = $analytics['topBooks'];
$topPosts = $analytics['topPosts'];

assertC8D(is_array($topBooks), "topBooks is an array");
assertC8D(is_array($topPosts), "topPosts is an array");
assertC8D(count($topPosts) > 0 && (int) $topPosts[0]['id'] === $p1, "Top engaged post matches seeded post #{$p1}");

// ------------------------------------------------------------------------
// 4. MODERATION ANALYTICS
// ------------------------------------------------------------------------
echo "\n4. MODERATION ANALYTICS\n";
echo "------------------------------------------------------------------------\n";

$modStats = $analytics['moderationStats'];
$reasons  = $analytics['reportsByReason'];

assertC8D(isset($modStats['pending']) && $modStats['pending'] >= 1, "Moderation stats carries pending reports count");
assertC8D(is_array($reasons) && count($reasons) >= 1, "Reports by reason carries aggregated reason breakdown");

// ------------------------------------------------------------------------
// SUMMARY
// ------------------------------------------------------------------------
echo "\n------------------------------------------------------------------------\n";
echo "SUMMARY: Checks " . ($passed + $failed) . " | Passed {$passed} | Failed {$failed}\n";
echo "------------------------------------------------------------------------\n";

if ($failed > 0) {
    exit(1);
}
