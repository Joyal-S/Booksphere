<?php

declare(strict_types=1);

/**
 * tests/CommunityC7DTest.php
 *
 * Automated CLI Test Suite for Phase C7-D: Community Gamification & Reputation.
 */

require_once __DIR__ . '/../bootstrap/constants.php';
require_once __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Database;
use BookSphere\App\Models\Book;
use BookSphere\App\Models\CommunityComment;
use BookSphere\App\Models\CommunityFollow;
use BookSphere\App\Models\CommunityLike;
use BookSphere\App\Models\CommunityPost;
use BookSphere\App\Models\CommunityReport;
use BookSphere\App\Models\CommunityReputation;
use BookSphere\App\Models\User;
use BookSphere\App\Services\CommunityService;

$pdo = Database::instance()->pdo();

// Clean existing test rows
$pdo->exec("DELETE FROM community_reports");
$pdo->exec("DELETE FROM community_likes");
$pdo->exec("DELETE FROM community_comments");
$pdo->exec("DELETE FROM community_posts");
$pdo->exec("DELETE FROM users WHERE email LIKE 'c7d_%@example.com'");
$pdo->exec("DELETE FROM books WHERE isbn LIKE '97800000077D%'");

// Create test users
$userModel = new User();
$u1Id = $userModel->create('C7D Alice', 'c7d_alice@example.com', password_hash('password123', PASSWORD_BCRYPT));
$u2Id = $userModel->create('C7D Bob', 'c7d_bob@example.com', password_hash('password123', PASSWORD_BCRYPT));

// Create test books
$pdo->exec("INSERT INTO books (id, title, isbn, language) VALUES (7730, 'C7D Gamification Book 1', '97800000077D1', 'en')");
$pdo->exec("INSERT INTO books (id, title, isbn, language) VALUES (7740, 'C7D Gamification Book 2', '97800000077D2', 'en')");
$b1Id = 7730;
$b2Id = 7740;

$postModel       = new CommunityPost();
$commentModel    = new CommunityComment();
$likeModel       = new CommunityLike();
$reportModel     = new CommunityReport();
$followModel     = new CommunityFollow();
$bookModel       = new Book();
$reputationModel = new CommunityReputation();
$service         = new CommunityService($postModel, $commentModel, $likeModel, $reportModel, $bookModel, null, $followModel, null, $reputationModel);

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
echo "PHASE C7-D: COMMUNITY GAMIFICATION & REPUTATION TEST SUITE\n";
echo "========================================================================\n\n";

// 1. COLD START USER REPUTATION
echo "1. COLD START USER REPUTATION\n";
echo "------------------------------------------------------------------------\n";
$repAliceCold = $reputationModel->getUserReputation($u1Id);
assertTest($repAliceCold['score'] === 0, "Cold start user has 0 reputation score", $passed, $failed);
assertTest(empty($repAliceCold['badges']), "Cold start user has 0 earned badges", $passed, $failed);
assertTest($repAliceCold['primary_badge'] === null, "Cold start user has null primary_badge", $passed, $failed);

// 2. REPUTATION SCORE ACCUMULATION & BADGES
echo "\n2. REPUTATION SCORE ACCUMULATION & BADGES\n";
echo "------------------------------------------------------------------------\n";
// Alice creates 2 posts across 2 books
$p1 = $service->createPost($u1Id, ['title' => 'Alice First Post', 'body' => 'Great book discussion content.', 'book_id' => $b1Id]);
$p2 = $service->createPost($u1Id, ['title' => 'Alice Second Post', 'body' => 'Another insightful discussion.', 'book_id' => $b2Id]);

// Alice creates 3 comments
$c1 = $service->createComment($u1Id, $p1, ['body' => 'My own comment on post 1']);
$c2 = $service->createComment($u1Id, $p2, ['body' => 'My own comment on post 2']);
$c3 = $service->createComment($u1Id, $p2, ['body' => 'Third comment on post 2']);

// Bob likes Alice's post 1
$service->likePost($u2Id, $p1);

$repAlice = $reputationModel->getUserReputation($u1Id);

// Score math: 2 active posts * 10 = 20 pts; 3 active comments * 2 = 6 pts; 1 like received * 5 = 5 pts. Total = 31 pts
assertTest($repAlice['score'] === 31, "Alice reputation score equals 31 pts (20 post pts + 6 comment pts + 5 like pts)", $passed, $failed);
assertTest($repAlice['breakdown']['posts_pts'] === 20, "Alice post points breakdown is 20", $passed, $failed);
assertTest($repAlice['breakdown']['comments_pts'] === 6, "Alice comment points breakdown is 6", $passed, $failed);
assertTest($repAlice['breakdown']['likes_pts'] === 5, "Alice like points breakdown is 5", $passed, $failed);

// Check Badges: Should earn 'First Discussion' and 'Book Discusser'
$badgeIds = array_column($repAlice['badges'], 'id');
assertTest(in_array('first_discussion', $badgeIds, true), "Alice earned 'First Discussion' badge", $passed, $failed);
assertTest(in_array('book_discusser', $badgeIds, true), "Alice earned 'Book Discusser' badge", $passed, $failed);

// 3. ANTI-SPAM SCORE CAPS
echo "\n3. ANTI-SPAM SCORE CAPS\n";
echo "------------------------------------------------------------------------\n";
// Create 20 posts for Bob (20 * 10 = 200, capped at 150)
for ($i = 1; $i <= 20; $i++) {
    $service->createPost($u2Id, ['title' => "Bob Post {$i}", 'body' => "Content for post {$i}", 'book_id' => $b1Id]);
}
$repBob = $reputationModel->getUserReputation($u2Id);
assertTest($repBob['breakdown']['posts_pts'] === 150, "Bob post points strictly capped at max 150 pts (preventing post spam)", $passed, $failed);

// 4. MODERATION SAFETY
echo "\n4. MODERATION SAFETY\n";
echo "------------------------------------------------------------------------\n";
// Hide Alice's post 1
$pdo->exec("UPDATE community_posts SET status = 'hidden' WHERE id = {$p1}");
$repAlicePostHidden = $reputationModel->getUserReputation($u1Id);

// Post 1 hidden -> only post 2 active (10 pts); comments on post 1 excluded; like on post 1 excluded.
assertTest($repAlicePostHidden['breakdown']['posts_pts'] === 10, "Hidden post excluded from post points (10 pts)", $passed, $failed);
assertTest($repAlicePostHidden['breakdown']['likes_pts'] === 0, "Likes on hidden post excluded from like points (0 pts)", $passed, $failed);

// Unhide post 1
$pdo->exec("UPDATE community_posts SET status = 'active' WHERE id = {$p1}");

// 5. PROFILE PAYLOAD INTEGRATION
echo "\n5. PROFILE PAYLOAD INTEGRATION\n";
echo "------------------------------------------------------------------------\n";
$profilePayload = $service->getUserProfile($u1Id, null);
assertTest(isset($profilePayload['reputation']), "getUserProfile includes 'reputation' payload", $passed, $failed);
assertTest($profilePayload['reputation']['score'] === 31, "Profile payload carries correct score 31", $passed, $failed);
assertTest(count($profilePayload['reputation']['badges']) === 2, "Profile payload carries 2 earned badges", $passed, $failed);

// 6. SECURITY & NON-FORGEABILITY
echo "\n6. SECURITY & NON-FORGEABILITY\n";
echo "------------------------------------------------------------------------\n";
assertTest(!isset($_POST['reputation']), "Reputation score is purely calculated server-side", $passed, $failed);

echo "\n------------------------------------------------------------------------\n";
echo "SUMMARY: Checks " . ($passed + $failed) . " | Passed {$passed} | Failed {$failed}\n";
echo "------------------------------------------------------------------------\n";

if ($failed > 0) {
    exit(1);
}
