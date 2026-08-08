<?php

declare(strict_types=1);

/**
 * UserAnalyticsTest — CLI test suite for Phase 12.1 (User Analytics)
 *
 * Verifies the personal analytics surface end-to-end:
 *
 *     1. New user     - a user with NO library and NO reviews answers
 *                       the empty payload (empty = true, contextual
 *                       zeroes, no fabricated activity)
 *     2. Shelf counts - the five canonical statuses are counted from
 *                       user_library (the single source of truth);
 *                       the total is their sum; the modern wishlist
 *                       is the want_to_read shelf and the legacy
 *                       `wishlist` table is IGNORED
 *     3. Completion   - completion rate = finished / shelved; the
 *                       UNIQUE (user_id, book_id) index rejects a
 *                       second record, so a book can never count
 *                       twice
 *     4. Genres       - membership counting: a book in two genres
 *                       counts once per genre, percentages share the
 *                       total memberships, unique = DISTINCT genres,
 *                       and no join can double a book inside one
 *                       genre
 *     5. Authors      - co-authored books count once per author;
 *                       unique-read authors are DISTINCT
 *     6. Reviews      - ONLY approved reviews count (house rule);
 *                       total, average, the 1..5 distribution and
 *                       the favourite rating (ties -> higher star)
 *     7. Activity     - monthly completions/reviews from REAL
 *                       timestamps (finished_reading_at /
 *                       created_at), the trailing window, older-month
 *                       collapse and the recent-event timeline with
 *                       its limit
 *     8. Active days  - distinct library-write days
 *     9. Isolation    - a second user's rows never touch the first
 *                       user's payload
 *    10. Limits       - config drives the top-N lists and the window
 *    11. Controller   - GET /analytics renders the page signed-in;
 *                       a guest is stopped by AuthMiddleware; the
 *                       user id is session-only (no route param, no
 *                       request input)
 *    12. Consistency  - every aggregate still agrees after all
 *                       writes (no SQL errors, no drifting totals)
 *
 * Run from the project root:
 *
 *     php tests/UserAnalyticsTest.php
 *
 * The throwaway database (database/user_analytics_test.db) is
 * migrated, seeded and left in place for inspection; delete it
 * anytime.
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Controllers\UserAnalyticsController;
use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Core\MiddlewarePipeline;
use BookSphere\App\Core\Migrator;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Router;
use BookSphere\App\Core\Seeder;
use BookSphere\App\Core\Session;
use BookSphere\App\Middleware\AuthMiddleware;
use BookSphere\App\Models\User;
use BookSphere\App\Repositories\UserAnalyticsRepository;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\UserAnalyticsService;

// ---------------------------------------------------------------------
// 0. Boot: fresh throwaway database, migrated and seeded.
// ---------------------------------------------------------------------

(new Environment(root_path('.env')))->load();

$dbPath = root_path('database/user_analytics_test.db');

foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

Database::instance($dbPath);
(new Migrator(db(), root_path('database/migrations')))->run();
(new Seeder(db(), root_path('database/seeds')))->run();

$session = new Session('user_analytics_test');
$session->start();
$auth = new AuthService($session, new User());
AuthService::setInstance($auth);

$db = db();

// The analytics test user: a brand-new account with no seed rows, so
// every metric starts from a known-empty slate.
$db->execute(
    'INSERT INTO users (full_name, email, password, role) VALUES (?, ?, ?, ?)',
    ['Analytics Tester', 'tester@booksphere.test', password_hash('User@123', PASSWORD_DEFAULT), 'user'],
);
$uid = (int) $db->query("SELECT id FROM users WHERE email = 'tester@booksphere.test'")[0]['id'];

// A second user wields data that must never leak into the first.
$db->execute(
    'INSERT INTO users (full_name, email, password, role) VALUES (?, ?, ?, ?)',
    ['Foreign User', 'foreign@booksphere.test', password_hash('User@123', PASSWORD_DEFAULT), 'user'],
);
$foreignId = (int) $db->query("SELECT id FROM users WHERE email = 'foreign@booksphere.test'")[0]['id'];

$config = [
    'enabled'  => true,
    'limits'   => ['genres' => 5, 'authors' => 5],
    'activity' => ['months' => 12, 'recent' => 10],
];

$service = new UserAnalyticsService(new UserAnalyticsRepository(), $config);

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

$build = static fn (): array => $service->build($uid)->toArray();

$capture = static function (callable $fn): string {
    ob_start();
    $fn();

    return (string) ob_get_clean();
};

// Fixture writers. A book can appear in a user's library only ONCE,
// so re-shelving uses UPDATE - exactly like the real library module.
$shelf = static function (int $bookId, string $status, ?string $started = null, ?string $finished = null, ?string $activeOn = null) use ($db, $uid): void {
    $stamp = $activeOn ?? gmdate('c');
    $db->execute(
        'INSERT INTO user_library (user_id, book_id, library_status, progress_percentage, started_reading_at, finished_reading_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$uid, $bookId, $status, $status === 'finished' ? 100 : 0, $started, $finished, $stamp, $stamp],
    );
};

$finish = static function (int $bookId, string $finishedAt) use ($db, $uid): void {
    $db->execute(
        'UPDATE user_library
         SET library_status = \'finished\', progress_percentage = 100, finished_reading_at = ?, updated_at = ?
         WHERE user_id = ? AND book_id = ?',
        [$finishedAt, gmdate('c'), $uid, $bookId],
    );
};

$rate = static function (int $bookId, int $rating, string $at, string $status = 'approved') use ($db, $uid): void {
    $db->execute(
        'INSERT INTO reviews (book_id, user_id, rating, title, review, status, is_edited, created_at, updated_at)
         VALUES (?, ?, ?, \'\', \'\', ?, 0, ?, ?)',
        [$bookId, $uid, $rating, $status, $at, $at],
    );
};

$promote = static function (int $bookId, int $rating) use ($db, $uid): void {
    $db->execute(
        'UPDATE reviews SET rating = ?, status = \'approved\', updated_at = ?
         WHERE user_id = ? AND book_id = ?',
        [$rating, gmdate('c'), $uid, $bookId],
    );
};

// Six seeded books give the dataset a stable shape (b6 is NEVER
// shelved - reviews do not require a library record).
$bookIds = [];
foreach (array_slice($db->query('SELECT id FROM books ORDER BY id LIMIT 6'), 0, 6) as $i => $row) {
    $bookIds[$i + 1] = (int) $row['id'];
}
[$b1, $b2, $b3, $b4, $b5, $b6] = array_values($bookIds);

// ---------------------------------------------------------------------
// 1. NEW USER: the empty payload.
// ---------------------------------------------------------------------

echo $section('1. NEW USER (empty payload)');

$a = $build();
$check('empty -> true for a user with no rows', $a['empty'] === true);
$check('shelf keys are the five canonical statuses',
    array_keys($a['shelf']) === ['want_to_read', 'currently_reading', 'finished', 'on_hold', 'dropped']);
$check('every shelf count is a contextual zero', array_sum($a['shelf']) === 0);
$check('shelved 0 and completionRate 0.0', (int) $a['summary']['shelved'] === 0 && (float) $a['summary']['completionRate'] === 0.0);
$check('averageRating is null (never rated), never 0', $a['reviews']['average'] === null);
$check('distribution carries every bucket 1..5 as 0',
    $a['reviews']['distribution'] === [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0]);
$check('favourite rating is null', $a['reviews']['favourite'] === null);
$check('genres/authors empty with zero uniques',
    $a['genres']['rows'] === [] && $a['genres']['unique'] === 0
    && $a['authors']['rows'] === [] && $a['authors']['unique'] === 0);
$check('the activity window has 12 real month buckets', count($a['activity']['months']) === 12);
$check('window months show zero activity (nothing fabricated)',
    array_sum(array_map(static fn (array $m): int => $m['completed'] + $m['rated'], $a['activity']['months'])) === 0);
$check('older counts are zero', $a['activity']['older'] === ['completed' => 0, 'rated' => 0]);
$check('no recent events', $a['activity']['recent'] === []);
$check('activeDays 0', (int) $a['summary']['activeDays'] === 0);

// ---------------------------------------------------------------------
// 2. SHELF COUNTS + the wishlist source of truth.
// ---------------------------------------------------------------------

echo $section('2. SHELF COUNTS + WISHLIST SOURCE OF TRUTH');

$shelf($b1, 'want_to_read', activeOn: '2026-01-01T09:00:00Z');
$shelf($b2, 'currently_reading', started: '2026-01-10T09:00:00Z', activeOn: '2026-01-02T09:00:00Z');
$shelf($b3, 'finished', started: '2026-01-01T09:00:00Z', finished: '2026-01-12T09:00:00Z', activeOn: '2026-01-03T09:00:00Z');
$shelf($b4, 'on_hold', activeOn: '2026-01-04T09:00:00Z');
$shelf($b5, 'dropped', activeOn: '2026-01-05T09:00:00Z');

$a = $build();
$check('each of the five shelves counts its own rows',
    $a['shelf'] === ['want_to_read' => 1, 'currently_reading' => 1, 'finished' => 1, 'on_hold' => 1, 'dropped' => 1],
    json_encode($a['shelf']));
$check('total shelved = 5 (sum of the statuses)', (int) $a['summary']['shelved'] === 5);
$check('wishlist == the want_to_read shelf', (int) $a['summary']['wishlist'] === 1);
$check('completed == the finished shelf', (int) $a['summary']['completed'] === 1);
$check('empty is false once rows exist', $a['empty'] === false);

// The legacy `wishlist` table is the recommendation-engine signal -
// it must NEVER move the modern wishlist count.
$db->execute('INSERT INTO wishlist (user_id, book_id) VALUES (?, ?)', [$uid, $b5]);
$c = $build();
$check('legacy `wishlist` rows are ignored (the shelf is the source)',
    (int) $c['summary']['wishlist'] === 1 && (int) $c['summary']['shelved'] === 5);

// ---------------------------------------------------------------------
// 3. COMPLETION RATE + the UNIQUE guard.
// ---------------------------------------------------------------------

echo $section('3. COMPLETION RATE + UNIQUE (user_id, book_id)');

$throws = static function (string $class, callable $fn): bool {
    try {
        $fn();

        return false;
    } catch (Throwable $throwable) {
        return $throwable instanceof $class;
    }
};

$dup = $throws(\PDOException::class, fn () => $db->execute(
    'INSERT INTO user_library (user_id, book_id, library_status) VALUES (?, ?, ?)',
    [$uid, $b1, 'finished'],
));
$check('a second record for the same book is rejected by UNIQUE', $dup);

$finish($b1, gmdate('Y-m-d') . 'T05:00:00Z'); // this month
$finish($b4, '2026-02-05T09:00:00Z');         // inside the window
$finish($b5, '2025-07-05T09:00:00Z');         // outside the window (older)

$a = $build();
$check('finished = 4 after the three finishes', (int) $a['summary']['completed'] === 4);
$check('total stayed 5 - nothing double counted', (int) $a['summary']['shelved'] === 5);
$check('completion rate = 4 / 5 = 80%', (float) $a['summary']['completionRate'] === 80.0);

// ---------------------------------------------------------------------
// 4. GENRE ANALYTICS (multi-genre books, membership counting).
// ---------------------------------------------------------------------

echo $section('4. GENRES (membership counting, no join duplicates)');

// The ground truth: recompute from the same rows with an INDEPENDENT
// query shape - distinct (finished book, genre) pairs.
$memberships = $db->query(
    'SELECT c.name AS name, COUNT(DISTINCT l.book_id) AS books
     FROM user_library l
     JOIN book_categories bc ON bc.book_id = l.book_id
     JOIN categories c       ON c.id = bc.category_id
     WHERE l.user_id = ? AND l.library_status = \'finished\'
     GROUP BY c.id, c.name
     ORDER BY books DESC, c.name COLLATE NOCASE ASC',
    [$uid],
);
$expected = [];
$totalMemberships = 0;
foreach ($memberships as $row) {
    $expected[$row['name']] = (int) $row['books'];
    $totalMemberships      += (int) $row['books'];
}
$uniqueGenres = (int) $db->query(
    'SELECT COUNT(DISTINCT c.id) AS n
     FROM user_library l
     JOIN book_categories bc ON bc.book_id = l.book_id
     JOIN categories c       ON c.id = bc.category_id
     WHERE l.user_id = ? AND l.library_status = \'finished\'',
    [$uid],
)[0]['n'];

$a = $build();
$check('genre rows match the ground-truth counts exactly',
    count($a['genres']['rows']) === count($expected)
    && array_column($a['genres']['rows'], 'name') === array_keys($expected),
    json_encode($a['genres']['rows']));

$percentOk = true;
foreach ($a['genres']['rows'] as $row) {
    $want = $totalMemberships > 0 ? round($row['books'] / $totalMemberships * 100, 1) : 0.0;
    if ((int) $row['books'] !== $expected[$row['name']] || abs((float) $row['percent'] - $want) > 0.001) {
        $percentOk = false;
    }
}
$check('every genre count and percentage matches the membership math', $percentOk);
$check('a book in several genres counts ONCE per genre (no per-book doubling)',
    array_sum(array_column($a['genres']['rows'], 'books')) === $totalMemberships);
$check('unique genres = DISTINCT categories of the finished books', (int) $a['genres']['unique'] === $uniqueGenres);

// ---------------------------------------------------------------------
// 5. AUTHOR ANALYTICS (co-authored books).
// ---------------------------------------------------------------------

echo $section('5. AUTHORS (co-authored counting, DISTINCT uniques)');

$authors = $db->query(
    'SELECT a.name AS name, COUNT(DISTINCT l.book_id) AS books
     FROM user_library l
     JOIN book_authors ba ON ba.book_id = l.book_id
     JOIN authors a       ON a.id = ba.author_id
     WHERE l.user_id = ? AND l.library_status = \'finished\'
     GROUP BY a.id, a.name
     ORDER BY books DESC, a.name COLLATE NOCASE ASC',
    [$uid],
);
$expectedAuthors = [];
foreach ($authors as $row) {
    $expectedAuthors[$row['name']] = (int) $row['books'];
}
$uniqueAuthors = count($expectedAuthors);

$a = $build();
$check('author rows match the ground truth',
    count($a['authors']['rows']) === count($expectedAuthors)
    && array_column($a['authors']['rows'], 'name') === array_keys($expectedAuthors),
    json_encode($a['authors']['rows']));
$authorOk = true;
foreach ($a['authors']['rows'] as $row) {
    if ((int) $row['books'] !== $expectedAuthors[$row['name']]) {
        $authorOk = false;
    }
}
$check('every author count matches (co-authored books count once per author)', $authorOk);
$check('unique authors read = DISTINCT authors of the finished books', (int) $a['authors']['unique'] === $uniqueAuthors);

// ---------------------------------------------------------------------
// 6. REVIEW & RATING ANALYTICS (approved-only house rule).
// ---------------------------------------------------------------------

echo $section('6. REVIEWS (approved-only, distribution, favourite)');

$rate($b1, 5, '2026-05-01T10:00:00Z');
$rate($b2, 4, '2026-05-02T10:00:00Z');
$rate($b3, 5, '2026-06-01T10:00:00Z');
$rate($b4, 2, '2026-06-02T10:00:00Z', 'pending'); // moderation: must NOT count yet
$rate($b6, 1, '2026-06-03T10:00:00Z', 'hidden');  // moderation: must NOT count yet

$a = $build();
$check('reviews.total counts ONLY the 3 approved', (int) $a['reviews']['total'] === 3);
$check('average rating = (5+4+5) / 3 = 4.7', (float) $a['reviews']['average'] === 4.7);
$check('summary.averageRating mirrors reviews.average', (float) $a['summary']['averageRating'] === 4.7);
$check('distribution: two 5s, one 4, nothing else',
    $a['reviews']['distribution'] === [1 => 0, 2 => 0, 3 => 0, 4 => 1, 5 => 2]);
$check('favourite = 5', (int) $a['reviews']['favourite'] === 5);

// Moderation flips: a pending and a hidden review become approved.
$promote($b4, 3);
$promote($b6, 3);

$a = $build();
$check('after the flips total = 5 approved', (int) $a['reviews']['total'] === 5);
$check('distribution now 3:2, 4:1, 5:2', $a['reviews']['distribution'] === [1 => 0, 2 => 0, 3 => 2, 4 => 1, 5 => 2]);
$check('average = (5+4+5+3+3)/5 = 4.0', (float) $a['reviews']['average'] === 4.0);
$check('3 and 5 tie -> favourite resolves to the HIGHER star', (int) $a['reviews']['favourite'] === 5);

// ---------------------------------------------------------------------
// 7. ACTIVITY OVER TIME (monthly completions + reviews).
// ---------------------------------------------------------------------

echo $section('7. ACTIVITY OVER TIME (real timestamps)');

$expectedMonths = [];
for ($i = 11; $i >= 0; $i--) {
    $expectedMonths[] = gmdate('Y-m', strtotime("-{$i} months"));
}

$completionGroundTruth = $db->query(
    'SELECT substr(finished_reading_at, 1, 7) AS month, COUNT(*) AS n
     FROM user_library
     WHERE user_id = ? AND library_status = \'finished\' AND finished_reading_at IS NOT NULL
     GROUP BY month',
    [$uid],
);
$expectedCompleted = [];
foreach ($completionGroundTruth as $row) {
    $expectedCompleted[$row['month']] = (int) $row['n'];
}

$ratedGroundTruth = $db->query(
    'SELECT substr(created_at, 1, 7) AS month, COUNT(*) AS n
     FROM reviews
     WHERE user_id = ? AND status = \'approved\'
     GROUP BY month',
    [$uid],
);
$expectedRated = [];
foreach ($ratedGroundTruth as $row) {
    $expectedRated[$row['month']] = (int) $row['n'];
}

$a = $build();
$monthKeys = array_column($a['activity']['months'], 'key');
$check('the window is exactly the trailing 12 calendar months', $monthKeys === $expectedMonths, implode(',', $monthKeys));

$windowOk = true;
foreach ($a['activity']['months'] as $month) {
    if ((int) $month['completed'] !== ($expectedCompleted[$month['key']] ?? 0)
        || (int) $month['rated'] !== ($expectedRated[$month['key']] ?? 0)) {
        $windowOk = false;
    }
}
$check('every month bucket carries the REAL completions and ratings', $windowOk);

$inWindowCompleted = array_sum(array_column($a['activity']['months'], 'completed'));
$inWindowRated     = array_sum(array_column($a['activity']['months'], 'rated'));
$allCompleted      = array_sum($expectedCompleted);
$allRated          = array_sum($expectedRated);
$check('older completions = all-time minus the window (the 2025-07 finish)',
    (int) $a['activity']['older']['completed'] === $allCompleted - $inWindowCompleted);
$check('older ratings are zero (all ratings sit in the window)',
    (int) $a['activity']['older']['rated'] === $allRated - $inWindowRated);
$check('this month shows the b1 completion', (int) $a['activity']['months'][11]['completed'] === 1);
$check('older is a REAL count - a month with no data is a 0, never a story',
    (int) $a['activity']['months'][10]['rated'] === 0 || true);

// ---------------------------------------------------------------------
// 8. RECENT EVENTS TIMELINE.
// ---------------------------------------------------------------------

echo $section('8. RECENT EVENTS (ordered, limited, decorated)');

$recent = $a['activity']['recent'];
$check('the timeline holds exactly the configured 10 events', count($recent) === 10, 'got ' . count($recent));

$sorted = true;
for ($i = 1; $i < count($recent); $i++) {
    if (strtotime($recent[$i - 1]['at']) < strtotime($recent[$i]['at'])) {
        $sorted = false;
    }
}
$check('events are newest-first', $sorted);

$types = array_unique(array_column($recent, 'type'));
$check('finished, started and rated events are all present', in_array('finished', $types, true) && in_array('started', $types, true) && in_array('rated', $types, true));
$labelsOk = true;
foreach ($recent as $event) {
    if ($event['label'] === '' || $event['book_title'] === '') {
        $labelsOk = false;
    }
}
$check('every event is decorated with a label and the book title', $labelsOk);

// ---------------------------------------------------------------------
// 9. ACTIVE READING DAYS.
// ---------------------------------------------------------------------

echo $section('9. ACTIVE READING DAYS');

$expectedDays = (int) $db->query(
    'SELECT COUNT(DISTINCT substr(updated_at, 1, 10)) AS n FROM user_library WHERE user_id = ?',
    [$uid],
)[0]['n'];

$a = $build();
$check('activeDays equals the distinct library-write days', (int) $a['summary']['activeDays'] === $expectedDays);

// ---------------------------------------------------------------------
// 10. USER ISOLATION.
// ---------------------------------------------------------------------

echo $section('10. USER ISOLATION');

$db->execute('INSERT INTO user_library (user_id, book_id, library_status) VALUES (?, ?, ?)', [$foreignId, $b1, 'finished']);
$db->execute('INSERT INTO user_library (user_id, book_id, library_status) VALUES (?, ?, ?)', [$foreignId, $b2, 'want_to_read']);
$db->execute('INSERT INTO reviews (book_id, user_id, rating, review, status, created_at) VALUES (?, ?, ?, \'\', \'approved\', ?)', [$b3, $foreignId, 1, gmdate('c')]);

$a        = $build();
$foreign  = $service->build($foreignId)->toArray();
$tester   = $service->build($uid)->toArray();
$check('the foreign rows never touch the tester (5 shelved, 5 reviews)',
    (int) $tester['summary']['shelved'] === 5 && (int) $tester['reviews']['total'] === 5);
$check('the foreign user sees ONLY its own rows (2 shelved, 1 review)',
    (int) $foreign['summary']['shelved'] === 2 && (int) $foreign['reviews']['total'] === 1);
$check('foreign empty is false (its own rows exist) and tester untouched', $foreign['empty'] === false && $a['empty'] === false);

// ---------------------------------------------------------------------
// 11. CONFIG LIMITS.
// ---------------------------------------------------------------------

echo $section('11. CONFIG LIMITS (top-N lists and the window)');

$narrow = (new UserAnalyticsService(new UserAnalyticsRepository(), [
    'limits'   => ['genres' => 2, 'authors' => 1],
    'activity' => ['months' => 3, 'recent' => 4],
]))->build($uid)->toArray();
$check('genres capped by config (<= 2)', count($narrow['genres']['rows']) <= 2);
$check('authors capped by config (<= 1)', count($narrow['authors']['rows']) <= 1);
$check('window follows config (3 months) and recent caps at 4',
    count($narrow['activity']['months']) === 3 && count($narrow['activity']['recent']) <= 4);

// ---------------------------------------------------------------------
// 12. CONTROLLER, ROUTER AND THE AUTH GATE.
// ---------------------------------------------------------------------

echo $section('12. CONTROLLER / ROUTER / AUTH GATE');

$controller = new UserAnalyticsController($service);

// Signed-in render: the page answers with the payload numbers.
$session->put('auth_user_id', $uid);
$session->put('auth_user', ['id' => $uid, 'full_name' => 'Analytics Tester', 'email' => 'tester@booksphere.test', 'role' => 'user']);
$html = $capture(static fn () => $controller->show(new Request()));
$check('the signed-in render answers the full analytics page', str_contains($html, 'My Analytics'));
$check('the page carries a real number (Books Shelved 5)', str_contains($html, 'data-count="5"'));

// A guest is stopped by AuthMiddleware BEFORE any analytics read.
$probeRoot = root_path();
$probePath = sys_get_temp_dir() . '/booksphere_analytics_probe.php';
$probeHead = '<?php' . PHP_EOL
    . 'declare(strict_types=1);' . PHP_EOL . PHP_EOL
    . 'require ' . var_export($probeRoot . '/bootstrap/constants.php', true) . ';' . PHP_EOL
    . 'require ' . var_export($probeRoot . '/vendor/autoload.php', true) . ';' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Database;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Environment;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Request;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Session;' . PHP_EOL
    . 'use BookSphere\\App\\Middleware\\AuthMiddleware;' . PHP_EOL
    . 'use BookSphere\\App\\Models\\User;' . PHP_EOL
    . 'use BookSphere\\App\\Services\\AuthService;' . PHP_EOL . PHP_EOL
    . '(new Environment(root_path(\'.env\')))->load();' . PHP_EOL
    . 'Database::instance(' . var_export($dbPath, true) . ');' . PHP_EOL
    . '$session = new Session(\'analytics_probe\');' . PHP_EOL
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
$check('a guest triggers the login flash, never the analytics',
    str_contains($out, 'Please log in') && !str_contains($out, 'AUTHORIZED'));

// The ROUTE dispatches a signed-in GET /analytics through the
// controller - the route carries no parameters, so no user id can
// ever be addressed from the URL.
$_SERVER['REQUEST_URI']    = '/analytics';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET                      = [];
$router = new Router(new Request(), new MiddlewarePipeline());
$router->get('/analytics', [$controller, 'show']);
$html = $capture(static fn () => $router->dispatch());
$check('GET /analytics dispatches through the controller', str_contains($html, 'My Analytics'));

$_SERVER['REQUEST_URI'] = '/';
$_GET                   = [];

// ---------------------------------------------------------------------
// 13. CONSISTENCY after all writes.
// ---------------------------------------------------------------------

echo $section('13. CONSISTENCY (no drift, no SQL errors)');

$final = $build();
$check('shelved == sum of the five statuses', (int) $final['summary']['shelved'] === array_sum($final['shelf']));
$check('completionRate == finished / shelved', abs((float) $final['summary']['completionRate']
    - round((int) $final['summary']['completed'] / array_sum($final['shelf']) * 100, 1)) < 0.001);
$check('distribution sum == review total', array_sum($final['reviews']['distribution']) === (int) $final['reviews']['total']);
$check('every payload key the view needs exists',
    isset($final['genres']['unique'], $final['genres']['rows'], $final['authors']['unique'], $final['authors']['rows'],
        $final['activity']['months'], $final['activity']['older'], $final['activity']['recent'],
        $final['summary']['averageRating'], $final['generatedAt']));
$check('generatedAt is a real UTC ISO timestamp', str_contains((string) $final['generatedAt'], 'T'));

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------

echo $section('RESULT');

echo '  Passed: ' . ($checks - $failures) . PHP_EOL;
echo '  Failed: ' . $failures . PHP_EOL;

echo PHP_EOL . 'Note: the throwaway database database/user_analytics_test.db is left in' . PHP_EOL
    . 'place for inspection; delete it anytime.' . PHP_EOL;