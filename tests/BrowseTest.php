<?php

declare(strict_types=1);

/**
 * BrowseTest — CLI test suite for Phase 5.5
 *
 * Verifies search, filters, combined filters, sorting, pagination,
 * empty states, SQL-injection safety and performance of the browse
 * pipeline (BookService + BookRepository), then smoke-tests the
 * real controller and views exactly as a browser would use them.
 *
 * Run from the project root:
 *
 *     php tests/BrowseTest.php
 *
 * How it works:
 *     - A throwaway SQLite database (database/browse_test.db) is
 *       created, migrated and seeded, so the real development data
 *       is never touched.
 *     - Every check prints PASS/FAIL; a summary line at the end
 *       doubles as the Phase 5.5 testing checklist for the viva.
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Core\Migrator;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Seeder;
use BookSphere\App\Core\Session;
use BookSphere\App\Controllers\BookController;
use BookSphere\App\Models\Author;
use BookSphere\App\Models\Book;
use BookSphere\App\Models\Category;
use BookSphere\App\Models\User;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\BookService;

// ---------------------------------------------------------------------
// 0. Boot: fresh throwaway database, migrated and seeded.
// ---------------------------------------------------------------------

(new Environment(root_path('.env')))->load();

$dbPath = root_path('database/browse_test.db');

foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

Database::instance($dbPath);
(new Migrator(db(), root_path('database/migrations')))->run();
(new Seeder(db(), root_path('database/seeds')))->run();

// A session must exist BEFORE any output, so the view smoke test
// can log in a stub user (session_start() refuses to run once
// output has been sent).
$session = new Session('browse_test');
$session->start();
AuthService::setInstance(new AuthService($session, new User()));

$service = new BookService(new Book(), new Author(), new Category());

// The seeded catalogue size this test asserts against.
$seedTotal = (int) db()->query('SELECT COUNT(*) c FROM books WHERE deleted_at IS NULL')[0]['c'];
$maxYear   = (int) db()->query('SELECT MAX(published_year) m FROM books WHERE deleted_at IS NULL')[0]['m'];
$minYear   = (int) db()->query('SELECT MIN(published_year) m FROM books WHERE deleted_at IS NULL')[0]['m'];

// ---------------------------------------------------------------------
// Test harness
// ---------------------------------------------------------------------

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;

    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;

    $ok ? $pass++ : $fail++;
}

function section(string $title): void
{
    echo PHP_EOL . str_repeat('-', 72) . PHP_EOL . $title . PHP_EOL . str_repeat('-', 72) . PHP_EOL;
}

$all = fn (array $items, callable $pick): array => array_map($pick, $items);

// ---------------------------------------------------------------------
// 1. Search
// ---------------------------------------------------------------------

section('1. SEARCH (title, author, ISBN, publisher, description, category, language)');

$r = $service->search('To Kill a Mockingbird');
check('Exact title', $r['total'] === 1 && $r['items'][0]['title'] === 'To Kill a Mockingbird', "{$r['total']} hit(s)");

$r = $service->search('mocking');
check('Partial title (case-insensitive LIKE)', $r['total'] >= 1 && $r['items'][0]['title'] === 'To Kill a Mockingbird');

$r = $service->search('Rowling');
check('Author name', $r['total'] >= 1 && str_contains($r['items'][0]['title'], 'Harry Potter'));

$r = $service->search('9780590353427');
check('ISBN', $r['total'] === 1 && str_contains($r['items'][0]['title'], 'Harry Potter'));

$r = $service->search('Harper');
check('Publisher', $r['total'] >= 4, "{$r['total']} hits (HarperCollins, Harper, HarperOne, Harper Perennial)");

$r = $service->search('dragon');
check('Description word', $r['total'] >= 1 && str_contains($r['items'][0]['title'], 'Hobbit'));

$r = $service->search('Fantasy');
check('Category name', $r['total'] === 2, "{$r['total']} hits (Harry Potter, The Hobbit)");

$r = $service->search('en');
check('Language code matches every English book', $r['total'] >= $seedTotal, "{$r['total']} hits via title/description/language");

$r = $service->search('potter', ['category_id' => $service->combineFilters(['category_id' => '4'])['category_id'] ?? null]);
$categories = db()->query("SELECT id FROM categories WHERE name = 'Fantasy'");
$r = $service->search('potter', ['category_id' => (int) $categories[0]['id']]);
check('Search + category combined', $r['total'] === 1 && str_contains($r['items'][0]['title'], 'Harry Potter'));

// ---------------------------------------------------------------------
// 2. Filters
// ---------------------------------------------------------------------

section('2. FILTERS (category, author, publisher, language, year, status, rating)');

$fantasy = (int) db()->query("SELECT id FROM categories WHERE name = 'Fantasy'")[0]['id'];
$r = $service->filter(['category_id' => $fantasy]);
check('Category filter', $r['total'] === 2, 'Fantasy has 2 books');

$harperLee = (int) db()->query("SELECT id FROM authors WHERE name = 'Harper Lee'")[0]['id'];
$r = $service->filter(['author_id' => $harperLee]);
check('Author filter', $r['total'] === 1 && $r['items'][0]['title'] === 'To Kill a Mockingbird');

$r = $service->filter(['publisher' => 'Harper']);
check('Publisher filter', $r['total'] >= 4);

$r = $service->filter(['language' => 'en']);
check('Language filter', $r['total'] === $seedTotal, "all $seedTotal seeded books are 'en'");

$r = $service->filter(['year_from' => 2000]);
$expected = (int) db()->query('SELECT COUNT(*) c FROM books WHERE published_year >= 2000 AND deleted_at IS NULL')[0]['c'];
check('Year from', $r['total'] === $expected, "$r[total] vs SQL $expected");

$r = $service->filter(['year_to' => 1950]);
$expected = (int) db()->query('SELECT COUNT(*) c FROM books WHERE published_year <= 1950 AND deleted_at IS NULL')[0]['c'];
check('Year to', $r['total'] === $expected);

$r = $service->filter(['year_from' => 1990, 'year_to' => 2010]);
check('Year range', $r['total'] >= 3, "{$r['total']} books between 1990 and 2010");

$r = $service->filter(['status' => 'published']);
check('Status filter', $r['total'] === $seedTotal);

$r = $service->filter(['min_rating' => '4.5']);
check('Minimum rating 4.5', $r['total'] === 1 && $r['items'][0]['average_rating'] >= 4.5);

$r = $service->filter(['min_rating' => '4']);
check('Minimum rating 4', $r['total'] >= 5 && $r['total'] < 21, "{$r['total']} books rated 4+");

// ---------------------------------------------------------------------
// 3. Combined filters
// ---------------------------------------------------------------------

section('3. COMBINED FILTERS');

$r = $service->paginate([
    'q'          => 'harry',
    'category_id' => $fantasy,
    'language'   => 'en',
    'status'     => 'published',
    'min_rating' => '4.5',
]);
check('Search + category + language + status + rating', $r['total'] === 1 && str_contains($r['items'][0]['title'], 'Harry Potter'));

$r = $service->paginate([
    'q'          => '1984',
    'year_from'  => 1940,
    'year_to'    => 1960,
    'author_id'  => (int) db()->query("SELECT id FROM authors WHERE name = 'George Orwell'")[0]['id'],
]);
check('Search + year range + author', $r['total'] === 1 && $r['items'][0]['title'] === '1984');

// ---------------------------------------------------------------------
// 4. Sorting
// ---------------------------------------------------------------------

section('4. SORTING (newest, oldest, A-Z, Z-A, rating, year, updated)');

$r = $service->sort('newest');
check('Newest (created_at DESC)', $r['items'][0]['created_at'] >= $r['items'][count($r['items']) - 1]['created_at']);

$r = $service->sort('oldest');
check('Oldest (created_at ASC)', $r['items'][0]['created_at'] <= $r['items'][count($r['items']) - 1]['created_at']);

$r = $service->sort('title_asc');
check('A–Z', $r['items'][0]['title'] === '1984');

$r = $service->sort('title_desc');
check('Z–A', $r['items'][0]['title'] === 'Wings of Fire');

$r = $service->sort('rating_desc');
check('Highest rated', (float) $r['items'][0]['average_rating'] === 4.5);

$r = $service->sort('rating_asc');
check('Lowest rated', (float) $r['items'][0]['average_rating'] === 3.9);

$r = $service->sort('year_desc', [], 1, 50);
check('Publication year (newest first, NULLs last)',
    (int) $r['items'][0]['published_year'] === $maxYear
    && (int) $r['items'][count($r['items']) - 1]['published_year'] === $minYear);

$r = $service->sort('updated_desc');
check('Recently updated (updated_at DESC)', $r['items'][0]['updated_at'] >= $r['items'][count($r['items']) - 1]['updated_at']);

$r = $service->sort('garbage_sort');
check('Unknown sort falls back to default', $r['items'][0]['title'] !== 'garbage_sort' && count($r['items']) > 0);

// ---------------------------------------------------------------------
// 5. Pagination
// ---------------------------------------------------------------------

section('5. PAGINATION (page sizes, bounds, first/last)');

$r = $service->paginate([], 1, 10);
check('Page 1 of 10-per-page', $r['perPage'] === 10 && count($r['items']) === 10 && $r['pages'] === 2, "pages={$r['pages']}, total={$r['total']}");

$r2 = $service->paginate([], 2, 10);
$ids1 = $all($r['items'], fn (array $b): int => (int) $b['id']);
$ids2 = $all($r2['items'], fn (array $b): int => (int) $b['id']);
check('Page 2 returns different rows', array_intersect($ids1, $ids2) === []);

$r = $service->paginate([], 999, 10);
check('Page beyond the last page clamps to it', $r['page'] === $r['pages'] && $r['pages'] === 2);

$r = $service->paginate([], 1, 50);
check('Page size 50 shows everything', count($r['items']) === $seedTotal && $r['pages'] === 1);

$r = $service->paginate([], 1, 999);
check('Unguarded page size falls back', $r['perPage'] === BookService::DEFAULT_PAGE_SIZE);

$r = $service->paginate(['per_page' => '20', 'page' => '2']);
check('String values from the query string', $r['perPage'] === 20 && count($r['items']) === $seedTotal - 20, 'page 2 of 20/page has the remaining books');

$r = $service->paginate(['page' => '0']);
check('Page 0 clamps to 1', $r['page'] === 1);

// ---------------------------------------------------------------------
// 6. Empty results
// ---------------------------------------------------------------------

section('6. EMPTY RESULTS');

$r = $service->search('zzzzzz-no-such-book');
check('No search hits', $r['total'] === 0 && $r['items'] === []);

$r = $service->filter(['category_id' => 999999]);
check('No filter matches', $r['total'] === 0);

$r = $service->search('zzzzzz', ['status' => 'published', 'min_rating' => '4']);
check('Combined search + filters, no hits', $r['total'] === 0 && $r['pages'] === 1);

// ---------------------------------------------------------------------
// 7. SQL injection
// ---------------------------------------------------------------------

section('7. SQL INJECTION RESISTANCE');

$attacks = [
    "x' OR '1'='1",
    "'; DROP TABLE books; --",
    "' UNION SELECT 1,2,3,4,5,6,7,8,9,10,11,12,13 FROM users; --",
];

foreach ($attacks as $i => $attack) {
    $r = $service->search($attack);
    check('Search attack ' . ($i + 1), $r['total'] === 0 && $r['items'] === [], substr($attack, 0, 28));
}

$r = $service->paginate(['sort' => "created_at; DROP TABLE books; --"]);
check('Sort injection falls back', count($r['items']) > 0);

$r = $service->paginate(['per_page' => "10; DROP TABLE books; --"]);
check('Per-page injection falls back', $r['perPage'] === 10);

$r = $service->filter(['min_rating' => "4; DROP TABLE books; --", 'publisher' => "x' OR '1'='1"]);
check('Numeric + text injection filtered', $r['total'] === 0);

$tables = db()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'books'");
check('books table intact after attacks', $tables !== []);

// ---------------------------------------------------------------------
// 8. combineFilters sanitization
// ---------------------------------------------------------------------

section('8. combineFilters() SANITIZATION');

$f = $service->combineFilters(['q' => '  harry ', 'status' => 'nuke', 'per_page' => '7', 'page' => '-3', 'min_rating' => 'banana', 'language' => 'xx', 'category_id' => 'abc']);
check('Junk values neutralized',
    $f['q'] === 'harry'
    && $f['status'] === ''
    && $f['perPage'] === 10
    && $f['page'] === 1
    && $f['min_rating'] === null
    && $f['language'] === ''
    && $f['category_id'] === null);

$f = $service->combineFilters(['year_from' => '1990abc', 'min_rating' => '4.5', 'per_page' => '50', 'page' => '3']);
check('Valid numeric strings survive', $f['year_from'] === null && $f['min_rating'] === 4.5 && $f['perPage'] === 50 && $f['page'] === 3);

// ---------------------------------------------------------------------
// 9. Performance
// ---------------------------------------------------------------------

section('9. PERFORMANCE (LIMIT/OFFSET on 2,500+ rows)');

// Grow the catalogue to 2,500+ rows quickly (single transaction).
$pdo = db()->pdo();
$pdo->beginTransaction();
$insert = $pdo->prepare(
    'INSERT INTO books (google_book_id, isbn, title, description, publisher, published_year, language, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
for ($i = 0; $i < 2500; $i++) {
    $insert->execute([
        'PERF' . $i,
        '978PERF' . str_pad((string) $i, 9, '0', STR_PAD_LEFT),
        'Performance Test Book ' . $i,
        'A synthetic row used by the Phase 5.5 performance check.',
        'Performance Press',
        1900 + ($i % 120),
        'en',
        'published',
    ]);
}
$pdo->commit();

$totalBooks = (int) db()->query('SELECT COUNT(*) c FROM books WHERE deleted_at IS NULL')[0]['c'];
check('Catalogue now 2,500+ rows', $totalBooks >= $seedTotal + 2500, "$totalBooks books");

$start = microtime(true);
$r = $service->paginate([], 50, 20);
$duration = (microtime(true) - $start) * 1000;
check('Deep page (50) of 20 rows under 1.5 s', $duration < 1500 && count($r['items']) === 20, round($duration, 1) . ' ms, pages=' . $r['pages']);

$start = microtime(true);
$r = $service->search('Performance Test Book 2499');
$duration = (microtime(true) - $start) * 1000;
check('Free-text search across 2,500 rows under 1.5 s', $r['total'] === 1 && $duration < 1500, round($duration, 1) . ' ms');

$start = microtime(true);
$r = $service->filter(['status' => 'published', 'year_from' => 2000, 'sort' => 'rating_desc']);
$duration = (microtime(true) - $start) * 1000;
check('Filtered + sorted page under 1.5 s', $duration < 1500 && count($r['items']) === 10, round($duration, 1) . ' ms');

// Prove the indexes are used by the status/year/rating filters.
echo PHP_EOL . '  EXPLAIN QUERY PLAN (status filter):' . PHP_EOL;
foreach (db()->query('EXPLAIN QUERY PLAN SELECT COUNT(*) FROM books b WHERE b.deleted_at IS NULL AND b.status = ?', ['published']) as $row) {
    echo '    ' . $row['detail'] . PHP_EOL;
}

echo '  EXPLAIN QUERY PLAN (year filter):' . PHP_EOL;
foreach (db()->query('EXPLAIN QUERY PLAN SELECT COUNT(*) FROM books b WHERE b.deleted_at IS NULL AND b.published_year >= ?', [2000]) as $row) {
    echo '    ' . $row['detail'] . PHP_EOL;
}

// ---------------------------------------------------------------------
// 10. Controller + view smoke test (as a browser would hit them)
// ---------------------------------------------------------------------

section('10. CONTROLLER + VIEW SMOKE TEST');

// The stubbed session + AuthService were created before any output
// at the top of this file, so the auth helpers work in the CLI.

$controller = new BookController($service);

// Admin user for the admin smoke test.
$session->put('auth_user', ['id' => 1, 'full_name' => 'Admin', 'email' => 'admin@booksphere.test', 'role' => 'admin']);

$_GET = ['q' => 'harry', 'sort' => 'title_asc', 'per_page' => '10'];
ob_start();
$controller->index(new Request(), []);
$html = (string) ob_get_clean();

check('Browse page renders (admin)', $html !== '');
check('Results shown', str_contains($html, 'Harry Potter'));
check('Search box + filters present', str_contains($html, 'browse-q') && str_contains($html, 'browse-category') && str_contains($html, 'browse-sort'));
check('Grid AND table both rendered', str_contains($html, 'book-browse-grid') && str_contains($html, 'book-browse-table'));
check('Admin actions present', str_contains($html, 'data-delete-url') && str_contains($html, 'Add Book'));
check('Filter chips show the active search', str_contains($html, 'Search: &quot;harry&quot;'));

$_GET = ['per_page' => '10', 'sort' => 'title_asc'];
ob_start();
$controller->index(new Request(), []);
$html = (string) ob_get_clean();
check('Pagination links present (full catalogue, 2 pages)', str_contains($html, 'aria-label="First page"') && str_contains($html, 'aria-label="Page 2"'));
check('Pagination summary shows the totals', str_contains($html, 'Page 1 of 2'));

$_GET = ['q' => 'zzzzzz-no-such-book'];
ob_start();
$controller->index(new Request(), []);
$html = (string) ob_get_clean();
check('Empty state renders', str_contains($html, 'No results for'));

// JSON endpoint (live search).
$_GET = ['q' => 'mocking', 'sort' => 'title_asc'];
ob_start();
$controller->searchJson(new Request(), []);
$json = json_decode((string) ob_get_clean(), true);
check('JSON endpoint returns rendered partial', is_array($json) && str_contains($json['html'], 'To Kill a Mockingbird'));
check('JSON carries the numbers', $json['total'] === 1 && $json['page'] === 1 && $json['pages'] === 1);

// JSON endpoint honours pagination.
$_GET = ['per_page' => '10', 'page' => '2'];
ob_start();
$controller->searchJson(new Request(), []);
$json = json_decode((string) ob_get_clean(), true);
check('JSON pagination (page 2 of the live catalogue)',
    is_array($json) && $json['page'] === 2
    && $json['pages'] === (int) ceil($json['total'] / 10)
    && str_contains($json['html'], 'book-browse-grid'));

// A non-admin user must NOT see admin actions.
$session->put('auth_user', ['id' => 2, 'full_name' => 'Riya', 'email' => 'riya@booksphere.test', 'role' => 'user']);
$_GET = ['q' => 'harry'];
ob_start();
$controller->index(new Request(), []);
$html = (string) ob_get_clean();
check('Non-admin hides admin actions', !str_contains($html, 'data-delete-url') && !str_contains($html, 'Add Book'));

// Show page: admin sees the Edit/Delete actions, a normal user
// does not (Phase 5.6 authorization hardening).
$harry = $service->search('harry')['items'][0];

$session->put('auth_user', ['id' => 1, 'full_name' => 'Admin', 'email' => 'admin@booksphere.test', 'role' => 'admin']);
$_GET = [];
ob_start();
$controller->show(new Request(), ['id' => (string) $harry['id']]);
$html = (string) ob_get_clean();
check('Show page renders (admin)', str_contains($html, e($harry['title'])));
check('Show page shows admin actions', str_contains($html, 'Edit book') && str_contains($html, 'data-delete-url'));

$session->put('auth_user', ['id' => 2, 'full_name' => 'Riya', 'email' => 'riya@booksphere.test', 'role' => 'user']);
ob_start();
$controller->show(new Request(), ['id' => (string) $harry['id']]);
$html = (string) ob_get_clean();
check('Show page hides admin actions from users',
    !str_contains($html, 'Edit book') && !str_contains($html, 'data-delete-url')
    && str_contains($html, 'Back to catalogue'));

// Clean up the stubbed session.
$session->forget('auth_user');

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------

section('RESULT');

echo '  Passed: ' . $pass . PHP_EOL;
echo '  Failed: ' . $fail . PHP_EOL;

echo PHP_EOL . 'Note: the throwaway database database/browse_test.db (with 2,500' . PHP_EOL
    . 'performance rows) is left in place for inspection; delete it anytime.' . PHP_EOL;

exit($fail === 0 ? 0 : 1);
