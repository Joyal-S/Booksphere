<?php

declare(strict_types=1);

/**
 * BookAnalyticsTest — CLI test suite for Phase 12.2 (Book Analytics)
 *
 * Verifies the catalogue-analytics surface end-to-end:
 *
 *     1. Empty catalogue - a catalogue with no visible books answers
 *                          the empty payload (empty = true, no
 *                          rankings, no activity)
 *     2. Overview        - book totals, cover availability, the import
 *                          marker and metadata completeness come from
 *                          the REAL books rows; "visible" is one scope
 *                          rule: published AND not deleted
 *     3. Shelves         - the five canonical statuses of the whole
 *                          community, counted from user_library; the
 *                          legacy `wishlist` table is NEVER a source
 *     4. Reviews         - ONLY approved reviews count (house rule);
 *                          total, average, the 1..5 distribution;
 *                          moderation flips move the numbers
 *     5. Highest rated   - ranking by the real approved average,
 *                          guarded by the configured minimum count - a
 *                          book with a single lucky 5-star never ranks
 *     6. Most reviewed   - approved-review counts per book
 *     7. Wishlist/Read   - the want_to_read shelf IS the modern
 *                          wishlist; finished = distinct readers
 *     8. Most engaged    - DISTINCT users across shelves AND reviews
 *                          (a user who shelves AND reviews counts once)
 *     9. Popularity      - the documented weighted formula over the
 *                          real signals, normalizers cap the score,
 *                          deterministic tie-breaks
 *    10. Trending        - the trailing-window score; activity older
 *                          than the window never leaks in
 *    11. Genres/authors  - catalogue size vs read-most lists; no
 *                          junction join doubles a book or an author
 *    12. Metadata        - publishers, languages, years and the
 *                          page-count buckets (missing metadata is
 *                          never invented into a bucket)
 *    13. Activity        - the trailing month window from REAL
 *                          timestamps, older months collapsed
 *    14. Limits          - config drives every top-N list and window
 *    15. Controller      - GET /book-analytics renders signed-in; a
 *                          guest is stopped by AuthMiddleware; the
 *                          route takes no parameters
 *    16. Consistency     - the aggregates still agree after every
 *                          write (no SQL errors, no drifting totals)
 *
 * Run from the project root:
 *
 *     php tests/BookAnalyticsTest.php
 *
 * The throwaway database (database/book_analytics_test.db) is
 * migrated, seeded and left in place for inspection; delete it
 * anytime.
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Controllers\BookAnalyticsController;
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
use BookSphere\App\Repositories\BookAnalyticsRepository;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\BookAnalyticsService;

// ---------------------------------------------------------------------
// 0. Boot: fresh throwaway database, migrated and seeded.
// ---------------------------------------------------------------------

(new Environment(root_path('.env')))->load();

$dbPath = root_path('database/book_analytics_test.db');

foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

Database::instance($dbPath);
(new Migrator(db(), root_path('database/migrations')))->run();
(new Seeder(db(), root_path('database/seeds')))->run();

$db = db();

// The seeder leaves demo user activity behind; the suite starts from
// an EMPTY community so every metric below is the fixture's own.
$db->execute('DELETE FROM reviews');
$db->execute('DELETE FROM user_library');
$db->execute('DELETE FROM wishlist');

$session = new Session('book_analytics_test');
$session->start();
$auth = new AuthService($session, new User());
AuthService::setInstance($auth);

// The module config under test: the same shape config/book_analytics.php
// returns. Default weights and normalizers are asserted exactly in the
// popularity and trending sections.
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
$service    = new BookAnalyticsService($repository, $config);

// Test users: the fixture "community". A review is UNIQUE (user_id,
// book_id), so each rating needs its own person - exactly like the
// real app.
$insert = static function (string $email) use ($db): int {
    $db->execute(
        'INSERT INTO users (full_name, email, password, role) VALUES (?, ?, ?, ?)',
        [$email, $email, password_hash('User@123', PASSWORD_DEFAULT), 'user'],
    );

    return (int) $db->lastInsertId();
};

$userId   = $insert('analyst@booksphere.test');
$foreignId = $insert('foreign@booksphere.test');
$thirdId  = $insert('third@booksphere.test');

$r = [];
foreach (range(1, 6) as $i) {
    $r[$i] = $insert("bulk{$i}@booksphere.test");
}
[$r1, $r2, $r3, $r4, $r5, $r6] = array_values($r);

// Six seeded books give the dataset a stable shape.
$bookIds = [];
foreach (array_slice($db->query('SELECT id FROM books ORDER BY id LIMIT 6'), 0, 6) as $i => $row) {
    $bookIds[$i + 1] = (int) $row['id'];
}
[$b1, $b2, $b3, $b4, $b5, $b6] = array_values($bookIds);

// A seventh book stays untouched by the fixtures - section 10 proves
// that merely OLD activity never reaches the trending window.
$b7 = (int) $db->query('SELECT id FROM books ORDER BY id LIMIT 1 OFFSET 6')[0]['id'];

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

$build   = static fn (): array => $service->build()->toArray();
$capture = static function (callable $fn): string {
    ob_start();
    $fn();

    return (string) ob_get_clean();
};

// Relative timestamps keep the window tests stable on any date.
$ago = static fn (int $days): string => gmdate('c', time() - $days * 86400);

// Fixture writers (the same shapes the real services write).
$shelf = static function (int $user, int $book, string $status, ?string $activityDay = null) use ($db): void {
    $stamp = $activityDay ?? gmdate('c');
    $db->execute(
        'INSERT INTO user_library (user_id, book_id, library_status, progress_percentage, started_reading_at, finished_reading_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$user, $book, $status, $status === 'finished' ? 100 : 0,
         $status === 'finished' ? $stamp : null, $status === 'finished' ? $stamp : null, $stamp, $stamp],
    );
};

$rate = static function (int $user, int $book, int $rating, string $at, string $status = 'approved') use ($db): void {
    $db->execute(
        'INSERT INTO reviews (book_id, user_id, rating, title, review, status, is_edited, created_at, updated_at)
         VALUES (?, ?, ?, \'\', \'\', ?, 0, ?, ?)',
        [$book, $user, $rating, $status, $at, $at],
    );
};

$promote = static function (int $user, int $book, int $rating, string $at) use ($db): void {
    $db->execute(
        'UPDATE reviews SET rating = ?, status = \'approved\', updated_at = ?
         WHERE user_id = ? AND book_id = ?',
        [$rating, $at, $user, $book],
    );
};

$throws = static function (string $class, callable $fn): bool {
    try {
        $fn();

        return false;
    } catch (Throwable $throwable) {
        return $throwable instanceof $class;
    }
};

// ---------------------------------------------------------------------
// 1. EMPTY CATALOGUE.
// ---------------------------------------------------------------------

echo $section('1. EMPTY CATALOGUE (guidance payload)');

$db->execute("UPDATE books SET deleted_at = '2020-01-01T00:00:00Z', status = 'draft'");
$e = $build();
$check('empty -> true with no visible book', $e['empty'] === true);
$check('every ranking list is empty', array_sum(array_map('count', $e['rankings'])) === 0);
$check('overview.books is 0', (int) $e['overview']['books'] === 0);
$check('no shelves and no metadata rows',
    array_sum($e['shelves']) === 0
    && $e['metadata']['genres']['size'] === [] && $e['metadata']['authors']['size'] === []
    && $e['metadata']['publishers'] === []
    && $e['metadata']['pageRanges'] === []);
$check('the activity window still carries its month buckets (all zeros)',
    count($e['activity']['window']) === 12
    && array_sum(array_map(static fn (array $m): int => $m['reviews'] + $m['finishes'], $e['activity']['window'])) === 0);
$check('recent activity is zero inside the window', array_sum($e['activity']['recent']) === 0);

// Restore the catalogue for the rest of the suite.
$db->execute('UPDATE books SET deleted_at = NULL, status = ?', ['published']);
$a = $build();
$check('restoring the books flips empty back to false', $a['empty'] === false);

// ---------------------------------------------------------------------
// 2. OVERVIEW (scope rule: published AND not deleted).
// ---------------------------------------------------------------------

echo $section('2. OVERVIEW (the visible catalogue only)');

$visible = (int) $db->query(
    "SELECT COUNT(*) AS n FROM books WHERE status = 'published' AND deleted_at IS NULL",
)[0]['n'];
$check('books == the visible catalogue', (int) $a['overview']['books'] === $visible, (string) $a['overview']['books'] . ' vs ' . $visible);

$check('covers: with + without == the whole catalogue',
    (int) $a['overview']['with_covers'] + (int) $a['overview']['without_covers'] === (int) $a['overview']['books']);

$imported = (int) $db->query(
    "SELECT COUNT(*) AS n FROM books WHERE status = 'published' AND deleted_at IS NULL AND google_book_id IS NOT NULL AND google_book_id != ''",
)[0]['n'];
$check('imported == books carrying the Google Books marker', (int) $a['overview']['imported'] === $imported);

// A soft-deleted book and a draft leave ALL the overview counts.
$db->execute("UPDATE books SET deleted_at = '2020-01-01T00:00:00Z' WHERE id = ?", [$b1]);
$db->execute("UPDATE books SET status = 'draft' WHERE id = ?", [$b2]);
$a = $build();
$check('a soft-deleted and a draft book drop every count',
    (int) $a['overview']['books'] === $visible - 2
    && (int) $a['overview']['with_covers'] + (int) $a['overview']['without_covers'] === $visible - 2);
$db->execute('UPDATE books SET deleted_at = NULL, status = ? WHERE id = ?', ['published', $b1]);
$db->execute('UPDATE books SET status = ? WHERE id = ?', ['published', $b2]);
$a = $build();

// ---------------------------------------------------------------------
// 3. COMMUNITY SHELVES.
// ---------------------------------------------------------------------

echo $section('3. COMMUNITY SHELVES (user_library is the source)');

$shelf($userId, $b1, 'want_to_read', $ago(2));
$shelf($foreignId, $b1, 'want_to_read', $ago(1));
$shelf($r1, $b2, 'finished', $ago(3));
$shelf($r2, $b2, 'want_to_read', $ago(2));
$shelf($r3, $b3, 'finished', $ago(4));
$shelf($r4, $b4, 'on_hold', $ago(1));
$shelf($r5, $b5, 'dropped', $ago(1));

$a = $build();
$expectedShelves = $db->query('SELECT library_status AS status, COUNT(*) AS n FROM user_library GROUP BY library_status');
$expected = ['want_to_read' => 0, 'currently_reading' => 0, 'finished' => 0, 'on_hold' => 0, 'dropped' => 0];
foreach ($expectedShelves as $row) {
    $expected[(string) $row['status']] = (int) $row['n'];
}
$check('the five canonical shelves with the real totals', $a['shelves'] === $expected, json_encode($a['shelves']));

// The legacy `wishlist` table is the recommendation-engine signal -
// it must never move the modern wishlist count.
$db->execute('INSERT INTO wishlist (user_id, book_id) VALUES (?, ?)', [$userId, $b2]);
$a = $build();
$check('legacy wishlist rows are ignored (user_library is the source)',
    (int) $a['shelves']['want_to_read'] === $expected['want_to_read']);

// ---------------------------------------------------------------------
// 4. REVIEWS (approved-only house rule).
// ---------------------------------------------------------------------

echo $section('4. REVIEWS (approved-only)');

$rate($r1, $b1, 5, $ago(6));
$rate($r2, $b1, 5, $ago(6));
$rate($r3, $b2, 4, $ago(5));
$rate($r4, $b2, 4, $ago(4));
$rate($r5, $b2, 4, $ago(3));
$rate($userId, $b4, 2, $ago(1), 'pending'); // moderation: NOT public yet
$rate($r6, $b4, 1, $ago(1), 'hidden');      // moderation: NOT public yet

$a = $build();
$check('overview.reviews == COUNT(approved) only', (int) $a['overview']['reviews'] === 5);
$check('averageRating == AVG(approved ratings) = 4.4', abs((float) $a['overview']['averageRating'] - 4.4) < 0.001);
$check('distribution sums to the review total and matches the stars',
    array_sum($a['overview']['distribution']) === (int) $a['overview']['reviews']
    && $a['overview']['distribution'][5] === 2 && $a['overview']['distribution'][4] === 3
    && $a['overview']['distribution'][2] === 0 && $a['overview']['distribution'][1] === 0);

// Moderation flips the pending/hidden reviews into the sums.
$promote($userId, $b4, 3, $ago(0));
$promote($r6, $b4, 3, $ago(0));
$a = $build();
$check('moderation flips count: 7 approved now',
    (int) $a['overview']['reviews'] === 7
    && $a['overview']['distribution'][4] === 3 && $a['overview']['distribution'][3] === 2
    && abs((float) $a['overview']['averageRating'] - 4.0) < 0.001);

// ---------------------------------------------------------------------
// 5. HIGHEST RATED (the real-average rank, minimum-count guard).
// ---------------------------------------------------------------------

echo $section('5. HIGHEST RATED (minimum-count guard)');

$check('no book ranks yet - none has 5+ approved reviews', $a['rankings']['highestRated'] === []);

// b6 earns five 5-star reviews across five people.
foreach ([$r1, $r2, $r3, $r4, $r5] as $ri) {
    $rate($ri, $b6, 5, $ago(2));
}
$a = $build();
$grade = $a['rankings']['highestRated'];
$check('the 5-star book ranks with its real average and count',
    count($grade) === 1 && $grade[0]['id'] === $b6
    && abs((float) $grade[0]['average'] - 5.0) < 0.001 && (int) $grade[0]['count'] === 5);

// A single 5-star review must NEVER qualify.
$rate($r6, $b5, 5, $ago(1));
$a = $build();
$highs = array_column($a['rankings']['highestRated'], 'id');
$check('the single-review book stays out of the ranking', $highs === [$b6]);

// ---------------------------------------------------------------------
// 6. MOST REVIEWED (ground-truth ordering).
// ---------------------------------------------------------------------

echo $section('6. MOST REVIEWED');

$want = [];
foreach ($db->query(
    'SELECT b.id
     FROM reviews r
     JOIN books b ON b.id = r.book_id
     WHERE b.deleted_at IS NULL AND b.status = \'published\' AND r.status = \'approved\'
     GROUP BY b.id
     ORDER BY COUNT(r.id) DESC, b.title COLLATE NOCASE ASC',
) as $row) {
    $want[] = (int) $row['id'];
}
$got = array_column($a['rankings']['mostReviewed'], 'id');
$check('the order matches the SQL ground truth', $got === $want, implode(',', $got));

// ---------------------------------------------------------------------
// 7. MOST WISHLISTED / MOST READ.
// ---------------------------------------------------------------------

echo $section('7. MOST WISHLISTED / MOST READ');

$wish = $a['rankings']['mostWishlisted'];
$check('wishlist tops with the two want_to_read rows on b1',
    (int) $wish[0]['count'] === 2 && $wish[0]['id'] === $b1
    && (int) $wish[1]['count'] === 1 && $wish[1]['id'] === $b2);

$read = $a['rankings']['mostRead'];
$readIds = array_column($read, 'id');
$check('only finish records count (a review alone never counts as a read)',
    !in_array($b6, $readIds, true) && count($read) === 2);
$check('each finished count is one DISTINCT reader', array_sum(array_column($read, 'count')) === 2);

// Finished UNIQUE guard: one user can finish a book only once.
$dup = $throws(\PDOException::class, fn () => $db->execute(
    'INSERT INTO user_library (user_id, book_id, library_status) VALUES (?, ?, \'finished\')',
    [$r1, $b2],
));
$check('a second finish record for the same (user, book) is rejected by UNIQUE', $dup);

// ---------------------------------------------------------------------
// 8. MOST ENGAGED (DISTINCT users across shelves AND reviews).
// ---------------------------------------------------------------------

echo $section('8. MOST ENGAGED (no double counting)');

$want = [];
foreach ($db->query(
    'WITH activity AS (
         SELECT l.book_id AS book_id, l.user_id AS user_id FROM user_library l
         UNION
         SELECT r.book_id AS book_id, r.user_id AS user_id FROM reviews r WHERE r.status = \'approved\'
     )
     SELECT b.id, COUNT(DISTINCT a.user_id) AS count
     FROM activity a
     JOIN books b ON b.id = a.book_id
     WHERE b.deleted_at IS NULL AND b.status = \'published\'
     GROUP BY b.id
     ORDER BY count DESC, b.title COLLATE NOCASE ASC',
) as $row) {
    $want[] = ['id' => (int) $row['id'], 'count' => (int) $row['count']];
}

$got = array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'count' => (int) $row['count']], $a['rankings']['mostEngaged']);
$check('the engaged order and counts match the DISTINCT ground truth', $got === array_slice($want, 0, 10), json_encode($got));

// User r1 does BOTH: reviews b1 (section 4) AND shelves it? No -
// r1's b1 review is already counted ONCE, even combined with the
// review of b2's shelf. Spot-check the deliberate case: a user who
// shelves and reviews THE SAME book still counts once.
$db->execute(
    'INSERT INTO user_library (user_id, book_id, library_status) VALUES (?, ?, \'want_to_read\')',
    [$r1, $b1],
);
$a = $build();
$b1Engaged = array_values(array_filter($a['rankings']['mostEngaged'], static fn (array $row): bool => $row['id'] === $b1));
$check('a user who reviews AND shelves the same book counts once',
    count($b1Engaged) === 1 && (int) $b1Engaged[0]['count'] === 4);
// (b1: shelves uid, foreign, r1 + reviews r1, r2 = 4 distinct - r1's shelf
// and review are ONE user, never two)

// ---------------------------------------------------------------------
// 9. POPULARITY (the documented formula).
// ---------------------------------------------------------------------

echo $section('9. POPULARITY (weighted formula)');

$score = static fn (float $average, int $reviews, int $interests): float
    => 0.40 * ($average / 5.0)
     + 0.30 * min($reviews / 10, 1.0)
     + 0.30 * min($interests / 10, 1.0);

// Ground truth: the same three signals read from the real tables, the
// same formula and the same tie-break (score DESC, title ASC) applied
// here - the service must produce EXACTLY this list.
$truth = [];
foreach ($db->query(
    'SELECT b.id AS id, b.title AS title,
            COALESCE((SELECT AVG(r.rating) FROM reviews r
                      WHERE r.book_id = b.id AND r.status = \'approved\'), 0) AS average,
            (SELECT COUNT(*) FROM reviews r
             WHERE r.book_id = b.id AND r.status = \'approved\') AS reviews,
            (SELECT COUNT(*) FROM user_library w
             WHERE w.book_id = b.id AND w.library_status = \'want_to_read\') AS interests
     FROM books b
     WHERE b.deleted_at IS NULL AND b.status = \'published\'',
) as $row) {
    $row = [
        'id'        => (int) $row['id'],
        'title'     => (string) $row['title'],
        'average'   => round((float) $row['average'], 2),
        'reviews'   => (int) $row['reviews'],
        'interests' => (int) $row['interests'],
    ];
    if ($row['reviews'] === 0 && $row['interests'] === 0) {
        continue;
    }
    $row['score'] = round($score((float) $row['average'], $row['reviews'], $row['interests']), 4);
    $truth[]      = $row;
}
usort($truth, static function (array $x, array $y): int {
    if ($x['score'] === $y['score']) {
        return strcasecmp($x['title'], $y['title']);
    }

    return $y['score'] <=> $x['score'];
});

$a = $build();
$pop = $a['rankings']['popular'];
$check('every service score equals the ground-truth formula and order',
    count($pop) === count($truth)
    && array_column($pop, 'id') === array_column($truth, 'id'),
    json_encode(array_column($pop, 'id')) . ' vs ' . json_encode(array_column($truth, 'id')));
$scoresOk = true;
foreach ($pop as $i => $row) {
    if (abs((float) $row['score'] - $truth[$i]['score']) > 0.0001) {
        $scoresOk = false;
    }
}
$check('every score matches the formula to 4 decimals', $scoresOk);

$b1Score = array_values(array_filter($pop, static fn (array $row): bool => $row['id'] === $b1))[0]['score'] ?? null;
$b6Score = array_values(array_filter($pop, static fn (array $row): bool => $row['id'] === $b6))[0]['score'] ?? null;
$check('a spot score: b1 = 0.4*1 + 0.3*min(2/10) + 0.3*min(3/10) = 0.55',
    $b1Score !== null && abs((float) $b1Score - 0.55) < 0.0001, (string) $b1Score);
$check('a spot score: b6 = 0.4*1 + 0.3*min(5/10) + 0 = 0.55',
    $b6Score !== null && abs((float) $b6Score - 0.55) < 0.0001, (string) $b6Score);
$check('books with NO signals never enter the popularity ranking',
    !array_filter($pop, static fn (array $row): bool => $row['id'] === $b7));

// The normalizers cap the components: a component can never push a
// score past its perfect 1.0 by volume alone.
$check('every popularity score sits in [0, 1]',
    array_reduce($pop, static fn (bool $ok, array $row): bool => (float) $row['score'] >= 0.0 && (float) $row['score'] <= 1.0001 && $ok, true));
$check('the tie-break is deterministic (score DESC, title ASC)',
    implode('|', array_column($pop, 'title')) === implode('|', array_column($truth, 'title')));

// ---------------------------------------------------------------------
// 10. TRENDING (the trailing window only).
// ---------------------------------------------------------------------

echo $section('10. TRENDING (window-scoped score)');

$trendScore = static fn (int $revs, int $ints, int $fins): float =>
    0.40 * min($revs / 5, 1.0)
    + 0.30 * min($ints / 5, 1.0)
    + 0.30 * min($fins / 5, 1.0);

$a = $build();
$ids = array_column($a['rankings']['trending'], 'id');
$check('books with ONLY activity older than 30 days never appear', !in_array($b7, $ids, true));

// b7 gets an old review and an old shelf - still before the window.
$db->execute(
    'INSERT INTO user_library (user_id, book_id, library_status, created_at, updated_at) VALUES (?, ?, \'want_to_read\', ?, ?)',
    [$foreignId, $b7, $ago(60), $ago(60)],
);
$rate($r1, $b7, 4, $ago(60));
$a = $build();
$ids = array_column($a['rankings']['trending'], 'id');
$check('old activity NEVER leaks into the trending window', !in_array($b7, $ids, true));

// Ground truth for the window: counts that sit inside now-30d. b3
// already carries r1's recent review and r3's recent finish - the
// fixture review below is the "before" state of the snap check.
$rate($r1, $b3, 5, $ago(2));
$truthT = [];
foreach ($db->query(
    'SELECT b.id AS id, b.title AS title,
            COALESCE((SELECT COUNT(*) FROM reviews r
                      WHERE r.book_id = b.id AND r.status = \'approved\' AND r.created_at >= ?), 0) AS reviews,
            COALESCE((SELECT COUNT(*) FROM user_library w
                      WHERE w.book_id = b.id AND w.library_status = \'want_to_read\' AND w.created_at >= ?), 0) AS interests,
            COALESCE((SELECT COUNT(*) FROM user_library f
                      WHERE f.book_id = b.id AND f.library_status = \'finished\' AND f.finished_reading_at >= ?), 0) AS finishes
     FROM books b
     WHERE b.deleted_at IS NULL AND b.status = \'published\'',
    [$ago(30), $ago(30), $ago(30)],
) as $row) {
    $row['reviews']   = (int) $row['reviews'];
    $row['interests'] = (int) $row['interests'];
    $row['finishes']  = (int) $row['finishes'];
    if ($row['reviews'] === 0 && $row['interests'] === 0 && $row['finishes'] === 0) {
        continue;
    }
    $row['score'] = round($trendScore($row['reviews'], $row['interests'], $row['finishes']), 4);
    $truthT[]     = ['id' => (int) $row['id'], 'title' => $row['title'], 'score' => $row['score']];
}
usort($truthT, static function (array $x, array $y): int {
    if ($x['score'] === $y['score']) {
        return strcasecmp($x['title'], $y['title']);
    }

    return $y['score'] <=> $x['score'];
});
$a = $build();
$gotT = array_column($a['rankings']['trending'], 'id');
$check('the trending order and scores equal the window ground truth',
    $gotT === array_column($truthT, 'id'), json_encode($gotT) . ' vs ' . json_encode(array_column($truthT, 'id')));
$trendOk = true;
foreach ($a['rankings']['trending'] as $i => $row) {
    if (abs((float) $row['score'] - $truthT[$i]['score']) > 0.0001) {
        $trendOk = false;
    }
}
$check('every trending score matches the formula to 4 decimals', $trendOk);

// A fresh review enters the window instantly (b3 already had r1's
// recent review and r3's recent finish: 0.4*min(2/5) + 0.3*min(1/5)).
$rate($r6, $b3, 5, $ago(0));
$a = $build();
$t3 = array_values(array_filter($a['rankings']['trending'], static fn (array $row): bool => $row['id'] === $b3))[0]['score'] ?? null;
$check('a fresh review snaps into the window (b3 = 0.16 + 0.06 = 0.22)',
    $t3 !== null && abs((float) $t3 - 0.22) < 0.0001, (string) $t3);

// ---------------------------------------------------------------------
// 11. GENRES & AUTHORS (no join doubling).
// ---------------------------------------------------------------------

echo $section('11. GENRES & AUTHORS');

$a = $build();

$genreTruth = [];
foreach ($db->query(
    'SELECT c.name AS name, COUNT(DISTINCT b.id) AS books
     FROM book_categories bc
     JOIN categories c ON c.id = bc.category_id
     JOIN books b ON b.id = bc.book_id
     WHERE b.deleted_at IS NULL AND b.status = \'published\'
     GROUP BY c.id, c.name
     ORDER BY books DESC, c.name COLLATE NOCASE ASC',
) as $row) {
    $genreTruth[$row['name']] = (int) $row['books'];
}
$genreOk = true;
foreach ($a['metadata']['genres']['size'] as $row) {
    if ((int) $row['books'] !== ($genreTruth[$row['name']] ?? -1)) {
        $genreOk = false;
    }
}
$check('genre catalogue sizes match the ground truth', $genreOk);

$genreReadTruth = [];
foreach ($db->query(
    'SELECT c.name AS name, COUNT(DISTINCT l.id) AS count
     FROM book_categories bc
     JOIN categories c ON c.id = bc.category_id
     JOIN user_library l ON l.book_id = bc.book_id
     JOIN books b ON b.id = bc.book_id
     WHERE b.deleted_at IS NULL AND b.status = \'published\' AND l.library_status = \'finished\'
     GROUP BY c.id, c.name
     ORDER BY count DESC, c.name COLLATE NOCASE ASC',
) as $row) {
    $genreReadTruth[$row['name']] = (int) $row['count'];
}
$genreReadOk = true;
foreach ($a['metadata']['genres']['reading'] as $row) {
    if ((int) $row['count'] !== ($genreReadTruth[$row['name']] ?? -1)) {
        $genreReadOk = false;
    }
}
$check('genre read-counts match the finished ground truth', $genreReadOk);
$check('unique genres == DISTINCT categories of the visible books',
    (int) $a['metadata']['genres']['unique'] === (int) $db->query(
        'SELECT COUNT(DISTINCT bc.category_id) AS n
         FROM book_categories bc
         JOIN books b ON b.id = bc.book_id
         WHERE b.deleted_at IS NULL AND b.status = \'published\'',
    )[0]['n']);

$authorTruth = [];
foreach ($db->query(
    'SELECT a.name AS name, COUNT(DISTINCT b.id) AS books
     FROM book_authors ba
     JOIN authors a ON a.id = ba.author_id
     JOIN books b ON b.id = ba.book_id
     WHERE b.deleted_at IS NULL AND b.status = \'published\'
     GROUP BY a.id, a.name
     ORDER BY books DESC, a.name COLLATE NOCASE ASC',
) as $row) {
    $authorTruth[$row['name']] = (int) $row['books'];
}
$authorOk = true;
foreach ($a['metadata']['authors']['size'] as $row) {
    if ((int) $row['books'] !== ($authorTruth[$row['name']] ?? -1)) {
        $authorOk = false;
    }
}
$check('author catalogue sizes match (a co-authored book counts once per author)', $authorOk);

// ---------------------------------------------------------------------
// 12. METADATA (publishers, languages, years, page ranges).
// ---------------------------------------------------------------------

echo $section('12. METADATA (never invented into a bucket)');

$publisherTruth = [];
foreach ($db->query(
    "SELECT b.publisher AS name, COUNT(*) AS books
     FROM books b
     WHERE b.deleted_at IS NULL AND b.status = 'published' AND b.publisher IS NOT NULL AND b.publisher != ''
     GROUP BY b.publisher
     ORDER BY books DESC, b.publisher COLLATE NOCASE ASC",
) as $row) {
    $publisherTruth[$row['name']] = (int) $row['books'];
}
$check('publishers match their ground truth',
    array_column($a['metadata']['publishers'], 'books') === array_values(array_slice($publisherTruth, 0, 10)));

$languageTruth = [];
foreach ($db->query(
    "SELECT b.language AS language, COUNT(*) AS books
     FROM books b
     WHERE b.deleted_at IS NULL AND b.status = 'published' AND b.language IS NOT NULL AND b.language != ''
     GROUP BY b.language
     ORDER BY books DESC, b.language COLLATE NOCASE ASC",
) as $row) {
    $languageTruth[$row['language']] = (int) $row['books'];
}
$ok = true;
foreach ($a['metadata']['languages'] as $row) {
    if ((int) $row['books'] !== ($languageTruth[$row['language']] ?? -1)) {
        $ok = false;
    }
}
$check('languages match their ground truth', $ok);

$yearTruth = [];
foreach ($db->query(
    "SELECT b.published_year AS year, COUNT(*) AS books
     FROM books b
     WHERE b.deleted_at IS NULL AND b.status = 'published' AND b.published_year IS NOT NULL
     GROUP BY b.published_year
     ORDER BY b.published_year DESC",
) as $row) {
    $yearTruth[(int) $row['year']] = (int) $row['books'];
}
$ok = true;
foreach ($a['metadata']['years'] as $row) {
    if ((int) $row['books'] !== ($yearTruth[(int) $row['year']] ?? -1)) {
        $ok = false;
    }
}
$check('years match their ground truth (year-less books never appear)', $ok);

// Page ranges: give b6 a page count so ONE bucket holds data, then
// verify the labels/limits partition the books that carry a count.
$db->execute('UPDATE books SET page_count = 420 WHERE id = ?', [$b6]);
$a = $build();
$ranges = array_column($a['metadata']['pageRanges'], 'books', 'label');
$withPages = (int) $db->query(
    "SELECT COUNT(*) AS n FROM books
     WHERE deleted_at IS NULL AND status = 'published' AND page_count IS NOT NULL",
)[0]['n'];
$check('the page buckets partition every book with a page count',
    array_sum($ranges) === $withPages && ($ranges['401 - 500'] ?? 0) >= 1,
    json_encode($ranges));
$check('the labels come from the config, never fabricated', ($ranges['Over 500'] ?? 0) >= 0 && isset($ranges['Up to 100']));

// Missing publisher: no bucket, the completeness numbers carry it.
$db->execute("UPDATE books SET publisher = NULL WHERE id = ?", [$b6]);
$a = $build();
$check('a book without a publisher is not in any publisher bucket',
    array_search($b6, array_column($a['metadata']['publishers'], 'name'), true) === false);

// ---------------------------------------------------------------------
// 13. ACTIVITY OVER TIME (real timestamps, trailing window).
// ---------------------------------------------------------------------

echo $section('13. ACTIVITY OVER TIME');

$expectedMonths = [];
for ($i = 11; $i >= 0; $i--) {
    $expectedMonths[] = gmdate('Y-m', strtotime("-{$i} months"));
}

$a = $build();
$keys = array_column($a['activity']['window'], 'key');
$check('the window is exactly the trailing 12 calendar months', $keys === $expectedMonths, implode(',', $keys));

$reviewMap = [];
foreach ($db->query(
    "SELECT substr(r.created_at, 1, 7) AS month, COUNT(*) AS n
     FROM reviews r
     JOIN books b ON b.id = r.book_id
     WHERE b.deleted_at IS NULL AND b.status = 'published' AND r.status = 'approved'
     GROUP BY month",
) as $row) {
    $reviewMap[$row['month']] = (int) $row['n'];
}
$finishMap = [];
foreach ($db->query(
    "SELECT substr(l.finished_reading_at, 1, 7) AS month, COUNT(*) AS n
     FROM user_library l
     JOIN books b ON b.id = l.book_id
     WHERE b.deleted_at IS NULL AND b.status = 'published' AND l.library_status = 'finished'
           AND l.finished_reading_at IS NOT NULL
     GROUP BY month",
) as $row) {
    $finishMap[$row['month']] = (int) $row['n'];
}

$windowOk = true;
foreach ($a['activity']['window'] as $month) {
    if ((int) $month['reviews'] !== ($reviewMap[$month['key']] ?? 0)
        || (int) $month['finishes'] !== ($finishMap[$month['key']] ?? 0)) {
        $windowOk = false;
    }
}
$check('every month bucket carries the REAL reviews and finishes', $windowOk);

$inWindowReviews  = array_sum(array_column($a['activity']['window'], 'reviews'));
$inWindowFinishes = array_sum(array_column($a['activity']['window'], 'finishes'));
$allReviews       = array_sum($reviewMap);
$allFinishes      = array_sum($finishMap);
$check('older reviews == all-time minus the window',
    (int) $a['activity']['older']['reviews'] === $allReviews - $inWindowReviews);
$check('older finishes == all-time minus the window',
    (int) $a['activity']['older']['finishes'] === $allFinishes - $inWindowFinishes);

// An OLD event lands in 'older' - backdating the b7 finish far back.
$db->execute(
    "UPDATE user_library SET library_status = 'finished', finished_reading_at = '2016-06-15T09:00:00Z', updated_at = ?
     WHERE user_id = ? AND book_id = ?",
    [gmdate('c'), $foreignId, $b7],
);
$a = $build();
$check('the 2016 finish counts as older, never in the window',
    (int) $a['activity']['older']['finishes'] >= 1
    && array_sum(array_map(static fn (array $m): int => $m['finishes'], $a['activity']['window'])) === $inWindowFinishes);

// ---------------------------------------------------------------------
// 14. CONFIG LIMITS.
// ---------------------------------------------------------------------

echo $section('14. CONFIG LIMITS (top-N and the window)');

$narrow = (new BookAnalyticsService(new BookAnalyticsRepository(), [
    'limits' => [
        'highest_rated' => 2, 'most_reviewed' => 1, 'popular' => 2, 'trending' => 2,
        'genres' => 2, 'authors' => 2, 'publishers' => 1, 'languages' => 1, 'years' => 1,
    ],
    'ratings' => ['minimum_count' => 5],
    'activity' => ['months' => 3],
]))->build()->toArray();
$check('highest rated capped (<= 2)', count($narrow['rankings']['highestRated']) <= 2);
$check('popularity capped (<= 2)', count($narrow['rankings']['popular']) <= 2);
$check('most reviewed capped (<= 2)', count($narrow['rankings']['mostReviewed']) <= 2);
$check('metadata lists capped', count($narrow['metadata']['genres']['size']) <= 2
    && count($narrow['metadata']['publishers']) <= 1 && count($narrow['metadata']['languages']) <= 1);
$check('window follows config (3 months)', count($narrow['activity']['window']) === 3);

// ---------------------------------------------------------------------
// 15. CONTROLLER / ROUTER / THE AUTH GATE.
// ---------------------------------------------------------------------

echo $section('15. CONTROLLER / ROUTER / AUTH GATE');

$controller = new BookAnalyticsController($service);

// Signed-in render: the page answers with the payload numbers.
$session->put('auth_user_id', $userId);
$session->put('auth_user', ['id' => $userId, 'full_name' => 'Catalog Analyst', 'email' => 'analyst@booksphere.test', 'role' => 'user']);
$html = $capture(static fn () => $controller->index(new Request()));
$check('the signed-in render answers the full page', str_contains($html, 'Book Analytics'));
$check('the rendered page carries a real number (the visible books)',
    str_contains($html, 'data-count="' . (int) $a['overview']['books'] . '"'), (string) (int) $a['overview']['books']);

// A guest is stopped by AuthMiddleware BEFORE any analytics read.
$probePath = sys_get_temp_dir() . '/booksphere_book_analytics_probe.php';
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
    . '$session = new Session(\'book_analytics_probe\');' . PHP_EOL
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

// The ROUTE dispatches a signed-in GET /book-analytics through the
// controller - the route takes no parameters.
$_SERVER['REQUEST_URI']    = '/book-analytics';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET                      = [];
$router = new Router(new Request(), new MiddlewarePipeline());
$router->get('/book-analytics', [$controller, 'index']);
$html = $capture(static fn () => $router->dispatch());
$check('GET /book-analytics dispatches through the controller', str_contains($html, 'Book Analytics'));

$_SERVER['REQUEST_URI'] = '/';
$_GET                   = [];

// ---------------------------------------------------------------------
// 16. CONSISTENCY after all writes.
// ---------------------------------------------------------------------

echo $section('16. CONSISTENCY (no drift, no SQL errors)');

$final = $build();
$check('overview.books still equals the visible catalogue',
    (int) $final['overview']['books'] === (int) $db->query(
        "SELECT COUNT(*) AS n FROM books WHERE status = 'published' AND deleted_at IS NULL",
    )[0]['n']);
$check('distribution sums to the review total',
    array_sum($final['overview']['distribution']) === (int) $final['overview']['reviews']);
$check('shelves and rankings carry every key the view reads',
    isset($final['rankings']['highestRated'], $final['rankings']['popular'], $final['rankings']['trending'],
        $final['metadata']['genres']['size'], $final['metadata']['authors']['reading'],
        $final['activity']['recent'], $final['activity']['window'], $final['activity']['older'],
        $final['overview']['averageRating'], $final['generatedAt']));
$check('popular and trending scores always sit in [0, 1]',
    array_reduce([$final['rankings']['popular'], $final['rankings']['trending']], static function (bool $ok, array $rows): bool {
        foreach ($rows as $row) {
            if ((float) $row['score'] < 0.0 || (float) $row['score'] > 1.0001) {
                return false;
            }
        }

        return $ok;
    }, true));
$check('generatedAt is a real UTC ISO timestamp', str_contains((string) $final['generatedAt'], 'T'));

// Regression (Phase 12.6 audit): recentActivity() used to bind 3
// params against 6 placeholders, so every recent_* count was
// silently zero. A fresh approved review inside the window MUST
// surface as one recent_review.
$freshId = $insert('recent@booksphere.test');
$rate($freshId, $b1, 4, $ago(2));
$swept = $build();
$check('a fresh in-window review surfaces as recent activity',
    (int) $swept['activity']['recent']['recent_reviews'] === (int) $final['activity']['recent']['recent_reviews'] + 1,
    'got ' . (int) $swept['activity']['recent']['recent_reviews'] . ' vs ' . (int) $final['activity']['recent']['recent_reviews']);

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------

echo $section('RESULT');

echo '  Passed: ' . ($checks - $failures) . PHP_EOL;
echo '  Failed: ' . $failures . PHP_EOL;

echo PHP_EOL . 'Note: the throwaway database database/book_analytics_test.db is left in' . PHP_EOL
    . 'place for inspection; delete it anytime.' . PHP_EOL;