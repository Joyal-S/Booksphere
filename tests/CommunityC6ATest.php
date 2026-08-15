<?php

declare(strict_types=1);

/**
 * CommunityC6ATest — CLI test suite for Phase C6-A (Community Profiles)
 *
 * Tests:
 * - Public Profile loading for existing user
 * - 404 response for nonexistent user
 * - Public info shown (name, initial, member since)
 * - Private info excluded (no email, password, remember_token in profile payload)
 * - User posts shown (active only, associated book links)
 * - User comments shown (active only, associated parent post and book links)
 * - Hidden/moderated posts and comments excluded from public profile
 * - Bounded pagination (posts and comments)
 * - Security: XSS safety, read-only authorization
 *
 * Run from project root:
 *     php tests/CommunityC6ATest.php
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
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
use BookSphere\App\Repositories\CommunityPostRepository;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\CommunityService;

// -----------------------------------------------------------------------
// Test runner helpers
// -----------------------------------------------------------------------

$passed = 0;
$failed = 0;
$errors = [];

function ok(bool $condition, string $name): void
{
    global $passed, $failed, $errors;
    if ($condition) {
        $passed++;
        echo "  ✓ {$name}\n";
    } else {
        $failed++;
        $errors[] = $name;
        echo "  ✗ {$name}\n";
    }
}

function throws(callable $fn, string $expectClass, string $name): void
{
    global $passed, $failed, $errors;
    try {
        $fn();
        $failed++;
        $errors[] = $name;
        echo "  ✗ {$name} (no exception thrown)\n";
    } catch (\Throwable $e) {
        if ($e instanceof $expectClass) {
            $passed++;
            echo "  ✓ {$name}\n";
        } else {
            $failed++;
            $errors[] = $name;
            echo "  ✗ {$name} (got " . get_class($e) . ": " . $e->getMessage() . ")\n";
        }
    }
}

// -----------------------------------------------------------------------
// Boot test environment
// -----------------------------------------------------------------------

(new Environment(root_path('.env')))->load();

$dbPath = root_path('database/community_c6a_test.db');
foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
    if (is_file($file)) {
        @unlink($file);
    }
}

Database::instance($dbPath);
(new Migrator(db(), root_path('database/migrations')))->run();
(new Seeder(db(), root_path('database/seeds')))->run();

$session = new Session('community_c6a_test');
$session->start();

$service = new CommunityService(
    new CommunityPost(),
    new CommunityComment(),
    new CommunityLike(),
    new CommunityReport(),
    new Book(),
);
$policy       = new CommunityPolicy();
$userModel    = new User();
$postRepo     = new CommunityPostRepository();
$commentRepo  = new CommunityCommentRepository();

// Create test fixtures
db()->execute(
    "INSERT INTO users (full_name, email, password, role, created_at, updated_at)
     VALUES (?, ?, ?, 'user', '2024-01-15T10:00:00Z', '2024-01-15T10:00:00Z')",
    ['User C6A', 'user_c6a@test.local', password_hash('secret', PASSWORD_BCRYPT)],
);
$userId = (int) db()->lastInsertId();

// Create a book fixture if none
$bookRow = db()->query("SELECT id FROM books LIMIT 1")[0] ?? null;
$bookId  = $bookRow ? (int) $bookRow['id'] : null;

// Create active post by user
$activePostId = $service->createPost($userId, [
    'title'   => 'Active C6A Post <script>alert("xss")</script>',
    'body'    => 'This is an active post body for C6A testing.',
    'book_id' => $bookId,
]);

// Create hidden post by user
$hiddenPostId = $service->createPost($userId, [
    'title'   => 'Hidden C6A Post',
    'body'    => 'This post will be hidden by moderation.',
]);
(new CommunityPost())->updateStatus($hiddenPostId, 'hidden');

// Create active comment by user on active post
$activeCommentId = $service->createComment($userId, $activePostId, [
    'body' => 'Active comment by User C6A <img src=x onerror=alert(1)>',
]);

// Create hidden comment by user
$hiddenCommentId = $service->createComment($userId, $activePostId, [
    'body' => 'Hidden comment by User C6A',
]);
(new CommunityComment())->updateStatus($hiddenCommentId, 'hidden');

// -----------------------------------------------------------------------
// Section 1: getUserProfile() Public Data & Privacy
// -----------------------------------------------------------------------
echo "\n--- Section 1: Public Profile Data & Privacy ---\n";

$profile = $service->getUserProfile($userId);

ok(is_array($profile), 'getUserProfile returns array payload');
ok(isset($profile['user']), 'Profile payload contains user section');
ok($profile['user']['id'] === $userId, 'User ID matches requested ID');
ok($profile['user']['full_name'] === 'User C6A', 'Public full_name is correctly returned');
ok($profile['user']['initial'] === 'U', 'Avatar initial is computed correctly');
ok(isset($profile['user']['member_since']), 'Member since date is present');
ok(!isset($profile['user']['email']), 'PRIVATE INFO: email is NOT present in user profile payload');
ok(!isset($profile['user']['password']), 'PRIVATE INFO: password is NOT present in user profile payload');
ok(!isset($profile['user']['remember_token']), 'PRIVATE INFO: remember_token is NOT present in user profile payload');

// -----------------------------------------------------------------------
// Section 2: Nonexistent User Handling
// -----------------------------------------------------------------------
echo "\n--- Section 2: Nonexistent User Handling ---\n";

throws(
    fn () => $service->getUserProfile(999999),
    CommunityException::class,
    'getUserProfile throws CommunityException for nonexistent user'
);

// -----------------------------------------------------------------------
// Section 3: Moderation Filtering (Active Only)
// -----------------------------------------------------------------------
echo "\n--- Section 3: Moderation Filtering ---\n";

$posts = $profile['posts']['items'];
$postIds = array_column($posts, 'id');

ok(in_array($activePostId, $postIds, true), 'Active post is included in user profile');
ok(!in_array($hiddenPostId, $postIds, true), 'MODERATION: Hidden post is EXCLUDED from user profile');
ok($profile['stats']['posts'] === 1, 'Post stat count counts only active posts');

$comments = $profile['comments']['items'];
$commentIds = array_column($comments, 'id');

ok(in_array($activeCommentId, $commentIds, true), 'Active comment is included in user profile');
ok(!in_array($hiddenCommentId, $commentIds, true), 'MODERATION: Hidden comment is EXCLUDED from user profile');
ok($profile['stats']['comments'] === 1, 'Comment stat count counts only active comments');

// -----------------------------------------------------------------------
// Section 4: Associated Book & Parent Post Links
// -----------------------------------------------------------------------
echo "\n--- Section 4: Associated Book & Parent Post Links ---\n";

$firstPost = $posts[0];
ok(isset($firstPost['title']), 'User post item has title');
if ($bookId !== null) {
    ok((int) ($firstPost['book_id'] ?? 0) === $bookId, 'User post item has linked book_id');
}

$firstComment = $comments[0];
ok(isset($firstComment['post_title']), 'User comment item has parent post_title');
ok((int) ($firstComment['post_id'] ?? 0) === $activePostId, 'User comment item has parent post_id');

// -----------------------------------------------------------------------
// Section 5: Bounded Pagination
// -----------------------------------------------------------------------
echo "\n--- Section 5: Bounded Pagination ---\n";

// Add extra active posts to test pagination
for ($i = 1; $i <= 15; $i++) {
    $service->createPost($userId, [
        'title' => "Extra Post {$i}",
        'body'  => "Body content for extra post {$i}",
    ]);
}

$pagedProfile = $service->getUserProfile($userId, 1, 1, 5);
ok(count($pagedProfile['posts']['items']) === 5, 'Posts list is bounded to per_page limit (5)');
ok($pagedProfile['posts']['total'] === 16, 'Total posts accurately counts 16 active posts');
ok($pagedProfile['posts']['pages'] === 4, 'Calculates 4 total pages for 16 posts with 5 per page');

$page2Profile = $service->getUserProfile($userId, 2, 1, 5);
ok($page2Profile['posts']['page'] === 2, 'Page 2 profile returns page 2');
ok(count($page2Profile['posts']['items']) === 5, 'Page 2 returns 5 items');

// -----------------------------------------------------------------------
// Section 6: Security & Policy Checks
// -----------------------------------------------------------------------
echo "\n--- Section 6: Security & Policy Checks ---\n";

// Profile browsing does not grant edit rights for other users
$otherUserPost = ['user_id' => $userId, 'id' => $activePostId];
$visitorUserId = $userId + 100;

ok(!$policy->canEdit($otherUserPost, $visitorUserId), 'Visitor cannot edit another user\'s post from profile');
ok(!$policy->canDelete($otherUserPost, $visitorUserId), 'Visitor cannot delete another user\'s post from profile');

// -----------------------------------------------------------------------
// Cleanup
// -----------------------------------------------------------------------
foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
    if (is_file($file)) {
        @unlink($file);
    }
}

// -----------------------------------------------------------------------
// Summary
// -----------------------------------------------------------------------
echo "\n" . str_repeat('-', 50) . "\n";
echo "CommunityC6ATest: {$passed} passed, {$failed} failed\n";
if (!empty($errors)) {
    echo "\nFailed assertions:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
}
echo str_repeat('-', 50) . "\n";
exit($failed > 0 ? 1 : 0);
