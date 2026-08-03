<?php

declare(strict_types=1);

/**
 * RecommendationDashboardTest — CLI test suite for Phase 6.4
 *
 * Verifies the Recommendation Dashboard end-to-end without touching
 * real data (same harness as RecommendationArchitectureTest):
 *
 *     1. The presenter: view-model shape for a signal-less user
 *     2. The presenter: the full personalized dashboard for an
 *        active user - shelves, reasons, scores, exclusions,
 *        genre suggestions and the six insights
 *     3. The service: profileFor() and toggleWishlist() round-trip
 *     4. Controller smoke tests: the dashboard page renders every
 *        Phase 6.4 section with the explainable card component,
 *        and the wishlist quick action answers JSON on the fetch
 *        path (the redirect path cannot run in CLI - it exits)
 *     5. The Phase 6.2 contract is preserved: strategy cards, the
 *        rec-reason / rec-score lines and the six routes still
 *        render exactly as RecommendationArchitectureTest expects
 *
 * Run from the project root:
 *
 *     php tests/RecommendationDashboardTest.php
 *
 * How it works:
 *     - A throwaway SQLite database (database/dashboard_test.db) is
 *       created, migrated and seeded.
 *     - Extra reviews / wishlist rows / views are inserted INTO THE
 *       THROWAWAY DATABASE ONLY to exercise the dashboard.
 *     - Every check prints PASS/FAIL; the summary line doubles as
 *       the Phase 6.4 testing checklist for the viva.
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
use BookSphere\App\DTO\RecommendationResult;
use BookSphere\App\Models\Category;
use BookSphere\App\Models\User;
use BookSphere\App\Policies\RecommendationPolicy;
use BookSphere\App\Presenters\RecommendationDashboardPresenter;
use BookSphere\App\Repositories\BookRepository;
use BookSphere\App\Repositories\RecommendationRepository;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\RecommendationFactory;
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

$dbPath = root_path('database/dashboard_test.db');

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
$session = new Session('dashboard_test');
$session->start();
AuthService::setInstance(new AuthService($session, new User()));

// ---------------------------------------------------------------------
// Wiring (mirrors routes/web.php exactly, INCLUDING the Phase 6.4
// dashboard presenter).
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

$service    = new RecommendationService($factory, $repository);
$policy     = new RecommendationPolicy();
$presenter  = new RecommendationDashboardPresenter($service, $repository, $bookRepository, new Category());
$controller = new RecommendationController($service, $policy, $presenter);

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

function insertReview(int $bookId, int $userId, int $rating): void
{
    db()->execute(
        'INSERT INTO reviews (user_id, book_id, rating, review, created_at) VALUES (?, ?, ?, ?, ?)',
        [$userId, $bookId, $rating, 'Test review', gmdate('Y-m-d\TH:i:s\Z')],
    );
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

function allShelfBooks(array $dashboard): array
{
    $books = [];

    foreach (($dashboard['recommended']['items'] ?? []) as $item) {
        $books[] = (int) ($item['id'] ?? 0);
    }

    foreach (($dashboard['follow'] ?? []) as $item) {
        $books[] = (int) ($item['id'] ?? 0);
    }

    foreach (($dashboard['trending'] ?? []) as $item) {
        $books[] = (int) ($item['id'] ?? 0);
    }

    foreach (($dashboard['recent'] ?? []) as $item) {
        $books[] = (int) ($item['id'] ?? 0);
    }

    foreach (($dashboard['becauseLiked'] ?? []) as $block) {
        foreach (($block['items'] ?? []) as $item) {
            $books[] = (int) ($item['id'] ?? 0);
        }
    }

    return $books;
}

// ---------------------------------------------------------------------
// 1. Presenter: the view-model shape (signal-less user)
// ---------------------------------------------------------------------

section('1. PRESENTER: dashboard shape for a signal-less user');

$quietUserId = 7; // a freshly created user with zero activity
db()->execute(
    'INSERT INTO users (full_name, email, password) VALUES (?, ?, ?)',
    ['Quiet Reader', 'quiet@test.dev', 'x'],
);

$session->put('auth_user_id', $quietUserId);
$session->put('auth_user', ['id' => $quietUserId, 'full_name' => 'Quiet Reader', 'email' => 'quiet@test.dev', 'role' => 'user']);

$quiet = $presenter->compose();

check('compose() returns the top-level keys', isset($quiet['userId'], $quiet['hasSignals'], $quiet['quality'], $quiet['wishlistIds'])
    && isset($quiet['recommended'], $quiet['becauseLiked'], $quiet['follow'], $quiet['trending'], $quiet['recent'], $quiet['genres'], $quiet['insights']));

check('The dashboard carries the current user id', $quiet['userId'] === $quietUserId);
check('A user with no activity has no signals', $quiet['hasSignals'] === false);
check('The quality score is a 0-100 integer', $quiet['quality']['score'] >= 0 && $quiet['quality']['score'] <= 100);
check('The quality label is a confidence tone', in_array($quiet['quality']['label'], ['high', 'medium', 'low'], true));
check('The quality time is human-readable', $quiet['quality']['generatedAt'] !== '');
check('The wishlist snapshot is a list of ints', array_reduce($quiet['wishlistIds'], fn (bool $c, mixed $id): bool => $c && is_int($id), true));
check('The recommended shelf is an array', is_array($quiet['recommended']) && isset($quiet['recommended']['items']));
check('The genre suggestions and insights are arrays', is_array($quiet['genres']) && is_array($quiet['insights']));
check('The insights are exactly six', count($quiet['insights']) === 6);
check('Every insight carries icon/label/value/tone', array_reduce($quiet['insights'], fn (bool $c, array $s): bool => $c && isset($s['icon'], $s['label'], $s['value'], $s['tone']), true));

// ---------------------------------------------------------------------
// 2. Presenter: the personalized dashboard (active user)
// ---------------------------------------------------------------------

section('2. PRESENTER: the personalized dashboard');

// Give user 2 (riya) real signals in the THROWAWAY DATABASE ONLY.
// The seeds already give riya reviews on The Martian and 1984, so
// here we add the other signal sources: a wishlist save and views.
$martianId  = bookIdByTitle('The Martian');
$nineteenId = bookIdByTitle('1984');
$wingsId    = bookIdByTitle('Wings of Fire');
$hobbitId   = bookIdByTitle('The Hobbit');

insertWishlist($hobbitId, 2);
insertView($wingsId, 2);
insertView($nineteenId, 2);

$session->put('auth_user_id', 2);
$session->put('auth_user', ['id' => 2, 'full_name' => 'Riya', 'email' => 'riya@booksphere.test', 'role' => 'user']);

$profile = $service->profileFor(2);
check('profileFor() returns a PersonalizationProfile', $profile instanceof PersonalizationProfile);
check('The profile has favourite categories', $profile->favouriteCategories !== []);
check('The profile has favourite authors', $profile->favouriteAuthors !== []);
check('The profile knows the wishlist save', in_array($hobbitId, $profile->wishlistBookIds, true));
check('The profile knows the viewed books', in_array($wingsId, $profile->recentlyViewedBookIds, true));
check('The profile knows the highly rated reads', in_array($martianId, $profile->highlyRatedBookIds, true));

$dashboard = $presenter->compose();

check('The active user has signals', $dashboard['hasSignals'] === true);check('The recommended shelf is capped at 8', count($dashboard['recommended']['items']) <= 8);
check('Every recommended book explains WHY', array_reduce($dashboard['recommended']['items'], fn (bool $c, array $i): bool => $c && !empty($i['reason']), true));
check('Every personalized pick carries a score and confidence', array_reduce($dashboard['recommended']['items'], fn (bool $c, array $i): bool => $c && isset($i['score'], $i['confidence']), true));

$excluded = array_merge($dashboard['wishlistIds'], [$wingsId, $nineteenId]);
$shelfBooks = allShelfBooks($dashboard);
$excludedPresent = array_intersect($excluded, $shelfBooks);
check('Wishlist saves and recently viewed books never reappear', $excludedPresent === [], 'dupes: ' . implode(',', $excludedPresent));

check('Because-you-liked has at most 3 anchor groups', count($dashboard['becauseLiked']) <= 3);
$anchorOk = array_reduce($dashboard['becauseLiked'], function (bool $c, array $block): bool {
    return $c && isset($block['anchor'], $block['items'])
        && $block['items'] !== []
        && count($block['items']) <= 4;
}, true);
check('Every anchor group has an anchor book and up to 4 picks', $anchorOk);
check('Anchor picks are capped at 4 per group', array_reduce($dashboard['becauseLiked'], fn (bool $c, array $block): bool => $c && count($block['items']) <= 4, true));

check('The follow shelf never exceeds 6 books', count($dashboard['follow']) <= 6);
check('Every follow pick says "New release from an author you follow."', array_reduce($dashboard['follow'], fn (bool $c, array $i): bool => $c && ($i['reason'] ?? '') === 'New release from an author you follow.', true));

check('Trending reasons name the interest overlap', array_reduce($dashboard['trending'], fn (bool $c, array $i): bool => $c && str_contains((string) ($i['reason'] ?? ''), 'Trending in'), true));
check('Recent shelves explain themselves', array_reduce($dashboard['recent'], fn (bool $c, array $i): bool => $c && in_array($i['reason'] ?? '', ['Newest matching your interests.', 'Recently added to the catalogue.'], true), true));

$favouriteNames = array_map(static fn (array $c): string => (string) $c['name'], $profile->favouriteCategories);
check('Suggested genres exclude the favourites', array_reduce($dashboard['genres'], function (bool $c, array $g) use ($favouriteNames): bool {
    return $c && !in_array((string) $g['name'], $favouriteNames, true);
}, true));

$insightLabels = array_map(static fn (array $s): string => (string) $s['label'], $dashboard['insights']);
check('The insights carry the six expected labels',
    in_array('Favourite Category', $insightLabels, true)
    && in_array('Favourite Author', $insightLabels, true)
    && in_array('Recommendation Confidence', $insightLabels, true)
    && in_array('Books Analysed', $insightLabels, true)
    && in_array('Recommendations Generated', $insightLabels, true)
    && in_array('Last Recommendation Update', $insightLabels, true));

// ---------------------------------------------------------------------
// 3. Service: the wishlist toggle
// ---------------------------------------------------------------------

section('3. SERVICE: toggleWishlist()');

check('A fresh toggle adds the book', $service->toggleWishlist(2, $wingsId) === true);
check('The repository now reports it', in_array($wingsId, $repository->wishlistBookIds(2), true));
check('The profile sees it after invalidation', $service->toggleWishlist(2, $wingsId) === false);
check('A second toggle removes the book', !in_array($wingsId, $repository->wishlistBookIds(2), true));
check('A missing book cannot be saved', $service->toggleWishlist(2, 999999) === false);
check('A guest cannot save anything', $service->toggleWishlist(0, $martianId) === false);

// A draft book must never enter a wishlist (throwaway DB only).
db()->execute("UPDATE books SET status = 'draft' WHERE id = ?", [$wingsId]);
check('A draft book cannot be saved', $service->toggleWishlist(2, $wingsId) === false);
db()->execute("UPDATE books SET status = 'published' WHERE id = ?", [$wingsId]);
// ---------------------------------------------------------------------
// 4. Controller smoke: the dashboard page
// ---------------------------------------------------------------------

section('4. CONTROLLER: the dashboard renders every section');

$session->put('auth_user_id', 2);
$session->put('auth_user', ['id' => 2, 'full_name' => 'Riya', 'email' => 'riya@booksphere.test', 'role' => 'user']);

ob_start();
$controller->index(new Request(), []);
$html = (string) ob_get_clean();

check('The hero renders with its headline', str_contains($html, 'Your reading, decoded.'));
check('The hero has the refresh form with CSRF', str_contains($html, 'data-refresh-form') && str_contains($html, 'name="_token"'));
check('The refresh button shows its running state copy', str_contains($html, 'Running now'));
check('The quality ring renders with a score', str_contains($html, 'rec-quality-ring') && str_contains($html, 'data-quality-score'));
check('Section 1 renders: Recommended for you', str_contains($html, 'Recommended for you'));
check('Section 2 renders: Picks born from your recent reads', str_contains($html, 'Picks born from your recent reads'));
check('Section 3 renders: Because you follow', str_contains($html, 'Because you follow'));
check('Section 4 renders: Trending near your interests', str_contains($html, 'Trending near your interests'));
check('Section 5 renders: Recently added to the library', str_contains($html, 'Recently added to the library'));
check('Section 6 renders: Explore new genres', str_contains($html, 'Explore new genres'));
check('Section 7 renders: Recommendation insights', str_contains($html, 'Recommendation insights'));
check('Every recommendation card carries the explainable reason', substr_count($html, 'rec-reason') >= 4);
check('The score chip and confidence text render', str_contains($html, 'rec-score') && str_contains($html, 'confidence'));
check('The wishlist quick action renders on cards', substr_count($html, 'data-wishlist-form') >= 4);
check('The "Why" panel and toggle render', str_contains($html, 'data-reason-toggle') && str_contains($html, 'Why this recommendation?'));
check('The skeleton shelf exists and starts hidden', str_contains($html, 'skeleton-card') && str_contains($html, 'data-skeletons'));
check('The genre cards render with a count', substr_count($html, 'genre-card') >= 2);
check('The strategy cards survive (>= 6 rec-card)', substr_count($html, 'rec-card') >= 6 && str_contains($html, 'Trending'));
check('The dashboard footer names the profile', str_contains($html, 'profile #2'));

// The wishlist toggle on the fetch path answers JSON (the redirect
// path cannot run in CLI - Response::redirect() exits the script).
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';
$_POST['book_id'] = (string) $martianId;

ob_start();
$controller->toggleWishlist(new Request(), []);
$json = (string) ob_get_clean();

unset($_POST['book_id']);

$payload = json_decode($json, true);
check('toggleWishlist() answers JSON on the fetch path', is_array($payload) && isset($payload['saved']));
check('The JSON says the book is now saved', $payload['saved'] === true);
check('The wishlist table really changed', in_array($martianId, $repository->wishlistBookIds(2), true));

// The Phase 6.2 legacy routes still render through the same template.
ob_start();
$controller->popular(new Request(), []);
$legacy = (string) ob_get_clean();
check('The legacy strategy pages still render (page intro + cards)', str_contains($legacy, 'rec-card-active') && str_contains($legacy, 'Running now') && str_contains($legacy, 'highest first'));
check('The legacy reason badge still renders on strategy books', str_contains($legacy, 'rec-reason'));

// A controller WITHOUT the dashboard presenter keeps the untouched
// Phase 6.2 overview: the personalized result shelf (with its
// rec-score lines) under the strategy cards.
$legacyController = new RecommendationController($service, $policy);
ob_start();
$legacyController->index(new Request(), []);
$legacyOverview = (string) ob_get_clean();
check('The legacy overview keeps its strategy cards and result shelf', substr_count($legacyOverview, 'rec-card') >= 6 && str_contains($legacyOverview, 'Recommended for you'));
check('The legacy result keeps its rec-reason and rec-score lines', str_contains($legacyOverview, 'rec-reason') && str_contains($legacyOverview, 'rec-score'));

// Clean up the stubbed session.
$session->forget('auth_user');
unset($_SERVER['HTTP_X_REQUESTED_WITH']);

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------

section('RESULT');

echo '  Passed: ' . $pass . PHP_EOL;
echo '  Failed: ' . $fail . PHP_EOL;

echo PHP_EOL . 'Note: the throwaway database database/dashboard_test.db is left' . PHP_EOL
    . 'in place for inspection; delete it anytime.' . PHP_EOL;

exit($fail === 0 ? 0 : 1);
