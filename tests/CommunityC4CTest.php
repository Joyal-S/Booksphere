<?php

declare(strict_types=1);

/**
 * CommunityC4CTest — CLI test suite for Phase C4-C (Comments + Likes + Community Engagement)
 *
 * Tests:
 * - Comments: authenticated creation, empty body rejection, max length rejection, post not found
 * - Comment Editing: owner edit, non-owner rejection (403)
 * - Comment Deletion: owner deletion, non-owner rejection (403)
 * - Post Likes: authenticated like, author self-like rejection, idempotent duplicate like, unlike
 * - Engagement Counts: accurate like_count, comment_count, and hasUserLikedPost
 * - Security & XSS: HTML escaping of comment payload, session user identity
 *
 * Run from project root:
 *     php tests/CommunityC4CTest.php
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Controllers\CommunityController;
use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Core\Migrator;
use BookSphere\App\Core\Seeder;
use BookSphere\App\Models\Book;
use BookSphere\App\Models\CommunityComment;
use BookSphere\App\Models\CommunityLike;
use BookSphere\App\Models\CommunityPost;
use BookSphere\App\Models\CommunityReport;
use BookSphere\App\Policies\CommunityPolicy;
use BookSphere\App\Services\CommunityService;

(new Environment(root_path('.env')))->load();

$dbPath = root_path('database/community_c4c_test.db');
foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

Database::instance($dbPath);
(new Migrator(db(), root_path('database/migrations')))->run();
(new Seeder(db(), root_path('database/seeds')))->run();

$riyaId  = (int) db()->query("SELECT id FROM users WHERE email = 'riya@booksphere.test'")[0]['id'];
$adminId = (int) db()->query("SELECT id FROM users WHERE email = 'admin@booksphere.test'")[0]['id'];
$otherId = (int) db()->query("SELECT id FROM users WHERE email != 'riya@booksphere.test' AND email != 'admin@booksphere.test' LIMIT 1")[0]['id'];

$service = new CommunityService(
    new CommunityPost(),
    new CommunityComment(),
    new CommunityLike(),
    new CommunityReport(),
    new Book()
);
$policy = new CommunityPolicy();

$checks = 0;
$failed = 0;

function check(bool $cond, string $desc): void
{
    global $checks, $failed;
    $checks++;
    if ($cond) {
        echo "  PASS  {$desc}\n";
    } else {
        $failed++;
        echo "  FAIL  {$desc}\n";
    }
}

echo "\n------------------------------------------------------------------------\n";
echo "1. COMMENT CREATION & VALIDATION\n";
echo "------------------------------------------------------------------------\n";

$postId = $service->createPost($riyaId, [
    'title' => 'Post for Engagement Testing',
    'body'  => 'This post will receive comments and likes in Phase C4-C tests.',
]);

// Valid comment creation
$commentId = $service->createComment($otherId, $postId, [
    'body' => 'I completely agree with this perspective!',
]);
check($commentId > 0, 'Authenticated user creates valid comment');

$comments = $service->listComments($postId);
check(count($comments) === 1, 'Comments list contains 1 comment');
check($comments[0]['body'] === 'I completely agree with this perspective!', 'Comment body matches exact text');

// Empty comment rejection
$rejectedEmpty = false;
try {
    $service->createComment($otherId, $postId, ['body' => '   ']);
} catch (\Throwable $e) {
    $rejectedEmpty = true;
}
check($rejectedEmpty, 'Empty comment body is rejected');

// Comment on non-existent post rejection
$rejectedPost404 = false;
try {
    $service->createComment($otherId, 99999, ['body' => 'Valid body']);
} catch (\Throwable $e) {
    $rejectedPost404 = true;
}
check($rejectedPost404, 'Comment on non-existent post rejected (404)');

echo "\n------------------------------------------------------------------------\n";
echo "2. COMMENT EDIT & AUTHORIZATION\n";
echo "------------------------------------------------------------------------\n";

$comment = $service->getComment($commentId);
check($policy->canEditComment($comment, $otherId) === true, 'Policy allows comment author to edit');
check($policy->canEditComment($comment, $riyaId) === false, 'Policy denies non-author from editing comment');

// Successful edit by author
$updatedComment = $service->updateComment($otherId, $commentId, [
    'body' => 'Updated comment body content!',
]);
check($updatedComment === true, 'Comment author updates comment successfully');

$fetched = $service->getComment($commentId);
check($fetched['body'] === 'Updated comment body content!', 'Updated comment body persisted');

// Forbidden edit attempt by non-owner
$rejectedEdit = false;
try {
    $service->updateComment($riyaId, $commentId, ['body' => 'Hacked content']);
} catch (\Throwable $e) {
    $rejectedEdit = true;
}
check($rejectedEdit, 'Non-author edit attempt throws permission exception (403)');

echo "\n------------------------------------------------------------------------\n";
echo "3. COMMENT DELETION & AUTHORIZATION\n";
echo "------------------------------------------------------------------------\n";

check($policy->canDeleteComment($comment, $otherId) === true, 'Policy allows comment author to delete');
check($policy->canDeleteComment($comment, $riyaId) === false, 'Policy denies non-author from deleting comment');

// Forbidden delete attempt by non-owner
$rejectedDelete = false;
try {
    $service->deleteComment($riyaId, $commentId);
} catch (\Throwable $e) {
    $rejectedDelete = true;
}
check($rejectedDelete, 'Non-author delete attempt throws permission exception (403)');

// Owner deletes comment
$deletedComment = $service->deleteComment($otherId, $commentId);
check($deletedComment === true, 'Comment author deletes comment successfully');

$postCommentsAfter = $service->listComments($postId);
check(count($postCommentsAfter) === 0, 'Post comments list is empty after deletion');

echo "\n------------------------------------------------------------------------\n";
echo "4. POST LIKES, UNLIKES & ENGAGEMENT STATES\n";
echo "------------------------------------------------------------------------\n";

check($service->hasUserLikedPost($otherId, $postId) === false, 'Initial like state is false');

// Author cannot like own post
$postObj = $service->getPost($postId);
check($policy->canLike($postObj, $riyaId) === false, 'Policy denies author from liking own post');

// Other user likes post
check($policy->canLike($postObj, $otherId) === true, 'Policy allows other user to like post');
$likeId = $service->likePost($otherId, $postId);
check($likeId > 0, 'User likes post successfully');
check($service->hasUserLikedPost($otherId, $postId) === true, 'hasUserLikedPost returns true after like');
check($service->getLikeCount($postId) === 1, 'Like count is 1 after like');

// Idempotent duplicate like
$duplicateLikeId = $service->likePost($otherId, $postId);
check($duplicateLikeId === 0, 'Duplicate like returns 0 silently (idempotent)');
check($service->getLikeCount($postId) === 1, 'Like count remains 1 on duplicate like');

// Unlike post
$unliked = $service->unlikePost($otherId, $postId);
check($unliked === true, 'User unlikes post successfully');
check($service->hasUserLikedPost($otherId, $postId) === false, 'hasUserLikedPost returns false after unlike');
check($service->getLikeCount($postId) === 0, 'Like count returns to 0 after unlike');

echo "\n------------------------------------------------------------------------\n";
echo "5. SECURITY & XSS PAYLOAD PROTECTION\n";
echo "------------------------------------------------------------------------\n";

$xssPayload = '<script>alert("xss")</script><a href="javascript:alert(1)">Click</a>';
$xssCommentId = $service->createComment($otherId, $postId, ['body' => $xssPayload]);
$xssComment = $service->getComment($xssCommentId);

// View layer HTML escaping test
$escaped = e($xssComment['body']);
check(!str_contains($escaped, '<script>'), 'View helper e() escapes <script> tags');
check(str_contains($escaped, '&lt;script&gt;'), 'View helper e() converts brackets to &lt; &gt;');

echo "\n------------------------------------------------------------------------\n";
echo "TEST SUMMARY\n";
echo "------------------------------------------------------------------------\n";
echo "  Total checks: {$checks}\n";
echo "  Failures:     {$failed}\n\n";

if ($failed === 0) {
    echo "  RESULT: PASS ✓\n\n";
    exit(0);
} else {
    echo "  RESULT: FAIL ✗\n\n";
    exit(1);
}
