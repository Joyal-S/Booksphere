<?php

declare(strict_types=1);

/**
 * tests/CommunityC7BTest.php
 *
 * Automated CLI Test Suite for Phase C7-B: User Following.
 */

require_once __DIR__ . '/../bootstrap/constants.php';
require_once __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Database;
use BookSphere\App\Core\RateLimiter;
use BookSphere\App\Core\Session;
use BookSphere\App\Models\Book;
use BookSphere\App\Models\CommunityComment;
use BookSphere\App\Models\CommunityFollow;
use BookSphere\App\Models\CommunityLike;
use BookSphere\App\Models\CommunityPost;
use BookSphere\App\Models\CommunityReport;
use BookSphere\App\Models\User;
use BookSphere\App\Policies\CommunityPolicy;
use BookSphere\App\Services\CommunityService;

$pdo = Database::instance()->pdo();

// Run migration 0037 if table community_follows does not exist
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='community_follows'")->fetchAll();
if (empty($tables)) {
    $migration = require root_path('database/migrations/0037_create_community_follows_table.php');
    $pdo->exec($migration['up']);
}

// Clean previous test data
$pdo->exec("DELETE FROM community_follows");
$pdo->exec("DELETE FROM community_reports");
$pdo->exec("DELETE FROM community_likes");
$pdo->exec("DELETE FROM community_comments");
$pdo->exec("DELETE FROM community_posts");
$pdo->exec("DELETE FROM users WHERE email LIKE 'c7b_%@example.com'");
$pdo->exec("DELETE FROM books WHERE title LIKE 'C7B %'");

// Create test users
$userModel = new User();
$u1Id = $userModel->create('C7B Alice', 'c7b_alice@example.com', password_hash('password123', PASSWORD_BCRYPT));
$u2Id = $userModel->create('C7B Bob', 'c7b_bob@example.com', password_hash('password123', PASSWORD_BCRYPT));
$u3Id = $userModel->create('C7B Charlie', 'c7b_charlie@example.com', password_hash('password123', PASSWORD_BCRYPT));

// Create test book & posts
$pdo->exec("INSERT OR IGNORE INTO books (id, title, isbn, language) VALUES (7700, 'C7B Test Book', '9780000007700', 'en')");
$bookId = 7700;
$bookModel = new Book();

$postModel = new CommunityPost();
$commentModel = new CommunityComment();
$likeModel = new CommunityLike();
$reportModel = new CommunityReport();
$followModel = new CommunityFollow();
$policy = new CommunityPolicy();
$service = new CommunityService($postModel, $commentModel, $likeModel, $reportModel, $bookModel, null, $followModel);

// Seed posts
$postBob = $service->createPost($u2Id, ['title' => 'Bob Discussion 1', 'body' => 'Discussion body by Bob user.', 'book_id' => $bookId]);
$postCharlie = $service->createPost($u3Id, ['title' => 'Charlie Discussion 1', 'body' => 'Discussion body by Charlie user.', 'book_id' => $bookId]);

$passed = 0;
$failed = 0;

function assertTest(bool $condition, string $description, &$passed, &$failed): void {
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description}\n";
        $failed++;
    }
}

echo "========================================================================\n";
echo "PHASE C7-B: USER FOLLOWING TEST SUITE\n";
echo "========================================================================\n\n";

// 1. FOLLOW & UNFOLLOW CORE
echo "1. FOLLOW & UNFOLLOW CORE\n";
echo "------------------------------------------------------------------------\n";
$fId1 = $service->followUser($u1Id, $u2Id);
assertTest($fId1 > 0, "Alice (User {$u1Id}) successfully follows Bob (User {$u2Id})", $passed, $failed);

$isFollowing = $service->isFollowingUser($u1Id, $u2Id);
assertTest($isFollowing === true, "isFollowingUser returns true for Alice -> Bob", $passed, $failed);

$statsBob = $service->getUserFollowStats($u2Id);
assertTest($statsBob['followers'] === 1, "Bob follower count is 1", $passed, $failed);

$statsAlice = $service->getUserFollowStats($u1Id);
assertTest($statsAlice['following'] === 1, "Alice following count is 1", $passed, $failed);

// 2. SELF-FOLLOW PREVENTION
echo "\n2. SELF-FOLLOW PREVENTION\n";
echo "------------------------------------------------------------------------\n";
$selfFollowBlocked = false;
try {
    $service->followUser($u1Id, $u1Id);
} catch (\BookSphere\App\Exceptions\CommunityException $e) {
    $selfFollowBlocked = true;
}
assertTest($selfFollowBlocked === true, "Self-follow (Alice -> Alice) is rejected by service exception", $passed, $failed);

$canFollowSelfPolicy = $policy->canFollowUser($u1Id, $u1Id);
assertTest($canFollowSelfPolicy === false, "CommunityPolicy::canFollowUser prevents self-follow", $passed, $failed);

// 3. DUPLICATE FOLLOW HANDLING
echo "\n3. DUPLICATE FOLLOW HANDLING\n";
echo "------------------------------------------------------------------------\n";
$dupId = $service->followUser($u1Id, $u2Id);
assertTest($dupId === 0, "Duplicate follow (Alice -> Bob again) returns 0 without crashing", $passed, $failed);
$statsBobDup = $service->getUserFollowStats($u2Id);
assertTest($statsBobDup['followers'] === 1, "Bob follower count remains exactly 1", $passed, $failed);

// 4. UNFOLLOW OPERATION
echo "\n4. UNFOLLOW OPERATION\n";
echo "------------------------------------------------------------------------\n";
$unfollowed = $service->unfollowUser($u1Id, $u2Id);
assertTest($unfollowed === true, "Alice successfully unfollows Bob", $passed, $failed);

$isFollowingAfter = $service->isFollowingUser($u1Id, $u2Id);
assertTest($isFollowingAfter === false, "isFollowingUser returns false after unfollow", $passed, $failed);

$statsBobAfter = $service->getUserFollowStats($u2Id);
assertTest($statsBobAfter['followers'] === 0, "Bob follower count drops to 0", $passed, $failed);

// Re-follow Bob for feed testing
$service->followUser($u1Id, $u2Id);

// 5. PROFILE INTEGRATION & STATS
echo "\n5. PROFILE INTEGRATION & STATS\n";
echo "------------------------------------------------------------------------\n";
$profileDataVisitor = $service->getUserProfile($u2Id, 1, 1, 10, $u1Id);
assertTest($profileDataVisitor['stats']['followers'] === 1, "Profile payload carries correct follower count", $passed, $failed);
assertTest($profileDataVisitor['stats']['is_following'] === true, "Profile payload carries is_following = true for Alice viewing Bob", $passed, $failed);

$profileDataSelf = $service->getUserProfile($u1Id, 1, 1, 10, $u1Id);
assertTest($profileDataSelf['stats']['is_following'] === false, "Profile payload carries is_following = false on self profile", $passed, $failed);

// 6. PAGINATED FOLLOWERS & FOLLOWING LISTS
echo "\n6. PAGINATED FOLLOWERS & FOLLOWING LISTS\n";
echo "------------------------------------------------------------------------\n";
$followersList = $service->listFollowers($u2Id, 1, 20);
assertTest($followersList['total'] === 1, "Bob followers list total is 1", $passed, $failed);
assertTest((int)$followersList['items'][0]['id'] === $u1Id, "Bob followers list includes Alice", $passed, $failed);

$followingList = $service->listFollowing($u1Id, 1, 20);
assertTest($followingList['total'] === 1, "Alice following list total is 1", $passed, $failed);
assertTest((int)$followingList['items'][0]['id'] === $u2Id, "Alice following list includes Bob", $passed, $failed);

// 7. FOLLOWING FEED FILTERING (feed=following)
echo "\n7. FOLLOWING FEED FILTERING (feed=following)\n";
echo "------------------------------------------------------------------------\n";
// Alice follows Bob ($u2Id), but does NOT follow Charlie ($u3Id)
$followingFeed = $service->listDiscoveryPosts('recent', null, null, null, 1, 20, $u1Id);
assertTest($followingFeed['total'] === 1, "Following feed returns exactly 1 post (Bob's post)", $passed, $failed);
assertTest((int)$followingFeed['items'][0]['id'] === $postBob, "Following feed contains Bob's post", $passed, $failed);

$allFeed = $service->listDiscoveryPosts('recent', null, null, null, 1, 20, null);
assertTest($allFeed['total'] === 2, "All feed returns 2 posts (Bob and Charlie)", $passed, $failed);

// 8. MODERATION SAFETY IN FOLLOWING FEED
echo "\n8. MODERATION SAFETY IN FOLLOWING FEED\n";
echo "------------------------------------------------------------------------\n";
// Hide Bob's post
$pdo->exec("UPDATE community_posts SET status = 'hidden' WHERE id = {$postBob}");
$followingFeedHidden = $service->listDiscoveryPosts('recent', null, null, null, 1, 20, $u1Id);
assertTest($followingFeedHidden['total'] === 0, "Hidden post by followed user is excluded from Following Feed", $passed, $failed);
// Restore Bob's post
$pdo->exec("UPDATE community_posts SET status = 'active' WHERE id = {$postBob}");

// 9. FORGED FOLLOWER IDENTITY SAFETY
echo "\n9. FORGED FOLLOWER IDENTITY SAFETY\n";
echo "------------------------------------------------------------------------\n";
$invalidUserBlocked = false;
try {
    $service->followUser($u1Id, 999999);
} catch (\BookSphere\App\Exceptions\CommunityException $e) {
    $invalidUserBlocked = true;
}
assertTest($invalidUserBlocked === true, "Following non-existent user 999999 throws userNotFound", $passed, $failed);

echo "\n------------------------------------------------------------------------\n";
echo "SUMMARY: Checks " . ($passed + $failed) . " | Passed {$passed} | Failed {$failed}\n";
echo "------------------------------------------------------------------------\n";

if ($failed > 0) {
    exit(1);
}
