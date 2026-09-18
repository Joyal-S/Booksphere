<?php

declare(strict_types=1);

/**
 * RecommendationExplanationAccuracyTest
 *
 * Phase R3 — Dedicated Automated Test Suite: Fix Explanation Accuracy
 *
 * Verifies all 15 scenarios required by Phase R3:
 *     1. Followed author: correct follow explanation ("Because you follow [Author].")
 *     2. Favourite/rated author without follow: must NOT say "Because you follow"
 *     3. Category match: category explanation names only categories the candidate actually belongs to
 *     4. Wishlist-related recommendation: wishlist/viewed explanation is truthful
 *     5. Rating-derived recommendation: rating explanation is truthful ("Shares categories with books you rated highly.")
 *     6. Review-derived recommendation: review explanation is truthful ("Highly rated by the community.")
 *     7. Community-derived recommendation: community explanation is truthful ("Based on books you discussed in the community.")
 *     8. Trending recommendation: trending explanation only if actual trending signal is present
 *     9. Popularity fallback: explanation accurately identifies fallback behavior ("A community favourite - a starting point for your profile.")
 *     10. Collaborative wording: no unsupported claim of user-to-user collaborative behavior ("Popular among readers of...")
 *     11. Multiple signals: explanation combines actual strongest/relevant signals (up to 2 sentences)
 *     12. No personalization signals: fallback explanation makes no false personal claims
 *     13. R1 exclusions: rated/reviewed books remain excluded
 *     14. R2 author-follow behavior: followed-author scoring remains active (+25 pts)
 *     15. User isolation: explanations from User A's follows/preferences never appear for User B
 *
 * Run from project root:
 *     php tests/RecommendationExplanationAccuracyTest.php
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
use BookSphere\App\Presenters\RecommendationDashboardPresenter;
use BookSphere\App\Repositories\AuthorFollowRepository;
use BookSphere\App\Repositories\BookRepository;
use BookSphere\App\Repositories\RecommendationRepository;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\CommunityRecommendationSignalService;
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

(new Environment(root_path('.env')))->load();

$dbPath = root_path('database/recommendation_explanation_r3_test.db');
foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
    if (is_file($file)) {
        @unlink($file);
    }
}

$db = Database::instance($dbPath);
(new Migrator($db, root_path('database/migrations')))->run();
(new Seeder($db, root_path('database/seeds')))->run();

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

$session = new Session('explanation_accuracy_test');
$session->start();
AuthService::setInstance(new AuthService($session, new User()));

$cacheDir = sys_get_temp_dir() . '/booksphere_r3_cache_' . bin2hex(random_bytes(4));
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
    echo PHP_EOL . str_repeat('=', 72) . PHP_EOL . $title . PHP_EOL . str_repeat('=', 72) . PHP_EOL;
}

// ---------------------------------------------------------------------
// Service & Repositories
// ---------------------------------------------------------------------

$bookRepo   = new BookRepository();
$recRepo    = new RecommendationRepository($bookRepo);
$followRepo = new AuthorFollowRepository();
$category   = new Category();

$factory = new RecommendationFactory(
    new PopularBooksStrategy($recRepo),
    new HighestRatedStrategy($recRepo),
    new TrendingBooksStrategy($recRepo),
    new SameCategoryStrategy($recRepo),
    new RecentlyAddedStrategy($recRepo),
    new SameAuthorStrategy($recRepo),
);

$commSignals = new CommunityRecommendationSignalService();
$service     = new RecommendationService($factory, $recRepo, $cache, null, null, $commSignals);
$followService = new FollowService(new AuthorFollow(), new Author(), null, null, $service);
$presenter   = new RecommendationDashboardPresenter($service, $recRepo, $bookRepo, $category);

// Clean up any previous test records
$db->execute("DELETE FROM author_follows WHERE user_id IN (1001, 1002)");
$db->execute("DELETE FROM reviews WHERE user_id IN (1001, 1002)");
$db->execute("DELETE FROM wishlist WHERE user_id IN (1001, 1002)");
$db->execute("DELETE FROM book_views WHERE user_id IN (1001, 1002)");
$db->execute("DELETE FROM user_library WHERE user_id IN (1001, 1002)");
$db->execute("DELETE FROM users WHERE id IN (1001, 1002)");

// Ensure test users exist
$db->execute("INSERT INTO users (id, full_name, email, password, role) VALUES (1001, 'R3 Test User A', 'r3_user_a@test.dev', 'hash', 'user')");
$db->execute("INSERT INTO users (id, full_name, email, password, role) VALUES (1002, 'R3 Test User B', 'r3_user_b@test.dev', 'hash', 'user')");

$userAId = 1001;
$userBId = 1002;

// Create test authors & categories
$db->execute("DELETE FROM book_authors WHERE author_id IN (8801, 8802, 8803)");
$db->execute("DELETE FROM book_categories WHERE category_id IN (8801, 8802)");
$db->execute("DELETE FROM books WHERE id IN (8801, 8802, 8803, 8804, 8805)");
$db->execute("DELETE FROM authors WHERE id IN (8801, 8802, 8803)");
$db->execute("DELETE FROM categories WHERE id IN (8801, 8802)");

$db->execute("INSERT INTO authors (id, name, biography) VALUES (8801, 'R3 Followed Author Austen', 'Bio')");
$db->execute("INSERT INTO authors (id, name, biography) VALUES (8802, 'R3 Rated Author Tolstoy', 'Bio')");
$db->execute("INSERT INTO authors (id, name, biography) VALUES (8803, 'R3 Unrelated Author Poe', 'Bio')");

$db->execute("INSERT INTO categories (id, name, slug) VALUES (8801, 'R3 SciFi Category', 'r3-scifi')");
$db->execute("INSERT INTO categories (id, name, slug) VALUES (8802, 'R3 Mystery Category', 'r3-mystery')");

// Create books:
// Book 8801: by Austen (Author 8801), Category 8801 (SciFi)
// Book 8802: by Tolstoy (Author 8802), Category 8801 (SciFi) - will be rated 5 stars by User A
// Book 8803: by Tolstoy (Author 8802), Category 8802 (Mystery) - unrated, candidate
// Book 8804: by Austen (Author 8801), Category 8802 (Mystery) - candidate for follow
// Book 8805: by Poe (Author 8803), Category 8801 (SciFi) - candidate for category only
foreach ([
    [8801, 'R3 Austen SciFi Book', 8801, 8801],
    [8802, 'R3 Tolstoy Rated Seed', 8802, 8801],
    [8803, 'R3 Tolstoy Unrated Mystery', 8802, 8802],
    [8804, 'R3 Austen Mystery Book', 8801, 8802],
    [8805, 'R3 Poe SciFi Book', 8803, 8801],
    [8806, 'R3 Austen Follow Shelf Book', 8801, 8802],
] as [$bId, $bTitle, $aId, $cId]) {
    $db->execute("INSERT INTO books (id, title, isbn, published_year, page_count, status, average_rating, ratings_count)
                  VALUES (?, ?, ?, 2020, 300, 'published', 4.5, 10)", [$bId, $bTitle, '978000000' . $bId]);
    $db->execute("INSERT INTO book_authors (book_id, author_id) VALUES (?, ?)", [$bId, $aId]);
    $db->execute("INSERT INTO book_categories (book_id, category_id) VALUES (?, ?)", [$bId, $cId]);
}

try {
    // -----------------------------------------------------------------
    // Scenario 1 & 2: Followed author vs Favourite/Rated author without follow
    // -----------------------------------------------------------------
    section('1 & 2. AUTHOR FOLLOW VS RATED/FAVOURITE AUTHOR EXPLANATION');

    // User A follows Author Austen (8801)
    $followService->follow($userAId, 8801);

    // User A rates Book 8802 (by Tolstoy, 8802) with 5 stars (creates rating & favourite author without follow)
    $db->execute("INSERT INTO reviews (user_id, book_id, rating, review, created_at)
                  VALUES (?, ?, 5, 'Masterpiece', ?)", [$userAId, 8802, gmdate('Y-m-d\TH:i:s\Z')]);

    $service->invalidatePersonalization($userAId);
    $profileA = $service->profileFor($userAId);

    check('User A follows Austen (8801)', in_array(8801, $profileA->followedAuthorIds, true));
    check('User A does NOT follow Tolstoy (8802)', !in_array(8802, $profileA->followedAuthorIds, true));
    check('User A has Tolstoy in favouriteAuthors from rating', isset($profileA->favouriteAuthors[8802]));

    $recA = $service->getPersonalizedRecommendations($userAId, 20);

    // Find Book 8804 (by followed author Austen)
    $austenItem = null;
    $tolstoyItem = null;
    foreach ($recA->items as $item) {
        if ((int) $item['id'] === 8804) {
            $austenItem = $item;
        }
        if ((int) $item['id'] === 8803) {
            $tolstoyItem = $item;
        }
    }

    check('Scenario 1: Followed author book carries "Because you follow R3 Followed Author Austen."',
        $austenItem !== null && str_contains($austenItem['reason'], 'Because you follow R3 Followed Author Austen'));

    check('Scenario 1: Followed author book matched author factor',
        $austenItem !== null && in_array('author', $austenItem['matched'], true));

    check('Scenario 2: Unfollowed rated author book does NOT say "Because you follow"',
        $tolstoyItem === null || !str_contains($tolstoyItem['reason'], 'Because you follow'));

    check('Scenario 2: Unfollowed rated author book does NOT carry author matched factor',
        $tolstoyItem === null || !in_array('author', $tolstoyItem['matched'] ?? [], true));

    // -----------------------------------------------------------------
    // Scenario 3: Category match precision
    // -----------------------------------------------------------------
    section('3. CATEGORY MATCH EXPLANATION PRECISION');

    // Find Book 8805 (by Poe, category SciFi)
    $poeSciFiItem = null;
    foreach ($recA->items as $item) {
        if ((int) $item['id'] === 8805) {
            $poeSciFiItem = $item;
        }
    }

    check('Scenario 3: SciFi book explanation names "R3 SciFi Category"',
        $poeSciFiItem !== null && str_contains($poeSciFiItem['reason'], 'R3 SciFi Category'));

    check('Scenario 3: SciFi book explanation does NOT claim Mystery category',
        $poeSciFiItem !== null && !str_contains($poeSciFiItem['reason'], 'R3 Mystery Category'));

    // -----------------------------------------------------------------
    // Scenario 4: Wishlist / Viewed explanation
    // -----------------------------------------------------------------
    section('4. WISHLIST & RECENTLY VIEWED EXPLANATIONS');

    $emptyProfile = new PersonalizationProfile(
        userId:                999,
        favouriteCategories:   [],
        favouriteAuthors:      [],
        wishlistBookIds:       [10],
        highlyRatedBookIds:    [],
        reviewedBookIds:       [],
        recentlyViewedBookIds: [],
        followedAuthorIds:     [],
        builtAt:               gmdate('Y-m-d\TH:i:s\Z'),
    );

    $wishlistOnlyReason = $service->getRecommendationReason(['wishlist'], $emptyProfile, ['wishlist' => 2, 'viewed' => 0]);
    check('Wishlist-only reason says "Similar to books in your wishlist."',
        $wishlistOnlyReason === 'Similar to books in your wishlist.');

    $viewedOnlyReason = $service->getRecommendationReason(['wishlist'], $emptyProfile, ['wishlist' => 2, 'viewed' => 2]);
    check('Viewed-only reason says "Similar to books you recently viewed."',
        $viewedOnlyReason === 'Similar to books you recently viewed.');

    $bothReason = $service->getRecommendationReason(['wishlist'], $emptyProfile, ['wishlist' => 3, 'viewed' => 1]);
    check('Both wishlist and viewed reason says "Similar to books in your wishlist or recently viewed."',
        $bothReason === 'Similar to books in your wishlist or recently viewed.');

    // -----------------------------------------------------------------
    // Scenario 5: Rating-derived recommendation (truthful, no collaborative claims)
    // -----------------------------------------------------------------
    section('5. RATING-DERIVED RECOMMENDATION EXPLANATION');

    $ratingReason = $service->getRecommendationReason(['rating'], $emptyProfile, ['rating' => 1]);
    check('Rating explanation is "Shares categories with books you rated highly."',
        $ratingReason === 'Shares categories with books you rated highly.');

    check('Rating explanation does NOT contain misleading collaborative claim "Popular among readers"',
        !str_contains($ratingReason, 'Popular among') && !str_contains($ratingReason, 'readers of'));

    // -----------------------------------------------------------------
    // Scenario 6: Review-derived recommendation
    // -----------------------------------------------------------------
    section('6. REVIEW-DERIVED RECOMMENDATION EXPLANATION');

    $reviewReason = $service->getRecommendationReason(['review_score'], $emptyProfile, ['review_score' => 0.9]);
    check('Review score explanation is "Highly rated by the community."',
        $reviewReason === 'Highly rated by the community.');

    // -----------------------------------------------------------------
    // Scenario 7: Community-derived recommendation
    // -----------------------------------------------------------------
    section('7. COMMUNITY-DERIVED RECOMMENDATION EXPLANATION');

    $communityReason = $service->getRecommendationReason(['community'], $emptyProfile, ['community' => 3.0]);
    check('Community explanation is "Based on books you discussed in the community."',
        $communityReason === 'Based on books you discussed in the community.');

    // -----------------------------------------------------------------
    // Scenario 8: Trending recommendation
    // -----------------------------------------------------------------
    section('8. TRENDING RECOMMENDATION EXPLANATION');

    $trendingReason = $service->getRecommendationReason(['trending'], $emptyProfile, ['trending' => 4.0]);
    check('Trending explanation is "Gaining momentum this month."',
        $trendingReason === 'Gaining momentum this month.');

    $noTrendingReason = $service->getRecommendationReason(['review_score'], $emptyProfile, ['trending' => 0, 'review_score' => 0.8]);
    check('No trending signal does NOT output trending explanation',
        !str_contains($noTrendingReason, 'Gaining momentum'));

    // -----------------------------------------------------------------
    // Scenario 9 & 12: Popularity fallback & Cold-start honesty
    // -----------------------------------------------------------------
    section('9 & 12. POPULARITY FALLBACK & COLD-START HONESTY');

    $fallbackReason = $service->getRecommendationReason([], $emptyProfile, []);
    check('No factors matched returns "A community favourite - a starting point for your profile."',
        $fallbackReason === 'A community favourite - a starting point for your profile.');

    $cleanProfile = new PersonalizationProfile(
        userId:                9999,
        favouriteCategories:   [],
        favouriteAuthors:      [],
        wishlistBookIds:       [],
        highlyRatedBookIds:    [],
        reviewedBookIds:       [],
        recentlyViewedBookIds: [],
        followedAuthorIds:     [],
        builtAt:               gmdate('Y-m-d\TH:i:s\Z'),
    );

    $cleanFallbackReason = $service->getRecommendationReason([], $cleanProfile, []);
    check('Cold-start reason makes no personal claims ("You enjoy", "Because you follow", "wishlist", "rated highly")',
        !str_contains($cleanFallbackReason, 'You enjoy')
        && !str_contains($cleanFallbackReason, 'Because you follow')
        && !str_contains($cleanFallbackReason, 'wishlist')
        && !str_contains($cleanFallbackReason, 'rated highly'));

    // -----------------------------------------------------------------
    // Scenario 10: Complete absence of unsupported collaborative claims
    // -----------------------------------------------------------------
    section('10. COMPLETE ABSENCE OF UNSUPPORTED COLLABORATIVE CLAIMS');

    $allReasonsString = '';
    foreach ($recA->items as $item) {
        $allReasonsString .= ' ' . ($item['reason'] ?? '');
    }

    check('Personalized recommendations never contain "Popular among readers of your highly rated books"',
        !str_contains($allReasonsString, 'Popular among readers of your highly rated books'));

    check('Personalized recommendations never contain "readers of your"',
        !str_contains($allReasonsString, 'readers of your'));

    // -----------------------------------------------------------------
    // Scenario 11: Multiple signals cleanly combined
    // -----------------------------------------------------------------
    section('11. MULTIPLE SIGNALS COMBINED');

    $multiReason = $service->getRecommendationReason(
        ['author', 'category'],
        $profileA,
        ['author' => 1, 'category' => 1],
        [8801], // candidate author: Austen
        [8801], // candidate category: SciFi
    );
    check('Multi-signal reason combines category and author follow',
        str_contains($multiReason, 'You enjoy R3 SciFi Category books.')
        && str_contains($multiReason, 'Because you follow R3 Followed Author Austen.'));

    // -----------------------------------------------------------------
    // Scenario 13: Phase R1 Exclusions remain intact
    // -----------------------------------------------------------------
    section('13. PHASE R1 EXCLUSIONS PRESERVED');

    $recIdsA = array_map(fn (array $i): int => (int) $i['id'], $recA->items);
    check('Rated Book 8802 is strictly excluded from recommendations',
        !in_array(8802, $recIdsA, true));

    // -----------------------------------------------------------------
    // Scenario 14: Phase R2 Author-follow scoring remains active
    // -----------------------------------------------------------------
    section('14. PHASE R2 AUTHOR-FOLLOW SCORING ACTIVE');

    check('Followed author book received >= 25 score boost',
        $austenItem !== null && (float) $austenItem['score'] >= 25.0);

    // -----------------------------------------------------------------
    // Scenario 15: User isolation
    // -----------------------------------------------------------------
    section('15. USER ISOLATION');

    // User B does NOT follow Austen and did not rate Tolstoy
    $profileB = $service->profileFor($userBId);
    check('User B does not have Austen in followedAuthorIds',
        !in_array(8801, $profileB->followedAuthorIds, true));

    $recB = $service->getPersonalizedRecommendations($userBId, 20);
    $recBReasons = implode(' ', array_column($recB->items, 'reason'));

    check('User B recommendations never claim to follow Austen',
        !str_contains($recBReasons, 'Because you follow R3 Followed Author Austen'));

    // Dashboard Section 4 isolation
    $session->put('auth_user_id', $userBId);
    $session->put('auth_user', ['id' => $userBId, 'role' => 'user']);
    $dashboardB = $presenter->compose();
    check('User B follow shelf is empty (follows 0 authors)',
        $dashboardB['follow'] === []);

    $session->put('auth_user_id', $userAId);
    $session->put('auth_user', ['id' => $userAId, 'role' => 'user']);
    $personalShelfA = RecommendationResult::fromBooks('personal', 'Recommended', [$austenItem], 'Personal note');
    $dashboardA = $presenter->compose($personalShelfA);
    check('User A follow shelf is populated with followed author releases',
        count($dashboardA['follow']) > 0);
    check('User A follow shelf picks all say "New release from an author you follow."',
        array_reduce($dashboardA['follow'], fn (bool $c, array $i): bool => $c && ($i['reason'] ?? '') === 'New release from an author you follow.', true));

} finally {
    // -----------------------------------------------------------------
    // Cleanup
    // -----------------------------------------------------------------
    $db->execute("DELETE FROM author_follows WHERE user_id IN (1001, 1002)");
    $db->execute("DELETE FROM reviews WHERE user_id IN (1001, 1002)");
    $db->execute("DELETE FROM wishlist WHERE user_id IN (1001, 1002)");
    $db->execute("DELETE FROM book_views WHERE user_id IN (1001, 1002)");
    $db->execute("DELETE FROM user_library WHERE user_id IN (1001, 1002)");
    $db->execute("DELETE FROM users WHERE id IN (1001, 1002)");

    $db->execute("DELETE FROM book_authors WHERE author_id IN (8801, 8802, 8803)");
    $db->execute("DELETE FROM book_categories WHERE category_id IN (8801, 8802)");
    $db->execute("DELETE FROM books WHERE id IN (8801, 8802, 8803, 8804, 8805, 8806)");
    $db->execute("DELETE FROM authors WHERE id IN (8801, 8802, 8803)");
    $db->execute("DELETE FROM categories WHERE id IN (8801, 8802)");
}

echo PHP_EOL . str_repeat('-', 72) . PHP_EOL;
echo "TOTAL: {$pass} passed, {$fail} failed" . PHP_EOL;
echo str_repeat('-', 72) . PHP_EOL;

if ($fail > 0) {
    exit(1);
}
