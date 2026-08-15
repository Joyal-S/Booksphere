<?php

declare(strict_types=1);

/**
 * CommunityTest ? CLI test suite for Phase C3-A (Community Core)
 *
 * Tests the complete backend foundation for the Community module:
 * - Repositories: CommunityPostRepository, CommunityCommentRepository,
 *                 CommunityLikeRepository, CommunityReportRepository
 * - Models: CommunityPost, CommunityComment, CommunityLike, CommunityReport
 * - Policy: CommunityPolicy
 * - Service: CommunityService
 * - Exceptions: CommunityException
 *
 * Run from project root:
 *     php tests/CommunityTest.php
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Core\Logger;
use BookSphere\App\Core\Migrator;
use BookSphere\App\Core\Seeder;
use BookSphere\App\Core\Session;
use BookSphere\App\Exceptions\CommunityException;
use BookSphere\App\Models\Book;
use BookSphere\App\Models\CommunityComment;
use BookSphere\App\Models\CommunityLike;
use BookSphere\App\Models\CommunityPost;
use BookSphere\App\Models\CommunityReport;
use BookSphere\App\Models\User;
use BookSphere\App\Policies\CommunityPolicy;
use BookSphere\App\Repositories\CommunityCommentRepository;
use BookSphere\App\Repositories\CommunityLikeRepository;
use BookSphere\App\Repositories\CommunityPostRepository;
use BookSphere\App\Repositories\CommunityReportRepository;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\CommunityService;

// Boot test environment
(new Environment(root_path('.env')))->load();

$dbPath = root_path('database/community_test.db');
foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

Database::instance($dbPath);
(new Migrator(db(), root_path('database/migrations')))->run();
(new Seeder(db(), root_path('database/seeds')))->run();

$session = new Session('community_test');
$session->start();
$auth = new AuthService($session, new User());
AuthService::setInstance($auth);

$logFile = sys_get_temp_dir() . '/booksphere_community_test.log';
if (is_file($logFile)) {
    unlink($logFile);
}
$logger = new Logger($logFile);

// Shared test fixtures
$userModel = new User();
$adminUser = $userModel->findByEmail('admin@booksphere.test');
$riyaUser  = $userModel->findByEmail('riya@booksphere.test');

$adminId = (int) $adminUser['id'];
$riyaId  = (int) $riyaUser['id'];
$nonOwnerId = 99999;

$bookModel = new Book();
$bookIdRow = db()->query('SELECT id FROM books LIMIT 1');
$bookId    = (int) ($bookIdRow[0]['id'] ?? 1);

// Component instances
$postRepo    = new CommunityPostRepository();
$commentRepo = new CommunityCommentRepository();
$likeRepo    = new CommunityLikeRepository();
$reportRepo  = new CommunityReportRepository();

$postModel    = new CommunityPost($postRepo);
$commentModel = new CommunityComment($commentRepo);
$likeModel    = new CommunityLike($likeRepo);
$reportModel  = new CommunityReport($reportRepo);

$policy  = new CommunityPolicy();
$service = new CommunityService($postModel, $commentModel, $likeModel, $reportModel, $bookModel, $logger);

$section = fn (string $title): string => "\n------------------------------------------------------------------------\n{$title}\n------------------------------------------------------------------------\n";
$check   = function (string $label, bool $ok): void {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . PHP_EOL;
    $GLOBALS['failures'] = ($GLOBALS['failures'] ?? 0) + ($ok ? 0 : 1);
    $GLOBALS['checks']   = ($GLOBALS['checks'] ?? 0) + 1;
};
$throws  = function (string $expected, callable $fn): bool {
    try {
        $fn();
    } catch (Throwable $e) {
        return $e instanceof $expected;
    }
    return false;
};

// =========================================================================
// 1. REPOSITORIES & MODELS FOUNDATION
// =========================================================================

echo $section('1. REPOSITORIES & MODELS: Basic CRUD operations');

// Post creation & retrieval
$postId1 = $postModel->create([
    'user_id' => $riyaId,
    'title'   => 'First Post Title',
    'body'    => 'This is the first post body text for testing.',
    'book_id' => $bookId,
    'status'  => 'active',
]);
$check('Post model creates post row', $postId1 > 0);

$fetchedPost = $postModel->find($postId1);
$check('Post model finds created post', is_array($fetchedPost) && $fetchedPost['title'] === 'First Post Title');
$check('Post query joins author_name', isset($fetchedPost['author_name']) && $fetchedPost['author_name'] === 'Riya Sharma');
$check('Post query joins book_title', isset($fetchedPost['book_title']) && $fetchedPost['book_title'] !== '');

// Comment creation & retrieval
$commentId1 = $commentModel->create([
    'post_id' => $postId1,
    'user_id' => $adminId,
    'body'    => 'Great post! I agree with your thoughts.',
]);
$check('Comment model creates comment row', $commentId1 > 0);

$fetchedComment = $commentModel->find($commentId1);
$check('Comment model finds created comment', is_array($fetchedComment) && $fetchedComment['body'] === 'Great post! I agree with your thoughts.');
$check('Comment query joins author_name', isset($fetchedComment['author_name']) && $fetchedComment['author_name'] !== '');

// Like creation & count
$likeId1 = $likeModel->create($postId1, $adminId);
$check('Like model creates like row', $likeId1 > 0);
$check('Like model detects existing like', $likeModel->exists($postId1, $adminId) === true);
$check('Like model counts post likes', $likeModel->count($postId1) === 1);

// Report creation
$reportId1 = $reportModel->create([
    'post_id'     => $postId1,
    'reported_by' => $adminId,
    'reason'      => 'Spam',
    'description' => 'Test report description',
]);
$check('Report model creates report row', $reportId1 > 0);

// =========================================================================
// 2. MODEL RELATIONSHIPS
// =========================================================================

echo $section('2. MODEL RELATIONSHIPS');

$authorUser = $postModel->author($fetchedPost);
$check('Post -> Author relationship resolves User', is_array($authorUser) && (int) $authorUser['id'] === $riyaId);

$linkedBook = $postModel->book($fetchedPost);
$check('Post -> Book relationship resolves Book', is_array($linkedBook) && (int) $linkedBook['id'] === $bookId);

$parentPost = $commentModel->post($fetchedComment);
$check('Comment -> Post relationship resolves Post', is_array($parentPost) && (int) $parentPost['id'] === $postId1);

$commentAuthor = $commentModel->author($fetchedComment);
$check('Comment -> Author relationship resolves User', is_array($commentAuthor) && (int) $commentAuthor['id'] === $adminId);

// =========================================================================
// 3. COMMUNITY SERVICE: POSTS
// =========================================================================

echo $section('3. SERVICE: POSTS (Create, Retrieve, List, Update, Delete)');

// Create valid post
$servicePostId = $service->createPost($riyaId, [
    'title'   => 'Service Test Post',
    'body'    => 'Detailed body content written for testing the community service layer.',
    'book_id' => $bookId,
]);
$check('Service creates valid post', $servicePostId > 0);

// Reject invalid posts
$check('Service rejects post with empty title', $throws(
    CommunityException::class,
    fn () => $service->createPost($riyaId, ['title' => '', 'body' => 'Valid body content long enough'])
));

$check('Service rejects post with title exceeding max length', $throws(
    CommunityException::class,
    fn () => $service->createPost($riyaId, ['title' => str_repeat('A', 121), 'body' => 'Valid body content long enough'])
));

$check('Service rejects post with body below min length', $throws(
    CommunityException::class,
    fn () => $service->createPost($riyaId, ['title' => 'Valid Title', 'body' => 'Short'])
));

$check('Service rejects post with non-existent book_id', $throws(
    CommunityException::class,
    fn () => $service->createPost($riyaId, ['title' => 'Valid Title', 'body' => 'Valid body content long enough', 'book_id' => 999999])
));

// Retrieve post & lists
$getPostRes = $service->getPost($servicePostId);
$check('Service getPost retrieves created post', $getPostRes['title'] === 'Service Test Post');

$listRes = $service->listPosts(1, 10);
$check('Service listPosts returns active posts', count($listRes['items']) >= 2 && $listRes['total'] >= 2);

$bookListRes = $service->listPostsForBook($bookId, 1, 10);
$check('Service listPostsForBook returns book-linked posts', count($bookListRes['items']) >= 2);

$userListRes = $service->listPostsByUser($riyaId, 1, 10);
$check('Service listPostsByUser returns user posts', count($userListRes['items']) >= 2);

// Update own post
$updateOk = $service->updatePost($riyaId, $servicePostId, [
    'title' => 'Updated Service Post Title',
    'body'  => 'Updated body content for testing the post update functionality.',
]);
$check('Service updates own post successfully', $updateOk === true);
$check('Updated post reflects new title', $service->getPost($servicePostId)['title'] === 'Updated Service Post Title');

// Non-owner (non-admin) cannot update post
$check('Service rejects update of another user\'s post', $throws(
    CommunityException::class,
    fn () => $service->updatePost($nonOwnerId, $servicePostId, [
        'title' => 'Hacked Title',
        'body'  => 'Hacked body text long enough for test.',
    ])
));

// Admin can update another user's post
$adminUpdateOk = $service->updatePost($adminId, $servicePostId, [
    'title'  => 'Admin Updated Title',
    'body'   => 'Admin updated body text long enough for test.',
    'status' => 'active',
]);
$check('Admin can update another user\'s post', $adminUpdateOk === true);

// Non-owner (non-admin) cannot delete post
$check('Service rejects deletion of another user\'s post', $throws(
    CommunityException::class,
    fn () => $service->deletePost($nonOwnerId, $servicePostId)
));

// Delete own post
$deleteOk = $service->deletePost($riyaId, $servicePostId);
$check('Service deletes own post successfully', $deleteOk === true);

$check('Deleted post throws 404 on getPost', $throws(
    CommunityException::class,
    fn () => $service->getPost($servicePostId)
));

// =========================================================================
// 4. COMMUNITY SERVICE: COMMENTS
// =========================================================================

echo $section('4. SERVICE: COMMENTS (Create, List, Update, Delete)');

// Create valid comment
$serviceCommentId = $service->createComment($riyaId, $postId1, [
    'body' => 'This is a valid test comment on post 1.',
]);
$check('Service creates valid comment', $serviceCommentId > 0);

// Reject invalid comments
$check('Service rejects empty comment', $throws(
    CommunityException::class,
    fn () => $service->createComment($riyaId, $postId1, ['body' => '   '])
));

$check('Service rejects comment exceeding max length', $throws(
    CommunityException::class,
    fn () => $service->createComment($riyaId, $postId1, ['body' => str_repeat('C', 2001)])
));

$check('Service rejects comment on non-existent post', $throws(
    CommunityException::class,
    fn () => $service->createComment($riyaId, 999999, ['body' => 'Valid comment text'])
));

// List comments
$commentsList = $service->listComments($postId1);
$check('Service listComments returns post comments', count($commentsList) >= 2);

// Update own comment
$commentUpdateOk = $service->updateComment($riyaId, $serviceCommentId, [
    'body' => 'Updated comment body text.',
]);
$check('Service updates own comment successfully', $commentUpdateOk === true);

// Non-owner cannot update comment
$check('Service rejects update of another user\'s comment', $throws(
    CommunityException::class,
    fn () => $service->updateComment($nonOwnerId, $serviceCommentId, ['body' => 'Hacked comment'])
));

// Non-owner cannot delete comment
$check('Service rejects deletion of another user\'s comment', $throws(
    CommunityException::class,
    fn () => $service->deleteComment($nonOwnerId, $serviceCommentId)
));

// Delete own comment
$commentDeleteOk = $service->deleteComment($riyaId, $serviceCommentId);
$check('Service deletes own comment successfully', $commentDeleteOk === true);

// =========================================================================
// 5. COMMUNITY SERVICE: LIKES
// =========================================================================

echo $section('5. SERVICE: LIKES (Like, Idempotence, Unlike, Count)');

// Clean like state for post 1
$service->unlikePost($riyaId, $postId1);
$service->unlikePost($adminId, $postId1);

$check('Initial like state is false', $service->hasUserLikedPost($riyaId, $postId1) === false);
$initialCount = $service->getLikeCount($postId1);

// Like post
$likeIdRes = $service->likePost($riyaId, $postId1);
$check('Service likes post successfully', $likeIdRes > 0);
$check('hasUserLikedPost returns true after like', $service->hasUserLikedPost($riyaId, $postId1) === true);
$check('Like count increases by 1', $service->getLikeCount($postId1) === $initialCount + 1);

// Repeat like is silently idempotent (returns 0)
$dupLikeId = $service->likePost($riyaId, $postId1);
$check('Duplicate like is silently idempotent (returns 0)', $dupLikeId === 0);
$check('Like count remains unchanged on duplicate like', $service->getLikeCount($postId1) === $initialCount + 1);

// Unlike post
$unlikeRes = $service->unlikePost($riyaId, $postId1);
$check('Service unlikes post successfully', $unlikeRes === true);
$check('hasUserLikedPost returns false after unlike', $service->hasUserLikedPost($riyaId, $postId1) === false);
$check('Like count decreases back', $service->getLikeCount($postId1) === $initialCount);

// Unlike non-existent like is idempotent (returns false)
$check('Unlike non-existent like returns false', $service->unlikePost($riyaId, $postId1) === false);

// =========================================================================
// 6. COMMUNITY SERVICE: REPORTS
// =========================================================================

echo $section('6. SERVICE: REPORTS (Report Post, Report Comment, Validations)');

// Report post
$postReportId = $service->reportPost($riyaId, $postId1, [
    'reason'      => 'Offensive Content',
    'description' => 'Detailed report description regarding post content.',
]);
$check('Service creates valid post report', $postReportId > 0);

// Report comment
$commentReportId = $service->reportComment($adminId, $commentId1, [
    'reason'      => 'Harassment',
    'description' => 'Detailed report description regarding comment content.',
]);
$check('Service creates valid comment report', $commentReportId > 0);

// Reject report with invalid target
$check('Service rejects report on non-existent post', $throws(
    CommunityException::class,
    fn () => $service->reportPost($riyaId, 999999, ['reason' => 'Spam'])
));

$check('Service rejects report on non-existent comment', $throws(
    CommunityException::class,
    fn () => $service->reportComment($riyaId, 999999, ['reason' => 'Spam'])
));

// Reject report with invalid reason
$check('Service rejects report with invalid reason', $throws(
    CommunityException::class,
    fn () => $service->reportPost($riyaId, $postId1, ['reason' => 'InvalidReasonEnum'])
));

// Check pending reports for moderation queue
$pendingReports = $service->pendingReports();
$check('Service returns pending reports for moderation', count($pendingReports) >= 2);
$check('Pending report count matches', $service->pendingReportCount() >= 2);

// =========================================================================
// 7. POLICY MATRIX
// =========================================================================

echo $section('7. POLICY MATRIX (Permissions & Ownership)');

$check('Policy: Guests can view feed', $policy->canViewFeed() === true);

// Set session auth_user_id and auth_user
$session->put('auth_user_id', $riyaId);
$session->put('auth_user', $riyaUser);
$check('Policy: Authenticated user can create post', $policy->canCreatePost() === true);

$check('Policy: Post author can edit post', $policy->canEdit($fetchedPost, $riyaId) === true);
$check('Policy: Non-author non-admin cannot edit post', $policy->canEdit($fetchedPost, $nonOwnerId) === false);
$check('Policy: Admin can edit post', $policy->canEdit($fetchedPost, $adminId) === true);
$check('Policy: Author cannot like own post', $policy->canLike($fetchedPost, $riyaId) === false);
$check('Policy: Other user can like post', $policy->canLike($fetchedPost, $adminId) === true);
$check('Policy: Author cannot report own post', $policy->canReport($fetchedPost, $riyaId) === false);
$check('Policy: Other user can report post', $policy->canReport($fetchedPost, $adminId) === true);

// =========================================================================
// SUMMARY
// =========================================================================

$failures = $GLOBALS['failures'] ?? 0;
$checks   = $GLOBALS['checks']   ?? 0;

echo $section('TEST SUMMARY');
echo "  Total checks: {$checks}\n";
echo "  Failures:     {$failures}\n";

if ($failures > 0) {
    echo "\n  RESULT: FAIL ?\n\n";
    exit(1);
} else {
    echo "\n  RESULT: PASS ?\n\n";
    exit(0);
}
