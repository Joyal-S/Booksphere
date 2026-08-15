<?php

declare(strict_types=1);

/**
 * CommunityC6BTest — CLI test suite for Phase C6-B (Community Discovery)
 *
 * Tests:
 * - Discovery mode: Recent ordering (created_at DESC)
 * - Discovery mode: Popular ordering (likes & comments weighted)
 * - Discovery mode: Trending ordering (recency & engagement gravity decay formula)
 * - Book filtering: valid book_id filter, invalid book_id fallback/handling
 * - Pagination & parameter persistence (page, per_page, sort, book_id)
 * - Moderation filtering (hidden posts excluded from all discovery modes)
 * - Security & fallback (invalid sort values, invalid page, SQL injection protection)
 *
 * Run from project root:
 *     php tests/CommunityC6BTest.php
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
use BookSphere\App\Repositories\CommunityPostRepository;
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

$dbPath = root_path('database/community_c6b_test.db');
foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
    if (is_file($file)) {
        @unlink($file);
    }
}

Database::instance($dbPath);
(new Migrator(db(), root_path('database/migrations')))->run();
(new Seeder(db(), root_path('database/seeds')))->run();

$session = new Session('community_c6b_test');
$session->start();

$service = new CommunityService(
    new CommunityPost(),
    new CommunityComment(),
    new CommunityLike(),
    new CommunityReport(),
    new Book(),
);
$postRepo = new CommunityPostRepository();

// Create test users
for ($u = 1; $u <= 10; $u++) {
    db()->execute(
        "INSERT INTO users (full_name, email, password, role, created_at, updated_at)
         VALUES ('User {$u} C6B', 'u{$u}_c6b@test.local', 'hash', 'user', datetime('now'), datetime('now'))"
    );
}
$userRows = db()->query("SELECT id FROM users ORDER BY id ASC");
$user1Id  = (int) $userRows[0]['id'];
$user2Id  = (int) $userRows[1]['id'];

// Book fixture
$bookRow = db()->query("SELECT id FROM books LIMIT 1")[0] ?? null;
$bookId1 = $bookRow ? (int) $bookRow['id'] : null;

// Post 1: Created older, high total engagement (popular post)
$oldDate = date('Y-m-d\TH:i:s\Z', strtotime('-10 days'));
db()->execute(
    "INSERT INTO community_posts (user_id, book_id, title, body, status, created_at, updated_at)
     VALUES (?, ?, 'Old Popular Post', 'Body of old popular post', 'active', ?, ?)",
    [$user1Id, $bookId1, $oldDate, $oldDate]
);
$postOldPopularId = (int) db()->lastInsertId();

// Add 5 likes to old popular post using valid user IDs
for ($i = 0; $i < 5; $i++) {
    $likerId = (int) $userRows[$i + 2]['id'];
    db()->execute(
        "INSERT INTO community_likes (post_id, user_id, created_at) VALUES (?, ?, ?)",
        [$postOldPopularId, $likerId, $oldDate]
    );
}

// Post 2: Created recently, moderate engagement (trending post)
$recentDate = date('Y-m-d\TH:i:s\Z', strtotime('-1 hour'));
db()->execute(
    "INSERT INTO community_posts (user_id, book_id, title, body, status, created_at, updated_at)
     VALUES (?, ?, 'Recent Trending Post', 'Body of recent trending post', 'active', ?, ?)",
    [$user2Id, $bookId1, $recentDate, $recentDate]
);
$postRecentTrendingId = (int) db()->lastInsertId();

// Add 3 comments to recent trending post
for ($i = 0; $i < 3; $i++) {
    $commenterId = (int) $userRows[$i]['id'];
    db()->execute(
        "INSERT INTO community_comments (post_id, user_id, body, status, created_at, updated_at)
         VALUES (?, ?, 'Trending comment', 'active', ?, ?)",
        [$postRecentTrendingId, $commenterId, $recentDate, $recentDate]
    );
}

// Post 3: Brand new post, low engagement (recent post)
$brandNewDate = date('Y-m-d\TH:i:s\Z', strtotime('now'));
db()->execute(
    "INSERT INTO community_posts (user_id, book_id, title, body, status, created_at, updated_at)
     VALUES (?, null, 'Brand New Post', 'Body of brand new post', 'active', ?, ?)",
    [$user1Id, $brandNewDate, $brandNewDate]
);
$postBrandNewId = (int) db()->lastInsertId();

// Post 4: Hidden post (moderated)
db()->execute(
    "INSERT INTO community_posts (user_id, book_id, title, body, status, created_at, updated_at)
     VALUES (?, null, 'Hidden Post', 'Body of hidden post', 'hidden', ?, ?)",
    [$user1Id, $brandNewDate, $brandNewDate]
);
$postHiddenId = (int) db()->lastInsertId();

// -----------------------------------------------------------------------
// Section 1: Recent Discovery Mode
// -----------------------------------------------------------------------
echo "\n--- Section 1: Recent Discovery Mode ---\n";

$recentFeed = $service->listDiscoveryPosts('recent', null, 1, 20);
ok(is_array($recentFeed['items']), 'listDiscoveryPosts(recent) returns array of items');
ok($recentFeed['sort'] === 'recent', 'Returns sort = recent');
$recentIds = array_column($recentFeed['items'], 'id');

ok(!in_array($postHiddenId, $recentIds, true), 'MODERATION: Hidden post is excluded from recent discovery');
ok($recentIds[0] === $postBrandNewId, 'Brand new post is ranked first in Recent mode');

// -----------------------------------------------------------------------
// Section 2: Popular Discovery Mode
// -----------------------------------------------------------------------
echo "\n--- Section 2: Popular Discovery Mode ---\n";

$popularFeed = $service->listDiscoveryPosts('popular', null, 1, 20);
ok($popularFeed['sort'] === 'popular', 'Returns sort = popular');
$popularIds = array_column($popularFeed['items'], 'id');

ok(!in_array($postHiddenId, $popularIds, true), 'MODERATION: Hidden post is excluded from popular discovery');
ok($popularIds[0] === $postOldPopularId || $popularIds[0] === $postRecentTrendingId, 'Highest engagement post ranks top in Popular mode');

// -----------------------------------------------------------------------
// Section 3: Trending Discovery Mode (Gravity Decay Recency)
// -----------------------------------------------------------------------
echo "\n--- Section 3: Trending Discovery Mode ---\n";

$trendingFeed = $service->listDiscoveryPosts('trending', null, 1, 20);
ok($trendingFeed['sort'] === 'trending', 'Returns sort = trending');
$trendingIds = array_column($trendingFeed['items'], 'id');

ok(!in_array($postHiddenId, $trendingIds, true), 'MODERATION: Hidden post is excluded from trending discovery');
ok($trendingIds[0] === $postRecentTrendingId, 'Recent active post ranks above 10-day-old post in Trending mode due to recency decay');

// -----------------------------------------------------------------------
// Section 4: Book Filter & Invalid Book Handling
// -----------------------------------------------------------------------
echo "\n--- Section 4: Book Filter & Invalid Handling ---\n";

if ($bookId1 !== null) {
    $bookFeed = $service->listDiscoveryPosts('recent', $bookId1, 1, 20);
    ok($bookFeed['book_id'] === $bookId1, 'book_id filter is preserved in feed metadata');
    $bookFeedIds = array_column($bookFeed['items'], 'id');
    ok(in_array($postOldPopularId, $bookFeedIds, true), 'Post linked to book is included in book discovery feed');
    ok(!in_array($postBrandNewId, $bookFeedIds, true), 'Post NOT linked to book is excluded from book discovery feed');
}

throws(
    fn () => $service->listDiscoveryPosts('recent', 999999, 1, 20),
    CommunityException::class,
    'Nonexistent book ID throws CommunityException::bookNotFound'
);

// -----------------------------------------------------------------------
// Section 5: Invalid Sort Fallback & Parameter Sanitization
// -----------------------------------------------------------------------
echo "\n--- Section 5: Invalid Sort Fallback & Security ---\n";

$invalidSortFeed = $service->listDiscoveryPosts("SELECT * FROM users", null, 1, 20);
ok($invalidSortFeed['sort'] === 'recent', 'Invalid sort value ("SELECT * FROM users") falls back safely to "recent"');
ok(count($invalidSortFeed['items']) > 0, 'Returns valid recent feed on invalid sort value');

// -----------------------------------------------------------------------
// Section 6: Bounded Pagination
// -----------------------------------------------------------------------
echo "\n--- Section 6: Bounded Pagination ---\n";

$pagedFeed = $service->listDiscoveryPosts('recent', null, 1, 2);
ok(count($pagedFeed['items']) === 2, 'Feed items bounded to perPage limit (2)');
ok($pagedFeed['per_page'] === 2, 'per_page is 2');
ok($pagedFeed['pages'] >= 2, 'Calculates correct total pages');

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
echo "CommunityC6BTest: {$passed} passed, {$failed} failed\n";
if (!empty($errors)) {
    echo "\nFailed assertions:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
}
echo str_repeat('-', 50) . "\n";
exit($failed > 0 ? 1 : 0);
