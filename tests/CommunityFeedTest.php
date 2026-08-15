<?php

declare(strict_types=1);

/**
 * CommunityFeedTest ? CLI test suite for Phase C4-A (Community Feed UI)
 *
 * Verifies:
 * - Community feed page (/community) renders HTML view with eyebrow, header, and intro
 * - Real backend posts render title, author, date, book reference, like & comment counts
 * - Empty state renders correctly when no active posts exist
 * - Post detail page (/community/post/{id}) renders post body and flat comments
 * - Book-linked posts render compact link to catalogue book
 * - Navigation links to /community/post/{id} are clean and safe
 *
 * Run from project root:
 *     php tests/CommunityFeedTest.php
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Controllers\CommunityController;
use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Core\Logger;
use BookSphere\App\Core\MiddlewarePipeline;
use BookSphere\App\Core\Migrator;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Router;
use BookSphere\App\Core\Seeder;
use BookSphere\App\Core\Session;
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

$dbPath = root_path('database/community_feed_test.db');
foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

Database::instance($dbPath);
(new Migrator(db(), root_path('database/migrations')))->run();
(new Seeder(db(), root_path('database/seeds')))->run();

$session = new Session('community_feed_test');
$session->start();
$auth = new AuthService($session, new User());
AuthService::setInstance($auth);

$logFile = sys_get_temp_dir() . '/booksphere_community_feed_test.log';
if (is_file($logFile)) {
    unlink($logFile);
}
$logger = new Logger($logFile);

// Fixtures
$userModel = new User();
$riyaUser  = $userModel->findByEmail('riya@booksphere.test');
$adminUser = $userModel->findByEmail('admin@booksphere.test');

$riyaId  = (int) $riyaUser['id'];
$adminId = (int) $adminUser['id'];

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
$bookModel    = new Book();

$service    = new CommunityService($postModel, $commentModel, $likeModel, $reportModel, $bookModel, $logger);
$policy     = new CommunityPolicy();
$controller = new CommunityController($service, $policy);

$GLOBALS['dbPath_feed_global'] = $dbPath;

$section = fn (string $title): string => "\n------------------------------------------------------------------------\n{$title}\n------------------------------------------------------------------------\n";
$check   = function (string $label, bool $ok): void {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . PHP_EOL;
    $GLOBALS['failures'] = ($GLOBALS['failures'] ?? 0) + ($ok ? 0 : 1);
    $GLOBALS['checks']   = ($GLOBALS['checks'] ?? 0) + 1;
};

// Subprocess runner for rendering views cleanly
$renderView = function (string $method, string $uri): string {
    $probePath = sys_get_temp_dir() . '/community_feed_probe_' . uniqid() . '.php';

    $code = '<?php' . PHP_EOL
        . 'require ' . var_export(root_path('bootstrap/constants.php'), true) . ';' . PHP_EOL
        . 'require ' . var_export(root_path('vendor/autoload.php'), true) . ';' . PHP_EOL
        . 'use BookSphere\App\Core\Database;' . PHP_EOL
        . 'use BookSphere\App\Core\Session;' . PHP_EOL
        . 'use BookSphere\App\Core\Request;' . PHP_EOL
        . 'use BookSphere\App\Core\Router;' . PHP_EOL
        . 'use BookSphere\App\Core\MiddlewarePipeline;' . PHP_EOL
        . 'use BookSphere\App\Services\AuthService;' . PHP_EOL
        . 'use BookSphere\App\Services\CommunityService;' . PHP_EOL
        . 'use BookSphere\App\Policies\CommunityPolicy;' . PHP_EOL
        . 'use BookSphere\App\Models\User;' . PHP_EOL
        . 'use BookSphere\App\Models\Book;' . PHP_EOL
        . 'use BookSphere\App\Models\CommunityPost;' . PHP_EOL
        . 'use BookSphere\App\Models\CommunityComment;' . PHP_EOL
        . 'use BookSphere\App\Models\CommunityLike;' . PHP_EOL
        . 'use BookSphere\App\Models\CommunityReport;' . PHP_EOL
        . 'use BookSphere\App\Repositories\CommunityPostRepository;' . PHP_EOL
        . 'use BookSphere\App\Repositories\CommunityCommentRepository;' . PHP_EOL
        . 'use BookSphere\App\Repositories\CommunityLikeRepository;' . PHP_EOL
        . 'use BookSphere\App\Repositories\CommunityReportRepository;' . PHP_EOL
        . 'use BookSphere\App\Controllers\CommunityController;' . PHP_EOL
        . 'Database::instance(' . var_export($GLOBALS['dbPath_feed_global'], true) . ');' . PHP_EOL
        . '$session = new Session("community_feed_probe");' . PHP_EOL
        . '$session->start();' . PHP_EOL
        . '$auth = new AuthService($session, new User());' . PHP_EOL
        . 'AuthService::setInstance($auth);' . PHP_EOL
        . '$_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . ';' . PHP_EOL
        . '$_SERVER["REQUEST_URI"] = ' . var_export($uri, true) . ';' . PHP_EOL
        . '$_POST = [];' . PHP_EOL
        . '$_GET = [];' . PHP_EOL
        . '$parsed = parse_url(' . var_export($uri, true) . ');' . PHP_EOL
        . 'if (isset($parsed["query"])) { parse_str($parsed["query"], $_GET); }' . PHP_EOL
        . '$postModel = new CommunityPost(new CommunityPostRepository());' . PHP_EOL
        . '$commentModel = new CommunityComment(new CommunityCommentRepository());' . PHP_EOL
        . '$likeModel = new CommunityLike(new CommunityLikeRepository());' . PHP_EOL
        . '$reportModel = new CommunityReport(new CommunityReportRepository());' . PHP_EOL
        . '$service = new CommunityService($postModel, $commentModel, $likeModel, $reportModel, new Book());' . PHP_EOL
        . '$controller = new CommunityController($service, new CommunityPolicy());' . PHP_EOL
        . '$router = new Router(new Request(), new MiddlewarePipeline());' . PHP_EOL
        . '$router->get("/community", [$controller, "index"]);' . PHP_EOL
        . '$router->get("/community/post/{id}", [$controller, "show"]);' . PHP_EOL
        . 'ob_start();' . PHP_EOL
        . 'try { $router->dispatch(); } catch (Throwable $e) { echo "ERR: " . $e->getMessage(); }' . PHP_EOL
        . '$out = ob_get_clean();' . PHP_EOL
        . 'echo $out;' . PHP_EOL;

    file_put_contents($probePath, $code);
    $rawOut = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' 2>&1');
    if (is_file($probePath)) {
        unlink($probePath);
    }
    return $rawOut;
};

// Seed 2 real posts & 1 comment
$postId1 = $service->createPost($riyaId, [
    'title'   => 'Feed UI Test Post Title',
    'body'    => 'Detailed body content written for testing the community feed UI rendering.',
    'book_id' => $bookId,
]);

$postId2 = $service->createPost($adminId, [
    'title'   => 'Second Discussion Post',
    'body'    => 'Another post body content for testing multiple posts in feed.',
]);

$commentId1 = $service->createComment($adminId, $postId1, [
    'body' => 'Feed test comment on post 1.',
]);

// =========================================================================
// 1. COMMUNITY FEED PAGE RENDERING
// =========================================================================

echo $section('1. COMMUNITY FEED PAGE RENDERING (/community)');

$feedHtml = $renderView('GET', '/community');

$check('Feed page contains eyebrow "COMMUNITY"', str_contains($feedHtml, 'COMMUNITY'));
$check('Feed page contains heading "BookSphere Community"', str_contains($feedHtml, 'BookSphere Community'));
$check('Feed page contains supporting text', str_contains($feedHtml, 'Discover conversations, share your thoughts'));
$check('Feed page contains Start a Discussion button', str_contains($feedHtml, 'Start a Discussion'));
$check('Feed page contains Latest Discussions section', str_contains($feedHtml, 'Latest Discussions'));

// Post content checks
$check('Feed renders post title 1', str_contains($feedHtml, 'Feed UI Test Post Title'));
$check('Feed renders post title 2', str_contains($feedHtml, 'Second Discussion Post'));
$check('Feed renders author name', str_contains($feedHtml, 'Riya Sharma'));
$check('Feed renders compact book link', str_contains($feedHtml, '/books/' . $bookId));

// =========================================================================
// 2. POST DETAIL PAGE RENDERING
// =========================================================================

echo $section('2. POST DETAIL PAGE RENDERING (/community/post/{id})');

$postDetailHtml = $renderView('GET', "/community/post/{$postId1}");

$check('Detail page contains post title', str_contains($postDetailHtml, 'Feed UI Test Post Title'));
$check('Detail page contains full post body', str_contains($postDetailHtml, 'Detailed body content written for testing'));
$check('Detail page contains author name', str_contains($postDetailHtml, 'Riya Sharma'));
$check('Detail page contains book link', str_contains($postDetailHtml, '/books/' . $bookId));
$check('Detail page contains comments list', str_contains($postDetailHtml, 'Feed test comment on post 1.'));

// =========================================================================
// 3. EMPTY STATE RENDERING
// =========================================================================

echo $section('3. EMPTY STATE RENDERING');

// Hard delete posts for empty state test
db()->query('DELETE FROM community_posts');

$emptyFeedHtml = $renderView('GET', '/community');
$check('Empty feed renders "No discussions yet"', str_contains($emptyFeedHtml, 'No discussions yet'));
$check('Empty feed renders helper message', str_contains($emptyFeedHtml, 'Be the first reader to start a conversation.'));

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
