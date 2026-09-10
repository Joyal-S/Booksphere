<?php

declare(strict_types=1);

/**
 * RecommendationLibraryExclusionTest
 *
 * Dedicated regression test suite for Issue #1:
 * Verifies that books in a user's library (across all statuses: finished,
 * currently_reading, want_to_read) are strictly excluded from personalized
 * recommendations and dashboard recommendation shelves.
 *
 * Also verifies recommendation cache invalidation across all library lifecycle
 * events (adding, status change, completion, deletion) and ensures unread books
 * continue to be recommended properly.
 *
 * Run from the project root:
 *     php tests/RecommendationLibraryExclusionTest.php
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Core\Logger;
use BookSphere\App\Core\Migrator;
use BookSphere\App\Core\Seeder;
use BookSphere\App\Core\Session;
use BookSphere\App\Models\Category;
use BookSphere\App\Models\User;
use BookSphere\App\Models\UserLibrary;
use BookSphere\App\Policies\LibraryPolicy;
use BookSphere\App\Presenters\RecommendationDashboardPresenter;
use BookSphere\App\Repositories\BookRepository;
use BookSphere\App\Repositories\LibraryRepository;
use BookSphere\App\Repositories\RecommendationRepository;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\LibraryService;
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
// 1. Setup Throwaway Database
// ---------------------------------------------------------------------

(new Environment(root_path('.env')))->load();

$dbPath = root_path('database/library_exclusion_test.db');

foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

Database::instance($dbPath);
(new Migrator(db(), root_path('database/migrations')))->run();
(new Seeder(db(), root_path('database/seeds')))->run();

$session = new Session('library_exclusion_test');
$session->start();
$auth = new AuthService($session, new User());
AuthService::setInstance($auth);

$cacheDir = sys_get_temp_dir() . '/booksphere_exclusion_cache_' . bin2hex(random_bytes(4));
mkdir($cacheDir, 0777, true);

$cache = new PersonalizationCache($cacheDir, 1800);
$booksRepo = new BookRepository();
$repo      = new RecommendationRepository($booksRepo);
$categoryModel = new Category();

$factory = new RecommendationFactory(
    new HighestRatedStrategy($repo),
    new PopularBooksStrategy($repo),
    new TrendingBooksStrategy($repo),
    new SameCategoryStrategy($repo),
    new RecentlyAddedStrategy($repo),
    new SameAuthorStrategy($repo),
);

$logger = new Logger(sys_get_temp_dir() . '/booksphere_exclusion_test.log');
$service = new RecommendationService($factory, $repo, $cache, $logger);

$libraryModel = new UserLibrary();
$bookModel    = new \BookSphere\App\Models\Book();
$libraryService = new LibraryService($libraryModel, $bookModel, $service, $logger);

$presenter = new RecommendationDashboardPresenter($service, $repo, $booksRepo, $categoryModel);

echo "\n--- 1. ISOLATED TEST USER & BASELINE ---\n";

$usersModel = new User();
$userId = $usersModel->create(
    'Exclusion Tester',
    'exclusion_' . bin2hex(random_bytes(4)) . '@test.dev',
    password_hash('Secret123!', PASSWORD_DEFAULT),
);

$db = db();
$allBooks = $db->query('SELECT id, title FROM books WHERE status = "published" ORDER BY id ASC LIMIT 10');

check('Catalogue has at least 10 books', count($allBooks) >= 10);

$book1 = (int) $allBooks[0]['id'];
$book2 = (int) $allBooks[1]['id'];
$book3 = (int) $allBooks[2]['id'];
$book4 = (int) $allBooks[3]['id'];
$book5 = (int) $allBooks[4]['id'];

// Give user preference signals so recommendation engine generates personalized candidates
$db->execute("INSERT INTO reviews (user_id, book_id, rating, review, created_at, updated_at) VALUES (?, ?, 5, 'Outstanding classic masterpiece!', datetime('now'), datetime('now'))", [$userId, $book5]);
$service->invalidatePersonalization($userId);

$initialRecs = $service->getPersonalizedRecommendations($userId, 10);
check('Personalized recommendations generated for user', count($initialRecs->items) > 0);

echo "\n--- 2. TEST SCENARIO: FINISHED BOOK EXCLUSION ---\n";

// Add Book 1 as finished (100%)
$libraryService->addBook(\BookSphere\App\DTO\LibraryItemDTO::fromArray([
    'user_id'  => $userId,
    'book_id'  => $book1,
    'status'   => 'finished',
    'progress' => 100,
]));

$service->invalidatePersonalization($userId);
$recsAfterFinished = $service->getPersonalizedRecommendations($userId, 10);
$recIdsAfterFinished = array_column($recsAfterFinished->items, 'id');

check('Finished book ID ' . $book1 . ' is NOT in personalized recommendations', !in_array($book1, $recIdsAfterFinished, true));

$dashboardAfterFinished = $presenter->compose($recsAfterFinished);
$recSectionIds = array_column($dashboardAfterFinished['sections']['recommended'] ?? [], 'id');
check('Finished book ID ' . $book1 . ' is NOT in dashboard recommended shelf', !in_array($book1, $recSectionIds, true));

echo "\n--- 3. TEST SCENARIO: CURRENTLY_READING BOOK EXCLUSION ---\n";

// Add Book 2 as currently_reading (50%)
$libraryService->addBook(\BookSphere\App\DTO\LibraryItemDTO::fromArray([
    'user_id'  => $userId,
    'book_id'  => $book2,
    'status'   => 'currently_reading',
    'progress' => 50,
]));

$service->invalidatePersonalization($userId);
$recsAfterReading = $service->getPersonalizedRecommendations($userId, 10);
$recIdsAfterReading = array_column($recsAfterReading->items, 'id');

check('Currently reading book ID ' . $book2 . ' is NOT in personalized recommendations', !in_array($book2, $recIdsAfterReading, true));
check('Previously finished book ID ' . $book1 . ' is STILL NOT in personalized recommendations', !in_array($book1, $recIdsAfterReading, true));

echo "\n--- 4. TEST SCENARIO: WANT_TO_READ BOOK EXCLUSION ---\n";

// Add Book 3 as want_to_read (0%)
$libraryService->addBook(\BookSphere\App\DTO\LibraryItemDTO::fromArray([
    'user_id'  => $userId,
    'book_id'  => $book3,
    'status'   => 'want_to_read',
    'progress' => 0,
]));

$service->invalidatePersonalization($userId);
$recsAfterWant = $service->getPersonalizedRecommendations($userId, 10);
$recIdsAfterWant = array_column($recsAfterWant->items, 'id');

check('Want-to-read book ID ' . $book3 . ' is NOT in personalized recommendations', !in_array($book3, $recIdsAfterWant, true));
check('All 3 library books (1, 2, 3) are excluded from recommendations', 
    !in_array($book1, $recIdsAfterWant, true) && 
    !in_array($book2, $recIdsAfterWant, true) && 
    !in_array($book3, $recIdsAfterWant, true)
);

echo "\n--- 5. UNREAD BOOKS CAN STILL BE RECOMMENDED ---\n";

check('Recommendations shelf still contains eligible unread books', count($recsAfterWant->items) > 0);
$firstRecId = (int) ($recsAfterWant->items[0]['id'] ?? 0);
check('Recommended book ID ' . $firstRecId . ' is an eligible catalogue book', $firstRecId > 0 && !in_array($firstRecId, [$book1, $book2, $book3], true));

echo "\n--- 6. CACHE INVALIDATION ON LIBRARY EVENTS ---\n";

// 6.1 Cache is written on recommendation generation
$service->getPersonalizedRecommendations($userId, 10);
check('Personalization cache file created for user', $cache->get($userId) !== null);

// 6.2 Adding a book to library invalidates cache
$libraryService->addBook(\BookSphere\App\DTO\LibraryItemDTO::fromArray([
    'user_id'  => $userId,
    'book_id'  => $book4,
    'status'   => 'want_to_read',
    'progress' => 0,
]));
check('Cache is invalidated immediately after adding book to library', $cache->get($userId) === null);

// Re-warm cache
$service->getPersonalizedRecommendations($userId, 10);
check('Cache re-warmed', $cache->get($userId) !== null);

// 6.3 Updating reading status invalidates cache
$libraryService->updateStatus($userId, $book4, 'finished');
check('Cache is invalidated immediately after status updated to finished', $cache->get($userId) === null);

// Re-warm cache
$service->getPersonalizedRecommendations($userId, 10);
check('Cache re-warmed again', $cache->get($userId) !== null);

// 6.4 Removing a book from library invalidates cache
$libraryService->removeBook($userId, $book4);
check('Cache is invalidated immediately after book deleted from library', $cache->get($userId) === null);

// Now that book4 is removed from library, it is eligible again
$recsAfterRemoval = $service->getPersonalizedRecommendations($userId, 10);
check('Personalized recommendations still generate valid shelf after deletion', count($recsAfterRemoval->items) > 0);

// Cleanup throwaway cache
foreach (glob($cacheDir . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($cacheDir);

echo "\n------------------------------------------------------------------------\n";
echo "RESULT: Checks: $checks | Failed: $failed\n";
echo "------------------------------------------------------------------------\n";

exit($failed > 0 ? 1 : 0);
