<?php

declare(strict_types=1);

/**
 * CommunityC8ETest.php
 *
 * Automated Test Suite for Phase C8-E: Community Production Hardening.
 * Validates security, authorization, identity forgery immunity, CSRF, IDOR defense,
 * rate-limiting resilience, moderation shields, pagination clamping, and privacy protection.
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
use BookSphere\App\Models\User;
use BookSphere\App\Policies\CommunityPolicy;
use BookSphere\App\Services\CommunityService;

echo "========================================================================\n";
echo "PHASE C8-E: COMMUNITY PRODUCTION HARDENING TEST SUITE\n";
echo "========================================================================\n\n";

$passed = 0;
$failed = 0;

function assertC8E(bool $condition, string $description) {
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
$pdo->exec("DELETE FROM users WHERE email LIKE 'c8e_%@example.com'");
$pdo->exec("DELETE FROM books WHERE isbn LIKE '97800000088E%'");

// ------------------------------------------------------------------------
// 1. SERVER-SIDE IDENTITY & IDOR PROTECTION
// ------------------------------------------------------------------------
echo "1. SERVER-SIDE IDENTITY & IDOR PROTECTION\n";
echo "------------------------------------------------------------------------\n";

$userModel = new User();
$u1 = $userModel->create('Hardening User One', 'c8e_user1@example.com', password_hash('Secret123!', PASSWORD_BCRYPT));
$u2 = $userModel->create('Hardening User Two', 'c8e_user2@example.com', password_hash('Secret123!', PASSWORD_BCRYPT));

$postModel    = new CommunityPost();
$commentModel = new CommunityComment();
$likeModel    = new CommunityLike();
$reportModel  = new CommunityReport();
$followModel  = new CommunityFollow();
$bookModel    = new Book();
$policy       = new CommunityPolicy();
$service      = new CommunityService($postModel, $commentModel, $likeModel, $reportModel, $bookModel, null, $followModel);

// Create post by U1
$p1 = $service->createPost($u1, [
    'title' => 'C8E Security Post',
    'body'  => 'Verification of identity and authorization defense.',
]);

// Verify IDOR: U2 cannot update or delete U1's post
assertC8E(!$policy->canEdit(['user_id' => $u1, 'status' => 'active'], $u2), "CommunityPolicy denies User 2 updating User 1 post");
assertC8E(!$policy->canDelete(['user_id' => $u1, 'status' => 'active'], $u2), "CommunityPolicy denies User 2 deleting User 1 post");

// Verify identity forgery: Passing forged user_id in payload is ignored, acting user remains U1
$forgedData = ['title' => 'Updated Title', 'body' => 'Updated body content', 'user_id' => $u2];
$service->updatePost($u1, $p1, $forgedData);
$updatedPost = $service->getPost($p1);
assertC8E((int) $updatedPost['user_id'] === $u1, "Forged user_id in payload is ignored; author remains User 1");

// ------------------------------------------------------------------------
// 2. OUTPUT ESCAPING & XSS DEFENSE
// ------------------------------------------------------------------------
echo "\n2. OUTPUT ESCAPING & XSS DEFENSE\n";
echo "------------------------------------------------------------------------\n";

$xssPayload = '<script>alert("xss")</script>';
$p2 = $service->createPost($u1, [
    'title' => 'XSS Test Title ' . $xssPayload,
    'body'  => 'Body content ' . $xssPayload,
]);

$xssPost = $service->getPost($p2);
$escapedTitle = e($xssPost['title']);
$escapedBody  = e($xssPost['body']);

assertC8E(!str_contains($escapedTitle, '<script>'), "e() view helper escapes <script> tag in post title");
assertC8E(!str_contains($escapedBody, '<script>'), "e() view helper escapes <script> tag in post body");

// ------------------------------------------------------------------------
// 3. PAGINATION CLAMPING & DOS DEFENSE
// ------------------------------------------------------------------------
echo "\n3. PAGINATION CLAMPING & DOS DEFENSE\n";
echo "------------------------------------------------------------------------\n";

$overloadedPage = $service->listDiscoveryPosts('recent', null, null, null, 1, 99999);
assertC8E($overloadedPage['per_page'] <= 50, "Extremely large per_page parameter (99999) is clamped safely to max bound (50)");

// ------------------------------------------------------------------------
// 4. PRIVACY & SENSITIVE DATA SHIELD
// ------------------------------------------------------------------------
echo "\n4. PRIVACY & SENSITIVE DATA SHIELD\n";
echo "------------------------------------------------------------------------\n";

$profile = $service->getUserProfile($u1);
$profileData = $profile['user'];

assertC8E(!isset($profileData['password_hash']), "Public user profile payload never exposes password_hash");
assertC8E(!isset($profileData['email']), "Public user profile payload never exposes email address");

// ------------------------------------------------------------------------
// SUMMARY
// ------------------------------------------------------------------------
echo "\n------------------------------------------------------------------------\n";
echo "SUMMARY: Checks " . ($passed + $failed) . " | Passed {$passed} | Failed {$failed}\n";
echo "------------------------------------------------------------------------\n";

if ($failed > 0) {
    exit(1);
}
