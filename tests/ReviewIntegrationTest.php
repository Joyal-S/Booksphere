<?php

declare(strict_types=1);

/**
 * ReviewIntegrationTest — CLI test suite for Phase 7.6 (Reviews &
 * Ratings across the platform)
 *
 * Verifies that the completed Reviews & Ratings module now feeds
 * every corner of BookSphere: the dashboard shelves, the author and
 * category pages, the enriched user profile, the extended admin
 * analytics and the recommendation engine's review_score factor.
 * Same throwaway-database harness as every other suite:
 *
 *     1. Platform statistics - the composed payload of the admin
 *        analytics (total reviews, active reviewers, books without
 *        reviews, highest / lowest rated, most active reviewers,
 *        most reviewed categories, category and author averages)
 *        computed from the SEED data (12 approved reviews, 4
 *        reviewers, 8 unreviewed books)
 *     2. Top Rated / Most Reviewed - the two dashboard shelves
 *        (average-first vs count-first ordering, truthful row shape,
 *        the community-favourites alias)
 *     3. Author statistics - one author with a review (Orwell:
 *        reviews / booksReviewed / average / highestRated /
 *        mostReviewed / recentReviews / topReviewers) and one
 *        untouched author (everything empty)
 *     4. Category statistics - Technology (3 reviews, top rated,
 *        most reviewed, community favourite, recent reviews) and an
 *        untouched category
 *     5. User statistics - Riya's enriched profile (total reviews,
 *        average given, favourite genres, most reviewed category),
 *        the review-activity timeline and the highest-rated book;
 *        a fresh user gets an empty profile
 *     6. Dashboard statistics - the single composed payload with all
 *        six shelves and the correct per-user slices
 *     7. Review-score recommendation integration - the seventh
 *        hybrid factor: the weight lives in config (10), the
 *        scoring mirror grants partial credit up to the cap, and the
 *        Reviews module reports its own weight through
 *        recommendationWeight()
 *     8. Admin analytics - the extended payload shape
 *        (overallAverage as ['average','count'], distribution,
 *        totals, reviewer / category / author rankings)
 *     9. Moderation discipline - a 'hidden' review contributes
 *        NOTHING to any aggregate; flipping it to 'approved' moves
 *        every counter
 *    10. Soft-deleted books - a book stamped deleted_at vanishes
 *        from every shelf and every statistics payload, and comes
 *        back after restore
 *    11. Model facade - the Review model forwards the Phase 7.6
 *        reads to the repository
 *    12. Controller smoke - AuthorController / CategoryController
 *        index + show render the new pages with real data
 *
 * Run from the project root:
 *
 *     php tests/ReviewIntegrationTest.php
 *
 * The throwaway database (database/review_integration_test.db) is
 * migrated, seeded and left in place for inspection; delete it
 * anytime.
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Core\Logger;
use BookSphere\App\Core\Migrator;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Seeder;
use BookSphere\App\Core\Session;
use BookSphere\App\Controllers\AuthorController;
use BookSphere\App\Controllers\CategoryController;
use BookSphere\App\Models\Author;
use BookSphere\App\Models\Book;
use BookSphere\App\Models\Category;
use BookSphere\App\Models\Review;
use BookSphere\App\Models\User;
use BookSphere\App\Repositories\ReviewRepository;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\RecommendationScoring;
use BookSphere\App\Services\ReviewService;

// ---------------------------------------------------------------------
// 0. Boot: fresh throwaway database, migrated and seeded.
// ---------------------------------------------------------------------

(new Environment(root_path('.env')))->load();

$dbPath = root_path('database/review_integration_test.db');

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
$session = new Session('review_integration_test');
$session->start();
$auth = new AuthService($session, new User());
AuthService::setInstance($auth);

$logFile = sys_get_temp_dir() . '/booksphere_review_integration_test.log';
if (is_file($logFile)) {
    unlink($logFile);
}

// ---------------------------------------------------------------------
// Shared fixtures (resolved from the seed data by email / title).
// ---------------------------------------------------------------------

$users     = new User();
$admin     = $users->findByEmail('admin@booksphere.test');
$riya      = $users->findByEmail('riya@booksphere.test');
$riyaId    = (int) $riya['id'];
$adminId   = (int) $admin['id'];

$findByName = fn (string $table, string $name): ?array => db()->query(
    "SELECT id, name FROM {$table} WHERE name = ?",
    [$name],
)[0] ?? null;

$orwell = $findByName('authors', 'George Orwell');
$gabo   = $findByName('authors', 'Gabriel Garcia Marquez');
$tech   = $findByName('categories', 'Technology');
$psych  = $findByName('categories', 'Psychology');
$classic = $findByName('categories', 'Classic Fiction');

$bookId = fn (string $title): int => (int) db()->query('SELECT id FROM books WHERE title = ?', [$title])[0]['id'];
$book1984    = $bookId('1984');
$bookHobbit  = $bookId('The Hobbit');

$reviewModel = new Review();
$repository  = new ReviewRepository();
$service     = new ReviewService(
    $reviewModel,
    new Book(),
    null,
    new Logger($logFile),
);

$section   = fn (string $title): string => "\n------------------------------------------------------------------------\n{$title}\n------------------------------------------------------------------------";
$check     = function (string $label, bool $ok): void {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . PHP_EOL;
    $GLOBALS['failures'] = ($GLOBALS['failures'] ?? 0) + ($ok ? 0 : 1);
    $GLOBALS['checks']   = ($GLOBALS['checks'] ?? 0) + 1;
};
$failures  = 0;
$checks    = 0;

// ---------------------------------------------------------------------
// 1. PLATFORM STATISTICS (the seed picture: 12 reviews, 4 reviewers,
//    8 unreviewed books, average 53/12 = 4.42)
// ---------------------------------------------------------------------

echo $section('1. PLATFORM STATISTICS: the seed picture');

$platform = $repository->platformStatistics();

$check('The platform counts the 12 seeded approved reviews', $platform['totalReviews'] === 12);
$check('The catalogue average matches 53 / 12', abs((float) $platform['average'] - (53 / 12)) < 0.0001);
$check('Four distinct reviewers are active', $platform['activeReviewers'] === 4);
$check('Eight books have no approved review yet', $platform['booksWithoutReviews'] === 8);

$highest = $platform['highestRated'];
$check('The highest rated list is the 5.0-average shelf', $highest !== [] && (float) $highest[0]['average'] === 5.0);
$check('1984 leads the highest rated shelf (5.0, title first)', $highest[0]['title'] === '1984');

$lowest = $platform['lowestRated'];
$check('Deep Work is the lowest rated reviewed book', $lowest[0]['title'] === 'Deep Work' && (float) $lowest[0]['average'] === 3.0);

$reviewers = $platform['mostActiveReviewers'];
$check('All four seeded users are the most active reviewers', count($reviewers) === 4 && $reviewers[0]['count'] == 3);
$check('The reviewer rows carry name / average / helpful', isset($reviewers[0]['user_name'], $reviewers[0]['average'], $reviewers[0]['helpful']));

$categories = $platform['mostReviewedCategories'];
$check('Technology is the most reviewed category (3 reviews)', $categories[0]['name'] === 'Technology' && $categories[0]['count'] == 3);

$averages = $platform['categoryAverage'];
$check('The category averages lead with Science Fiction at 5.0', $averages[0]['name'] === 'Science Fiction' && (float) $averages[0]['average'] === 5.0);
$check('Every category average row carries its id', isset($averages[0]['id']));

$authors = $platform['authorAverage'];
$check('The author averages are computed over approved reviews', count($authors) === 13 && (float) $authors[0]['average'] === 5.0);
$check('Every author average row carries its id', isset($authors[0]['id']));

// ---------------------------------------------------------------------
// 2. TOP RATED / MOST REVIEWED (the dashboard shelves)
// ---------------------------------------------------------------------

echo $section('2. TOP RATED / MOST REVIEWED: the shelves');

$topRated = $repository->topRatedBooks(5);
$check('The top-rated shelf returns 5 books', count($topRated) === 5);
$check('The shelf rows carry the renderable shape', isset($topRated[0]['id'], $topRated[0]['title'], $topRated[0]['cover_image'], $topRated[0]['average'], $topRated[0]['count']));
$check('The shelf is ordered by average then count then title', $topRated[0]['title'] === '1984' && (float) $topRated[0]['average'] === 5.0);
$check('The admin highestRatedBooks read delegates to the same query', $repository->highestRatedBooks(5) === $topRated);

$mostReviewed = $repository->mostReviewedBooks(5);
$check('The most-reviewed shelf returns 5 books', count($mostReviewed) === 5);
$check('The most-reviewed shelf orders by count first', $mostReviewed[0]['title'] === '1984' && (int) $mostReviewed[0]['count'] === 1);
$check('The service communityFavorites() is the same list', $service->communityFavorites(4) === $service->mostReviewedBooks(4));

// ---------------------------------------------------------------------
// 3. AUTHOR STATISTICS (one reviewed author, one untouched author)
// ---------------------------------------------------------------------

echo $section('3. AUTHOR STATISTICS: the author page payload');

$orwellStats = $repository->authorStatistics((int) $orwell['id']);
$check('Orwell: 1 approved review across 1 book', $orwellStats['reviews'] === 1 && $orwellStats['booksReviewed'] === 1);
$check('Orwell: the average is his single 5.0', (float) $orwellStats['average'] === 5.0);
$check('Orwell: 1984 is both highest rated and most reviewed', $orwellStats['highestRated']['title'] === '1984' && $orwellStats['mostReviewed']['title'] === '1984');
$check('Orwell: the recent review carries the reviewer and the cover', $orwellStats['recentReviews'][0]['user_name'] === 'Riya Sharma' && isset($orwellStats['recentReviews'][0]['cover_image']));
$check('Orwell: Riya leads the top reviewers', $orwellStats['topReviewers'][0]['user_name'] === 'Riya Sharma' && $orwellStats['topReviewers'][0]['count'] == 1);

$gaboStats = $repository->authorStatistics((int) $gabo['id']);
$check('An untouched author reports an empty profile', $gaboStats['reviews'] === 0 && $gaboStats['booksReviewed'] === 0 && (float) $gaboStats['average'] === 0.0);
$check('An untouched author has no spotlight books or reviews', $gaboStats['highestRated'] === null && $gaboStats['mostReviewed'] === null && $gaboStats['recentReviews'] === []);

$unknownAuthor = $repository->authorStatistics(999999);
$check('A missing author id degrades to the same empty shape', $unknownAuthor['reviews'] === 0 && $unknownAuthor['highestRated'] === null);

// ---------------------------------------------------------------------
// 4. CATEGORY STATISTICS (Technology, the untouched Psychology)
// ---------------------------------------------------------------------

echo $section('4. CATEGORY STATISTICS: the category page payload');

$techStats = $repository->categoryStatistics((int) $tech['id']);
$check('Technology: 3 approved reviews across 3 books', $techStats['reviews'] === 3 && $techStats['booksReviewed'] === 3);
$check('Technology: the average is (4+5+3)/3', abs((float) $techStats['average'] - 4.0) < 0.0001);
$check('Technology: The Pragmatic Programmer leads top rated', $techStats['topRated'][0]['title'] === 'The Pragmatic Programmer');
$check('Technology: the same book is the community favourite', $techStats['communityFavourite']['title'] === 'The Pragmatic Programmer');
$check('Technology: the most-reviewed shelf matches', $techStats['mostReviewed'][0]['title'] === 'The Pragmatic Programmer');
$check('Technology: three recent community reviews', count($techStats['recentReviews']) === 3);

$classicStats = $repository->categoryStatistics((int) $classic['id']);
$check('Classic Fiction: 2 reviews average 4.5', $classicStats['reviews'] === 2 && abs((float) $classicStats['average'] - 4.5) < 0.0001);

$psychStats = $repository->categoryStatistics((int) $psych['id']);
$check('An untouched category reports an empty profile', $psychStats['reviews'] === 0 && $psychStats['topRated'] === [] && $psychStats['communityFavourite'] === null);

// ---------------------------------------------------------------------
// 5. USER STATISTICS (the enriched profile)
// ---------------------------------------------------------------------

echo $section('5. USER STATISTICS: the enriched profile');

$riyaStats = $repository->userStatistics($riyaId);
$check('Riya: 3 approved reviews with an average of 14/3', $riyaStats['count'] === 3 && abs((float) $riyaStats['average'] - (14 / 3)) < 0.0001);
$check('Riya: the highest-rated book spotlight exists', $riyaStats['highest'] !== null);
$check('Riya: Classic Fiction is the most reviewed category', $riyaStats['mostReviewedCategory'] === 'Classic Fiction');
$check('Riya: the favourite genres lead with Classic Fiction (2)', $riyaStats['favouriteCategories'][0]['name'] === 'Classic Fiction' && $riyaStats['favouriteCategories'][0]['count'] == 2);
$check('Riya: the favourite genre rows carry their id', isset($riyaStats['favouriteCategories'][0]['id']));

$timeline = $repository->reviewActivityTimeline($riyaId);
$check('Riya: one month row with her 3 reviews', count($timeline) === 1 && $timeline[0]['count'] == 3 && (bool) preg_match('/^\d{4}-\d{2}$/', (string) $timeline[0]['month']));

$highestBook = $repository->userHighestRatedBook($riyaId);
$check('Riya: her highest rated book is a 5.0 pick', $highestBook !== null && (float) $highestBook['average'] === 5.0);
$check('Riya: the highest rated book carries the renderable shape', isset($highestBook['id'], $highestBook['title'], $highestBook['cover_image']));

$freshId = (int) db()->query(
    'INSERT INTO users (full_name, email, password) VALUES (?, ?, ?) RETURNING id',
    ['Fresh Reviewer', 'fresh@test.dev', 'x'],
)[0]['id'];
$freshStats = $repository->userStatistics($freshId);
$check('A fresh user reports an empty profile', $freshStats['count'] === 0 && $freshStats['average'] === null && $freshStats['mostReviewedCategory'] === null && $freshStats['favouriteCategories'] === []);
$check('A fresh user has no highest rated book and no timeline', $repository->userHighestRatedBook($freshId) === null && $repository->reviewActivityTimeline($freshId) === []);

// ---------------------------------------------------------------------
// 6. DASHBOARD STATISTICS (the composed payload)
// ---------------------------------------------------------------------

echo $section('6. DASHBOARD STATISTICS: the composed payload');

$dashboard = $service->dashboardStatistics($riyaId);
foreach (['topRated', 'recentlyReviewed', 'communityFavourites', 'recentCommunityReviews', 'myLatestReview', 'myHighestRatedBook'] as $key) {
    $check('The dashboard payload carries ' . $key, array_key_exists($key, $dashboard));
}
$check('The dashboard shelves hold 4 books each', count($dashboard['topRated']) === 4 && count($dashboard['communityFavourites']) === 4 && count($dashboard['recentlyReviewed']) === 4);
$check('The recent community reviews are the highest-rated slice', count($dashboard['recentCommunityReviews']) === 4);
$check('Riya: her latest review is present', is_array($dashboard['myLatestReview']));
$check('Riya: her highest rated book card is present', is_array($dashboard['myHighestRatedBook']) && (float) $dashboard['myHighestRatedBook']['average'] === 5.0);

$adminDashboard = $service->dashboardStatistics($adminId);
$check('Admin: the newest review is Atomic Habits (last seed insert)', $adminDashboard['myLatestReview']['book_title'] === 'Atomic Habits');

$emptyDashboard = $service->dashboardStatistics($freshId);
$check('A fresh user gets empty personal shelves', $emptyDashboard['myLatestReview'] === null && $emptyDashboard['myHighestRatedBook'] === null);

// ---------------------------------------------------------------------
// 7. REVIEW-SCORE RECOMMENDATION INTEGRATION (the seventh factor)
// ---------------------------------------------------------------------

echo $section('7. REVIEW-SCORE RECOMMENDATION INTEGRATION');

$weights = RecommendationScoring::hybridWeights();
$check('The review_score weight lives in config at 10', (int) config('recommendations.hybrid_weights.review_score', 10) === 10);
$check('The scoring mirror reads the same weight', (float) $weights['review_score'] === 10.0);
$check('The default constant agrees with the config', RecommendationScoring::HYBRID_WEIGHTS_DEFAULT['review_score'] === 10);
$check('The seven weights still sum to 100', abs(array_sum($weights) - 100) < 0.0001);
$check('The factor cap is the full 1.0 (5.0 rating)', RecommendationScoring::REVIEW_SCORE_FACTOR_CAP === 1.0);

$score = fn (float $signal): float => RecommendationScoring::hybridScore([
    'category' => 0, 'author' => 0, 'wishlist' => 0, 'rating' => 0,
    'review_score' => $signal, 'trending' => 0, 'popularity' => 0,
]);
$check('A perfect community rating earns the full 10 points', abs($score(1.0) - 10.0) < 0.0001);
$check('A 2.5-rated book earns half the factor', abs($score(0.5) - 5.0) < 0.0001);
$check('A book without reviews earns nothing', abs($score(0.0) - 0.0) < 0.0001);
$check('The signal can never exceed its own weight (capped)', $score(1.7) === 10.0);
$check('The Reviews module reports its own weight', $service->recommendationWeight() === 10);

// ---------------------------------------------------------------------
// 8. ADMIN ANALYTICS (the extended payload)
// ---------------------------------------------------------------------

echo $section('8. ADMIN ANALYTICS: the extended payload');

$analytics = $service->adminAnalytics();
$check('The overall average is shaped as average + count', isset($analytics['overallAverage']['average'], $analytics['overallAverage']['count']) && $analytics['overallAverage']['count'] === 12);
$check('The overall average value matches the catalogue', abs((float) $analytics['overallAverage']['average'] - (53 / 12)) < 0.0001);

$distribution = $analytics['distribution'];
$check('The distribution fills all five stars', $distribution[1] === 0 && $distribution[3] === 1 && $distribution[4] === 5 && $distribution[5] === 6);
$check('The distribution sums to the review total', array_sum($distribution) === 12);

foreach (['highestRated', 'lowestRated', 'booksWithoutRatings', 'categoryAverage', 'totalReviews', 'activeReviewers', 'booksWithoutReviews', 'mostActiveReviewers', 'mostReviewedCategories', 'authorAverage'] as $key) {
    $check('The admin payload carries ' . $key, array_key_exists($key, $analytics));
}
$check('The admin payload reports 12 reviews and 4 reviewers', $analytics['totalReviews'] === 12 && $analytics['activeReviewers'] === 4);
$check('The unrated-books list holds the 8 untouched titles', count($analytics['booksWithoutRatings']) === 8);

// ---------------------------------------------------------------------
// 9. MODERATION DISCIPLINE (hidden reviews contribute nothing)
// ---------------------------------------------------------------------

echo $section('9. MODERATION DISCIPLINE: status counts');

$hiddenId = (int) db()->query(
    'INSERT INTO reviews (book_id, user_id, rating, title, review, status)
     VALUES (?, ?, 4, \'Hidden review\', \'A hidden review that must never leak into aggregates.\', \'hidden\') RETURNING id',
    [$bookHobbit, $riyaId],
)[0]['id'];
$platform = $repository->platformStatistics();
$check('A hidden review does not move any platform counter', $platform['totalReviews'] === 12 && $platform['booksWithoutReviews'] === 8);
$check('A hidden review does not enter the shelves', $repository->topRatedBooks(20)[0]['title'] === '1984');

$repository->updateStatus($hiddenId, 'approved');
$platform = $repository->platformStatistics();
$check('Approving the review moves every counter', $platform['totalReviews'] === 13 && $platform['booksWithoutReviews'] === 7);
$check('The newly approved book joins the shelves', $repository->mostReviewedBooks(20)[0]['title'] === '1984' && in_array('The Hobbit', array_column($repository->mostReviewedBooks(20), 'title'), true));

// ---------------------------------------------------------------------
// 10. SOFT-DELETED BOOKS (deleted_at IS NULL everywhere)
// ---------------------------------------------------------------------

echo $section('10. SOFT-DELETED BOOKS: the deleted_at discipline');

db()->execute(
    'UPDATE books SET deleted_at = ? WHERE id = ?',
    ['2026-08-04T00:00:00Z', $book1984],
);
$platform = $repository->platformStatistics();
$check('Deleting a reviewed book drops the review total', $platform['totalReviews'] === 12);
$check('The deleted book leaves the top-rated shelf', $repository->topRatedBooks(20)[0]['title'] !== '1984');
$check('The deleted book leaves the community favourites', $repository->mostReviewedBooks(20)[0]['title'] !== '1984');
$check('The author of a deleted book loses their profile', $repository->authorStatistics((int) $orwell['id'])['reviews'] === 0);
$check('The deleted book never appears in the unrated list either', $platform['booksWithoutReviews'] === 7);

db()->execute('UPDATE books SET deleted_at = NULL WHERE id = ?', [$book1984]);
$platform = $repository->platformStatistics();
$check('Restoring the book restores every counter', $platform['totalReviews'] === 13);

// ---------------------------------------------------------------------
// 11. MODEL FACADE (Review forwards the Phase 7.6 reads)
// ---------------------------------------------------------------------

echo $section('11. MODEL FACADE: the Review model forwards');

$check('Review::platformStatistics() matches the repository', $reviewModel->platformStatistics()['totalReviews'] === 13);
$check('Review::mostReviewedBooks() forwards the shelf', count($reviewModel->mostReviewedBooks(3)) === 3);
$check('Review::authorStatistics() forwards the profile', $reviewModel->authorStatistics((int) $orwell['id'])['reviews'] === 1);
$check('Review::categoryStatistics() forwards the profile', $reviewModel->categoryStatistics((int) $tech['id'])['reviews'] === 3);
$check('Review::userStatistics() forwards the enriched profile', $reviewModel->userStatistics($riyaId)['count'] === 4);
$check('Review::reviewActivityTimeline() forwards the timeline', $reviewModel->reviewActivityTimeline($riyaId)[0]['count'] == 4);
$check('Review::userHighestRatedBook() forwards the card', $reviewModel->userHighestRatedBook($riyaId) !== null);
$check('Review::mostActiveReviewers() forwards the ranking', count($reviewModel->mostActiveReviewers(3)) === 3);
$check('Review::topRatedBooks() forwards the shelf', $reviewModel->topRatedBooks(4)[0]['title'] === '1984');
$check('Review::authorAverage() forwards the averages', $reviewModel->authorAverage()[0]['average'] == 5.0);

// ---------------------------------------------------------------------
// 12. CONTROLLER SMOKE (the two new controllers render real data)
// ---------------------------------------------------------------------

echo $section('12. CONTROLLER SMOKE: authors and categories pages');

$session->put('auth_user_id', $riyaId);
$session->put('auth_user', ['id' => $riyaId, 'full_name' => 'Riya Sharma', 'email' => 'riya@booksphere.test', 'role' => 'user']);

$authorController = new AuthorController(new Author(), $service);
ob_start();
$authorController->index(new Request());
$authorsHtml = (string) ob_get_clean();
$check('The author directory renders with the rating badges', str_contains($authorsHtml, 'Authors') && str_contains($authorsHtml, 'George Orwell') && str_contains($authorsHtml, '5.0'));

ob_start();
$authorController->show(new Request(), ['id' => (int) $orwell['id']]);
$authorHtml = (string) ob_get_clean();
$check('The author page renders the aggregated profile', str_contains($authorHtml, 'George Orwell') && str_contains($authorHtml, 'Riya Sharma') && str_contains($authorHtml, 'Top reviewers'));

$categoryController = new CategoryController(new Category(), $service);
ob_start();
$categoryController->index(new Request());
$categoriesHtml = (string) ob_get_clean();
$check('The category directory renders with the rating badges', str_contains($categoriesHtml, 'Categories') && str_contains($categoriesHtml, 'Technology') && str_contains($categoriesHtml, '3 reviews'));

ob_start();
$categoryController->show(new Request(), ['id' => (int) $tech['id']]);
$categoryHtml = (string) ob_get_clean();
$check('The category page renders the aggregated profile', str_contains($categoryHtml, 'Technology') && str_contains($categoryHtml, 'The Pragmatic Programmer') && str_contains($categoryHtml, 'Community favourite'));

// ---------------------------------------------------------------------
// RESULT
// ---------------------------------------------------------------------

echo $section('RESULT');
echo '  Checks: ' . $checks . PHP_EOL;
echo '  Failed: ' . $failures . PHP_EOL;

echo PHP_EOL . 'Note: the throwaway database database/review_integration_test.db and the log file ' . $logFile . ' are left in place for inspection; delete them anytime.' . PHP_EOL;

exit($failures === 0 ? 0 : 1);
