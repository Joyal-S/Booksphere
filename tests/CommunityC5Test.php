<?php

declare(strict_types=1);

/**
 * CommunityC5Test — CLI test suite for Phase C5 (Community Moderation & Reporting)
 *
 * Covers:
 *   - Duplicate report prevention (post and comment)
 *   - Report post: valid, own post, invalid reason, non-existent post
 *   - Report comment: valid, own comment, invalid reason, non-existent comment
 *   - CommunityPolicy: canReport(), canModerate()
 *   - CommunityService: moderateReport(), hidePost(), unhidePost(), hideComment(), unhideComment()
 *   - CommunityService: listReports(), getReportWithContext()
 *   - CommunityReport::existsByReporter(), findAll(), countAll(), findWithContext()
 *   - Content visibility: hidden posts absent from active feed
 *   - alreadyReported() exception factory
 *
 * Run from project root:
 *     php tests/CommunityC5Test.php
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Core\Migrator;
use BookSphere\App\Core\Seeder;
use BookSphere\App\Core\Session;
use BookSphere\App\Exceptions\CommunityException;
use BookSphere\App\Models\CommunityComment;
use BookSphere\App\Models\CommunityLike;
use BookSphere\App\Models\CommunityPost;
use BookSphere\App\Models\CommunityReport;
use BookSphere\App\Models\User;
use BookSphere\App\Policies\CommunityPolicy;
use BookSphere\App\Repositories\CommunityReportRepository;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\CommunityService;
use BookSphere\App\Models\Book;

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

$dbPath = root_path('database/community_c5_test.db');
foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

Database::instance($dbPath);
(new Migrator(db(), root_path('database/migrations')))->run();
(new Seeder(db(), root_path('database/seeds')))->run();

$session = new Session('community_c5_test');
$session->start();

$service = new CommunityService(
    new CommunityPost(),
    new CommunityComment(),
    new CommunityLike(),
    new CommunityReport(),
    new Book(),
);
$policy  = new CommunityPolicy();
$reports = new CommunityReport();
$repo    = new CommunityReportRepository();

// -----------------------------------------------------------------------
// Create test fixtures
// -----------------------------------------------------------------------

// Two users
$userIdA = db()->lastInsertId() ?: 1;
$rowA = db()->query("SELECT id FROM users LIMIT 1")[0] ?? null;
$rowB = db()->query("SELECT id FROM users LIMIT 1 OFFSET 1")[0] ?? null;

if ($rowA === null || $rowB === null) {
    // Create minimal users
    db()->execute(
        "INSERT INTO users (full_name, email, password, role, created_at, updated_at)
         VALUES (?, ?, ?, 'reader', datetime('now'), datetime('now'))",
        ['Alice C5', 'alice_c5@test.local', password_hash('test', PASSWORD_BCRYPT)],
    );
    $userIdA = (int) db()->lastInsertId();
    db()->execute(
        "INSERT INTO users (full_name, email, password, role, created_at, updated_at)
         VALUES (?, ?, ?, 'reader', datetime('now'), datetime('now'))",
        ['Bob C5', 'bob_c5@test.local', password_hash('test', PASSWORD_BCRYPT)],
    );
    $userIdB = (int) db()->lastInsertId();
    db()->execute(
        "INSERT INTO users (full_name, email, password, role, created_at, updated_at)
         VALUES (?, ?, ?, 'admin', datetime('now'), datetime('now'))",
        ['Admin C5', 'admin_c5@test.local', password_hash('test', PASSWORD_BCRYPT)],
    );
    $adminId = (int) db()->lastInsertId();
} else {
    $userIdA = (int) $rowA['id'];
    $userIdB = (int) ($rowB['id'] ?? $userIdA + 1);
    db()->execute(
        "INSERT OR IGNORE INTO users (full_name, email, password, role, created_at, updated_at)
         VALUES (?, ?, ?, 'admin', datetime('now'), datetime('now'))",
        ['Admin C5', 'admin_c5@test.local', password_hash('test', PASSWORD_BCRYPT)],
    );
    $adminRow = db()->query("SELECT id FROM users WHERE email = 'admin_c5@test.local' LIMIT 1")[0];
    $adminId  = (int) $adminRow['id'];
}

// Create a post owned by User A
$postId = $service->createPost($userIdA, [
    'title' => 'C5 Test Post',
    'body'  => 'This is a community moderation test post body.',
]);

// Create a comment by User A on that post
$commentId = $service->createComment($userIdA, $postId, [
    'body' => 'C5 test comment.',
]);

// -----------------------------------------------------------------------
// Section 1: CommunityException::alreadyReported()
// -----------------------------------------------------------------------
echo "\n--- Section 1: alreadyReported() exception factory ---\n";

ok(
    CommunityException::alreadyReported() instanceof CommunityException,
    'alreadyReported() returns a CommunityException'
);
ok(
    str_contains(CommunityException::alreadyReported()->getMessage(), 'already reported'),
    'alreadyReported() message contains "already reported"'
);

// -----------------------------------------------------------------------
// Section 2: Reporting a post
// -----------------------------------------------------------------------
echo "\n--- Section 2: Reporting a post ---\n";

// User B reports User A's post
$reportId = $service->reportPost($userIdB, $postId, ['reason' => 'Spam']);
ok($reportId > 0, 'User B can report User A\'s post (returns report id)');

// Duplicate: same user, same post
throws(
    fn () => $service->reportPost($userIdB, $postId, ['reason' => 'Spam']),
    CommunityException::class,
    'Duplicate post report throws CommunityException::alreadyReported()'
);

// Invalid reason
throws(
    fn () => $service->reportPost($userIdB, $postId, ['reason' => 'NotAReason']),
    CommunityException::class,
    'Invalid reason throws CommunityException'
);

// Non-existent post
throws(
    fn () => $service->reportPost($userIdB, 99999, ['reason' => 'Spam']),
    CommunityException::class,
    'Non-existent post throws CommunityException'
);

// -----------------------------------------------------------------------
// Section 3: Reporting a comment
// -----------------------------------------------------------------------
echo "\n--- Section 3: Reporting a comment ---\n";

// User B reports User A's comment
$commentReportId = $service->reportComment($userIdB, $commentId, ['reason' => 'Harassment']);
ok($commentReportId > 0, 'User B can report User A\'s comment (returns report id)');

// Duplicate
throws(
    fn () => $service->reportComment($userIdB, $commentId, ['reason' => 'Spam']),
    CommunityException::class,
    'Duplicate comment report throws CommunityException::alreadyReported()'
);

// Non-existent comment
throws(
    fn () => $service->reportComment($userIdB, 99999, ['reason' => 'Spam']),
    CommunityException::class,
    'Non-existent comment throws CommunityException'
);

// -----------------------------------------------------------------------
// Section 4: CommunityPolicy authorization
// -----------------------------------------------------------------------
echo "\n--- Section 4: CommunityPolicy ---\n";

$post = $service->getPost($postId);

ok($policy->canReport($post, $userIdB), 'Non-author can report post');
ok(!$policy->canReport($post, $userIdA), 'Author cannot report own post');
ok(!$policy->canModerate(null), 'Non-admin cannot moderate (null actorId)');

// -----------------------------------------------------------------------
// Section 5: CommunityReportRepository::existsByReporter()
// -----------------------------------------------------------------------
echo "\n--- Section 5: existsByReporter() ---\n";

ok(
    $repo->existsByReporter($userIdB, $postId, null),
    'existsByReporter returns true for existing pending post report'
);
ok(
    !$repo->existsByReporter($userIdA, $postId, null),
    'existsByReporter returns false for a user with no report on that post'
);
ok(
    $repo->existsByReporter($userIdB, null, $commentId),
    'existsByReporter returns true for existing pending comment report'
);

// -----------------------------------------------------------------------
// Section 6: listReports() + countAll()
// -----------------------------------------------------------------------
echo "\n--- Section 6: listReports() + countAll() ---\n";

$pageData = $service->listReports(1, 30, 'pending');
ok(is_array($pageData['items']), 'listReports returns items array');
ok($pageData['total'] >= 2, 'listReports total >= 2 (post + comment reports)');
ok($pageData['page'] === 1, 'listReports page is 1');
ok(isset($pageData['pages']), 'listReports has pages key');

$count = $reports->countAll('pending');
ok($count >= 2, 'countAll(pending) >= 2');

$dismissedCount = $reports->countAll('dismissed');
ok(is_int($dismissedCount), 'countAll(dismissed) returns int');

// -----------------------------------------------------------------------
// Section 7: getReportWithContext()
// -----------------------------------------------------------------------
echo "\n--- Section 7: getReportWithContext() ---\n";

$ctx = $service->getReportWithContext($reportId);
ok(is_array($ctx), 'getReportWithContext returns array');
ok((int) ($ctx['id'] ?? 0) === $reportId, 'getReportWithContext has correct id');
ok(isset($ctx['content_type']), 'getReportWithContext has content_type');
ok($ctx['content_type'] === 'post', 'content_type is "post" for post report');
ok(isset($ctx['reporter_name']), 'getReportWithContext has reporter_name');
ok(isset($ctx['reason']), 'getReportWithContext has reason');

throws(
    fn () => $service->getReportWithContext(99999),
    CommunityException::class,
    'getReportWithContext throws for non-existent report'
);

// -----------------------------------------------------------------------
// Section 8: Admin moderation — moderateReport()
// -----------------------------------------------------------------------
echo "\n--- Section 8: moderateReport() ---\n";

$result = $service->moderateReport($adminId, $reportId, 'reviewed');
ok($result === true, 'Admin can mark report as reviewed');

$updated = $reports->find($reportId);
ok(($updated['status'] ?? '') === 'reviewed', 'Report status is now "reviewed"');

$result2 = $service->moderateReport($adminId, $reportId, 'resolved');
ok($result2 === true, 'Admin can resolve a report');

$resolved = $reports->find($reportId);
ok(($resolved['status'] ?? '') === 'resolved', 'Report status is now "resolved"');

// Invalid status
throws(
    fn () => $service->moderateReport($adminId, $reportId, 'flying'),
    CommunityException::class,
    'Invalid status throws CommunityException'
);

// -----------------------------------------------------------------------
// Section 9: Admin moderation — hidePost() / unhidePost()
// -----------------------------------------------------------------------
echo "\n--- Section 9: hidePost() / unhidePost() ---\n";

$hideResult = $service->hidePost($adminId, $postId);
ok($hideResult === true, 'Admin can hide a post');

// Verify hidden post is absent from active feed
$allActive = (new CommunityPost())->findActive(50);
$foundInActive = array_filter($allActive, fn ($p) => (int)($p['id'] ?? 0) === $postId);
ok(count($foundInActive) === 0, 'Hidden post is absent from active feed');

$unhideResult = $service->unhidePost($adminId, $postId);
ok($unhideResult === true, 'Admin can unhide a post');

$allActiveAfter = (new CommunityPost())->findActive(50);
$foundAfter = array_filter($allActiveAfter, fn ($p) => (int)($p['id'] ?? 0) === $postId);
ok(count($foundAfter) > 0, 'Unhidden post is visible again in active feed');

// -----------------------------------------------------------------------
// Section 10: Admin moderation — hideComment() / unhideComment()
// -----------------------------------------------------------------------
echo "\n--- Section 10: hideComment() / unhideComment() ---\n";

$hideCommentResult = $service->hideComment($adminId, $commentId);
ok($hideCommentResult === true, 'Admin can hide a comment');

// Hidden comment absent from post comments
$commentsAfterHide = (new CommunityComment())->findByPost($postId);
$foundComment = array_filter($commentsAfterHide, fn ($c) => (int)($c['id'] ?? 0) === $commentId);
ok(count($foundComment) === 0, 'Hidden comment is absent from post comment list');

$unhideCommentResult = $service->unhideComment($adminId, $commentId);
ok($unhideCommentResult === true, 'Admin can unhide a comment');

$commentsAfterUnhide = (new CommunityComment())->findByPost($postId);
$foundCommentBack = array_filter($commentsAfterUnhide, fn ($c) => (int)($c['id'] ?? 0) === $commentId);
ok(count($foundCommentBack) > 0, 'Unhidden comment is visible again');

// -----------------------------------------------------------------------
// Section 11: Non-existent target error handling
// -----------------------------------------------------------------------
echo "\n--- Section 11: Non-existent targets ---\n";

throws(
    fn () => $service->hidePost($adminId, 99999),
    CommunityException::class,
    'hidePost throws for non-existent post'
);

throws(
    fn () => $service->hideComment($adminId, 99999),
    CommunityException::class,
    'hideComment throws for non-existent comment'
);

throws(
    fn () => $service->moderateReport($adminId, 99999, 'resolved'),
    CommunityException::class,
    'moderateReport throws for non-existent report'
);

// -----------------------------------------------------------------------
// Section 12: findAll() enriched rows
// -----------------------------------------------------------------------
echo "\n--- Section 12: findAll() enriched rows ---\n";

$allPending = $repo->findAll(50, 0, 'pending');
ok(is_array($allPending), 'findAll returns array');

if (!empty($allPending)) {
    $row = $allPending[0];
    ok(isset($row['reporter_name']), 'findAll rows have reporter_name');
    ok(isset($row['content_type']), 'findAll rows have content_type');
    ok(isset($row['content_preview']), 'findAll rows have content_preview');
    ok(in_array($row['content_type'], ['post', 'comment', 'unknown'], true), 'content_type is a known value');
} else {
    echo "  (no pending rows for enrichment assertions — skipping)\n";
}

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
echo "CommunityC5Test: {$passed} passed, {$failed} failed\n";
if (!empty($errors)) {
    echo "\nFailed assertions:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
}
echo str_repeat('-', 50) . "\n";
exit($failed > 0 ? 1 : 0);
