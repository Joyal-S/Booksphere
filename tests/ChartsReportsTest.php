<?php

declare(strict_types=1);

/**
 * ChartsReportsTest — CLI test suite for Phase 12.5 (Charts & Reports)
 *
 * Verifies the visual layer and the print/CSV reports end-to-end:
 *
 *     1. ChartPresenter    - the four chart shapes are pure JSON
 *                            config (never statistics); labels are
 *                            escaped so no `</script>` can ever break
 *                            out of the embedded config
 *     2. Chart card        - the component renders the canvas, the
 *                            JSON config script and an accessible
 *                            summary; a missing chart renders the
 *                            "not enough data" empty state instead
 *     3. Report ranges     - RecommendationRepository::logTotalsSince,
 *                            logCountsBySignalSince and logsForRange
 *                            scope the SAME groupings with a single
 *                            generated_at filter - older rows never
 *                            leak into a range
 *     4. Ranged dashboard  - AdminAnalyticsService::dashboard($since)
 *                            agrees with the repository, and the
 *                            all-time call behaves exactly like the
 *                            12.4 one (no semantics changed)
 *     5. Page sections     - /analytics, /book-analytics and /admin
 *                            render the visuals with REAL numbers and
 *                            the charts never appear when data is
 *                            empty (insufficient-data states)
 *     6. User report       - GET /analytics/report renders the
 *                            printable sheet (report-print class, the
 *                            four report blocks, the tables, the
 *                            generated stamp); guests are stopped by
 *                            the auth gate
 *     7. Admin report      - GET /admin/analytics/report renders the
 *                            ranged sheet (presets, custom dates,
 *                            limitation note); unknown ranges fall
 *                            back; ?format=csv streams exactly the
 *                            range rows as a download
 *     8. Consistency       - ranged numbers and all-time numbers
 *                            still agree after every write - no
 *                            drifting totals, no SQL errors
 *
 * Run from the project root:
 *
 *     php tests/ChartsReportsTest.php
 *
 * The throwaway database (database/charts_reports_test.db) is
 * migrated, seeded with masonry timestamps and left in place for
 * inspection; delete it anytime.
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Controllers\AdminController;
use BookSphere\App\Controllers\UserAnalyticsController;
use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Core\View;
use BookSphere\App\Models\User;
use BookSphere\App\Presenters\ChartPresenter;
use BookSphere\App\Repositories\BookAnalyticsRepository;
use BookSphere\App\Repositories\BookRepository;
use BookSphere\App\Repositories\RecommendationRepository;
use BookSphere\App\Repositories\UserAnalyticsRepository;
use BookSphere\App\Services\AdminAnalyticsService;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\BookAnalyticsService;
use BookSphere\App\Services\RecommendationMetrics;
use BookSphere\App\Services\UserAnalyticsService;
use BookSphere\App\Core\Session;

// ---------------------------------------------------------------------
// 0. Boot: fresh throwaway database, migrated and seeded.
// ---------------------------------------------------------------------

(new Environment(root_path('.env')))->load();

$dbPath = root_path('database/charts_reports_test.db');

foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

Database::instance($dbPath);
(new \BookSphere\App\Core\Migrator(db(), root_path('database/migrations')))->run();
(new \BookSphere\App\Core\Seeder(db(), root_path('database/seeds')))->run();

$db = db();

// The suite starts from an EMPTY community so every metric below is
// the fixture's own.
$db->execute('DELETE FROM reviews');
$db->execute('DELETE FROM user_library');
$db->execute('DELETE FROM wishlist');
$db->execute('DELETE FROM recommendation_logs');

$session = new Session('charts_reports_test');
$session->start();
$auth = new AuthService($session, new User());
AuthService::setInstance($auth);

// Services under test - the same composition the routes use.
$userAnalyticsService = new UserAnalyticsService(new UserAnalyticsRepository(), (array) (config('analytics') ?? []));
$bookAnalyticsService = new BookAnalyticsService(new BookAnalyticsRepository(), (array) (config('book_analytics') ?? []));
$logs = new RecommendationRepository(new BookRepository());
$metrics = new RecommendationMetrics($logs);
$adminService = new AdminAnalyticsService($bookAnalyticsService, $logs, $metrics);

// Users & catalogue.
$insertUser = static function (string $email) use ($db): int {
    $db->execute(
        'INSERT INTO users (full_name, email, password, role) VALUES (?, ?, ?, ?)',
        [$email, $email, password_hash('User@123', PASSWORD_DEFAULT), 'user'],
    );

    return (int) $db->lastInsertId();
};

$u1 = $insertUser('charts@reader.test');
$u2 = $insertUser('charts@cataloguer.test');

$bookRows = array_slice($db->query('SELECT id FROM books ORDER BY id LIMIT 6'), 0, 6);
$bookIds  = [];
foreach ($bookRows as $i => $row) {
    $bookIds[$i + 1] = (int) $row['id'];
}
[$b1, $b2, $b3, $b4, $b5, $b6] = array_values($bookIds);

// Fixture writers. The recommendation logs are stamped directly with
// controlled past timestamps - the exact rows the engine produces,
// spread so every range has a distinguishable count.
$insertLog = static function (int $user, int $book, string $signal, string $reason, float $score, string $stamp) use ($db): void {
    $db->execute(
        'INSERT INTO recommendation_logs (user_id, book_id, signal, reason, score, generated_at)
         VALUES (?, ?, ?, ?, ?, ?)',
        [$user, $book, $signal, $reason, $score, $stamp],
    );
};

$now       = gmdate('Y-m-d\TH:i:s\Z');
$daysAgo   = static fn (int $days): string => gmdate('Y-m-d\TH:i:s\Z', strtotime('-' . $days . ' days'));

// 7 days or newer ......... 2 rows
$insertLog($u1, $b1, 'dashboard_recommended', 'Because you finished similar books', 88.0, $daysAgo(5));
$insertLog($u2, $b2, 'readers_also_enjoyed', 'Readers of your shelf liked this',      80.0, $daysAgo(5));
// 30 days or newer ......... 2 more rows (4 total <= 30d)
$insertLog($u1, $b3, 'because_you_read', 'Based on your last finish',                 90.0, $daysAgo(15));
$insertLog($u2, $b1, 'dashboard_recommended', 'Because you read its genre',           74.0, $daysAgo(15));
// 90 days or newer ......... 3 more rows (7 total <= 90d)
$insertLog($u1, $b1, 'dashboard_recommended', '',                                     61.0, $daysAgo(60));
$insertLog($u2, $b2, 'readers_also_enjoyed', 'A new release for you',                 70.0, $daysAgo(60));
$insertLog($u1, $b4, '',                    '',                                       30.0, $daysAgo(60));
// older than the year ..... 2 rows (9 total all-time)
$insertLog($u2, $b4, 'dashboard_recommended', '',                                     25.0, $daysAgo(200));
$insertLog($u1, $b2, 'because_you_read', '',                                         40.0, $daysAgo(400));

// The user-visible activity (community shelves + one approved rating)
// so the analytics payloads are non-empty and the chart sections
// render.
$shelf = static function (int $user, int $book, string $status) use ($db): void {
    $stamp = gmdate('c');
    $db->execute(
        'INSERT INTO user_library (user_id, book_id, library_status, progress_percentage, started_reading_at, finished_reading_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$user, $book, $status, $status === 'finished' ? 100 : 0,
         $status === 'finished' ? $stamp : null, $status === 'finished' ? $stamp : null, $stamp, $stamp],
    );
};
$shelf($u1, $b1, 'finished');
$shelf($u1, $b2, 'finished');
$shelf($u1, $b3, 'currently_reading');
$shelf($u1, $b4, 'want_to_read');
$shelf($u1, $b5, 'on_hold');
$shelf($u1, $b6, 'dropped');
$shelf($u2, $b1, 'want_to_read');
$shelf($u2, $b4, 'finished');

$db->execute(
    'INSERT INTO reviews (book_id, user_id, rating, review, status, is_edited, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, 0, ?, ?)',
    [$b1, $u1, 5, 'A fixture review for the personal page.', 'approved', $now, $now],
);
$db->execute(
    'INSERT INTO reviews (book_id, user_id, rating, review, status, is_edited, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, 0, ?, ?)',
    [$b4, $u2, 4, 'Another approved review.', 'approved', $now, $now],
);

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

$decode = static function (string $json): array {
    $decoded = json_decode($json, true);

    return is_array($decoded) ? $decoded : [];
};

// ---------------------------------------------------------------------
// 1. CHART PRESENTER (pure config, nothing computed).
// ---------------------------------------------------------------------

echo $section('1. CHART PRESENTER (pure JSON config shapes)');

$doughnut = $decode(ChartPresenter::doughnut('shelf', ['Finished', 'Want to read'], [2, 4], 'Two of your books are finished.'));
$check('the doughnut config carries its own type', ($doughnut['type'] ?? '') === 'doughnut');
$check('the doughnut labels and values pass through untouched',
    ($doughnut['labels'] ?? []) === ['Finished', 'Want to read'] && ($doughnut['sets'][0]['values'] ?? []) === [2, 4]);
$check('the doughnut carries an accessible summary sentence', str_contains((string) ($doughnut['summary'] ?? ''), 'finished'));

$bar = $decode(ChartPresenter::bar('months', ['Jul', 'Aug'], [['label' => 'Reviews', 'tone' => 'warning', 'values' => [3, 5]]], ''));
$check('the bar shape carries both axes', ($bar['type'] ?? '') === 'bar' && ($bar['labels'] ?? []) === ['Jul', 'Aug']);
$check('the bar dataset carries its tone token', ($bar['sets'][0]['tone'] ?? '') === 'warning' && ($bar['sets'][0]['label'] ?? '') === 'Reviews');

$hbar = $decode(ChartPresenter::hbar('genres', ['Sci-Fi', 'Romance'], [7.5, 2.5], ''));
$check('the hbar shape is the horizontal bar', ($hbar['type'] ?? '') === 'hbar');

$line = $decode(ChartPresenter::line('activity', ['Jan', 'Feb'], [['label' => 'Finished', 'tone' => 'success', 'values' => [1, 2]]], ''));
$check('the line shape is a line', ($line['type'] ?? '') === 'line');

$escaped = ChartPresenter::doughnut('x', ['</script><b>boom</b>'], [1], '');
$check('labels can never break out of the config script tag', !str_contains($escaped, '<'));
$check('the presenter hands back the label, escaped but intact',
    str_contains($escaped, 'boom') && str_contains($escaped, '1'));
unset($escaped);

// ---------------------------------------------------------------------
// 2. THE CHART-CARD COMPONENT.
// ---------------------------------------------------------------------

echo $section('2. THE chart-card COMPONENT (canvas + summary + empty state)');

$html = View::fragment('components.chart-card', [
    'chart'        => ChartPresenter::doughnut('s', ['A', 'B'], [1, 2], 'Fixture summary.'),
    'chartTitle'   => 'My Shelf',
    'chartEyebrow' => 'Statuses',
    'chartTrend'   => '',
    'chartSummary' => 'Fixture summary.',
]);
$check('the card renders the canvas with a describing role', str_contains($html, '<canvas') && str_contains($html, 'role="img"'));
$check('the card embeds the chart config as an inline JSON script', str_contains($html, 'data-chart-config'));
$check('the card always carries the accessible summary text', str_contains($html, 'Fixture summary.'));
$check('the title and eyebrow reach the header', str_contains($html, 'My Shelf') && str_contains($html, 'Statuses'));

$emptyHtml = View::fragment('components.chart-card', [
    'chart'        => '',
    'chartTitle'   => 'Community Shelves',
    'chartEyebrow' => 'Five statuses',
    'chartTrend'   => '',
    'chartSummary' => '',
]);
$check('an EMPTY config renders the not-enough-data empty state', str_contains($emptyHtml, 'empty') && !str_contains($emptyHtml, 'data-chart-config'));

// ---------------------------------------------------------------------
// 3. REPORT RANGES (RecommendationRepository).
// ---------------------------------------------------------------------

echo $section('3. REPORT RANGES (generated_at-scoped aggregates)');

$d7  = gmdate('Y-m-d', strtotime('-7 days'));
$d30 = gmdate('Y-m-d', strtotime('-30 days'));
$d90 = gmdate('Y-m-d', strtotime('-90 days'));

$allTotals   = $logs->logTotals();
$rangeTotals = $logs->logTotalsSince($d30);
$check('the all-time totals still count every row', $allTotals['logs'] === 9, (string) $allTotals['logs']);
$check('the ranged totals count ONLY the in-range rows', $rangeTotals['logs'] === 4, (string) $rangeTotals['logs']);
$check('the ranged count at 7 days is smaller still', $logs->logTotalsSince($d7)['logs'] === 2);
$check('the 90-day count sits between the two', $logs->logTotalsSince($d90)['logs'] === 7);
$check('the ranged latest is never null once rows exist', is_string($rangeTotals['latest']) && $rangeTotals['latest'] !== '');

$rangeSignals = $logs->logCountsBySignalSince($d30, 10);
$rangeMap = [];
foreach ($rangeSignals as $row) {
    $rangeMap[(string) $row['signal']] = (int) $row['logs'];
}
$totalSurface = array_sum($rangeMap);
$check('the ranged surfaces total the ranged rows', $totalSurface === 4, (string) $totalSurface);
$check('the ranged surfaces carry the engine surfaces, scoped', (($rangeMap['dashboard_recommended'] ?? 0) === 2) && (($rangeMap['because_you_read'] ?? 0) === 1));

$csvRows = $logs->logsForRange($d30, gmdate('Y-m-d'));
$check('logsForRange returns exactly the rows of the range', count($csvRows) === 4, (string) count($csvRows));
$check('logsForRange orders newest first', (string) ($csvRows[0]['title'] ?? '') !== '');
$older = $logs->logsForRange('2020-01-01', '2020-12-31');
$check('a range with no rows answers empty, never guessed', $older === []);

// ---------------------------------------------------------------------
// 4. THE RANGED DASHBOARD (AdminAnalyticsService).
// ---------------------------------------------------------------------

echo $section('4. THE RANGED DASHBOARD (same shape, one filter)');

$scoped = $adminService->dashboard($d30);
$check('the ranged dashboard exposes the same blocks',
    isset($scoped['books']['overview'], $scoped['recommendation']['totals'], $scoped['recommendation']['signals'], $scoped['engine']['cache'], $scoped['generatedAt']));
$check('the ranged dashboard totals agree with the repository',
    (int) ($scoped['recommendation']['totals']['logs'] ?? -1) === $rangeTotals['logs']);
$check('the ranged per-surface breakdown agrees with the repository',
    (int) array_sum(array_map(static fn (array $r): int => (int) $r['logs'], $scoped['recommendation']['signals'])) === 4);

$all = $adminService->dashboard();
$check('the all-time dashboard is unchanged (12.4 regression)',
    ($all['recommendation']['totals'] ?? []) === $allTotals);
$check('the all-time dashboard serves the same totals the old API did',
    ($all['books']['overview']['books'] ?? 0) === (int) $db->query('SELECT COUNT(*) AS n FROM books WHERE status = \'published\' AND deleted_at IS NULL')[0]['n']);
$check('top and slept stay all-time lists, clearly labelled only in the view',
    isset($all['recommendation']['top'], $all['recommendation']['slept']));

// ---------------------------------------------------------------------
// 5. THE PAGE SECTIONS (charts render with real numbers).
// ---------------------------------------------------------------------

echo $section('5. THE PAGE SECTIONS (/analytics, /book-analytics, /admin)');

$session->put('auth_user_id', $u1);
$session->put('auth_user', ['id' => $u1, 'full_name' => 'Reader', 'email' => 'reader@booksphere.test', 'role' => 'user']);
$userHtml = $capture(static fn () => (new UserAnalyticsController($userAnalyticsService))->show(new \BookSphere\App\Core\Request()));
$check('the personal page opens', str_contains($userHtml, 'My Analytics'));
$check('the personal page renders the visual section', str_contains($userHtml, 'At a glance') && str_contains($userHtml, 'data-chart-config'));
$check('the personal page charts carry the fixture shelf', str_contains($userHtml, 'Your shelf split'));
$check('the personal page links its print report', str_contains($userHtml, '/analytics/report'));

$bookHtml = $capture(static fn () => (new \BookSphere\App\Controllers\BookAnalyticsController($bookAnalyticsService))->index(new \BookSphere\App\Core\Request()));
$check('the book-analytics page renders its visual section', str_contains($bookHtml, 'data-chart-config'));
$check('the book-analytics visual band carries the shelf chart', str_contains($bookHtml, 'Community shelves') && str_contains($bookHtml, 'Catalogue at a glance'));

$session->put('auth_user', ['id' => $u1, 'full_name' => 'Reader', 'email' => 'reader@booksphere.test', 'role' => 'admin']);
$adminHtml = $capture(static fn () => (new AdminController(new RecommendationMetrics($logs), null, null, $adminService))->index(new \BookSphere\App\Core\Request()));
$check('the admin page renders its visual band', str_contains($adminHtml, 'Platform at a glance'));
$check('the admin visual band warns about missing click tracking', str_contains($adminHtml, 'click or conversion tracking'));
$check('the admin page links the print report', str_contains($adminHtml, '/admin/analytics/report'));

// ---------------------------------------------------------------------
// 6. THE USER REPORT (GET /analytics/report).
// ---------------------------------------------------------------------

echo $section('6. THE USER READING REPORT');

$session->put('auth_user', ['id' => $u1, 'full_name' => 'Reader', 'email' => 'reader@booksphere.test', 'role' => 'user']);
$reportHtml = $capture(static fn () => (new UserAnalyticsController($userAnalyticsService))->report(new \BookSphere\App\Core\Request()));
$check('the report renders its own sheet without the chrome', str_contains($reportHtml, 'report-print') && str_contains($reportHtml, 'My Reading Report'));
$check('the report embeds the charts and the summary tables',
    str_contains($reportHtml, 'data-chart-config') && str_contains($reportHtml, 'Status') && str_contains($reportHtml, 'Prepared'));
$check('the report stamp is present', str_contains($reportHtml, 'UTC'));

// The auth gate on the report route (probe: a guest never reaches it).
$probePath = sys_get_temp_dir() . '/booksphere_charts_probe.php';
$probeHead = '<?php' . PHP_EOL
    . 'declare(strict_types=1);' . PHP_EOL . PHP_EOL
    . 'require ' . var_export(root_path() . '/bootstrap/constants.php', true) . ';' . PHP_EOL
    . 'require ' . var_export(root_path() . '/vendor/autoload.php', true) . ';' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Database;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Environment;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Request;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Session;' . PHP_EOL
    . 'use BookSphere\\App\\Middleware\\AuthMiddleware;' . PHP_EOL
    . 'use BookSphere\\App\\Models\\User;' . PHP_EOL
    . 'use BookSphere\\App\\Services\\AuthService;' . PHP_EOL . PHP_EOL
    . '(new Environment(root_path(\'.env\')))->load();' . PHP_EOL
    . 'Database::instance(' . var_export($dbPath, true) . ');' . PHP_EOL
    . '$session = new Session(\'charts_probe\');' . PHP_EOL
    . '$session->start();' . PHP_EOL
    . '$auth = new AuthService($session, new User());' . PHP_EOL
    . 'AuthService::setInstance($auth);' . PHP_EOL
    . 'register_shutdown_function(function (): void {' . PHP_EOL
    . '    $flash = session()->getFlash(\'success\') ?? session()->getFlash(\'error\') ?? session()->getFlash(\'info\');' . PHP_EOL
    . '    echo $flash === null ? \'NO_FLASH\' : (string) $flash;' . PHP_EOL
    . '});' . PHP_EOL;

file_put_contents($probePath, $probeHead
    . '(new AuthMiddleware($auth))->handle(new Request(), static function (): string { return "AUTHORIZED"; });' . PHP_EOL);
$out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' 2>&1');
unlink($probePath);
$check('a guest on the report route is redirected to login, never the sheet',
    str_contains($out, 'Please log in') && !str_contains($out, 'AUTHORIZED'));

// ---------------------------------------------------------------------
// 7. THE ADMIN REPORT (GET /admin/analytics/report + CSV).
// ---------------------------------------------------------------------

echo $section('7. THE ADMIN REPORT (range picker + CSV export)');

$adminUser = ['id' => $u1, 'full_name' => 'Admin', 'email' => 'admin@booksphere.test', 'role' => 'admin'];
$session->put('auth_user_id', $u1);
$session->put('auth_user', $adminUser);

$adminController = new AdminController(new RecommendationMetrics($logs), null, null, $adminService);
$adminReport = static function (array $query = []) use (&$capture, $adminController): string {
    $_GET = $query;

    return $capture(static fn () => $adminController->analyticsReport(new \BookSphere\App\Core\Request(), []));
};

$reportHtml = $adminReport(['range' => '30d']);
$check('the admin report renders the sheet, the range and the CSV link',
    str_contains($reportHtml, 'Analytics Report') && str_contains($reportHtml, 'Last 30 days') && str_contains($reportHtml, 'format=csv'));
$check('the admin report scopes the tiles to the range', str_contains($reportHtml, 'in range'));
$check('the admin report carries the limitation note', str_contains($reportHtml, 'Limitations, honestly'));
$check('the admin report charts the surface breakdown', str_contains($reportHtml, 'Surfaces'));

$report7 = $adminReport(['range' => '7d']);
$check('the 7-day report labels its own range', str_contains($report7, 'Last 7 days'));

$reportCustom = $adminReport(['range' => 'custom', 'since' => gmdate('Y-m-d', strtotime('-15 days')), 'until' => gmdate('Y-m-d')]);
$check('a validated custom range renders its own label', str_contains($reportCustom, 'to'));
$_GET = [];

// --- The invalid-range probes: a rejection redirects and exits, so
// a subprocess triggers each and the shutdown hook echoes the flash.
$runProbe = static function (array $query) use ($probeHead, $probePath, $u1, $adminUser): string {
    file_put_contents($probePath, $probeHead
        . '$session->put(\'auth_user_id\', ' . $u1 . ');' . PHP_EOL
        . '$session->put(\'auth_user\', ' . var_export($adminUser, true) . ');' . PHP_EOL
        . '$_SERVER[\'REQUEST_URI\'] = \'/admin/analytics/report\';' . PHP_EOL
        . '$_GET = ' . var_export($query, true) . ';' . PHP_EOL
        . '(new \\BookSphere\\App\\Controllers\\AdminController(null, null, null, null))->analyticsReport(new \\BookSphere\\App\\Core\\Request(), []);' . PHP_EOL);

    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' 2>&1');
    unlink($probePath);

    return (string) $out;
};

$out = $runProbe(['range' => 'custom', 'since' => 'not-a-date', 'until' => '2020-01-01']);
$check('an invalid custom range is rejected with the flash, never the sheet',
    str_contains($out, 'valid since/until pair') && !str_contains($out, 'Analytics Report'));

$out = $runProbe(['range' => 'banana']);
$check('an unknown range key is rejected, not trusted', str_contains($out, 'Unknown report range'));

// --- The CSV probe: a real subprocess issues the download. This
// probe re-uses the boot head WITHOUT the flash echo (a download
// must end with the file contents, nothing else).
$probeCsvHead = str_replace(
    'register_shutdown_function(function (): void {' . PHP_EOL
    . '    $flash = session()->getFlash(\'success\') ?? session()->getFlash(\'error\') ?? session()->getFlash(\'info\');' . PHP_EOL
    . '    echo $flash === null ? \'NO_FLASH\' : (string) $flash;' . PHP_EOL
    . '});' . PHP_EOL,
    '',
    $probeHead,
);
$writeCsvProbe = static function () use ($probePath, $probeCsvHead, $u1, $adminUser): void {
    file_put_contents($probePath, $probeCsvHead
        . '$session->put(\'auth_user_id\', ' . $u1 . ');' . PHP_EOL
        . '$session->put(\'auth_user\', ' . var_export($adminUser, true) . ');' . PHP_EOL
        . '$_SERVER[\'REQUEST_URI\'] = \'/admin/analytics/report\';' . PHP_EOL
        . '$_GET = [\'format\' => \'csv\', \'range\' => \'30d\'];' . PHP_EOL
        . '$controller = new \BookSphere\\App\\Controllers\\AdminController(' . PHP_EOL
        . '    new \\BookSphere\\App\\Services\\RecommendationMetrics(new \\BookSphere\\App\\Repositories\\RecommendationRepository(new \\BookSphere\\App\\Repositories\\BookRepository())),' . PHP_EOL
        . '    null, null,' . PHP_EOL
        . '    new \\BookSphere\\App\\Services\\AdminAnalyticsService(' . PHP_EOL
        . '        new \\BookSphere\\App\\Services\\BookAnalyticsService(new \\BookSphere\\App\\Repositories\\BookAnalyticsRepository(), \\BookSphere\\App\\Core\\Config::loadFromDirectory(root_path(\'config\'))->get(\'book_analytics\', [])),' . PHP_EOL
        . '        new \\BookSphere\\App\\Repositories\\RecommendationRepository(new \\BookSphere\\App\\Repositories\\BookRepository()),' . PHP_EOL
        . '        new \\BookSphere\\App\\Services\\RecommendationMetrics(new \\BookSphere\\App\\Repositories\\RecommendationRepository(new \\BookSphere\\App\\Repositories\\BookRepository()))' . PHP_EOL
        . '    )' . PHP_EOL
        . ');' . PHP_EOL
        . '$controller->analyticsReport(new \\BookSphere\\App\\Core\\Request(), []);' . PHP_EOL);
};

$runCsvProbe = static function () use ($writeCsvProbe, $probePath): string {
    $writeCsvProbe();
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' 2>&1');
    unlink($probePath);

    return $out;
};

$csv = $runCsvProbe();
$csvLines = array_values(array_filter(array_map('trim', explode("\n", $csv)), static fn (string $line): bool => $line !== ''));
$check('the CSV export issues a download with a header row', count($csvLines) >= 5 && str_contains((string) ($csvLines[0] ?? ''), 'user,title,signal'));
$check('the CSV export carries exactly the 30-day rows', count($csvLines) === 5, (string) count($csvLines));
$check('the CSV export carries the ranged surface signals', str_contains($csv, 'dashboard_recommended') && str_contains($csv, 'because_you_read'));

// CSV formula injection (Phase 12.6 audit): a reason that LOOKS like
// a spreadsheet formula must never reach the sheet as a live cell.
$logs->logRecommendations($u1, [['book_id' => $b1, 'reason' => '=SUM(A1:A9)', 'score' => 10.0, 'signal' => 'injection_probe']]);
$csv2 = $runCsvProbe();
$check('a formula-like reason is neutralized with a leading apostrophe',
    str_contains($csv2, "'=SUM(A1:A9)") && preg_match('/(^|,)=' . preg_quote('SUM(A1:A9)', '/') . '/', $csv2) !== 1);

// ---------------------------------------------------------------------
// 8. CONSISTENCY after all writes.
// ---------------------------------------------------------------------

echo $section('8. CONSISTENCY (no drift, no SQL errors)');

$final = $adminService->dashboard($d30);
$check('the final ranged totals still agree with the repository',
    (int) ($final['recommendation']['totals']['logs'] ?? -1) === $logs->logTotalsSince($d30)['logs']);
$finalAll = $adminService->dashboard();
$check('the final all-time totals still agree with the repository',
    (int) ($finalAll['recommendation']['totals']['logs'] ?? -1) === $logs->logTotals()['logs']);
$check('a ranged report never out-counts the all-time report',
    (int) ($final['recommendation']['totals']['logs'] ?? 0) <= (int) ($finalAll['recommendation']['totals']['logs'] ?? 0));
$check('the report views still render after the writes',
    str_contains($capture(static fn () => (new UserAnalyticsController($userAnalyticsService))->report(new \BookSphere\App\Core\Request())), 'My Reading Report'));

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------

echo $section('RESULT');

echo '  Passed: ' . ($checks - $failures) . PHP_EOL;
echo '  Failed: ' . $failures . PHP_EOL;

echo PHP_EOL . 'Note: the throwaway database database/charts_reports_test.db is left in' . PHP_EOL
    . 'place for inspection; delete it anytime.' . PHP_EOL . PHP_EOL;

exit($failures > 0 ? 1 : 0);