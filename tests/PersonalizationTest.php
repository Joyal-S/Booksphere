<?php

declare(strict_types=1);

/**
 * PersonalizationTest — CLI test suite for Phase 6.3
 *
 * Verifies the hybrid personalized recommendation engine without
 * touching the Book module or the development database:
 *
 *     1. Configuration   - the weights live in config/recommendations.php,
 *                          sum to 100, popularity is the smallest
 *     2. Scoring engine  - hybridScore() mirrors the documented
 *                          formula (partial credit, caps, bonuses)
 *     3. Pipeline steps  - filterRecommendations() (wishlist, viewed,
 *                          duplicates), sortRecommendations() (score,
 *                          then trending tiebreak), limitRecommendations()
 *     4. User profiles   - favourite categories/authors derived
 *                          automatically from wishlist + ratings +
 *                          reviews; poorly rated books contribute
 *                          nothing; empty users get an empty profile
 *     5. Personalized shelves - the brief's scenarios: empty wishlist,
 *                          many ratings, reviews only, new user, heavy
 *                          user; wishlist exclusion; no duplicates;
 *                          every item carries score + reason +
 *                          confidence; different users get different
 *                          shelves; recently-viewed similarity works
 *                          and never recommends the viewed book itself
 *     6. Cache           - hit/miss, TTL expiry, invalidate() drops
 *                          the payload, service restores the cached
 *                          result (same generatedAt) and recomputes
 *                          after invalidation
 *     7. View tracking   - recordBookView() upserts and caps
 *     8. Controller smoke - /recommendations renders the personal
 *                          shelf with reasons and score chips
 *
 * Run from the project root:
 *
 *     php tests/PersonalizationTest.php
 *
 * Same harness as the Phase 6.2 suite: a throwaway SQLite database
 * (database/personalization_test.db) is migrated (including the
 * Phase 6.3 book_views table) and seeded; all scenario data goes
 * into the throwaway database only.
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Core\Migrator;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Seeder;
use BookSphere\App\Core\Session;
use BookSphere\App\Controllers\RecommendationController;
use BookSphere\App\DTO\PersonalizationProfile;
use BookSphere\App\Policies\RecommendationPolicy;
use BookSphere\App\Repositories\BookRepository;
use BookSphere\App\Repositories\RecommendationRepository;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\PersonalizationCache;
use BookSphere\App\Services\RecommendationFactory;
use BookSphere\App\Services\RecommendationScoring;
use BookSphere\App\Services\RecommendationService;
use BookSphere\App\Strategies\HighestRatedStrategy;
use BookSphere\App\Strategies\PopularBooksStrategy;
use BookSphere\App\Strategies\RecentlyAddedStrategy;
use BookSphere\App\Strategies\SameAuthorStrategy;
use BookSphere\App\Strategies\SameCategoryStrategy;
use BookSphere\App\Strategies\TrendingBooksStrategy;
use BookSphere\App\Models\User;

// ---------------------------------------------------------------------
// 0. Boot: fresh throwaway database + cache directory.
// ---------------------------------------------------------------------

(new Environment(root_path('.env')))->load();

$dbPath = root_path('database/personalization_test.db');

foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

Database::instance($dbPath);
(new Migrator(db(), root_path('database/migrations')))->run();
(new Seeder(db(), root_path('database/seeds')))->run();

// Two throwaway reviewers so rating-based scenarios have distinct
// reviewers per book (users: 1=admin, 2=riya, 3=arjun, 4=meera).
foreach (['tester.a@test.dev', 'tester.b@test.dev'] as $email) {
    db()->execute(
        'INSERT INTO users (full_name, email, password) VALUES (?, ?, ?)',
        ['Test Reviewer', $email, 'x'],
    );
}

// Scenario users, created explicitly so every scenario starts from a
// known signal set (ids 7+ are never seeded).
$userIds = [];
foreach ([
    'clean@test.dev'      => 'Clean User',   // no signals at all
    'scifi@test.dev'      => 'Scifi Fan',
    'bio@test.dev'        => 'Bio Fan',
    'reviews@test.dev'    => 'Reviews Only',
    'heavy@test.dev'      => 'Heavy User',
    'views@test.dev'      => 'Viewer',
    'cache@test.dev'      => 'Cache Test',
] as $email => $name) {
    db()->execute(
        'INSERT INTO users (full_name, email, password) VALUES (?, ?, ?)',
        [$name, $email, 'x'],
    );
    $userIds[$email] = (int) db()->query('SELECT id FROM users WHERE email = ?', [$email])[0]['id'];
}

// A session must exist BEFORE any output.
$session = new Session('personalization_test');
$session->start();
AuthService::setInstance(new AuthService($session, new User()));

// ---------------------------------------------------------------------
// Wiring (mirrors routes/web.php exactly, including the cache).
// ---------------------------------------------------------------------

$repository = new RecommendationRepository(new BookRepository());

$factory = new RecommendationFactory(
    new PopularBooksStrategy($repository),
    new HighestRatedStrategy($repository),
    new TrendingBooksStrategy($repository),
    new SameCategoryStrategy($repository),
    new RecentlyAddedStrategy($repository),
    new SameAuthorStrategy($repository),
);

$cacheDir = root_path('database/personalization_test_cache');

foreach (glob($cacheDir . '/user_*.json') ?: [] as $file) {
    @unlink($file);
}

$cache    = new PersonalizationCache($cacheDir, (int) config('recommendations.cache.ttl_seconds', 1800));
$service  = new RecommendationService($factory, $repository, $cache);
$policy   = new RecommendationPolicy();
$controller = new RecommendationController($service, $policy);

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

function categoryIdByName(string $name): int
{
    return (int) db()->query('SELECT id FROM categories WHERE name = ?', [$name])[0]['id'];
}

function insertReview(int $bookId, int $userId, int $rating, ?string $review = 'Test review'): void
{
    db()->execute(
        'INSERT INTO reviews (user_id, book_id, rating, review, created_at) VALUES (?, ?, ?, ?, ?)',
        [$userId, $bookId, $rating, $review, gmdate('Y-m-d\TH:i:s\Z')],
    );
}

function insertWishlist(int $bookId, int $userId): void
{
    db()->execute(
        'INSERT INTO wishlist (user_id, book_id, created_at) VALUES (?, ?, ?)',
        [$userId, $bookId, gmdate('Y-m-d\TH:i:s\Z')],
    );
}

// ---------------------------------------------------------------------
// 1. Configuration: the weights
// ---------------------------------------------------------------------

section('1. CONFIG: hybrid weights');

$weights = RecommendationScoring::hybridWeights();

check('The six weights exist', count(array_intersect(array_keys($weights), ['category', 'author', 'wishlist', 'rating', 'trending', 'popularity'])) === 6);
check('The weights sum to 100', abs(array_sum($weights) - 100) < 0.001);
check('Popularity is the smallest weight (never dominates)', $weights['popularity'] < $weights['category'] && $weights['popularity'] <= $weights['wishlist']);
check('Category is the dominant weight', $weights['category'] > $weights['author'] && $weights['category'] > $weights['wishlist']);
check('The config file holds the weights', (int) config('recommendations.hybrid_weights.category') === (int) $weights['category']);
check('The cache TTL is configured', (int) config('recommendations.cache.ttl_seconds') === 1800);

// ---------------------------------------------------------------------
// 2. The scoring engine (pure mirror)
// ---------------------------------------------------------------------

section('2. SCORING: the hybrid formula');

$score = $service->calculateHybridScore([
    'category'   => 1,
    'author'     => 0,
    'wishlist'   => 0,
    'viewed'     => 0,
    'rating'     => 0,
    'trending'   => 0,
    'popularity' => 0,
]);
check('One shared favourite category earns half the category weight', abs($score - $weights['category'] / 2) < 0.001);

$score = $service->calculateHybridScore([
    'category'   => 2,
    'author'     => 1,
    'wishlist'   => 0,
    'viewed'     => 0,
    'rating'     => 0,
    'trending'   => 0,
    'popularity' => 0,
]);
check('Two shared categories + one author earn 40 + 25', abs($score - ($weights['category'] + $weights['author'])) < 0.001);

$score = $service->calculateHybridScore([
    'category'   => 5,
    'author'     => 0,
    'wishlist'   => 0,
    'viewed'     => 0,
    'rating'     => 0,
    'trending'   => 0,
    'popularity' => 0,
]);
check('Shared categories are capped at 2', abs($score - $weights['category']) < 0.001);

$score = $service->calculateHybridScore([
    'category'   => 0,
    'author'     => 0,
    'wishlist'   => 3,
    'viewed'     => 0,
    'rating'     => 0,
    'trending'   => 5.0,
    'popularity' => 3.0,
]);
check('Wishlist + trending + full popularity earn 15 + 5 + 5', abs($score - ($weights['wishlist'] + $weights['trending'] + $weights['popularity'])) < 0.001);

$score = $service->calculateHybridScore([
    'category'   => 0,
    'author'     => 0,
    'wishlist'   => 0,
    'viewed'     => 0,
    'rating'     => 0,
    'trending'   => 0,
    'popularity' => 100,
]);
check('Popularity alone can never exceed its small weight', $score <= $weights['popularity'] + 0.001);

$zero = $service->calculateHybridScore([
    'category' => 0, 'author' => 0, 'wishlist' => 0, 'viewed' => 0, 'rating' => 0, 'trending' => 0, 'popularity' => 0,
]);
check('No signals -> score 0', $zero === 0.0);

// ---------------------------------------------------------------------
// 3. The pipeline steps
// ---------------------------------------------------------------------

section('3. PIPELINE: filter, sort, limit');

$raw = [
    ['id' => 1, 'score' => 10, 'title' => 'A'],
    ['id' => 2, 'score' => 20, 'title' => 'B'],
    ['id' => 1, 'score' => 99, 'title' => 'A dup'],
    ['id' => 0, 'score' => 99, 'title' => 'junk'],
    ['id' => 3, 'score' => 30, 'title' => 'C'],
];
$filtered = $service->filterRecommendations($raw, [3]);
check('filterRecommendations() drops excluded ids', array_column($filtered, 'id') === [1, 2]);
check('filterRecommendations() drops duplicates', count($filtered) === 2);
check('filterRecommendations() drops junk ids', array_search(0, array_column($filtered, 'id'), true) === false);

$sorted = $service->sortRecommendations([
    ['id' => 1, 'score' => 10, 'trending_score' => 0, 'popularity_score' => 5],
    ['id' => 2, 'score' => 10, 'trending_score' => 2, 'popularity_score' => 0],
    ['id' => 3, 'score' => 10, 'trending_score' => 0, 'popularity_score' => 9],
    ['id' => 4, 'score' => 50, 'trending_score' => 0, 'popularity_score' => 0],
]);
check('sortRecommendations() orders by score first', (int) $sorted[0]['id'] === 4);
check('Equal scores prefer the trending book', (int) $sorted[1]['id'] === 2);
check('Then popularity, then id for determinism', (int) $sorted[2]['id'] === 3 && (int) $sorted[3]['id'] === 1);

check('limitRecommendations() cuts to size', count($service->limitRecommendations($sorted, 2)) === 2);
check('limitRecommendations() keeps order', $service->limitRecommendations($sorted, 2) === array_slice($sorted, 0, 2));

// ---------------------------------------------------------------------
// 4. Profiles: favourites derived from the signal sources
// ---------------------------------------------------------------------

section('4. PROFILES: favourite categories and authors');

// scifi@test.dev: wishlists The Martian, 1984 and Sapiens (Science
// Fiction + History) and rates Sapiens 4 - both signals combine.
$martianId = bookIdByTitle('The Martian');
$nineteenId = bookIdByTitle('1984');
$sapiensId = bookIdByTitle('Sapiens');
insertWishlist($martianId, $userIds['scifi@test.dev']);
insertWishlist($nineteenId, $userIds['scifi@test.dev']);
insertWishlist($sapiensId, $userIds['scifi@test.dev']);
insertReview($sapiensId, $userIds['scifi@test.dev'], 4);

// bio@test.dev: wishlists Wings of Fire and Malgudi Days
// (Biography & Memoir / Short Stories).
$wingsId = bookIdByTitle('Wings of Fire');
$malgudiId = bookIdByTitle('Malgudi Days');
insertWishlist($wingsId, $userIds['bio@test.dev']);
insertWishlist($malgudiId, $userIds['bio@test.dev']);

// The seed catalog holds exactly ONE Biography & Memoir book, so the
// bio wishlist alone would leave Biography & Memoir tied with Fiction
// and Short Stories at weight 3. Add a second biography book so the
// favourite is unambiguous (weight 6) and the tie-break can never
// push Biography out of the top-2 favourites.
db()->execute(
    "INSERT INTO books (google_book_id, isbn, title, description, publisher, published_year, language, page_count, average_rating, ratings_count, status)
     VALUES ('GB901', '9780000000001', 'The Story of My Life', 'A biography for the personalization tests.', 'Test Press', 2025, 'en', 320, 4.0, 1, 'published')",
);
$bioBookId = (int) db()->lastInsertId();
db()->execute('INSERT INTO book_categories (book_id, category_id) VALUES (?, ?)', [$bioBookId, categoryIdByName('Biography & Memoir')]);
insertWishlist($bioBookId, $userIds['bio@test.dev']);

// reviews@test.dev: only written reviews, three Science Fiction books.
$hungerGamesId = bookIdByTitle('The Hunger Games');
insertReview($martianId, $userIds['reviews@test.dev'], 5);
insertReview($hungerGamesId, $userIds['reviews@test.dev'], 4);
insertReview($nineteenId, $userIds['reviews@test.dev'], 4);

// The profile builder is private, so the observable outcome is used:
// a personal shelf whose reasons name the favourite categories.
$scifiShelf = $service->getPersonalizedRecommendations($userIds['scifi@test.dev'], 10);
$scifiReasons = implode(' ', array_column($scifiShelf->items, 'reason'));
check('Wishlist signals surface Science Fiction as a favourite', str_contains($scifiReasons, 'Science Fiction'));
check('Ratings and wishlist signals combine into favourites', str_contains($scifiReasons, 'History'));

$bioShelf = $service->getPersonalizedRecommendations($userIds['bio@test.dev'], 10);
$bioReasons = implode(' ', array_column($bioShelf->items, 'reason'));
check('Biography wishlist surfaces Biography favourites', str_contains($bioReasons, 'Biography'));

// Poorly rated books must not build favourites: heavy@test.dev rates
// one Biography book 1 star and one SF book 1 star, plus nothing
// else. Its shelf must NOT claim "You enjoy Biography/SF".
$heavyId = $userIds['heavy@test.dev'];
insertReview($wingsId, $heavyId, 1);
insertReview($martianId, $heavyId, 1);
$heavyShelf = $service->getPersonalizedRecommendations($heavyId, 10);
$heavyReasons = implode(' ', array_column($heavyShelf->items, 'reason'));
check('1-star ratings never build favourites', !str_contains($heavyReasons, 'You enjoy') && !str_contains($heavyReasons, 'Because you follow'));
check('The poorly rated user still gets a fallback shelf', $heavyShelf->total > 0);

// ---------------------------------------------------------------------
// 5. Personalized shelves: the brief's scenarios
// ---------------------------------------------------------------------

section('5. SHELVES: the scenarios');

// --- New user: no signals at all --------------------------------------

$cleanShelf = $service->getPersonalizedRecommendations($userIds['clean@test.dev'], 10);
$cleanReasons = implode(' ', array_column($cleanShelf->items, 'reason'));
check('A new user gets a shelf (popularity fallback)', $cleanShelf->total > 0);
// The fallback pool is picked by popularity, so some items are honest
// about being trending too; what they must NOT do is claim personal
// knowledge ("You enjoy...", "Because you follow...", "wishlist").
check('The fallback reasons make no personal claims', !str_contains($cleanReasons, 'You enjoy') && !str_contains($cleanReasons, 'Because you follow') && !str_contains($cleanReasons, 'wishlist'));
// Every seeded review is recent, so every popular book is trending and
// the "starting point" branch is data-suppressed; it is unit-tested
// directly on the public reason API in section 5 below.
check('The fallback shelf is low confidence', array_reduce($cleanShelf->items, fn (bool $c, array $i): bool => $c && $i['confidence'] === 'low', true));

// --- Every item is explained ------------------------------------------

$scifiShelf = $service->getPersonalizedRecommendations($userIds['scifi@test.dev'], 10);
check('Every item carries a reason', array_reduce($scifiShelf->items, fn (bool $c, array $i): bool => $c && $i['reason'] !== '', true));
check('Every item carries a score 0-100', array_reduce($scifiShelf->items, fn (bool $c, array $i): bool => $c && (float) $i['score'] > 0 && (float) $i['score'] <= 100, true));
check('Every item carries a confidence label', array_reduce($scifiShelf->items, fn (bool $c, array $i): bool => $c && in_array($i['confidence'], ['high', 'medium', 'low'], true), true));
check('No duplicate books on the shelf', count(array_column($scifiShelf->items, 'id')) === count(array_unique(array_column($scifiShelf->items, 'id'))));
check('Scores are sorted descending', (function (array $items): bool {
    $previous = PHP_FLOAT_MAX;
    foreach ($items as $item) {
        if ((float) $item['score'] > $previous) {
            return false;
        }
        $previous = (float) $item['score'];
    }
    return true;
})($scifiShelf->items));

// --- Wishlist exclusion ------------------------------------------------

$martianIds = array_column($scifiShelf->items, 'id');
check('Wishlist books are never recommended', array_search($martianId, $martianIds, true) === false && array_search($nineteenId, $martianIds, true) === false);

// --- Different users get different shelves -----------------------------

$sfCategoryId = categoryIdByName('Science Fiction');

$scifiIds = array_column($scifiShelf->items, 'id');
$bioIds   = array_column($bioShelf->items, 'id');
check('Two users with different interests get different shelves', $scifiIds !== $bioIds);
check('The scifi user gets Science Fiction books first', (function (array $items) use ($sfCategoryId): bool {
    foreach (array_slice($items, 0, 3) as $item) {
        if (str_contains($item['categories_list'], 'Science Fiction')) {
            return true;
        }
    }
    return false;
})($scifiShelf->items));

// --- Stable scoring -----------------------------------------------------
//
// MUST run before the heavy-user reviews below: those inserts change
// the review counts, hence the popularity tie-breaks, so a shelf
// recomputed after them can legitimately differ.

$stableA = $service->getPersonalizedRecommendations($userIds['scifi@test.dev'], 10);
$cache->invalidate($userIds['scifi@test.dev']);
$stableB = $service->getPersonalizedRecommendations($userIds['scifi@test.dev'], 10);
check('The same user gets the same shelf when recomputed', array_column($stableA->items, 'id') === array_column($stableB->items, 'id'));

// --- Heavy user: many ratings, still fast and clean --------------------

// 15 more reviews across books the heavy user has NOT rated yet (the
// UNIQUE(user_id, book_id) rule forbids a second review of the same
// book). The shelf is recomputed after a cache invalidation.
$untouchedIds = array_map(
    fn (array $row): int => (int) $row['id'],
    db()->query(
        'SELECT id FROM books WHERE id NOT IN (?, ?) ORDER BY id LIMIT 15',
        [$wingsId, $martianId],
    ),
);
foreach ($untouchedIds as $i => $bookId) {
    insertReview($bookId, $heavyId, [5, 4, 3, 2][$i % 4]);
}
$cache->invalidate($heavyId);
$heavyShelf = $service->getPersonalizedRecommendations($heavyId, 10);
check('A heavy user gets a valid shelf', $heavyShelf->total > 0 && $heavyShelf->total <= 10);
check('The heavy shelf is fully explained', array_reduce($heavyShelf->items, fn (bool $c, array $i): bool => $c && $i['reason'] !== '', true));

// --- Recently viewed similarity -----------------------------------------

$viewsId = $userIds['views@test.dev'];
$service->recordBookView($viewsId, $nineteenId);   // 1984: Science Fiction + Classic Fiction
$viewsShelf = $service->getPersonalizedRecommendations($viewsId, 10);
$viewedReasons = implode(' ', array_column($viewsShelf->items, 'reason'));
check('Recently viewed books feed the similarity factor', str_contains($viewedReasons, 'recently viewed'));
check('The viewed book itself is never recommended', array_search($nineteenId, array_column($viewsShelf->items, 'id'), true) === false);

// --- Reviewer with only reviews -----------------------------------------

$reviewsShelf = $service->getPersonalizedRecommendations($userIds['reviews@test.dev'], 10);
$reviewsReasons = implode(' ', array_column($reviewsShelf->items, 'reason'));
check('A reviews-only user gets category reasons', str_contains($reviewsReasons, 'Science Fiction'));

// --- The honest "starting point" fallback reason -------------------------
//
// Every seeded review is recent, so every popular book is trending and
// this branch never fires on the shelf data above. It is verified
// directly through the public reason API: an item with no matched
// factor must not claim personal knowledge.

$emptyProfile = new PersonalizationProfile(
    userId:                999,
    favouriteCategories:   [],
    favouriteAuthors:      [],
    wishlistBookIds:       [],
    highlyRatedBookIds:    [],
    reviewedBookIds:       [],
    recentlyViewedBookIds: [],
    builtAt:               gmdate('Y-m-d\TH:i:s\Z'),
);
$startingReason = $service->getRecommendationReason([], $emptyProfile);
check('No-signal items admit they are a starting point', str_contains($startingReason, 'starting point'));

// ---------------------------------------------------------------------
// 6. Cache
// ---------------------------------------------------------------------

section('6. CACHE: per-user, TTL, invalidation');

$cacheId = $userIds['cache@test.dev'];

$first = $service->getPersonalizedRecommendations($cacheId, 10);
check('The first call computes a fresh result', $first->total > 0);

$second = $service->getPersonalizedRecommendations($cacheId, 10);
check('The second call restores the cached result', $second->generatedAt === $first->generatedAt);

$payload = $cache->get($cacheId);
check('The cache file holds the payload', is_array($payload) && (int) $payload['total'] === $first->total);

// A new signal arrives AFTER the first computation. The next call must
// serve the stale cached shelf (that is what makes the cache useful),
// and only an explicit invalidation must produce the updated shelf.
// generatedAt cannot prove it: two runs inside the same second share
// the timestamp, so the wishlist exclusion is the observable change.
insertWishlist($martianId, $cacheId);

$stale = $service->getPersonalizedRecommendations($cacheId, 10);
check('The cache serves the stale shelf after a signal change', $stale->generatedAt === $first->generatedAt);

$service->invalidatePersonalization($cacheId);
check('invalidatePersonalization() drops the payload', $cache->get($cacheId) === null);

$fresh = $service->getPersonalizedRecommendations($cacheId, 10);
check('After invalidation a fresh result is computed', array_search($martianId, array_column($fresh->items, 'id'), true) === false);
check('The fresh shelf is cached again', $cache->get($cacheId) !== null);

$cache->flush();
check('flush() clears every user', $cache->get($cacheId) === null);

// A cache with a 1-second TTL expires.
$shortCache = new PersonalizationCache($cacheDir . '/short', 1);
$shortCache->put(1, ['total' => 3]);
check('A fresh short-TTL cache hits', $shortCache->get(1) === ['total' => 3]);
sleep(2);
check('A stale short-TTL cache misses', $shortCache->get(1) === null);

// A disabled cache never stores anything.
$offCache = new PersonalizationCache($cacheDir . '/off', 30, false);
$offCache->put(2, ['total' => 3]);
check('A disabled cache never stores', $offCache->get(2) === null);

// ---------------------------------------------------------------------
// 7. View tracking
// ---------------------------------------------------------------------

section('7. VIEWS: the recently-viewed signal');

$service->recordBookView($viewsId, $martianId);
$service->recordBookView($viewsId, $martianId); // same book again
$service->recordBookView($viewsId, $sapiensId);

$viewHistory = $repository->recentlyViewedBookIds($viewsId, 50);
check('recordBookView() upserts (no duplicates)', count(array_unique($viewHistory)) === count($viewHistory) && count($viewHistory) === 3);

$service->recordBookView($viewsId, 0);
$service->recordBookView(0, $martianId);
check('recordBookView() ignores junk ids', $repository->recentlyViewedBookIds($viewsId, 50) === $viewHistory);

$viewedNow = $repository->recentlyViewedBookIds($viewsId, 2);
check('The cap returns the most recent views', count($viewedNow) === 2 && in_array($sapiensId, $viewedNow, true));

// ---------------------------------------------------------------------
// 8. Controller smoke: the personal shelf renders
// ---------------------------------------------------------------------

section('8. CONTROLLER: the personal shelf');

$session->put('auth_user_id', $userIds['scifi@test.dev']);
$session->put('auth_user', ['id' => $userIds['scifi@test.dev'], 'full_name' => 'Scifi Fan', 'email' => 'scifi@test.dev', 'role' => 'user']);

ob_start();
$controller->index(new Request(), []);
$html = (string) ob_get_clean();
check('index() renders the personal shelf', str_contains($html, 'Recommended for you') && str_contains($html, 'Hybrid personalization'));
check('The personal shelf shows reasons', str_contains($html, 'rec-reason') && str_contains($html, 'You enjoy'));
check('The personal shelf shows scores and confidence', str_contains($html, 'rec-score') && str_contains($html, 'confidence'));
check('The strategy cards still render', substr_count($html, 'rec-card') >= 6);
check('No personal books are wishlist books', !str_contains($html, 'The Martian</span>') && !str_contains($html, '1984</span>'));

$session->forget('auth_user');

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------

section('RESULT');

echo '  Passed: ' . $pass . PHP_EOL;
echo '  Failed: ' . $fail . PHP_EOL;

echo PHP_EOL . 'Note: the throwaway database database/personalization_test.db and the' . PHP_EOL
    . 'cache directory database/personalization_test_cache are left in place' . PHP_EOL
    . 'for inspection; delete them anytime.' . PHP_EOL;

exit($fail === 0 ? 0 : 1);
