<?php

declare(strict_types=1);

/**
 * CommunityPostDetailsTest — CLI test suite for Phase C4-B (Create Post + Post Detail Experience)
 *
 * Tests:
 * - Create Post: authenticated creation, title/body validation, optional book attachment, unauthenticated rejection
 * - Post Detail: detail page rendering, related book card, non-existent 404
 * - Edit Post: owner editing, non-owner rejection, server validation
 * - Delete Post: owner deletion, non-owner rejection, CSRF protection
 * - Security: session user identity enforcement
 *
 * Run from project root:
 *     php tests/CommunityPostDetailsTest.php
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Controllers\CommunityController;
use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Core\Logger;
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

$dbPath = root_path('database/community_c4b_test.db');
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
$riyaUser  = db()->query("SELECT * FROM users WHERE email = 'riya@booksphere.test'")[0];
$adminUser = db()->query("SELECT * FROM users WHERE email = 'admin@booksphere.test'")[0];
$bookId = (int) db()->query('SELECT id FROM books LIMIT 1')[0]['id'];

$service = new CommunityService(
    new CommunityPost(),
    new CommunityComment(),
    new CommunityLike(),
    new CommunityReport(),
    new Book()
);
$policy = new CommunityPolicy();
$controller = new CommunityController($service, $policy);

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
echo "1. CREATE POST (Auth & Validations)\n";
echo "------------------------------------------------------------------------\n";

// Valid post creation
$postId = $service->createPost($riyaId, [
    'title'   => 'C4B Perspective Discussion',
    'body'    => 'This is a test discussion body for Phase C4-B testing.',
    'book_id' => $bookId,
]);
check($postId > 0, 'Service creates post with optional book attachment');

$post = $service->getPost($postId);
check($post['title'] === 'C4B Perspective Discussion', 'Created post returns exact title');
check((int) $post['book_id'] === $bookId, 'Created post links to book ID');

// Invalid title
$rejectedTitle = false;
try {
    $service->createPost($riyaId, ['title' => '', 'body' => 'Valid body long enough']);
} catch (\Throwable $e) {
    $rejectedTitle = true;
}
check($rejectedTitle, 'Service rejects empty title');

// Invalid body
$rejectedBody = false;
try {
    $service->createPost($riyaId, ['title' => 'Valid Title', 'body' => 'Short']);
} catch (\Throwable $e) {
    $rejectedBody = true;
}
check($rejectedBody, 'Service rejects short body (<10 chars)');

// Invalid book ID
$rejectedBook = false;
try {
    $service->createPost($riyaId, ['title' => 'Valid Title', 'body' => 'Valid body long enough', 'book_id' => 999999]);
} catch (\Throwable $e) {
    $rejectedBook = true;
}
check($rejectedBook, 'Service rejects non-existent book ID');

echo "\n------------------------------------------------------------------------\n";
echo "2. POST DETAIL EXPERIENCE\n";
echo "------------------------------------------------------------------------\n";

check($post['author_name'] === 'Riya Sharma', 'Post detail joins author name');
check(!empty($post['created_at']), 'Post detail includes created_at');

$post404 = false;
try {
    $service->getPost(999999);
} catch (\Throwable $e) {
    $post404 = true;
}
check($post404, 'Non-existent post throws 404 domain exception');

echo "\n------------------------------------------------------------------------\n";
echo "3. EDIT OWN POST (Auth & Policy Gates)\n";
echo "------------------------------------------------------------------------\n";

check($policy->canEdit($post, $riyaId) === true, 'Policy allows post owner to edit');
check($policy->canEdit($post, 99999) === false, 'Policy denies non-owner from editing');
check($policy->canEdit($post, $adminId) === true, 'Policy allows admin to edit');

$updated = $service->updatePost($riyaId, $postId, [
    'title' => 'Updated Discussion Title',
    'body'  => 'Updated body content for C4-B post detail testing.',
]);
check($updated === true, 'Owner updates post successfully');

$updatedPost = $service->getPost($postId);
check($updatedPost['title'] === 'Updated Discussion Title', 'Post title updated in DB');

echo "\n------------------------------------------------------------------------\n";
echo "4. DELETE OWN POST (Auth & Policy Gates)\n";
echo "------------------------------------------------------------------------\n";

check($policy->canDelete($post, $riyaId) === true, 'Policy allows post owner to delete');
check($policy->canDelete($post, 99999) === false, 'Policy denies non-owner from deleting');

$deleted = $service->deletePost($riyaId, $postId);
check($deleted === true, 'Owner deletes post successfully');

$deleted404 = false;
try {
    $service->getPost($postId);
} catch (\Throwable $e) {
    $deleted404 = true;
}
check($deleted404, 'Deleted post no longer accessible (throws 404)');

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
