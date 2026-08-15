<?php

declare(strict_types=1);

/**
 * CommunityHttpTest ? CLI test suite for Phase C3-B (Community HTTP Layer)
 *
 * Tests the complete HTTP surface for the Community module:
 * - Public routes: /community, /community/post/{id}, /community/posts/{id}/comments, /community/book/{id}, /community/user/{id}
 * - Auth & CSRF protections
 * - Post CRUD HTTP endpoints & validations
 * - Comment CRUD HTTP endpoints & validations
 * - Like & Unlike HTTP endpoints & idempotence
 * - Report HTTP endpoints & validation
 * - Security & IDOR prevention
 *
 * Run from project root:
 *     php tests/CommunityHttpTest.php
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Controllers\CommunityController;
use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Core\Logger;
use BookSphere\App\Core\Migrator;
use BookSphere\App\Core\Seeder;

// Boot test environment
(new Environment(root_path('.env')))->load();

$dbPath = root_path('database/community_http_test.db');
foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

Database::instance($dbPath);
(new Migrator(db(), root_path('database/migrations')))->run();
(new Seeder(db(), root_path('database/seeds')))->run();

$logFile = sys_get_temp_dir() . '/booksphere_community_http_test.log';
if (is_file($logFile)) {
    unlink($logFile);
}

// Fixtures
$riyaId  = (int) db()->query("SELECT id FROM users WHERE email = 'riya@booksphere.test'")[0]['id'];
$adminId = (int) db()->query("SELECT id FROM users WHERE email = 'admin@booksphere.test'")[0]['id'];

$riyaUser  = db()->query("SELECT * FROM users WHERE email = 'riya@booksphere.test'")[0];
$adminUser = db()->query("SELECT * FROM users WHERE email = 'admin@booksphere.test'")[0];
$nonOwnerUser = ['id' => 99999, 'full_name' => 'Non Owner', 'role' => 'user'];

$bookIdRow = db()->query('SELECT id FROM books LIMIT 1');
$bookId    = (int) ($bookIdRow[0]['id'] ?? 1);

$GLOBALS['dbPath_global'] = $dbPath;

$section = fn (string $title): string => "\n------------------------------------------------------------------------\n{$title}\n------------------------------------------------------------------------\n";
$check   = function (string $label, bool $ok): void {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . PHP_EOL;
    $GLOBALS['failures'] = ($GLOBALS['failures'] ?? 0) + ($ok ? 0 : 1);
    $GLOBALS['checks']   = ($GLOBALS['checks'] ?? 0) + 1;
};

// Subprocess execution runner: each call runs isolated with its own headers, response codes and session.
$runHttp = function (string $method, string $uri, array $postData = [], ?array $sessionUser = null): array {
    $probePath = sys_get_temp_dir() . '/community_http_call_' . uniqid() . '.php';

    $userCode = $sessionUser !== null
        ? '$session->put("auth_user_id", ' . (int) $sessionUser['id'] . '); $session->put("auth_user", ' . var_export($sessionUser, true) . ');'
        : '';

    $tokenHandling = '';
    if (isset($postData['_token']) && $postData['_token'] === 'VALID_TOKEN') {
        $tokenHandling = '$postData["_token"] = $csrf->token();';
        unset($postData['_token']);
    }

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
        . 'use BookSphere\App\Middleware\AuthMiddleware;' . PHP_EOL
        . 'use BookSphere\App\Middleware\CsrfMiddleware;' . PHP_EOL
        . 'use BookSphere\App\Core\Csrf;' . PHP_EOL
        . 'Database::instance(' . var_export($GLOBALS['dbPath_global'], true) . ');' . PHP_EOL
        . '$session = new Session("community_http_call");' . PHP_EOL
        . '$session->start();' . PHP_EOL
        . '$auth = new AuthService($session, new User());' . PHP_EOL
        . 'AuthService::setInstance($auth);' . PHP_EOL
        . $userCode . PHP_EOL
        . '$csrf = new Csrf($session);' . PHP_EOL
        . '$postData = ' . var_export($postData, true) . ';' . PHP_EOL
        . $tokenHandling . PHP_EOL
        . '$_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . ';' . PHP_EOL
        . '$_SERVER["REQUEST_URI"] = ' . var_export($uri, true) . ';' . PHP_EOL
        . '$_SERVER["HTTP_ACCEPT"] = "application/json";' . PHP_EOL
        . '$_POST = $postData;' . PHP_EOL
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
        . '$authMw = new AuthMiddleware($auth);' . PHP_EOL
        . '$csrfMw = new CsrfMiddleware($csrf);' . PHP_EOL
        . '$router->get("/community", [$controller, "index"]);' . PHP_EOL
        . '$router->get("/community/post/{id}", [$controller, "show"]);' . PHP_EOL
        . '$router->get("/community/posts/{id}/comments", [$controller, "comments"]);' . PHP_EOL
        . '$router->get("/community/book/{id}", [$controller, "bookPosts"]);' . PHP_EOL
        . '$router->get("/community/user/{id}", [$controller, "userPosts"]);' . PHP_EOL
        . '$router->post("/community/posts", [$controller, "storePost"], [$authMw, $csrfMw]);' . PHP_EOL
        . '$router->patch("/community/posts/{id}", [$controller, "updatePost"], [$authMw, $csrfMw]);' . PHP_EOL
        . '$router->delete("/community/posts/{id}", [$controller, "destroyPost"], [$authMw, $csrfMw]);' . PHP_EOL
        . '$router->post("/community/posts/{id}/comments", [$controller, "storeComment"], [$authMw, $csrfMw]);' . PHP_EOL
        . '$router->patch("/community/comments/{id}", [$controller, "updateComment"], [$authMw, $csrfMw]);' . PHP_EOL
        . '$router->delete("/community/comments/{id}", [$controller, "destroyComment"], [$authMw, $csrfMw]);' . PHP_EOL
        . '$router->post("/community/posts/{id}/like", [$controller, "like"], [$authMw, $csrfMw]);' . PHP_EOL
        . '$router->delete("/community/posts/{id}/like", [$controller, "unlike"], [$authMw, $csrfMw]);' . PHP_EOL
        . '$router->post("/community/posts/{id}/report", [$controller, "reportPost"], [$authMw, $csrfMw]);' . PHP_EOL
        . '$router->post("/community/comments/{id}/report", [$controller, "reportComment"], [$authMw, $csrfMw]);' . PHP_EOL
        . 'register_shutdown_function(function() use ($session) {' . PHP_EOL
        . '    $code = http_response_code() ?: 200;' . PHP_EOL
        . '    $flash = $session->getFlash("error");' . PHP_EOL
        . '    $out = ob_get_contents();' . PHP_EOL
        . '    if ($flash !== null) { $out = "FLASH_ERROR: " . $flash; }' . PHP_EOL
        . '    echo "PROBE_RESULT:" . json_encode(["status" => $code, "out" => $out]);' . PHP_EOL
        . '});' . PHP_EOL
        . 'ob_start();' . PHP_EOL
        . 'try { $router->dispatch(); } catch (Throwable $e) {}' . PHP_EOL;

    file_put_contents($probePath, $code);
    $rawOut = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' 2>&1');
    if (is_file($probePath)) {
        unlink($probePath);
    }

    if (str_contains($rawOut, 'PROBE_RESULT:')) {
        $jsonStr = strstr($rawOut, 'PROBE_RESULT:');
        $jsonStr = str_replace('PROBE_RESULT:', '', $jsonStr);
        $decoded = json_decode($jsonStr, true);
        if (is_array($decoded) && isset($decoded['status'])) {
            $body = json_decode((string) $decoded['out'], true);
            return [
                'status' => (int) $decoded['status'],
                'body'   => is_array($body) ? $body : $decoded['out'],
            ];
        }
    }

    return ['status' => 500, 'body' => $rawOut];
};

// Pre-create initial test post & comment using initial probe setup
$initPostRes = $runHttp('POST', '/community/posts', [
    '_token'  => 'VALID_TOKEN',
    'title'   => 'Initial HTTP Test Post',
    'body'    => 'Body content long enough for initial HTTP post test.',
    'book_id' => $bookId,
], $riyaUser);
$postId1 = (int) ($initPostRes['body']['id'] ?? 1);

$initCommentRes = $runHttp('POST', "/community/posts/{$postId1}/comments", [
    '_token' => 'VALID_TOKEN',
    'body'   => 'Initial comment for HTTP test.',
], $adminUser);
$commentId1 = (int) ($initCommentRes['body']['id'] ?? 1);

// =========================================================================
// 1. PUBLIC ACCESS ENDPOINTS
// =========================================================================

echo $section('1. PUBLIC ACCESS ENDPOINTS');

$feedRes = $runHttp('GET', '/community');
$check('GET /community returns 200 JSON', $feedRes['status'] === 200 && is_array($feedRes['body']) && isset($feedRes['body']['items']));

$showRes = $runHttp('GET', "/community/post/{$postId1}");
$check('GET /community/post/{id} returns 200 JSON with post detail', $showRes['status'] === 200 && $showRes['body']['title'] === 'Initial HTTP Test Post');

$commentsRes = $runHttp('GET', "/community/posts/{$postId1}/comments");
$check('GET /community/posts/{id}/comments returns 200 JSON with comments', $commentsRes['status'] === 200 && is_array($commentsRes['body']['items']));

$bookPostsRes = $runHttp('GET', "/community/book/{$bookId}");
$check('GET /community/book/{id} returns 200 JSON with book posts', $bookPostsRes['status'] === 200 && is_array($bookPostsRes['body']['items']));

$userPostsRes = $runHttp('GET', "/community/user/{$riyaId}");
$check('GET /community/user/{id} returns 200 JSON with user posts', $userPostsRes['status'] === 200 && is_array($userPostsRes['body']['items']));

// =========================================================================
// 2. AUTHENTICATION & CSRF SECURITY
// =========================================================================

echo $section('2. AUTHENTICATION & CSRF SECURITY');

$unauthRes = $runHttp('POST', '/community/posts', ['_token' => 'VALID_TOKEN', 'title' => 'Test', 'body' => 'Content']);
$check('Unauthenticated POST /community/posts is rejected with redirect flash', str_contains((string) $unauthRes['body'], 'Please log in to continue.'));

$csrfFailRes = $runHttp('POST', '/community/posts', ['_token' => 'invalid_csrf_token', 'title' => 'Test', 'body' => 'Valid body content'], $riyaUser);
$check('Authenticated POST with invalid CSRF token returns 419 error', $csrfFailRes['status'] === 419);

// =========================================================================
// 3. POST ENDPOINTS (CRUD & VALIDATION)
// =========================================================================

echo $section('3. POST ENDPOINTS (Create, Retrieve, Edit, Delete)');

// Create valid post
$createPostRes = $runHttp('POST', '/community/posts', [
    '_token'  => 'VALID_TOKEN',
    'title'   => 'HTTP Created Post Title',
    'body'    => 'Valid body content long enough for HTTP test post.',
    'book_id' => $bookId,
], $riyaUser);
$check('POST /community/posts creates post with 201 response', $createPostRes['status'] === 201 && isset($createPostRes['body']['id']));
$newPostId = (int) ($createPostRes['body']['id'] ?? 0);

// Reject invalid post title
$invalidTitleRes = $runHttp('POST', '/community/posts', [
    '_token' => 'VALID_TOKEN',
    'title'  => '',
    'body'   => 'Valid body content long enough.',
], $riyaUser);
$check('POST /community/posts rejects empty title with 422', $invalidTitleRes['status'] === 422);

// Reject invalid book_id
$invalidBookRes = $runHttp('POST', '/community/posts', [
    '_token'  => 'VALID_TOKEN',
    'title'   => 'Valid Title',
    'body'    => 'Valid body content long enough.',
    'book_id' => 999999,
], $riyaUser);
$check('POST /community/posts rejects non-existent book_id with 404', $invalidBookRes['status'] === 404);

// Edit own post
$editPostRes = $runHttp('PATCH', "/community/posts/{$newPostId}", [
    '_token' => 'VALID_TOKEN',
    'title'  => 'HTTP Updated Title',
    'body'   => 'Updated body text long enough for testing.',
], $riyaUser);
$check('PATCH /community/posts/{id} updates own post', $editPostRes['status'] === 200 && $editPostRes['body']['success'] === true);

// Reject editing another user's post
$forbiddenEditRes = $runHttp('PATCH', "/community/posts/{$newPostId}", [
    '_token' => 'VALID_TOKEN',
    'title'  => 'Hacked Title',
    'body'   => 'Hacked body text long enough.',
], $nonOwnerUser);
$check('PATCH /community/posts/{id} rejects editing another user\'s post with 403', $forbiddenEditRes['status'] === 403);

// Delete own post
$deletePostRes = $runHttp('DELETE', "/community/posts/{$newPostId}", [
    '_token' => 'VALID_TOKEN',
], $riyaUser);
$check('DELETE /community/posts/{id} deletes own post', $deletePostRes['status'] === 200 && $deletePostRes['body']['success'] === true);

// =========================================================================
// 4. COMMENT ENDPOINTS (CRUD & VALIDATION)
// =========================================================================

echo $section('4. COMMENT ENDPOINTS (Create, Edit, Delete)');

// Create valid comment
$createCommentRes = $runHttp('POST', "/community/posts/{$postId1}/comments", [
    '_token' => 'VALID_TOKEN',
    'body'   => 'HTTP test comment on post 1.',
], $riyaUser);
$check('POST /community/posts/{id}/comments creates comment with 201 response', $createCommentRes['status'] === 201 && isset($createCommentRes['body']['id']));
$newCommentId = (int) ($createCommentRes['body']['id'] ?? 0);

// Reject empty comment
$invalidCommentRes = $runHttp('POST', "/community/posts/{$postId1}/comments", [
    '_token' => 'VALID_TOKEN',
    'body'   => '   ',
], $riyaUser);
$check('POST /community/posts/{id}/comments rejects empty comment with 422', $invalidCommentRes['status'] === 422);

// Edit own comment
$editCommentRes = $runHttp('PATCH', "/community/comments/{$newCommentId}", [
    '_token' => 'VALID_TOKEN',
    'body'   => 'Updated HTTP comment body.',
], $riyaUser);
$check('PATCH /community/comments/{id} updates own comment', $editCommentRes['status'] === 200 && $editCommentRes['body']['success'] === true);

// Delete own comment
$deleteCommentRes = $runHttp('DELETE', "/community/comments/{$newCommentId}", [
    '_token' => 'VALID_TOKEN',
], $riyaUser);
$check('DELETE /community/comments/{id} deletes own comment', $deleteCommentRes['status'] === 200 && $deleteCommentRes['body']['success'] === true);

// =========================================================================
// 5. LIKE ENDPOINTS (Like, Unlike, Count)
// =========================================================================

echo $section('5. LIKE ENDPOINTS (Like, Idempotence, Unlike)');

// Riya tries to like own post -> 403
$selfLikeRes = $runHttp('POST', "/community/posts/{$postId1}/like", [
    '_token' => 'VALID_TOKEN',
], $riyaUser);
$check('POST /community/posts/{id}/like rejects author liking own post with 403', $selfLikeRes['status'] === 403);

// Admin likes Riya's post -> 200
$likeRes = $runHttp('POST', "/community/posts/{$postId1}/like", [
    '_token' => 'VALID_TOKEN',
], $adminUser);
$check('POST /community/posts/{id}/like succeeds for other user', $likeRes['status'] === 200 && $likeRes['body']['liked'] === true);

// Repeat like -> idempotent 200
$dupLikeRes = $runHttp('POST', "/community/posts/{$postId1}/like", [
    '_token' => 'VALID_TOKEN',
], $adminUser);
$check('Duplicate POST /community/posts/{id}/like is idempotent', $dupLikeRes['status'] === 200 && $dupLikeRes['body']['liked'] === true);

// Unlike post -> 200
$unlikeRes = $runHttp('DELETE', "/community/posts/{$postId1}/like", [
    '_token' => 'VALID_TOKEN',
], $adminUser);
$check('DELETE /community/posts/{id}/like unlikes post', $unlikeRes['status'] === 200 && $unlikeRes['body']['liked'] === false);

// =========================================================================
// 6. REPORT ENDPOINTS (Post & Comment Reporting)
// =========================================================================

echo $section('6. REPORT ENDPOINTS (Report Post & Comment)');

// Admin reports Riya's post
$reportPostRes = $runHttp('POST', "/community/posts/{$postId1}/report", [
    '_token'      => 'VALID_TOKEN',
    'reason'      => 'Spam',
    'description' => 'Reporting post as spam test.',
], $adminUser);
$check('POST /community/posts/{id}/report creates post report with 201 response', $reportPostRes['status'] === 201 && isset($reportPostRes['body']['id']));

// Reject invalid report reason
$invalidReasonRes = $runHttp('POST', "/community/posts/{$postId1}/report", [
    '_token' => 'VALID_TOKEN',
    'reason' => 'InvalidReasonEnum',
], $adminUser);
$check('POST /community/posts/{id}/report rejects invalid reason with 422', $invalidReasonRes['status'] === 422);

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
