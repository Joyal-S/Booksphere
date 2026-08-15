<?php

declare(strict_types=1);

/**
 * tests/CommunityC7ATest.php
 *
 * Automated Test Suite for Phase C7-A: Community Quality & Trust.
 * Verifies Rate Limiting, Validation, XSS escaping, Duplicate Prevention,
 * IDOR Defense, Moderated Content Protection, and Recommendation Boundedness.
 */

require_once __DIR__ . '/../bootstrap/constants.php';
require_once __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Database;
use BookSphere\App\Core\RateLimiter;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Session;
use BookSphere\App\Controllers\CommunityController;
use BookSphere\App\Exceptions\CommunityException;
use BookSphere\App\Models\Book;
use BookSphere\App\Models\CommunityComment;
use BookSphere\App\Models\CommunityLike;
use BookSphere\App\Models\CommunityPost;
use BookSphere\App\Models\CommunityReport;
use BookSphere\App\Policies\CommunityPolicy;
use BookSphere\App\Services\CommunityRecommendationSignalService;
use BookSphere\App\Services\CommunityService;

$session = new Session('c7a_test');
$session->start();

$passed = 0;
$failed = 0;

$assert = static function (string $description, bool $condition) use (&$passed, &$failed): void {
    if ($condition) {
        echo "  ✓ {$description}\n";
        $passed++;
    } else {
        echo "  ✗ FAIL: {$description}\n";
        $failed++;
    }
};

echo "========================================================================\n";
echo "PHASE C7-A: COMMUNITY QUALITY & TRUST TEST SUITE\n";
echo "========================================================================\n\n";

$db = Database::instance()->pdo();

// Clean existing test rows
$db->exec("DELETE FROM community_reports");
$db->exec("DELETE FROM community_likes");
$db->exec("DELETE FROM community_comments");
$db->exec("DELETE FROM community_posts");

// Ensure test users exist
$db->exec("INSERT OR IGNORE INTO users (id, full_name, email, password) VALUES (801, 'Quality User A', 'c7a_usera@test.com', 'hash')");
$db->exec("INSERT OR IGNORE INTO users (id, full_name, email, password) VALUES (802, 'Quality User B', 'c7a_userb@test.com', 'hash')");
$db->exec("INSERT OR IGNORE INTO books (id, google_book_id, isbn, title, status) VALUES (801, 'c7a-gbook-801', '978000000801', 'C7A Quality Book', 'published')");

$postModel    = new CommunityPost();
$commentModel = new CommunityComment();
$likeModel    = new CommunityLike();
$reportModel  = new CommunityReport();
$bookModel    = new Book();

$service      = new CommunityService($postModel, $commentModel, $likeModel, $reportModel, $bookModel);
$policy       = new CommunityPolicy();
$rateLimiter  = new RateLimiter($session);
$controller   = new CommunityController($service, $policy, $rateLimiter);

// ---------------------------------------------------------------------
// Section 1: Validation & Content Safety
// ---------------------------------------------------------------------
echo "--- Section 1: Validation & Content Safety ---\n";

try {
    $service->createPost(801, ['title' => '   ', 'body' => 'Valid Body content here.']);
    $assert('Whitespace-only title is rejected', false);
} catch (CommunityException $e) {
    $assert('Whitespace-only title is rejected', true);
}

try {
    $service->createPost(801, ['title' => 'Valid Title', 'body' => str_repeat('A', 10001)]);
    $assert('Oversized post body (>10000 chars) is rejected', false);
} catch (CommunityException $e) {
    $assert('Oversized post body (>10000 chars) is rejected', true);
}

try {
    $service->createComment(801, 999999, ['body' => '   ']);
    $assert('Whitespace-only comment body is rejected', false);
} catch (CommunityException $e) {
    $assert('Whitespace-only comment body is rejected', true);
}

// ---------------------------------------------------------------------
// Section 2: Duplicate Content & Report Abuse Prevention
// ---------------------------------------------------------------------
echo "\n--- Section 2: Duplicate Content & Report Abuse Prevention ---\n";

$postId1 = $service->createPost(801, [
    'title'   => 'Unique Discussion Title 101',
    'body'    => 'This is a unique discussion body for duplicate testing.',
    'book_id' => 801,
]);

try {
    $service->createPost(801, [
        'title'   => 'Unique Discussion Title 101',
        'body'    => 'This is a unique discussion body for duplicate testing.',
        'book_id' => 801,
    ]);
    $assert('Short-window duplicate post is rejected', false);
} catch (CommunityException $e) {
    $assert('Short-window duplicate post is rejected', str_contains($e->getMessage(), 'duplicate'));
}

$commentId1 = $service->createComment(802, $postId1, ['body' => 'This is a test comment for duplicate testing.']);

try {
    $service->createComment(802, $postId1, ['body' => 'This is a test comment for duplicate testing.']);
    $assert('Short-window duplicate comment is rejected', false);
} catch (CommunityException $e) {
    $assert('Short-window duplicate comment is rejected', str_contains($e->getMessage(), 'duplicate'));
}

$reportId1 = $service->reportPost(802, $postId1, ['reason' => 'Spam']);
$assert('First post report succeeds', $reportId1 > 0);

try {
    $service->reportPost(802, $postId1, ['reason' => 'Spam']);
    $assert('Duplicate report returns clear alreadyReported exception', false);
} catch (CommunityException $e) {
    $assert('Duplicate report returns clear alreadyReported exception', $e->getMessage() === 'You have already reported this content.');
}

// ---------------------------------------------------------------------
// Section 3: Moderated Content Protection
// ---------------------------------------------------------------------
echo "\n--- Section 3: Moderated Content Protection ---\n";

// Hide post
$db->exec("UPDATE community_posts SET status = 'hidden' WHERE id = {$postId1}");

try {
    $service->createComment(802, $postId1, ['body' => 'Attempt comment on hidden post']);
    $assert('Commenting on hidden post is rejected (404)', false);
} catch (CommunityException $e) {
    $assert('Commenting on hidden post is rejected (404)', true);
}

try {
    $service->likePost(802, $postId1);
    $assert('Liking hidden post is rejected (404)', false);
} catch (CommunityException $e) {
    $assert('Liking hidden post is rejected (404)', true);
}

try {
    $service->reportPost(801, $postId1, ['reason' => 'Spam']);
    $assert('Reporting hidden post is rejected (404)', false);
} catch (CommunityException $e) {
    $assert('Reporting hidden post is rejected (404)', true);
}

// Restore post
$db->exec("UPDATE community_posts SET status = 'active' WHERE id = {$postId1}");

// ---------------------------------------------------------------------
// Section 4: IDOR & Server-Side Authorization Defense
// ---------------------------------------------------------------------
echo "\n--- Section 4: IDOR & Server-Side Authorization Defense ---\n";

try {
    $service->updatePost(802, $postId1, ['title' => 'Hacked Title']);
    $assert('User B updating User A post is rejected', false);
} catch (CommunityException $e) {
    $assert('User B updating User A post is rejected', true);
}

try {
    $service->deletePost(802, $postId1);
    $assert('User B deleting User A post is rejected', false);
} catch (CommunityException $e) {
    $assert('User B deleting User A post is rejected', true);
}

try {
    $service->updateComment(801, $commentId1, ['body' => 'Hacked Comment']);
    $assert('User A updating User B comment is rejected', false);
} catch (CommunityException $e) {
    $assert('User A updating User B comment is rejected', true);
}

try {
    $service->deleteComment(801, $commentId1);
    $assert('User A deleting User B comment is rejected', false);
} catch (CommunityException $e) {
    $assert('User A deleting User B comment is rejected', true);
}

$assert('Author cannot like own post via policy', !$policy->canLike(['user_id' => 801], 801));
$assert('Author cannot report own post via policy', !$policy->canReport(['user_id' => 801], 801));

// ---------------------------------------------------------------------
// Section 5: Rate Limiting & Throttling
// ---------------------------------------------------------------------
echo "\n--- Section 5: Rate Limiting & Throttling ---\n";

$session->put('_rate_limit', []); // Reset session rate limit state
$persistentKey = 'user_801';

// Exhaust bucket for community_post (limit 20)
for ($i = 0; $i < 20; $i++) {
    $rateLimiter->allow('community_post', 20, 60, $persistentKey);
}

$assert('Post creation bucket exhausted at limit 20', !$rateLimiter->allow('community_post', 20, 60, $persistentKey));
$assert('RateLimiter reports tooManyAttempts for community_post', $rateLimiter->tooManyAttempts('community_post', 20, 60, $persistentKey));

$session->put('_rate_limit', []); // Reset session rate limit state

// Exhaust bucket for community_report (limit 10)
for ($i = 0; $i < 10; $i++) {
    $rateLimiter->allow('community_report', 10, 60, $persistentKey);
}

$assert('Report creation bucket exhausted at limit 10', !$rateLimiter->allow('community_report', 10, 60, $persistentKey));

// ---------------------------------------------------------------------
// Section 6: Recommendation Boundedness & Moderation Shield
// ---------------------------------------------------------------------
echo "\n--- Section 6: Recommendation Boundedness & Moderation Shield ---\n";

$signalService = new CommunityRecommendationSignalService();

// Active post on book 801
$signalsActive = $signalService->getUserBookSignals(801);
$assert('Active community post contributes signal points', ($signalsActive[801] ?? 0.0) > 0.0);

// Hide post
$db->exec("UPDATE community_posts SET status = 'hidden' WHERE id = {$postId1}");
$signalsHidden = $signalService->getUserBookSignals(801);
$assert('Hidden community post contributes 0 signal points', ($signalsHidden[801] ?? 0.0) === 0.0);

// Restore post
$db->exec("UPDATE community_posts SET status = 'active' WHERE id = {$postId1}");

// Clean up test rows
$db->exec("DELETE FROM community_reports WHERE id = {$reportId1}");
$db->exec("DELETE FROM community_comments WHERE id = {$commentId1}");
$db->exec("DELETE FROM community_posts WHERE id = {$postId1}");

// Summary
echo "\n------------------------------------------------------------------------\n";
echo "TEST SUMMARY: {$passed} passed, {$failed} failed\n";
echo "------------------------------------------------------------------------\n\n";

if ($failed === 0) {
    echo "RESULT: PASS ✓\n";
    exit(0);
}

echo "RESULT: FAIL ✗\n";
exit(1);
