<?php

declare(strict_types=1);

/**
 * RecommendationAuthorFollowTest
 *
 * Phase R2 — Dedicated Automated Regression Test Suite
 *
 * Verifies:
 *     1. Minimal profile integration: followedAuthorIds loaded in PersonalizationProfile
 *     2. Scoring integration: author signal (+25 points) activated for books by followed authors
 *     3. UI explanation: "Because you follow [Author]" generated for followed author recommendations
 *     4. User isolation: User A follows Author X -> affects User A only; User B does not receive it
 *     5. Unfollow behavior: unfollowing removes the signal after cache invalidation
 *     6. Multi-author follows: user following multiple authors receives signals for each
 *     7. Priority of hard exclusions over author signals:
 *        - Followed author's book is rated -> EXCLUDED
 *        - Followed author's book is in library -> EXCLUDED
 *        - Followed author's book is wishlisted -> EXCLUDED
 *        - Followed author's book is recently viewed -> EXCLUDED
 *     8. Cache isolation between users with different follow profiles
 *     9. Dashboard presenter follow shelf and hasSignals integration
 *
 * Run from project root:
 *     php tests/RecommendationAuthorFollowTest.php
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Core\Migrator;
use BookSphere\App\Core\Seeder;
use BookSphere\App\Core\Session;
use BookSphere\App\DTO\PersonalizationProfile;
use BookSphere\App\DTO\RecommendationResult;
use BookSphere\App\Models\Author;
use BookSphere\App\Models\AuthorFollow;
use BookSphere\App\Models\Category;
use BookSphere\App\Models\User;
use BookSphere\App\Models\UserLibrary;
use BookSphere\App\Presenters\RecommendationDashboardPresenter;
use BookSphere\App\Repositories\AuthorFollowRepository;
use BookSphere\App\Repositories\BookRepository;
use BookSphere\App\Repositories\RecommendationRepository;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\FollowService;
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

$checks = 0;
$failed = 0;

function check(string $description, bool $condition, string $details = ''): void
{
    global $checks, $failed;
    $checks++;
    if ($condition) {
        echo "  PASS  $description\n";
    } else {
        $failed++;
        echo "  FAIL  $description" . ($details !== '' ? " ($details)" : '') . "\n";
    }
}

// ---------------------------------------------------------------------
// 1. Setup Isolated Throwaway Database & Cache
// ---------------------------------------------------------------------

(new Environment(root_path('.env')))->load();

$dbPath = root_path('database/recommendation_author_follow_test.db');
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

$session = new Session('author_follow_test');
$session->start();
$auth = new AuthService($session, new User());
AuthService::setInstance($auth);

$cacheDir = sys_get_temp_dir() . '/booksphere_r2_cache_' . bin2hex(random_bytes(4));
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
$recRepo = new RecommendationRepository($booksRepo);

$factory = new RecommendationFactory(
    new HighestRatedStrategy($recRepo),
    new PopularBooksStrategy($recRepo),
    new TrendingBooksStrategy($recRepo),
    new SameCategoryStrategy($recRepo),
    new RecentlyAddedStrategy($recRepo),
    new SameAuthorStrategy($recRepo),
);

$service = new RecommendationService(
    factory: $factory,
    repository: $recRepo,
    cache: $cache,
);

$followRepo = new AuthorFollowRepository();
$authorModel = new Author();
$authorFollowModel = new AuthorFollow($followRepo);
$followService = new FollowService($authorFollowModel, $authorModel, null, null, $service);

$userModel = new User();
$userAId = $userModel->create('User R2 A', 'user_r2_a@test.dev', password_hash('Secret123!', PASSWORD_BCRYPT));
$userBId = $userModel->create('User R2 B', 'user_r2_b@test.dev', password_hash('Secret123!', PASSWORD_BCRYPT));

// Find or create test authors with multiple books
// Author X: "Test Author Christie" with 3 books
db()->execute("INSERT INTO authors (name, biography, created_at) VALUES ('Test Author Christie', 'Mystery author', datetime('now'))");
$authorXId = (int) db()->lastInsertId();

// Author Y: "Test Author Doyle" with 2 books
db()->execute("INSERT INTO authors (name, biography, created_at) VALUES ('Test Author Doyle', 'Sherlock author', datetime('now'))");
$authorYId = (int) db()->lastInsertId();

// Create books for Author X
$now = gmdate('Y-m-d\TH:i:s\Z');
db()->execute(
    "INSERT INTO books (title, isbn, published_year, description, average_rating, status, created_at) VALUES (?, ?, ?, ?, ?, 'published', ?)",
    ['The Mysterious Affair at Styles', '978000000001', 1920, 'First Poirot novel', 4.2, $now]
);
$bookX1 = (int) db()->lastInsertId();

db()->execute(
    "INSERT INTO books (title, isbn, published_year, description, average_rating, status, created_at) VALUES (?, ?, ?, ?, ?, 'published', ?)",
    ['The Murder of Roger Ackroyd', '978000000002', 1926, 'Classic mystery', 4.5, $now]
);
$bookX2 = (int) db()->lastInsertId();

db()->execute(
    "INSERT INTO books (title, isbn, published_year, description, average_rating, status, created_at) VALUES (?, ?, ?, ?, ?, 'published', ?)",
    ['And Then There Were None', '978000000003', 1939, 'Island mystery', 4.6, $now]
);
$bookX3 = (int) db()->lastInsertId();

// Link books to Author X
db()->execute("INSERT INTO book_authors (book_id, author_id) VALUES (?, ?)", [$bookX1, $authorXId]);
db()->execute("INSERT INTO book_authors (book_id, author_id) VALUES (?, ?)", [$bookX2, $authorXId]);
db()->execute("INSERT INTO book_authors (book_id, author_id) VALUES (?, ?)", [$bookX3, $authorXId]);

// Create books for Author Y
db()->execute(
    "INSERT INTO books (title, isbn, published_year, description, average_rating, status, created_at) VALUES (?, ?, ?, ?, ?, 'published', ?)",
    ['A Study in Scarlet', '978000000004', 1887, 'First Holmes', 4.3, $now]
);
$bookY1 = (int) db()->lastInsertId();

db()->execute(
    "INSERT INTO books (title, isbn, published_year, description, average_rating, status, created_at) VALUES (?, ?, ?, ?, ?, 'published', ?)",
    ['The Sign of the Four', '978000000005', 1890, 'Second Holmes', 4.1, $now]
);
$bookY2 = (int) db()->lastInsertId();

db()->execute("INSERT INTO book_authors (book_id, author_id) VALUES (?, ?)", [$bookY1, $authorYId]);
db()->execute("INSERT INTO book_authors (book_id, author_id) VALUES (?, ?)", [$bookY2, $authorYId]);

db()->execute(
    "INSERT INTO books (title, isbn, published_year, description, average_rating, status, created_at) VALUES (?, ?, ?, ?, ?, 'published', ?)",
    ['Death on the Nile', '978000000006', 1937, 'Poirot in Egypt', 4.4, $now]
);
$bookX4 = (int) db()->lastInsertId();
db()->execute("INSERT INTO book_authors (book_id, author_id) VALUES (?, ?)", [$bookX4, $authorXId]);

db()->execute(
    "INSERT INTO books (title, isbn, published_year, description, average_rating, status, created_at) VALUES (?, ?, ?, ?, ?, 'published', ?)",
    ['The Hound of the Baskervilles', '978000000007', 1902, 'Dartmoor mystery', 4.5, $now]
);
$bookY3 = (int) db()->lastInsertId();
db()->execute("INSERT INTO book_authors (book_id, author_id) VALUES (?, ?)", [$bookY3, $authorYId]);

echo "\n--- 1. BASELINE: PROFILE INTEGRATION ---\n";

$profileA0 = $service->profileFor($userAId);
check('User A starts with empty followedAuthorIds', $profileA0->followedAuthorIds === []);
check('User A favouriteAuthorIds does not contain Author X or Y',
    !in_array($authorXId, $profileA0->favouriteAuthorIds(), true) && !in_array($authorYId, $profileA0->favouriteAuthorIds(), true));

echo "\n--- 2. STEP 2 & 3: AUTHOR FOLLOW INTEGRATION & SCORING ---\n";

// User A follows Author X via FollowService
$followService->follow($userAId, $authorXId);

$profileA1 = $service->profileFor($userAId);
check('PersonalizationProfile loads followedAuthorIds', in_array($authorXId, $profileA1->followedAuthorIds, true));
check('PersonalizationProfile favouriteAuthorIds includes followed author', in_array($authorXId, $profileA1->favouriteAuthorIds(), true));
check('PersonalizationProfile favouriteAuthors map has Author X name', isset($profileA1->favouriteAuthors[$authorXId]['name']) && $profileA1->favouriteAuthors[$authorXId]['name'] === 'Test Author Christie');

$recA = $service->getPersonalizedRecommendations($userAId, 10);
$recAIds = array_column($recA->items, 'id');
check('User A receives Author X books in personalized recommendations',
    in_array($bookX1, $recAIds, true) || in_array($bookX2, $recAIds, true) || in_array($bookX3, $recAIds, true));

// Inspect the item for Author X's book
$matchedAuthorItem = null;
foreach ($recA->items as $item) {
    if (in_array($item['id'], [$bookX1, $bookX2, $bookX3], true)) {
        $matchedAuthorItem = $item;
        break;
    }
}
check('Author X book carries the author matched factor',
    $matchedAuthorItem !== null && in_array('author', $matchedAuthorItem['matched'] ?? [], true));
check('Author X recommendation explanation mentions followed author',
    $matchedAuthorItem !== null && str_contains($matchedAuthorItem['reason'], 'Because you follow Test Author Christie'));
check('Author X book receives at least author weight score (>= 25 pts)',
    $matchedAuthorItem !== null && (float) $matchedAuthorItem['score'] >= 25.0);

echo "\n--- 3. STEP 4: USER ISOLATION & CACHE ISOLATION ---\n";

// User B did NOT follow Author X
$profileB = $service->profileFor($userBId);
check('User B profile does not have Author X in followedAuthorIds', !in_array($authorXId, $profileB->followedAuthorIds, true));

$recB = $service->getPersonalizedRecommendations($userBId, 10);
$userBAuthorItem = null;
foreach ($recB->items as $item) {
    if (in_array($item['id'], [$bookX1, $bookX2, $bookX3], true)) {
        $userBAuthorItem = $item;
        break;
    }
}
check('User B does not receive author follow matched factor for Author X',
    $userBAuthorItem === null || !in_array('author', $userBAuthorItem['matched'] ?? [], true));
check('User B reason never claims to follow Author X',
    $userBAuthorItem === null || !str_contains($userBAuthorItem['reason'], 'Because you follow Test Author Christie'));

// Cache isolation: fetching User A again from warm cache
$recAWarm = $service->getPersonalizedRecommendations($userAId, 10);
$warmAuthorItem = null;
foreach ($recAWarm->items as $item) {
    if (in_array($item['id'], [$bookX1, $bookX2, $bookX3], true)) {
        $warmAuthorItem = $item;
        break;
    }
}
check('Cached recommendation for User A preserves author follow signal and reason',
    $warmAuthorItem !== null && str_contains($warmAuthorItem['reason'], 'Because you follow Test Author Christie'));

echo "\n--- 4. STEP 4: UNFOLLOW & SWITCH AUTHOR ---\n";

// User A unfollows Author X
$followService->unfollow($userAId, $authorXId);

$profileAUnfollowed = $service->profileFor($userAId);
check('Unfollowing drops Author X from followedAuthorIds', !in_array($authorXId, $profileAUnfollowed->followedAuthorIds, true));

$recAPostUnfollow = $service->getPersonalizedRecommendations($userAId, 10);
$postUnfollowItem = null;
foreach ($recAPostUnfollow->items as $item) {
    if (in_array($item['id'], [$bookX1, $bookX2, $bookX3], true)) {
        $postUnfollowItem = $item;
        break;
    }
}
check('Author X follow signal disappears for User A post-unfollow',
    $postUnfollowItem === null || !in_array('author', $postUnfollowItem['matched'] ?? [], true));

// Now User A follows Author Y instead
$followService->follow($userAId, $authorYId);

$profileAFollowY = $service->profileFor($userAId);
check('User A now has Author Y in followedAuthorIds', in_array($authorYId, $profileAFollowY->followedAuthorIds, true));
check('User A does not have Author X in followedAuthorIds', !in_array($authorXId, $profileAFollowY->followedAuthorIds, true));

$recAY = $service->getPersonalizedRecommendations($userAId, 10);
$itemY = null;
$itemX = null;
foreach ($recAY->items as $item) {
    if (in_array($item['id'], [$bookY1, $bookY2], true)) {
        $itemY = $item;
    }
    if (in_array($item['id'], [$bookX1, $bookX2, $bookX3], true)) {
        $itemX = $item;
    }
}
check('User A receives Author Y books with author follow signal',
    $itemY !== null && in_array('author', $itemY['matched'] ?? [], true));
check('Author Y book explains "Because you follow Test Author Doyle"',
    $itemY !== null && str_contains($itemY['reason'], 'Because you follow Test Author Doyle'));
check('Author X books no longer receive author follow signal',
    $itemX === null || !in_array('author', $itemX['matched'] ?? [], true));

echo "\n--- 5. STEP 5: PRIORITY OF HARD EXCLUSIONS OVER AUTHOR FOLLOW SIGNALS ---\n";

// Re-follow Author X as well so User A follows both Author X and Author Y
$followService->follow($userAId, $authorXId);

// Author X has 3 books:
// Book X1: Add rating (R1 exclusion test D)
// Book X2: Add to library (R1 exclusion test E)
// Book X3: Remains eligible (must receive author signal)
// Author Y has 2 books:
// Book Y1: Add to wishlist (R1 exclusion test F)
// Book Y2: Add to recent views (R1 exclusion test G)

// D. Rating exclusion
db()->execute(
    "INSERT INTO reviews (user_id, book_id, rating, review, status, created_at) VALUES (?, ?, 5, 'Great read!', 'approved', datetime('now'))",
    [$userAId, $bookX1]
);

// E. Library exclusion
db()->execute(
    "INSERT INTO user_library (user_id, book_id, library_status, created_at, updated_at) VALUES (?, ?, 'currently_reading', datetime('now'), datetime('now'))",
    [$userAId, $bookX2]
);

// F. Wishlist exclusion
$recRepo->toggleWishlist($userAId, $bookY1);

// G. Recently viewed exclusion
$recRepo->recordBookView($userAId, $bookY2);

// Invalidate cache
$service->invalidatePersonalization($userAId);

$recExclusions = $service->getPersonalizedRecommendations($userAId, 15);
$recExclusionIds = array_column($recExclusions->items, 'id');

check('D. Followed author book already rated (bookX1) is strictly EXCLUDED',
    !in_array($bookX1, $recExclusionIds, true));
check('E. Followed author book already in library (bookX2) is strictly EXCLUDED',
    !in_array($bookX2, $recExclusionIds, true));
check('F. Followed author book in wishlist (bookY1) is strictly EXCLUDED',
    !in_array($bookY1, $recExclusionIds, true));
check('G. Followed author book recently viewed (bookY2) is strictly EXCLUDED',
    !in_array($bookY2, $recExclusionIds, true));

// Eligible book Book X3 must still receive the recommendation with author signal!
check('Eligible book by followed author (bookX3) is RECOMMENDED',
    in_array($bookX3, $recExclusionIds, true));

$eligibleItem = null;
foreach ($recExclusions->items as $item) {
    if ((int) $item['id'] === $bookX3) {
        $eligibleItem = $item;
        break;
    }
}
check('Eligible book (bookX3) receives author follow signal and +25 score',
    $eligibleItem !== null && in_array('author', $eligibleItem['matched'] ?? [], true) && (float) $eligibleItem['score'] >= 25.0);

echo "\n--- 6. DASHBOARD PRESENTER INTEGRATION ---\n";

$session->put('auth_user_id', $userAId);
$session->put('auth_user', ['id' => $userAId, 'full_name' => 'User R2 A', 'email' => 'user_r2_a@test.dev', 'role' => 'user']);

$presenter = new RecommendationDashboardPresenter($service, $recRepo, $booksRepo, new Category());
$personalShelf = RecommendationResult::fromBooks('personal', 'Recommended', [$eligibleItem], 'Personal note');
$dashboard = $presenter->compose($personalShelf);

check('Dashboard hasSignals is true for user following authors', $dashboard['hasSignals'] === true);
check('Dashboard follow shelf contains items', !empty($dashboard['follow']));
$followShelfIds = array_column($dashboard['follow'], 'id');
check('Dashboard follow shelf excludes rated bookX1', !in_array($bookX1, $followShelfIds, true));
check('Dashboard follow shelf excludes library bookX2', !in_array($bookX2, $followShelfIds, true));
check('Dashboard follow shelf excludes wishlist bookY1', !in_array($bookY1, $followShelfIds, true));
check('Dashboard follow shelf excludes recently viewed bookY2', !in_array($bookY2, $followShelfIds, true));

echo "\n========================================================================\n";
echo "RESULT: {$checks} checks, {$failed} failed\n";
echo "========================================================================\n\n";

if ($failed > 0) {
    exit(1);
}
