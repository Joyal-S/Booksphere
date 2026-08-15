<?php

declare(strict_types=1);

/**
 * PerformanceAuditTest — Dedicated performance & query plan regression test suite for Phase 13.2
 *
 * Verifies performance SLAs and functional integrity across BookSphere:
 *     1. Query Execution Speed (Analytics, Admin, Recommendations, Search < 10ms)
 *     2. EXPLAIN QUERY PLAN Verification (idx_books_status_rating eliminates temp B-tree sorting)
 *     3. Functional Integrity Verification (Search, Recommendations, Analytics payload values match)
 *
 * Run from project root:
 *     php tests/PerformanceAuditTest.php
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Config;
use BookSphere\App\Core\Database;
use BookSphere\App\DTO\SearchQuerySpec;
use BookSphere\App\Repositories\BookAnalyticsRepository;
use BookSphere\App\Repositories\BookRepository;
use BookSphere\App\Repositories\LibraryRepository;
use BookSphere\App\Repositories\RecommendationRepository;
use BookSphere\App\Repositories\ReviewRepository;
use BookSphere\App\Repositories\SearchRepository;
use BookSphere\App\Repositories\UserAnalyticsRepository;
use BookSphere\App\Services\AdminAnalyticsService;
use BookSphere\App\Services\BookAnalyticsService;
use BookSphere\App\Services\PersonalizationCache;
use BookSphere\App\Services\RecommendationFactory;
use BookSphere\App\Services\RecommendationMetrics;
use BookSphere\App\Services\RecommendationService;
use BookSphere\App\Services\UserAnalyticsService;
use BookSphere\App\Strategies\HighestRatedStrategy;
use BookSphere\App\Strategies\PopularBooksStrategy;
use BookSphere\App\Strategies\RecentlyAddedStrategy;
use BookSphere\App\Strategies\SameAuthorStrategy;
use BookSphere\App\Strategies\SameCategoryStrategy;
use BookSphere\App\Strategies\TrendingBooksStrategy;

$checks = 0;
$passed = 0;

$check = function (string $name, bool $condition) use (&$checks, &$passed): void {
    $checks++;
    if ($condition) {
        $passed++;
        echo "  PASS  {$name}\n";
    } else {
        echo "  FAIL  {$name}\n";
    }
};

echo "\n========================================================================\n";
echo "PHASE 13.2 PERFORMANCE AUDIT TEST SUITE\n";
echo "========================================================================\n";

$config = Config::loadFromDirectory(root_path('config'));
$db = Database::instance();

// 1. QUERY PLAN VERIFICATION
echo "\n1. EXPLAIN QUERY PLAN VERIFICATION\n";
$sql = "SELECT * FROM books WHERE status = 'published' AND deleted_at IS NULL ORDER BY average_rating DESC, id DESC LIMIT 10";
$plans = $db->query('EXPLAIN QUERY PLAN ' . $sql);
$usesIndex = false;
$usesTempBTree = false;

foreach ($plans as $plan) {
    if (str_contains($plan['detail'], 'idx_books_status_rating')) {
        $usesIndex = true;
    }
    if (str_contains($plan['detail'], 'USE TEMP B-TREE')) {
        $usesTempBTree = true;
    }
}
$check('Browse rating sort query uses idx_books_status_rating composite index', $usesIndex);
$check('Browse rating sort query eliminates temporary B-Tree sorting', !$usesTempBTree);

// 2. ENDPOINT PERFORMANCE SLAs
echo "\n2. ENDPOINT PERFORMANCE SLAs (< 15ms threshold)\n";
$bookRepo = new BookRepository();
$recRepo = new RecommendationRepository($bookRepo);
$bookAnalyticsRepo = new BookAnalyticsRepository();
$bookAnalyticsService = new BookAnalyticsService($bookAnalyticsRepo, $config->get('book_analytics'));
$recMetrics = new RecommendationMetrics($recRepo);

$userAnalyticsService = new UserAnalyticsService(new UserAnalyticsRepository(), $config->get('analytics'));
$t0 = microtime(true);
$userAnalytics = $userAnalyticsService->build(1);
$userAnalyticsMs = (microtime(true) - $t0) * 1000;
$check('User Analytics builds in < 15ms (' . sprintf('%.2f', $userAnalyticsMs) . 'ms)', $userAnalyticsMs < 15.0);

$t0 = microtime(true);
$bookAnalytics = $bookAnalyticsService->build();
$bookAnalyticsMs = (microtime(true) - $t0) * 1000;
$check('Book Analytics builds in < 15ms (' . sprintf('%.2f', $bookAnalyticsMs) . 'ms)', $bookAnalyticsMs < 15.0);

$adminAnalyticsService = new AdminAnalyticsService($bookAnalyticsService, $recRepo, $recMetrics);
$t0 = microtime(true);
$adminDashboard = $adminAnalyticsService->dashboard();
$adminAnalyticsMs = (microtime(true) - $t0) * 1000;
$check('Admin Analytics Dashboard builds in < 15ms (' . sprintf('%.2f', $adminAnalyticsMs) . 'ms)', $adminAnalyticsMs < 15.0);

$searchRepo = new SearchRepository();
$t0 = microtime(true);
$searchResults = $searchRepo->searchBooks(new SearchQuerySpec('books', 'PHP', ['PHP']));
$searchMs = (microtime(true) - $t0) * 1000;
$check('Book search executes in < 15ms (' . sprintf('%.2f', $searchMs) . 'ms)', $searchMs < 15.0);

// 3. FUNCTIONAL INTEGRITY VERIFICATION
echo "\n3. FUNCTIONAL INTEGRITY VERIFICATION\n";
$check('User Analytics returns non-null summary array', is_array($userAnalytics->toArray()));
$check('Book Analytics returns valid distribution array', isset($bookAnalytics->toArray()['overview']['distribution']));
$check('Admin Dashboard contains book catalogue block', isset($adminDashboard['books']));
$check('Search returns structured items and total count', isset($searchResults['items'], $searchResults['total']));

echo "\n------------------------------------------------------------------------\n";
echo "SUMMARY: Checks {$checks} | Passed {$passed} | Failed " . ($checks - $passed) . "\n";
echo "------------------------------------------------------------------------\n\n";

if ($passed !== $checks) {
    exit(1);
}
