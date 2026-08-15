<?php

declare(strict_types=1);

/**
 * RecommendationOptimizationTest — CLI test suite for Phase 6.5
 *
 * Verifies the production-readiness work of Phase 6.5 without
 * touching real data (same harness as RecommendationDashboardTest):
 *
 *     1. Indexes: migration 0013 exists and SQLite's EXPLAIN QUERY
 *        PLAN actually uses the new composite indexes for the
 *        engine's count / recent-views / active-catalogue queries
 *     2. Scoring: the 0-100 normalization (popularityPercent /
 *        trendingPercent) stays bounded, capped and monotonic
 *     3. Presenter: the "Updated X minutes ago" freshness phrase
 *        and the cross-section deduplication (no book may appear
 *        in two dashboard sections)
 *     4. Cache: put/get round-trip, per-user invalidate, full flush,
 *        and graceful degradation (a corrupted cache file is a
 *        quiet miss, never a crash)
 *     5. Rate limiting: the session RateLimiter enforces the
 *        window/limit and resets cleanly (the HTTP 429 exit path
 *        cannot run in CLI - Response::error() exits - so it is
 *        covered by the manual checklist + smoke test)
 *     6. Metrics: RecommendationMetrics::summary() composes the
 *        cache / config / data / scores blocks from live state
 *     7. Admin: the AdminMiddleware lets an admin through, and the
 *        /admin/recommendations page renders its monitoring cards
 *
 * Run from the project root:
 *
 *     php tests/RecommendationOptimizationTest.php
 *
 * How it works:
 *     - A throwaway SQLite database (database/optimization_test.db)
 *       is created, migrated and seeded.
 *     - A throwaway cache directory under sys_get_temp_dir() holds
 *       the per-user cache files for the cache tests.
 *     - Every check prints PASS/FAIL; the summary line doubles as
 *       the Phase 6.5 testing checklist for the viva.
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Core\Logger;
use BookSphere\App\Core\Migrator;
use BookSphere\App\Core\RateLimiter;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Seeder;
use BookSphere\App\Core\Session;
use BookSphere\App\Controllers\AdminController;
use BookSphere\App\Controllers\RecommendationController;
use BookSphere\App\Middleware\AdminMiddleware;
use BookSphere\App\Models\Category;
use BookSphere\App\Models\User;
use BookSphere\App\Policies\RecommendationPolicy;
use BookSphere\App\Presenters\RecommendationDashboardPresenter;
use BookSphere\App\Repositories\BookRepository;
use BookSphere\App\Repositories\RecommendationRepository;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\PersonalizationCache;
use BookSphere\App\Services\RecommendationFactory;
use BookSphere\App\Services\RecommendationMetrics;
use BookSphere\App\Services\RecommendationScoring;
use BookSphere\App\Services\RecommendationService;
use BookSphere\App\Strategies\HighestRatedStrategy;
use BookSphere\App\Strategies\PopularBooksStrategy;
use BookSphere\App\Strategies\RecentlyAddedStrategy;
use BookSphere\App\Strategies\SameAuthorStrategy;
use BookSphere\App\Strategies\SameCategoryStrategy;
use BookSphere\App\Strategies\TrendingBooksStrategy;

// ---------------------------------------------------------------------
// 0. Boot: fresh throwaway database, migrated and seeded.
// ---------------------------------------------------------------------

(new Environment(root_path('.env')))->load();

$dbPath = root_path('database/optimization_test.db');

foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

Database::instance($dbPath);
(new Migrator(db(), root_path('database/migrations')))->run();
(new Seeder(db(), root_path('database/seeds')))->run();

// A session must exist BEFORE any output (session_start() refuses
// to run once output has been sent).
$session = new Session('optimization_test');
$session->start();
AuthService::setInstance(new AuthService($session, new User()));

// ---------------------------------------------------------------------
// Wiring (mirrors routes/web.php): the service WITHOUT a cache (used
// by the presenter tests, like the Phase 6.4 suite) and a second
// service WITH a throwaway cache directory (used by the cache,
// degradation and metrics tests).
// ---------------------------------------------------------------------

$bookRepository = new BookRepository();
$repository     = new RecommendationRepository($bookRepository);

$factory = new RecommendationFactory(
    new PopularBooksStrategy($repository),
    new HighestRatedStrategy($repository),
    new TrendingBooksStrategy($repository),
    new SameCategoryStrategy($repository),
    new RecentlyAddedStrategy($repository),
    new SameAuthorStrategy($repository),
);

$service        = new RecommendationService($factory, $repository);
$policy         = new RecommendationPolicy();
$presenter      = new RecommendationDashboardPresenter($service, $repository, $bookRepository, new Category());
$limiter        = new RateLimiter($session);
$controller     = new RecommendationController($service, $policy, $presenter, $limiter);

$cacheDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'booksphere_optimization_cache';

foreach (glob($cacheDir . '/user_*.json') ?: [] as $file) {
    unlink($file);
}

$cache         = new PersonalizationCache($cacheDir, 1800);
$cachedService = new RecommendationService($factory, $repository, $cache, new Logger(root_path('storage/logs/optimization_test.log')));
$metrics       = new RecommendationMetrics($repository, $cache);
$admin         = new AdminController($metrics);
$adminAuth     = new AdminMiddleware(auth());

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

function bookIdByTitle(string $title): int
{
    return (int) db()->query('SELECT id FROM books WHERE title = ?', [$title])[0]['id'];
}

function insertWishlist(int $bookId, int $userId): void
{
    db()->execute(
        'INSERT INTO wishlist (user_id, book_id, created_at) VALUES (?, ?, ?)',
        [$userId, $bookId, gmdate('Y-m-d\TH:i:s\Z')],
    );
}

function insertView(int $bookId, int $userId): void
{
    db()->execute(
        'INSERT INTO book_views (user_id, book_id, viewed_at) VALUES (?, ?, ?)',
        [$userId, $bookId, gmdate('Y-m-d\TH:i:s\Z')],
    );
}

function asUser(Session $session, int $id, string $name, string $email, string $role = 'user'): void
{
    $session->put('auth_user_id', $id);
    $session->put('auth_user', ['id' => $id, 'full_name' => $name, 'email' => $email, 'role' => $role]);
}

function explainPlan(string $sql): string
{
    $rows = db()->query('EXPLAIN QUERY PLAN ' . $sql);

    $plan = '';

    foreach ($rows as $row) {
        $plan .= (string) ($row['detail'] ?? '') . "\n";
    }

    return $plan;
}

// ---------------------------------------------------------------------
// 1. Indexes: migration 0013 + EXPLAIN QUERY PLAN proof
// ---------------------------------------------------------------------

section('1. INDEXES: migration 0013 serves the engine queries');

$indexNames = array_map(
    static fn (array $row): string => (string) $row['name'],
    db()->query("SELECT name FROM sqlite_master WHERE type = 'index' AND name LIKE 'idx_%'"),
);

foreach (['idx_reviews_book_created', 'idx_wishlist_book_created', 'idx_book_views_user_viewed', 'idx_books_status_deleted'] as $index) {
    check('The index ' . $index . ' exists', in_array($index, $indexNames, true));
}

$reviewPlan = explainPlan(
    "SELECT COUNT(*) FROM reviews r WHERE r.book_id = 1 AND r.created_at >= '2000-01-01T00:00:00Z'",
);
check('The review count uses the composite index', str_contains($reviewPlan, 'idx_reviews_book_created'), trim(str_replace("\n", ' | ', $reviewPlan)));

$wishlistPlan = explainPlan(
    "SELECT COUNT(*) FROM wishlist w WHERE w.book_id = 1 AND w.created_at >= '2000-01-01T00:00:00Z'",
);
check('The wishlist count uses the composite index', str_contains($wishlistPlan, 'idx_wishlist_book_created'));

$viewsPlan = explainPlan(
    'SELECT id FROM book_views WHERE user_id = 1 ORDER BY viewed_at DESC LIMIT 10',
);
check('The recent-views read uses the composite index', str_contains($viewsPlan, 'idx_book_views_user_viewed'));

$activePlan = explainPlan(
    "SELECT id FROM books b WHERE b.deleted_at IS NULL AND b.status = 'published'",
);
check('The active-catalogue filter uses the status index', str_contains($activePlan, 'idx_books_status_deleted') || str_contains($activePlan, 'idx_books_status_rating'));

// ---------------------------------------------------------------------
// 2. Scoring: the 0-100 normalization
// ---------------------------------------------------------------------

section('2. SCORING: every score lives on the 0-100 scale');

check('A raw popularity of 0 is 0', RecommendationScoring::popularityPercent(0.0) === 0);
check('The normalizer value maps to 100', RecommendationScoring::popularityPercent(RecommendationScoring::POPULARITY_NORMALIZER) === 100);
check('Popularity is capped at 100', RecommendationScoring::popularityPercent(999.0) === 100);
check('Popularity never dips below 0', RecommendationScoring::popularityPercent(-5.0) === 0);
check('Popularity half of the normalizer is 50', RecommendationScoring::popularityPercent(1.5) === 50);

check('Trending 0 is 0', RecommendationScoring::trendingPercent(0.0) === 0);
check('Trending max maps to 100', RecommendationScoring::trendingPercent(RecommendationScoring::TRENDING_MAX_RAW) === 100);
check('Trending is capped at 100', RecommendationScoring::trendingPercent(12.0) === 100);

$bounded = true;

for ($i = -10; $i <= 100; $i++) {
    $raw = $i / 4;

    if (RecommendationScoring::popularityPercent($raw) < 0 || RecommendationScoring::popularityPercent($raw) > 100) {
        $bounded = false;
    }

    if (RecommendationScoring::trendingPercent($raw) < 0 || RecommendationScoring::trendingPercent($raw) > 100) {
        $bounded = false;
    }
}

check('Both normalizations stay inside 0-100 across a sweep', $bounded);

$monotonic = true;

for ($i = 0; $i < 100; $i++) {
    if (RecommendationScoring::popularityPercent(($i + 1) / 10) < RecommendationScoring::popularityPercent($i / 10)) {
        $monotonic = false;
    }
}

check('Normalization is monotonic (ordering never changes)', $monotonic);

// ---------------------------------------------------------------------
// 3. Presenter: freshness phrase + cross-section deduplication
// ---------------------------------------------------------------------

section('3. PRESENTER: freshness and no cross-section duplicates');

// Give riya (user 2) enough signals for a full dashboard: the seeds
// already give her reviews; add wishlist saves and views.
$martianId  = bookIdByTitle('The Martian');
$hobbitId   = bookIdByTitle('The Hobbit');
$wingsId    = bookIdByTitle('Wings of Fire');
$nineteenId = bookIdByTitle('1984');
$hungerId   = bookIdByTitle('The Hunger Games');

insertWishlist($hobbitId, 2);
insertWishlist($hungerId, 2);
insertView($wingsId, 2);
insertView($nineteenId, 2);

asUser($session, 2, 'Riya', 'riya@booksphere.test');

$dashboard = $presenter->compose();

check('The payload carries the freshness phrase', isset($dashboard['updatedAgo']) && is_string($dashboard['updatedAgo']));
check('The phrase follows the "Updated X ago" shape', (bool) preg_match('/^Updated (just now|\d+ (minute|hour|day)s? ago|.+)$/', $dashboard['updatedAgo']));
check('The recommended section carries its own freshness phrase', (bool) preg_match('/^Updated /', $dashboard['recommended']['updatedAgo'] ?? ''));
check('The recommended total agrees with its items', $dashboard['recommended']['total'] === count($dashboard['recommended']['items']));

$sectionIds = [];

foreach ($dashboard['recommended']['items'] as $item) {
    $sectionIds[] = (int) $item['id'];
}

foreach ($dashboard['becauseLiked'] as $block) {
    foreach ($block['items'] as $item) {
        $sectionIds[] = (int) $item['id'];
    }
}

foreach ($dashboard['follow'] as $item) {
    $sectionIds[] = (int) $item['id'];
}

foreach ($dashboard['trending'] as $item) {
    $sectionIds[] = (int) $item['id'];
}

foreach ($dashboard['recent'] as $item) {
    $sectionIds[] = (int) $item['id'];
}

$duplicates = array_filter(array_count_values($sectionIds), static fn (int $count): bool => $count > 1);

check('No book appears in two dashboard sections', $duplicates === [], 'dupes: ' . implode(',', array_keys($duplicates)));

// ---------------------------------------------------------------------
// 4. Cache: round-trip, invalidation, flush, graceful degradation
// ---------------------------------------------------------------------

section('4. CACHE: round-trip, invalidation, flush, degradation');

$result = $cachedService->getPersonalizedRecommendations(2, 5);

check('The cached service still returns a real result', $result instanceof \BookSphere\App\DTO\RecommendationResult);

$filesAfterRun = glob($cacheDir . '/user_*.json') ?: [];
check('The run stored a per-user cache file', count($filesAfterRun) === 1, 'files: ' . count($filesAfterRun));

$cacheHit = $cachedService->getPersonalizedRecommendations(2, 5);
check('A second run still works (served from cache)', $cacheHit->total === $result->total);

$cachedService->invalidatePersonalization(2);
check('invalidatePersonalization() drops the user file', glob($cacheDir . '/user_2.json') === [] || glob($cacheDir . '/user_2.json') === false);

// Invalidate twice (refresh + toggle happen back to back in the app).
$cachedService->invalidatePersonalization(2);
check('A second invalidate is a quiet no-op (no crash)', true);

$cachedService->getPersonalizedRecommendations(2, 5);
$cachedService->flushPersonalization();
check('flushPersonalization() drops every user file', (glob($cacheDir . '/user_*.json') ?: []) === []);

// Corrupted payload: the file exists but holds junk JSON.
$cachedService->getPersonalizedRecommendations(3, 5);
file_put_contents($cacheDir . '/user_3.json', 'this is not json');

$degraded = $cachedService->getPersonalizedRecommendations(3, 5);
check('A corrupted cache file is a miss, not a crash', $degraded instanceof \BookSphere\App\DTO\RecommendationResult);
check('The corrupted file was replaced by a healthy run', (json_decode((string) file_get_contents($cacheDir . '/user_3.json'), true) ?: []) !== []);

// Phase 8.6 regression: the cache stores the shelf under the FIRST
// caller's limit - a later caller with a SMALLER limit must get its
// own limit re-applied (and the total recounted), not the full
// cached shelf. Before the fix the second read silently grew.
$cachedService->invalidatePersonalization(2);
$cachedService->getPersonalizedRecommendations(2, 5);
$smallerHit = $cachedService->getPersonalizedRecommendations(2, 3);
check('A cache hit re-applies the caller limit (cached 5, asked 3)', count($smallerHit->items) === 3 && $smallerHit->total === 3);

// The Phase 8.6 dashboard gate: personalizedShelfIsCached() must
// report true on a hit (nothing to log) and false on a miss (the
// generation gets logged).
check('personalizedShelfIsCached() reports the hit', $cachedService->personalizedShelfIsCached(2) === true);
$cachedService->invalidatePersonalization(2);
check('personalizedShelfIsCached() reports the miss after invalidate', $cachedService->personalizedShelfIsCached(2) === false);
check('personalizedShelfIsCached() never caches guests', $cachedService->personalizedShelfIsCached(0) === false);

// ---------------------------------------------------------------------
// 5. Rate limiting: window, limit, reset
// ---------------------------------------------------------------------

section('5. RATE LIMIT: the session RateLimiter');

$limiter->reset();
$allows = [$limiter->allow('demo', 3, 60), $limiter->allow('demo', 3, 60), $limiter->allow('demo', 3, 60)];

check('The first three hits within the limit are allowed', $allows === [true, true, true]);
check('The fourth hit is refused', $limiter->allow('demo', 3, 60) === false);

$session->put('_rate_limit', ['demo' => ['starts' => time() - 61, 'count' => 99]]);
check('An expired window starts fresh', $limiter->allow('demo', 3, 60) === true);

$limiter->reset();
check('reset() clears every bucket', $limiter->allow('demo', 3, 60) === true && $limiter->allow('demo', 3, 60) === true && $limiter->allow('demo', 3, 60) === true);

// The controller wiring: the throttled controller still works under
// the limit (the 429 exit path cannot run in CLI).
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';
$_POST['book_id'] = (string) $martianId;

ob_start();
$controller->toggleWishlist(new Request(), []);
$json = (string) ob_get_clean();

unset($_POST['book_id']);
unset($_SERVER['HTTP_X_REQUESTED_WITH']);

$payload = json_decode($json, true);
check('The throttled controller still answers the wishlist toggle under the limit', is_array($payload) && ($payload['saved'] ?? null) === true);

// ---------------------------------------------------------------------
// 6. Metrics: the summary composes live state
// ---------------------------------------------------------------------

section('6. METRICS: RecommendationMetrics::summary()');

$summary = $metrics->summary();

check('The summary carries all four blocks', isset($summary['cache'], $summary['config'], $summary['data'], $summary['scores']));
check('The cache block reports the cached shelves', ($summary['cache']['files'] ?? -1) >= 1, 'files: ' . ($summary['cache']['files'] ?? 'n/a'));
check('The cache block knows the TTL', ($summary['cache']['ttl'] ?? 0) === 1800);
check('The data block counts the published catalogue', ($summary['data']['totals']['published_books'] ?? 0) > 0);
check('The data block counts the signals', ($summary['data']['totals']['reviews'] ?? 0) > 0 && ($summary['data']['totals']['wishlist'] ?? 0) > 0);
check('The config block carries the live weights', (float) ($summary['config']['hybrid_weights']['category'] ?? 0) === 40.0);
check('The config block carries the rate limits', ($summary['config']['security']['rate_limit']['wishlist_toggle']['limit'] ?? 0) === 60);
check('The score block carries the normalized average', ($summary['scores']['popularity']['percent'] ?? -1) >= 0 && ($summary['scores']['popularity']['percent'] ?? 101) <= 100);
check('The score block sampled the catalogue', ($summary['scores']['sampleSize'] ?? 0) > 0);

// ---------------------------------------------------------------------
// 7. Admin: middleware + the monitoring page renders
// ---------------------------------------------------------------------

section('7. ADMIN: middleware and the monitoring page');

asUser($session, 1, 'Admin', 'admin@booksphere.test', 'admin');

$passed = $adminAuth->handle(new Request(), static fn (): string => 'authorized');
check('AdminMiddleware lets an admin through', $passed === 'authorized');

ob_start();
$admin->metrics(new Request(), []);
$html = (string) ob_get_clean();

check('The metrics page renders its headline', str_contains($html, 'Recommendation Engine'));
check('The metrics page renders the cache stat cards', str_contains($html, 'Cached Shelves') && str_contains($html, 'Cache Size'));
check('The metrics page renders the data health block', str_contains($html, 'Data health') && str_contains($html, 'Published books'));
check('The metrics page renders the top categories', str_contains($html, 'Top categories by signal'));
check('The metrics page renders the score cards', str_contains($html, 'Avg Popularity') && str_contains($html, 'Avg Trending'));
check('The flush tool renders as a CSRF form', str_contains($html, 'Flush cache') && str_contains($html, 'name="_token"'));
check('The page shows no engine errors', !str_contains($html, 'Application error'));

$session->forget('auth_user');

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------

section('RESULT');

echo '  Passed: ' . $pass . PHP_EOL;
echo '  Failed: ' . $fail . PHP_EOL;

echo PHP_EOL . 'Note: the throwaway database database/optimization_test.db and the' . PHP_EOL
    . 'cache directory ' . $cacheDir . ' are left in place for inspection;' . PHP_EOL
    . 'delete them anytime.' . PHP_EOL;

exit($fail === 0 ? 0 : 1);
