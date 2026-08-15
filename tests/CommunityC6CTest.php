<?php

declare(strict_types=1);

/**
 * CommunityC6CTest — CLI test suite for Phase C6-C (Community Search & Filtering)
 *
 * Tests:
 * - Search matching by title
 * - Search matching by post content/body
 * - Search matching by author display name & book title
 * - Query normalization (trim whitespace, collapse multiple spaces)
 * - Security (SQL injection safety, XSS escaping, oversized queries)
 * - Combined filters (q, sort, book_id, author_id)
 * - Pagination parameter persistence & bounded items
 * - Moderation filtering (hidden/moderated posts excluded from search)
 * - Nonexistent search query handling (empty items array, total = 0)
 *
 * Run from project root:
 *     php tests/CommunityC6CTest.php
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

// -----------------------------------------------------------------------
// Boot test environment
// -----------------------------------------------------------------------

(new Environment(root_path('.env')))->load();

$dbPath = root_path('database/community_c6c_test.db');
foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
    if (is_file($file)) {
        @unlink($file);
    }
}

Database::instance($dbPath);
(new Migrator(db(), root_path('database/migrations')))->run();
(new Seeder(db(), root_path('database/seeds')))->run();

$session = new Session('community_c6c_test');
$session->start();

$service = new CommunityService(
    new CommunityPost(),
    new CommunityComment(),
    new CommunityLike(),
    new CommunityReport(),
    new Book(),
);
$postRepo = new CommunityPostRepository();

// Create test users with distinct names
db()->execute(
    "INSERT INTO users (full_name, email, password, role, created_at, updated_at)
     VALUES ('Alice Smith C6C', 'alice_c6c@test.local', 'hash', 'user', datetime('now'), datetime('now'))"
);
$userAliceId = (int) db()->lastInsertId();

db()->execute(
    "INSERT INTO users (full_name, email, password, role, created_at, updated_at)
     VALUES ('Bob Jones C6C', 'bob_c6c@test.local', 'hash', 'user', datetime('now'), datetime('now'))"
);
$userBobId = (int) db()->lastInsertId();

// Book fixture
$bookRow = db()->query("SELECT id, title FROM books LIMIT 1")[0] ?? null;
$bookId1 = $bookRow ? (int) $bookRow['id'] : null;
$bookTitle1 = $bookRow ? (string) $bookRow['title'] : 'Default Book';

// Post 1: Title match ("Atomic Habits Discussion")
db()->execute(
    "INSERT INTO community_posts (user_id, book_id, title, body, status, created_at, updated_at)
     VALUES (?, ?, 'Atomic Habits Discussion', 'General discussion about habits and routine.', 'active', datetime('now'), datetime('now'))",
    [$userAliceId, $bookId1]
);
$postAtomicId = (int) db()->lastInsertId();

// Post 2: Body match ("Deep Focus Techniques")
db()->execute(
    "INSERT INTO community_posts (user_id, book_id, title, body, status, created_at, updated_at)
     VALUES (?, null, 'Deep Focus Techniques', 'We will discuss concentration and productivity strategies.', 'active', datetime('now'), datetime('now'))",
    [$userBobId]
);
$postFocusId = (int) db()->lastInsertId();

// Post 3: Hidden post matching search query (Moderated)
db()->execute(
    "INSERT INTO community_posts (user_id, book_id, title, body, status, created_at, updated_at)
     VALUES (?, null, 'Atomic Secrets Hidden', 'Hidden habits discussion text', 'hidden', datetime('now'), datetime('now'))",
    [$userAliceId]
);
$postHiddenId = (int) db()->lastInsertId();

// -----------------------------------------------------------------------
// Section 1: Search Matching (Title & Body)
// -----------------------------------------------------------------------
echo "\n--- Section 1: Search Matching ---\n";

$titleSearch = $service->listDiscoveryPosts('recent', null, null, 'Atomic', 1, 20);
ok($titleSearch['query'] === 'Atomic', 'Search query returned in payload metadata');
$titleSearchIds = array_column($titleSearch['items'], 'id');
ok(in_array($postAtomicId, $titleSearchIds, true), 'Search matches post title');
ok(!in_array($postHiddenId, $titleSearchIds, true), 'MODERATION: Hidden post is EXCLUDED from title search');

$bodySearch = $service->listDiscoveryPosts('recent', null, null, 'productivity', 1, 20);
$bodySearchIds = array_column($bodySearch['items'], 'id');
ok(in_array($postFocusId, $bodySearchIds, true), 'Search matches post body content');
ok(!in_array($postAtomicId, $bodySearchIds, true), 'Unrelated post is excluded from body search');

// -----------------------------------------------------------------------
// Section 2: Author Name & Book Title Matching
// -----------------------------------------------------------------------
echo "\n--- Section 2: Author & Book Matching ---\n";

$authorSearch = $service->listDiscoveryPosts('recent', null, null, 'Alice Smith', 1, 20);
$authorSearchIds = array_column($authorSearch['items'], 'id');
ok(in_array($postAtomicId, $authorSearchIds, true), 'Search matches author full name');

if ($bookTitle1 !== '') {
    $bookSearch = $service->listDiscoveryPosts('recent', null, null, mb_substr($bookTitle1, 0, 10), 1, 20);
    $bookSearchIds = array_column($bookSearch['items'], 'id');
    ok(in_array($postAtomicId, $bookSearchIds, true), 'Search matches linked book title');
}

// -----------------------------------------------------------------------
// Section 3: Query Normalization & Empty Query Handling
// -----------------------------------------------------------------------
echo "\n--- Section 3: Query Normalization ---\n";

$whitespaceSearch = $service->listDiscoveryPosts('recent', null, null, "   Atomic   Habits   ", 1, 20);
ok($whitespaceSearch['query'] === 'Atomic Habits', 'Leading, trailing, and multi-spaces collapsed');
ok(count($whitespaceSearch['items']) > 0, 'Normalized query returns matching posts');

$emptySearch = $service->listDiscoveryPosts('recent', null, null, "   ", 1, 20);
ok($emptySearch['query'] === null, 'Whitespace-only query normalizes to null');
ok($emptySearch['total'] >= 2, 'Empty query returns standard active discovery feed');

// -----------------------------------------------------------------------
// Section 4: Security & Parameter Sanitization
// -----------------------------------------------------------------------
echo "\n--- Section 4: Security & Parameter Sanitization ---\n";

$sqlInjectionSearch = $service->listDiscoveryPosts('recent', null, null, "' OR '1'='1", 1, 20);
ok(is_array($sqlInjectionSearch['items']), 'SQL injection payload executed safely without exception');

$xssSearch = $service->listDiscoveryPosts('recent', null, null, "<script>alert(1)</script>", 1, 20);
ok($xssSearch['query'] === '<script>alert(1)</script>', 'XSS payload stored normalized safely');

$oversizedQuery = str_repeat('A', 200);
$longSearch = $service->listDiscoveryPosts('recent', null, null, $oversizedQuery, 1, 20);
ok(mb_strlen((string) $longSearch['query']) === 100, 'Oversized search query capped at 100 characters');

// -----------------------------------------------------------------------
// Section 5: Author Filtering & Combined Filters
// -----------------------------------------------------------------------
echo "\n--- Section 5: Author Filtering & Combined Filters ---\n";

$authorFilterFeed = $service->listDiscoveryPosts('recent', null, $userBobId, null, 1, 20);
ok($authorFilterFeed['author_id'] === $userBobId, 'author_id is preserved in response payload');
$authorFilterIds = array_column($authorFilterFeed['items'], 'id');
ok(in_array($postFocusId, $authorFilterIds, true), 'Author filter includes posts authored by specified user');
ok(!in_array($postAtomicId, $authorFilterIds, true), 'Author filter excludes posts by other users');

$combinedFeed = $service->listDiscoveryPosts('popular', $bookId1, $userAliceId, 'Atomic', 1, 20);
ok($combinedFeed['sort'] === 'popular', 'Combined feed preserves sort = popular');
ok($combinedFeed['book_id'] === $bookId1, 'Combined feed preserves book_id filter');
ok($combinedFeed['author_id'] === $userAliceId, 'Combined feed preserves author_id filter');
ok($combinedFeed['query'] === 'Atomic', 'Combined feed preserves search query');

// -----------------------------------------------------------------------
// Section 6: Bounded Pagination & No Results
// -----------------------------------------------------------------------
echo "\n--- Section 6: Pagination & No Results ---\n";

$noResults = $service->listDiscoveryPosts('recent', null, null, 'NonexistentTermXYZ123', 1, 20);
ok($noResults['total'] === 0, 'Unmatched search query produces total = 0');
ok(count($noResults['items']) === 0, 'Unmatched search query produces empty items array');

$pagedSearch = $service->listDiscoveryPosts('recent', null, null, null, 1, 1);
ok(count($pagedSearch['items']) === 1, 'Pagination per_page bound to 1 item');
ok($pagedSearch['pages'] >= 2, 'Total pages calculated accurately');

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
echo "CommunityC6CTest: {$passed} passed, {$failed} failed\n";
if (!empty($errors)) {
    echo "\nFailed assertions:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
}
echo str_repeat('-', 50) . "\n";
exit($failed > 0 ? 1 : 0);
