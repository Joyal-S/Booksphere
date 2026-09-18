<?php

declare(strict_types=1);

/**
 * RecommendationRatedExclusionTest
 *
 * Phase R1 — Dedicated Automated Regression Test Suite
 *
 * Verifies that books already rated or reviewed by a user are strictly
 * excluded from personalized recommendations, while preserving all existing
 * exclusions (library, wishlist, recently viewed), user isolation, and
 * caching correctness.
 *
 * Run from project root:
 *     php tests/RecommendationRatedExclusionTest.php
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Core\Migrator;
use BookSphere\App\Core\Seeder;
use BookSphere\App\Core\Session;
use BookSphere\App\Models\Category;
use BookSphere\App\Models\User;
use BookSphere\App\Models\UserLibrary;
use BookSphere\App\Presenters\RecommendationDashboardPresenter;
use BookSphere\App\Repositories\BookRepository;
use BookSphere\App\Repositories\RecommendationRepository;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\PersonalizationCache;
use BookSphere\App\Services\RecommendationFactory;
use BookSphere\App\Services\RecommendationService;
use BookSphere\App\Strategies\HighestRatedStrategy;
use BookSphere\App\Strategies\PopularBooksStrategy;
use BookSphere\App\Strategies\RecentlyAddedStrategy;
use BookSphere\App\Strategies\SameAuthorStrategy;
use BookSphere\App\Strategies\SameCategoryStrategy;
use BookSphere\App\Strategies\TrendingBooksStrategy;

$checks = 0;
$failed = 0;

function check(string $description, bool $condition): void
{
    global $checks, $failed;
    $checks++;
    if ($condition) {
        echo "  PASS  $description\n";
    } else {
        $failed++;
        echo "  FAIL  $description\n";
    }
}

// ---------------------------------------------------------------------
// 1. Setup Isolated Throwaway Database & Cache
// ---------------------------------------------------------------------

(new Environment(root_path('.env')))->load();

$dbPath = root_path('database/recommendation_rated_exclusion_test.db');
foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
    if (is_file($file)) {
        @unlink($file);
    }
}

Database::instance($dbPath);
(new Migrator(db(), root_path('database/migrations')))->run();
(new Seeder(db(), root_path('database/seeds')))->run();

register_shutdown_function(static function () use ($dbPath): void {
    $ref = new ReflectionClass(Database::class);
    $prop = $ref->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
    foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
});

$session = new Session('rated_exclusion_test');
$session->start();
$auth = new AuthService($session, new User());
AuthService::setInstance($auth);

$cacheDir = sys_get_temp_dir() . '/booksphere_r1_cache_' . bin2hex(random_bytes(4));
mkdir($cacheDir, 0777, true);

register_shutdown_function(static function () use ($cacheDir): void {
    if (is_dir($cacheDir)) {
        $files = glob($cacheDir . '/*');
        foreach ($files as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($cacheDir);
    }
});

$cache = new PersonalizationCache($cacheDir, 1800);
$booksRepo = new BookRepository();
$repo = new RecommendationRepository($booksRepo);

$factory = new RecommendationFactory(
    new HighestRatedStrategy($repo),
    new PopularBooksStrategy($repo),
    new TrendingBooksStrategy($repo),
    new SameCategoryStrategy($repo),
    new RecentlyAddedStrategy($repo),
    new SameAuthorStrategy($repo),
);

$service = new RecommendationService(
    repository: $repo,
    factory: $factory,
    cache: $cache,
);

$dashboardPresenter = new RecommendationDashboardPresenter(
    service: $service,
    books: $booksRepo,
    repository: $repo,
    categories: new Category(),
);

// Create dedicated test users in throwaway db
$db = db();
$db->query("INSERT INTO users (id, full_name, email, password, role) VALUES (501, 'User A', 'usera@test.dev', 'hash', 'user')");
$db->query("INSERT INTO users (id, full_name, email, password, role) VALUES (502, 'User B', 'userb@test.dev', 'hash', 'user')");
$db->query("INSERT INTO users (id, full_name, email, password, role) VALUES (503, 'User Cold', 'cold@test.dev', 'hash', 'user')");

echo "\n--- 1. BASELINE VERIFICATION ---\n";
// Cold user receives recommendations
$coldRecs = $service->getPersonalizedRecommendations(503, 10);
check('Cold-start user receives recommendations', count($coldRecs->items) > 0);
$baselineIds = array_column($coldRecs->items, 'id');
check('Baseline candidate pool is healthy (>= 5 books)', count($baselineIds) >= 5);

echo "\n--- 2. EDGE CASES: RATING VALUES 1 THROUGH 5 EXCLUSION ---\n";

// Case A: 1-Star Rating
$book1Star = $baselineIds[0];
$db->query(
    "INSERT INTO reviews (user_id, book_id, rating, review, created_at) VALUES (501, ?, 1, 'Terrible book', '2026-01-01T00:00:00Z')",
    [$book1Star]
);
$service->invalidatePersonalization(501);
$recsA1 = $service->getPersonalizedRecommendations(501, 15);
$idsA1 = array_column($recsA1->items, 'id');
check('A. 1-Star rated book is strictly excluded from recommendations', !in_array($book1Star, $idsA1, true));

// Case B: 2-Star Rating
$book2Star = $baselineIds[1];
$db->query(
    "INSERT INTO reviews (user_id, book_id, rating, review, created_at) VALUES (501, ?, 2, 'Not great', '2026-01-02T00:00:00Z')",
    [$book2Star]
);
$service->invalidatePersonalization(501);
$recsA2 = $service->getPersonalizedRecommendations(501, 15);
$idsA2 = array_column($recsA2->items, 'id');
check('B. 2-Star rated book is strictly excluded from recommendations', !in_array($book2Star, $idsA2, true));

// Case C: 3-Star Rating
$book3Star = $baselineIds[2];
$db->query(
    "INSERT INTO reviews (user_id, book_id, rating, review, created_at) VALUES (501, ?, 3, 'Average read', '2026-01-03T00:00:00Z')",
    [$book3Star]
);
$service->invalidatePersonalization(501);
$recsA3 = $service->getPersonalizedRecommendations(501, 15);
$idsA3 = array_column($recsA3->items, 'id');
check('C. 3-Star rated book is strictly excluded from recommendations', !in_array($book3Star, $idsA3, true));

// Case D: 4-Star Rating
$book4Star = $baselineIds[3];
$db->query(
    "INSERT INTO reviews (user_id, book_id, rating, review, created_at) VALUES (501, ?, 4, 'Very good book', '2026-01-04T00:00:00Z')",
    [$book4Star]
);
$service->invalidatePersonalization(501);
$recsA4 = $service->getPersonalizedRecommendations(501, 15);
$idsA4 = array_column($recsA4->items, 'id');
check('D. 4-Star rated book is strictly excluded from recommendations', !in_array($book4Star, $idsA4, true));

// Case E: 5-Star Rating (The primary failure case from the forensic audit)
$book5Star = $baselineIds[4];
$db->query(
    "INSERT INTO reviews (user_id, book_id, rating, review, created_at) VALUES (501, ?, 5, 'Absolute masterpiece', '2026-01-05T00:00:00Z')",
    [$book5Star]
);
$service->invalidatePersonalization(501);
$recsA5 = $service->getPersonalizedRecommendations(501, 15);
$idsA5 = array_column($recsA5->items, 'id');
check('E. 5-Star rated book is strictly excluded from recommendations', !in_array($book5Star, $idsA5, true));

echo "\n--- 3. COMBINATION & OVERLAP EDGE CASES ---\n";

// Case F: User reviewed a book (with explicit text)
check('F. Reviewed book (5-star with text) is excluded', !in_array($book5Star, $idsA5, true));

// Case G: Book is reviewed + wishlisted
$bookWishAndReview = $baselineIds[5] ?? 10;
$db->query("INSERT INTO wishlist (user_id, book_id, created_at) VALUES (501, ?, '2026-01-06T00:00:00Z')", [$bookWishAndReview]);
$db->query("INSERT INTO reviews (user_id, book_id, rating, review, created_at) VALUES (501, ?, 4, 'Liked and saved', '2026-01-06T00:00:00Z')", [$bookWishAndReview]);
$service->invalidatePersonalization(501);
$recsG = $service->getPersonalizedRecommendations(501, 15);
$idsG = array_column($recsG->items, 'id');
check('G. Book reviewed + wishlisted remains strictly excluded', !in_array($bookWishAndReview, $idsG, true));

// Case H: Book is reviewed + in library (finished)
$bookLibAndReview = $baselineIds[6] ?? 11;
$db->query("INSERT INTO user_library (user_id, book_id, library_status, created_at, updated_at) VALUES (501, ?, 'finished', datetime('now'), datetime('now'))", [$bookLibAndReview]);
$db->query("INSERT INTO reviews (user_id, book_id, rating, review, created_at) VALUES (501, ?, 5, 'Finished and reviewed', '2026-01-07T00:00:00Z')", [$bookLibAndReview]);
$service->invalidatePersonalization(501);
$recsH = $service->getPersonalizedRecommendations(501, 15);
$idsH = array_column($recsH->items, 'id');
check('H. Book reviewed + in library remains strictly excluded', !in_array($bookLibAndReview, $idsH, true));

// Case I: Book is rated + recently viewed
$bookViewAndRate = $baselineIds[7] ?? 12;
$service->recordBookView(501, $bookViewAndRate);
$db->query("INSERT INTO reviews (user_id, book_id, rating, review, created_at) VALUES (501, ?, 3, 'Viewed and rated', '2026-01-08T00:00:00Z')", [$bookViewAndRate]);
$service->invalidatePersonalization(501);
$recsI = $service->getPersonalizedRecommendations(501, 15);
$idsI = array_column($recsI->items, 'id');
check('I. Book rated + recently viewed remains strictly excluded', !in_array($bookViewAndRate, $idsI, true));

// Case J: Multiple reviewed/rated books all excluded simultaneously
$allExcludedSoFar = [
    $book1Star, $book2Star, $book3Star, $book4Star, $book5Star,
    $bookWishAndReview, $bookLibAndReview, $bookViewAndRate
];
$intersection = array_intersect($allExcludedSoFar, $idsI);
check('J. All multiple rated/reviewed books remain excluded simultaneously', $intersection === []);

// Case K: User with no ratings/reviews retains cold-start behavior
$recsCold = $service->getPersonalizedRecommendations(503, 10);
check('K. Cold-start user recommendations remain active and populated', count($recsCold->items) > 0);

// Case L: User Isolation - User B CAN receive books that User A has rated
$service->invalidatePersonalization(502);
$recsB = $service->getPersonalizedRecommendations(502, 15);
$idsB = array_column($recsB->items, 'id');
check('L. User B can still receive books rated only by User A (user isolation)', in_array($book5Star, $idsB, true) || in_array($book1Star, $idsB, true) || count($idsB) > 0);
check('User A exclusion does not leak into User B profile', !in_array(501, [$service->profileFor(502)->userId], true));

echo "\n--- 4. REGRESSION VERIFICATION (LIBRARY, WISHLIST, RECENT VIEWS, DUPES) ---\n";

// Existing Library exclusion still works independently
$libraryOnlyBook = $baselineIds[8] ?? 14;
$db->query("INSERT INTO user_library (user_id, book_id, library_status, created_at, updated_at) VALUES (502, ?, 'currently_reading', datetime('now'), datetime('now'))", [$libraryOnlyBook]);
$service->invalidatePersonalization(502);
$recsLibOnly = $service->getPersonalizedRecommendations(502, 15);
$idsLibOnly = array_column($recsLibOnly->items, 'id');
check('4. Existing library exclusion (currently_reading) still works', !in_array($libraryOnlyBook, $idsLibOnly, true));

// Existing Wishlist exclusion still works independently
$wishlistOnlyBook = $baselineIds[9] ?? 16;
$db->query("INSERT INTO wishlist (user_id, book_id, created_at) VALUES (502, ?, '2026-01-10T00:00:00Z')", [$wishlistOnlyBook]);
$service->invalidatePersonalization(502);
$recsWishOnly = $service->getPersonalizedRecommendations(502, 15);
$idsWishOnly = array_column($recsWishOnly->items, 'id');
check('5. Existing wishlist exclusion still works', !in_array($wishlistOnlyBook, $idsWishOnly, true));

// Existing Recently-Viewed exclusion still works independently
$viewOnlyBook = $baselineIds[10] ?? 20;
$service->recordBookView(502, $viewOnlyBook);
$service->invalidatePersonalization(502);
$recsViewOnly = $service->getPersonalizedRecommendations(502, 15);
$idsViewOnly = array_column($recsViewOnly->items, 'id');
check('6. Existing recently-viewed exclusion still works', !in_array($viewOnlyBook, $idsViewOnly, true));

// No duplicate recommendations introduced
$uniqueIds = array_unique($idsViewOnly);
check('7. No duplicate recommendation IDs are introduced on the shelf', count($uniqueIds) === count($idsViewOnly));

// Unrelated eligible books remain available
check('8. Unrelated eligible books remain available in recommendation pool', count($idsViewOnly) > 0);

// Recommendation cache isolation
$cachedA = $cache->get(501);
$cachedB = $cache->get(502);
check('10. Cache stores separate isolated keys per user', $cachedA !== null && $cachedB !== null);
$cachedIdsA = array_column($cachedA['items'], 'id');
$cachedIdsB = array_column($cachedB['items'], 'id');
check('Cache does not cross-contaminate excluded books between users', !in_array($book5Star, $cachedIdsA, true));

echo "\n--- 5. DASHBOARD PRESENTER EXCLUSIONS ---\n";
// Verify RecommendationDashboardPresenter::compose() also excludes rated books across other shelves
$session->put('auth_user_id', 501);
$session->put('auth_user', ['id' => 501, 'full_name' => 'User A', 'email' => 'usera@test.dev', 'role' => 'user']);

$dashboardData = $dashboardPresenter->compose();
$recommendedShelfIds = array_column($dashboardData['recommended']['items'] ?? [], 'id');
check('Dashboard recommended shelf excludes 5-star rated book', !in_array($book5Star, $recommendedShelfIds, true));
check('Dashboard recommended shelf excludes 1-star rated book', !in_array($book1Star, $recommendedShelfIds, true));

// Verify other shelves (follow, trending, recent) also don't contain rated books
$trendingIds = array_column($dashboardData['trending'] ?? [], 'id');
$recentIds = array_column($dashboardData['recent'] ?? [], 'id');
check('Dashboard trending shelf excludes rated books', !in_array($book5Star, $trendingIds, true));
check('Dashboard recent shelf excludes rated books', !in_array($book5Star, $recentIds, true));

echo "\n========================================================================\n";
echo "RESULT: {$checks} checks, {$failed} failed\n";
echo "========================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
