<?php

declare(strict_types=1);

/**
 * AdminAnalyticsTest — CLI test suite for Phase 12.4 (Admin Dashboard)
 *
 * Verifies the coordinated administration dashboard end-to-end:
 *
 *     1. Log totals  - the recommendation_logs aggregates answer
 *                      real numbers: rows served, distinct users and
 *                      books, the latest generation timestamp
 *                      (no data is ever guessed)
 *     2. Surfaces    - logCountsBySignal groups the audit trail by
 *                      its section key (dashboard_recommended, ...)
 *     3. Top books   - the most recommended books, ordered by volume
 *                      with the deterministic title tie-break
 *     4. Sleepers    - a book suggested >= 3 times that NOBODY acted
 *                      on wears a flag; interaction removes the flag
 *                      and a single-serve book is never flagged
 *     5. Dashboard   - AdminAnalyticsService composes the 12.2 book
 *                      payload and the 12.3 recommendation block into
 *                      one contract with a real generatedAt stamp
 *     6. Controller  - GET /admin renders the tiles with REAL
 *                      numbers; the route dispatches; AdminMiddleware
 *                      stops guests (login flash) and non-admins
 *                      (403) before ANY analytics read
 *     7. Consistency - the aggregates agree after all writes
 *
 * Run from the project root:
 *
 *     php tests/AdminAnalyticsTest.php
 *
 * The throwaway database (database/admin_analytics_test.db) is
 * migrated, seeded and left in place for inspection; delete it
 * anytime.
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Controllers\AdminController;
use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Core\MiddlewarePipeline;
use BookSphere\App\Core\Migrator;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Core\Router;
use BookSphere\App\Core\Seeder;
use BookSphere\App\Core\Session;
use BookSphere\App\Middleware\AdminMiddleware;
use BookSphere\App\Models\User;
use BookSphere\App\Repositories\BookAnalyticsRepository;
use BookSphere\App\Repositories\BookRepository;
use BookSphere\App\Repositories\RecommendationRepository;
use BookSphere\App\Services\AdminAnalyticsService;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\BookAnalyticsService;
use BookSphere\App\Services\RecommendationMetrics;

// ---------------------------------------------------------------------
// 0. Boot: fresh throwaway database, migrated and seeded.
// ---------------------------------------------------------------------

(new Environment(root_path('.env')))->load();

$dbPath = root_path('database/admin_analytics_test.db');

foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

Database::instance($dbPath);
(new Migrator(db(), root_path('database/migrations')))->run();
(new Seeder(db(), root_path('database/seeds')))->run();

$db = db();

// The seeder leaves demo activity behind; the suite starts from an
// EMPTY community (and an EMPTY audit trail) so every metric below
// is the fixture's own.
$db->execute('DELETE FROM reviews');
$db->execute('DELETE FROM user_library');
$db->execute('DELETE FROM wishlist');
$db->execute('DELETE FROM recommendation_logs');

$session = new Session('admin_analytics_test');
$session->start();
$auth = new AuthService($session, new User());
AuthService::setInstance($auth);

// The module config under test: the same shape config/book_analytics.php
// returns (identical to the BookAnalyticsTest fixture).
$config = [
    'enabled' => true,
    'limits'  => [
        'highest_rated'   => 10,
        'most_reviewed'   => 10,
        'most_wishlisted' => 10,
        'most_read'       => 10,
        'most_engaged'    => 10,
        'popular'         => 10,
        'trending'        => 10,
        'genres'          => 12,
        'authors'         => 12,
        'publishers'      => 10,
        'languages'       => 10,
        'years'           => 12,
    ],
    'ratings'   => ['minimum_count' => 5],
    'popularity' => [
        'rating_weight'       => 0.40,
        'review_weight'       => 0.30,
        'interest_weight'     => 0.30,
        'rating_divisor'      => 5.0,
        'review_normalizer'   => 10,
        'interest_normalizer' => 10,
    ],
    'trending' => [
        'window_days'         => 30,
        'review_weight'       => 0.40,
        'interest_weight'     => 0.30,
        'reading_weight'      => 0.30,
        'review_normalizer'   => 5,
        'interest_normalizer' => 5,
        'reading_normalizer'  => 5,
    ],
    'activity'    => ['months' => 12],
    'page_ranges' => [
        ['label' => 'Up to 100', 'min' => 0,   'max' => 100],
        ['label' => '101 - 200', 'min' => 101, 'max' => 200],
        ['label' => '201 - 300', 'min' => 201, 'max' => 300],
        ['label' => '301 - 400', 'min' => 301, 'max' => 400],
        ['label' => '401 - 500', 'min' => 401, 'max' => 500],
        ['label' => 'Over 500',  'min' => 501, 'max' => null],
    ],
];

$repository = new BookAnalyticsRepository();
$bookService = new BookAnalyticsService($repository, $config);

// Test users: the fixture "community" (their records feed the sleep
// test - a review is UNIQUE (user_id, book_id), so each rating needs
// its own person, exactly like the real app).
$insertUser = static function (string $email) use ($db): int {
    $db->execute(
        'INSERT INTO users (full_name, email, password, role) VALUES (?, ?, ?, ?)',
        [$email, $email, password_hash('User@123', PASSWORD_DEFAULT), 'user'],
    );

    return (int) $db->lastInsertId();
};

$u1 = $insertUser('first@booksphere.test');
$u2 = $insertUser('second@booksphere.test');

// Six seeded books give the dataset a stable shape.
$bookRows = array_slice($db->query('SELECT id FROM books ORDER BY id LIMIT 6'), 0, 6);
$bookIds  = [];
foreach ($bookRows as $i => $row) {
    $bookIds[$i + 1] = (int) $row['id'];
}
[$b1, $b2, $b3, $b4, $b5, $b6] = array_values($bookIds);

$logs = new RecommendationRepository(new BookRepository());

// Fixture writers (the SAME shapes the engine writes).
$log = static function (int $user, int $book, string $signal, string $reason = '', float $score = 50.0) use ($logs): void {
    $logs->logRecommendations($user, [
        ['book_id' => $book, 'reason' => $reason, 'score' => $score, 'signal' => $signal],
    ]);
};

$shelf = static function (int $user, int $book, string $status) use ($db): void {
    $stamp = gmdate('c');
    $db->execute(
        'INSERT INTO user_library (user_id, book_id, library_status, progress_percentage, started_reading_at, finished_reading_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$user, $book, $status, $status === 'finished' ? 100 : 0,
         $status === 'finished' ? $stamp : null, $status === 'finished' ? $stamp : null, $stamp, $stamp],
    );
};

$rate = static function (int $user, int $book, int $rating) use ($db): void {
    $stamp = gmdate('c');
    $db->execute(
        'INSERT INTO reviews (book_id, user_id, rating, title, review, status, is_edited, created_at, updated_at)
         VALUES (?, ?, ?, \'\', \'\', \'approved\', 0, ?, ?)',
        [$book, $user, $rating, $stamp, $stamp],
    );
};

// ---------------------------------------------------------------------
// Harness.
// ---------------------------------------------------------------------

$checks   = 0;
$failures = 0;

$section = static fn (string $title): string =>
    "\n------------------------------------------------------------------------\n{$title}\n------------------------------------------------------------------------";

$check = static function (string $label, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($ok || $detail === '' ? '' : '  [' . $detail . ']') . PHP_EOL;
    $checks++;
    $failures += $ok ? 0 : 1;
};

$capture = static function (callable $fn): string {
    ob_start();
    $fn();

    return (string) ob_get_clean();
};

$dashboard = static fn (): array => (new AdminAnalyticsService($bookService, $logs, new RecommendationMetrics($logs)))->dashboard();

// ---------------------------------------------------------------------
// 1. LOG TOTALS (RecommendationRepository::logTotals).
// ---------------------------------------------------------------------

echo $section('1. LOG TOTALS (recommendation_logs aggregates)');

$empty = $logs->logTotals();
$check('an empty audit trail reports zeros and NO date',
    $empty['logs'] === 0 && $empty['users'] === 0 && $empty['books'] === 0 && $empty['latest'] === null);

$log($u1, $b1, 'dashboard_recommended', 'Because you finished similar books', 88.0);
$log($u1, $b1, 'dashboard_recommended', 'Because you read its genre', 74.0);
$log($u1, $b1, 'dashboard_recommended', 'Readers also enjoyed', 61.0);
$log($u1, $b2, 'dashboard_recommended', 'Because you', 55.0);
$log($u1, $b2, 'dashboard_recommended', 'Because', 42.0);
$log($u1, $b3, 'because_you_read', 'Based on your last finish', 90.0);
$log($u2, $b1, 'readers_also_enjoyed', 'Readers of your shelf liked this', 80.0);
$log($u2, $b1, 'readers_also_enjoyed', 'A new release for you', 70.0);
$log($u2, $b4, 'dashboard_recommended', '', 30.0);
$log($u2, $b4, 'dashboard_recommended', '', 26.0);
$log($u2, $b4, 'dashboard_recommended', '', 25.0);

$totals = $logs->logTotals();
$check('totals count every row of the fixture', $totals['logs'] === 11, (string) $totals['logs']);
$check('totals count the distinct RECIPIENTS', $totals['users'] === 2, (string) $totals['users']);
$check('totals count the distinct suggested TITLES', $totals['books'] === 4, (string) $totals['books']);
$check('the latest generation is a real ISO timestamp', is_string($totals['latest']) && str_contains($totals['latest'], 'T'));

// ---------------------------------------------------------------------
// 2. SURFACES (logCountsBySignal).
// ---------------------------------------------------------------------

echo $section('2. SURFACES (per-signal breakdown)');

$signals = $logs->logCountsBySignal(8);
$signalMap = [];
foreach ($signals as $row) {
    $signalMap[(string) $row['signal']] = (int) $row['logs'];
}
$check('dashboard_recommended leads the breakdown', ($signalMap['dashboard_recommended'] ?? 0) === 8, (string) ($signalMap['dashboard_recommended'] ?? 0));
$check('because_you_read and readers_also_enjoyed complete it',
    ($signalMap['because_you_read'] ?? 0) === 1 && ($signalMap['readers_also_enjoyed'] ?? 0) === 2);
$check('the surfaces are ordered by volume (desc)', ($signals[0]['logs'] ?? 0) >= ($signals[1]['logs'] ?? 0) && ($signals[1]['logs'] ?? 0) >= ($signals[2]['logs'] ?? 0));
$check('the limit is honoured', count($signals) <= 8);

// ---------------------------------------------------------------------
// 3. TOP BOOKS (topRecommendedBooks).
// ---------------------------------------------------------------------

echo $section('3. TOP RECOMMENDED BOOKS');

$top = $logs->topRecommendedBooks(5);
$head = $top[0] ?? [];
$check('the most-suggested book leads the list', (int) ($head['id'] ?? 0) === $b1, (string) ($head['id'] ?? 0));
$check('its suggestion count is the REAL count', (int) ($head['logs'] ?? 0) === 5, (string) ($head['logs'] ?? 0));
$check('every row is a readable book row', isset($head['title']) && $head['title'] !== '');
$check('the top list is limited and complete', count($top) <= 5 && count($top) === 4, (string) count($top));

// ---------------------------------------------------------------------
// 4. SLEEPERS (sleptBooks).
// ---------------------------------------------------------------------

echo $section('4. SLEEPING SUGGESTIONS');

$slept = array_map(static fn (array $r): int => (int) $r['id'], $logs->sleptBooks(5));
$check('a tripled-suggested, untouched book is flagged', in_array($b1, $slept, true));
$check('a tripled-suggested book nobody saved is flagged', in_array($b4, $slept, true));
$check('a once-suggested book is NEVER flagged', !in_array($b3, $slept, true));

// Community interaction (a real approved review) removes the flag.
$rate($u2, $b4, 5);
$slept = array_map(static fn (array $r): int => (int) $r['id'], $logs->sleptBooks(5));
$check('an acted-on book leaves the sleep list', !in_array($b4, $slept, true));

// ---------------------------------------------------------------------
// 5. DASHBOARD SERVICE (AdminAnalyticsService).
// ---------------------------------------------------------------------

echo $section('5. DASHBOARD SERVICE (the coordinated payload)');

$payload = $dashboard();

$check('the 12.2 block exposes the book overview', isset($payload['books']['overview']['books']));
$check('the overview books match the visible catalogue',
    (int) $payload['books']['overview']['books'] === (int) $db->query(
        'SELECT COUNT(*) AS n FROM books WHERE status = \'published\' AND deleted_at IS NULL',
    )[0]['n']);
$check('the 12.2 rankings and shelves are present',
    isset($payload['books']['rankings']['popular'], $payload['books']['rankings']['trending'], $payload['books']['shelves']));
$check('the 12.3 recommendation block carries its totals',
    isset($payload['recommendation']['totals'], $payload['recommendation']['signals'], $payload['recommendation']['top'], $payload['recommendation']['slept']));
$check('the recommendation totals agree with the repository',
    $payload['recommendation']['totals'] === $totals);
$check('the engine health block carries the cache and score keys',
    isset($payload['engine']['cache'], $payload['engine']['config'], $payload['engine']['scores']['popularity']));
$check('the engine cache is reported as disabled without a cache object',
    ($payload['engine']['cache']['enabled'] ?? true) === false);
$check('generatedAt carries a real UTC stamp', is_string($payload['generatedAt']) && str_contains($payload['generatedAt'], 'T'));

// ---------------------------------------------------------------------
// 6. CONTROLLER / ROUTER / THE ADMIN GATE.
// ---------------------------------------------------------------------

echo $section('6. CONTROLLER / ROUTER / ADMIN GATE');

$adminController = new AdminController(
    new RecommendationMetrics($logs),
    null,
    null,
    new AdminAnalyticsService($bookService, $logs, new RecommendationMetrics($logs)),
);
$session->put('auth_user_id', $u1);
$session->put('auth_user', ['id' => $u1, 'full_name' => 'Analyst', 'email' => 'reader@booksphere.test', 'role' => 'admin']);
$html = $capture(static fn () => $adminController->index(new Request()));

$check('the signed-in admin render includes the 12.2 section', str_contains($html, 'Book Analytics'));
$check('the render includes the 12.3 section', str_contains($html, 'Recommendation Analytics'));
$check('the render includes the coordinated tiles',
    str_contains($html, 'Recommendations served') && str_contains($html, 'Books in catalogue'));
$check('the tiles carry REAL numbers', str_contains($html, 'data-count="' . (int) ($totals['logs']) . '"'));

// The Controller stays thin: a controller WITHOUT the analytics
// service degrades to the rating page (no crash, no fields).
$bare = new AdminController();
$html2 = $capture(static fn () => $bare->index(new Request()));
$check('without the coordinator the page renders its classic rating tiles', str_contains($html2, 'Rating Analytics'));

// --- The AuthMiddleware probe harness (validates AdminMiddleware on
// the route end-to-end).
$probePath = sys_get_temp_dir() . '/booksphere_admin_probe.php';
$probeHead = '<?php' . PHP_EOL
    . 'declare(strict_types=1);' . PHP_EOL . PHP_EOL
    . 'require ' . var_export(root_path() . '/bootstrap/constants.php', true) . ';' . PHP_EOL
    . 'require ' . var_export(root_path() . '/vendor/autoload.php', true) . ';' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Database;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Environment;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Request;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Session;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Response;' . PHP_EOL
    . 'use BookSphere\\App\\Middleware\\AdminMiddleware;' . PHP_EOL
    . 'use BookSphere\\App\\Models\\User;' . PHP_EOL
    . 'use BookSphere\\App\\Services\\AuthService;' . PHP_EOL . PHP_EOL
    . '(new Environment(root_path(\'.env\')))->load();' . PHP_EOL
    . 'Database::instance(' . var_export($dbPath, true) . ');' . PHP_EOL
    . '$session = new Session(\'admin_probe\');' . PHP_EOL
    . '$session->start();' . PHP_EOL
    . '$auth = new AuthService($session, new User());' . PHP_EOL
    . 'AuthService::setInstance($auth);' . PHP_EOL
    . 'register_shutdown_function(function (): void {' . PHP_EOL
    . '    $flash = session()->getFlash(\'success\') ?? session()->getFlash(\'error\') ?? session()->getFlash(\'info\');' . PHP_EOL
    . '    echo $flash === null ? \'NO_FLASH\' : (string) $flash;' . PHP_EOL
    . '});' . PHP_EOL;

// Guest: blocked with the login flash, never the dashboard.
file_put_contents($probePath, $probeHead
    . '(new AdminMiddleware($auth))->handle(new Request(), static function (): string { return "AUTHORIZED"; });' . PHP_EOL);
$out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' 2>&1');
unlink($probePath);
$check('an admin guest is redirected to login, never authorized',
    str_contains($out, 'Please log in') && !str_contains($out, 'AUTHORIZED'));

// Plain user: the 403 gate.
file_put_contents($probePath, $probeHead
    . '$session->put(\'auth_user_id\', ' . $u1 . ');' . PHP_EOL
    . '$session->put(\'auth_user\', [\'id\' => ' . $u1 . ', \'full_name\' => \'User\', \'email\' => \'u@test\', \'role\' => \'user\']);' . PHP_EOL
    . '(new AdminMiddleware($auth))->handle(new Request(), static function (): string { return "AUTHORIZED"; });' . PHP_EOL);
$out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' 2>&1');
unlink($probePath);
$check('a signed-in non-admin receives the 403 wall',
    str_contains('0' . $out, 'restricted') && !str_contains('0' . $out, 'AUTHORIZED'));

// Administrator: passes the gate.
file_put_contents($probePath, $probeHead
    . '$session->put(\'auth_user_id\', ' . $u1 . ');' . PHP_EOL
    . '$session->put(\'auth_user\', [\'id\' => ' . $u1 . ', \'full_name\' => \'Admin\', \'email\' => \'admin@example\', \'role\' => \'admin\']);' . PHP_EOL
    . '$middleware = new AdminMiddleware($auth);' . PHP_EOL
    . '$middleware->handle(new Request(), static function (): string { echo "AUTHORIZED"; });' . PHP_EOL);
$out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' 2>&1');
unlink($probePath);
$check('an administrator passes the gate', str_contains($out, 'AUTHORIZED'));

// The ROUTE dispatches GET /admin through the controller.
$_SERVER['REQUEST_URI']    = '/admin';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET                      = [];
$router = new Router(new Request(), new MiddlewarePipeline());
$router->get('/admin', [$adminController, 'index']);
$html = $capture(static fn () => $router->dispatch());
$check('GET /admin dispatches through the controller', str_contains($html, 'Book Analytics') && str_contains($html, 'Recommendation Analytics'));

$_SERVER['REQUEST_URI'] = '/';
$_GET                   = [];

// ---------------------------------------------------------------------
// 7. CONSISTENCY after all writes.
// ---------------------------------------------------------------------

echo $section('7. CONSISTENCY (no drift, no SQL errors)');

$final = $dashboard();
$check('the final totals still match the repository',
    $final['recommendation']['totals']['logs'] === $logs->logTotals()['logs']);
$slept = array_map(static fn (array $r): int => (int) $r['id'], $final['recommendation']['slept']);
$check('the remaining sleeper is exactly the untouched tripled book',
    count($slept) === 1 && in_array($b1, $slept, true), implode(',', $slept));
$check('the 12.2 books block still answers every view key',
    isset($final['books']['overview']['books'], $final['books']['rankings']['popular'], $final['engine']['scores']['popularity'], $final['generatedAt']));

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------

echo $section('RESULT');

echo '  Passed: ' . ($checks - $failures) . PHP_EOL;
echo '  Failed: ' . $failures . PHP_EOL;

echo PHP_EOL . 'Note: the throwaway database database/admin_analytics_test.db is left in' . PHP_EOL
    . 'place for inspection; delete it anytime.' . PHP_EOL . PHP_EOL;

exit($failures > 0 ? 1 : 0);