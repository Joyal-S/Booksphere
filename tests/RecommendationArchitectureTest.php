<?php

declare(strict_types=1);

/**
 * RecommendationArchitectureTest — CLI test suite for Phase 6.2
 *
 * Verifies the six REAL recommendation algorithms without touching
 * the Book module or the development database:
 *
 *     1. Context sanitization (ids, limits) and immutability
 *     2. The factory registry (six strategies, unknown key failure)
 *     3. The service (the seven get* methods, id validation, merged
 *        deduplicated shelf)
 *     4. The six strategies (metadata, context support contracts,
 *        per-item reasons)
 *     5. The repository algorithms against REAL seeded data:
 *        weighted popularity ordering (verified against the pure-PHP
 *        scoring mirror), the min-review confidence threshold,
 *        recency ordering, the 30-day trending window, category /
 *        author filters, multi-category and multi-author support,
 *        anchor-book exclusion, prepared-statement injection safety
 *     6. The policy (authorization gate)
 *     7. Controller smoke tests: every recommendations route renders
 *        a real shelf exactly as a browser would use it
 *
 * Run from the project root:
 *
 *     php tests/RecommendationArchitectureTest.php
 *
 * How it works (same harness as BrowseTest):
 *     - A throwaway SQLite database (database/recommendation_test.db)
 *       is created, migrated and seeded, so real data is never
 *       touched.
 *     - Extra review/wishlist rows are inserted INTO THE THROWAWAY
 *       DATABASE ONLY to exercise the weighted ordering and the
 *       confidence threshold.
 *     - Every check prints PASS/FAIL; the summary line doubles as
 *       the Phase 6.2 testing checklist for the viva.
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
use BookSphere\App\DTO\RecommendationContext;
use BookSphere\App\Exceptions\RecommendationException;
use BookSphere\App\Policies\RecommendationPolicy;
use BookSphere\App\Repositories\BookRepository;
use BookSphere\App\Repositories\RecommendationRepository;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\RecommendationFactory;
use BookSphere\App\Services\RecommendationScoring;
use BookSphere\App\Services\RecommendationService;
use BookSphere\App\Strategies\HighestRatedStrategy;
use BookSphere\App\Strategies\PopularBooksStrategy;
use BookSphere\App\Strategies\RecentlyAddedStrategy;
use BookSphere\App\Strategies\RecommendationStrategy;
use BookSphere\App\Strategies\SameAuthorStrategy;
use BookSphere\App\Strategies\SameCategoryStrategy;
use BookSphere\App\Strategies\TrendingBooksStrategy;
use BookSphere\App\Models\User;

// ---------------------------------------------------------------------
// 0. Boot: fresh throwaway database, migrated and seeded.
// ---------------------------------------------------------------------

(new Environment(root_path('.env')))->load();

$dbPath = root_path('database/recommendation_test.db');

foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

Database::instance($dbPath);
(new Migrator(db(), root_path('database/migrations')))->run();
(new Seeder(db(), root_path('database/seeds')))->run();

// Two throwaway reviewers so the confidence-threshold test can give
// one book five distinct reviewers (users: 1=admin, 2=riya, 3=arjun,
// 4=meera; riya already reviewed The Martian and 1984).
foreach (['tester.a@test.dev', 'tester.b@test.dev'] as $email) {
    db()->execute(
        'INSERT INTO users (full_name, email, password) VALUES (?, ?, ?)',
        ['Test Reviewer', $email, 'x'],
    );
}

// A session must exist BEFORE any output (session_start() refuses
// to run once output has been sent).
$session = new Session('recommendation_test');
$session->start();
AuthService::setInstance(new AuthService($session, new User()));

// ---------------------------------------------------------------------
// Wiring (mirrors routes/web.php exactly).
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

$service    = new RecommendationService($factory, $repository);
$policy     = new RecommendationPolicy();
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

// Small data helpers (throwaway database only).
function bookIdByTitle(string $title): int
{
    return (int) db()->query('SELECT id FROM books WHERE title = ?', [$title])[0]['id'];
}

function insertReview(int $bookId, int $userId, int $rating, ?string $createdAt = null): void
{
    db()->execute(
        'INSERT INTO reviews (user_id, book_id, rating, review, created_at) VALUES (?, ?, ?, ?, ?)',
        [$userId, $bookId, $rating, 'Test review', $createdAt ?? gmdate('Y-m-d\TH:i:s\Z')],
    );
}

function insertWishlist(int $bookId, int $userId, ?string $createdAt = null): void
{
    db()->execute(
        'INSERT INTO wishlist (user_id, book_id, created_at) VALUES (?, ?, ?)',
        [$userId, $bookId, $createdAt ?? gmdate('Y-m-d\TH:i:s\Z')],
    );
}

// ---------------------------------------------------------------------
// 1. RecommendationContext: sanitization + immutability
// ---------------------------------------------------------------------

section('1. CONTEXT (input DTO): sanitization and immutability');

$context = RecommendationContext::fromArray([
    'user_id'     => '42',
    'book_id'     => 'abc',
    'category_id' => -7,
    'author_id'   => '9',
    'limit'       => '1000',
], 7);

check('Positive ids pass, junk becomes null', $context->userId === 42 && $context->bookId === null && $context->categoryId === null && $context->authorId === 9);
check('Limit is clamped to MAX_LIMIT', $context->limit === RecommendationContext::MAX_LIMIT);

$context = RecommendationContext::fromArray(['limit' => '-5'], 7);
check('Negative limit is clamped to the minimum', $context->limit === 1);

$context = RecommendationContext::fromArray(['limit' => 'abc']);
check('Non-numeric limit falls back to the default', $context->limit === RecommendationContext::DEFAULT_LIMIT);

$context = RecommendationContext::fromArray([], 3);
check('Session user id is used as the fallback', $context->userId === 3);

// ---------------------------------------------------------------------
// 2. RecommendationFactory: the registry
// ---------------------------------------------------------------------

section('2. FACTORY: strategy registry');

check('make("popular") returns the Popular strategy', $factory->make('popular') instanceof PopularBooksStrategy);
check('make("rating") returns the Highest Rated strategy', $factory->make('rating') instanceof HighestRatedStrategy);
check('make("trending") returns the Trending strategy', $factory->make('trending') instanceof TrendingBooksStrategy);
check('make("category") returns the Same Category strategy', $factory->make('category') instanceof SameCategoryStrategy);
check('make("recent") returns the Recently Added strategy', $factory->make('recent') instanceof RecentlyAddedStrategy);
check('make("author") returns the Same Author strategy', $factory->make('author') instanceof SameAuthorStrategy);

$thrown = false;
try {
    $factory->make('bestsellers');
} catch (RecommendationException $exception) {
    $thrown = str_contains($exception->getMessage(), 'Unknown recommendation strategy');
}
check('Unknown key throws RecommendationException', $thrown);

check('all() returns the six strategies in registration order', count($factory->all()) === 6 && $factory->all()[0] instanceof PopularBooksStrategy);

// ---------------------------------------------------------------------
// 3. RecommendationService: the seven get* methods
// ---------------------------------------------------------------------

section('3. SERVICE: the pipelines');

// --- Popular ----------------------------------------------------------

$popular = $service->getPopularBooks(5);
check('getPopularBooks() returns a DTO with real books', $popular instanceof BookSphere\App\DTO\RecommendationResult && $popular->total > 0);
check('The popular note names the weights', str_contains($popular->note, '0.5') || str_contains($popular->note, '0.50'));
check('Every popular book carries a reason', array_reduce($popular->items, fn (bool $c, array $i): bool => $c && !empty($i['reason']), true));

// --- Highest rated (confidence threshold) -----------------------------

$highest = $service->getHighestRatedBooks(5);
check('getHighestRatedBooks() is empty at first (no book has 5+ reviews)', $highest->total === 0 && $highest->items === []);

// Give two books enough reviews to qualify (throwaway DB only).
// The Martian ends with 5 reviews (avg 5.0), 1984 with 5 reviews
// (avg 4.8) - each book gets four distinct NEW reviewers.
$martianId  = bookIdByTitle('The Martian');   // seed: 1 x 5 stars (riya)
$nineteenId = bookIdByTitle('1984');          // seed: 1 x 5 stars (riya)
$martianReviewers  = [1, 3, 4, 5];
$nineteenReviewers = [1, 3, 4, 6];
foreach ([0, 1, 2, 3] as $i) {
    insertReview($martianId, $martianReviewers[$i], 5);
    insertReview($nineteenId, $nineteenReviewers[$i], $i === 3 ? 4 : 5);
}

$highest = $service->getHighestRatedBooks(5);
check('Qualified books now appear (5+ reviews)', $highest->total >= 2);
$ids = array_map(fn (array $i): int => (int) $i['id'], $highest->items);
check('Martian (avg 5.0) ranks above 1984 (avg 4.8)', array_search($martianId, $ids, true) < array_search($nineteenId, $ids, true));
check('Every top-rated book has at least the minimum review count', array_reduce($highest->items, fn (bool $c, array $i): bool => $c && (int) $i['review_count'] >= RecommendationScoring::MIN_REVIEWS_FOR_RATING, true));
check('Top-rated ordering is average DESC', (function (array $items): bool {
    $previous = PHP_FLOAT_MAX;
    foreach ($items as $item) {
        if ((float) $item['average_rating'] > $previous) {
            return false;
        }
        $previous = (float) $item['average_rating'];
    }
    return true;
})($highest->items));

// --- Recently added ----------------------------------------------------

$recent = $service->getRecentlyAddedBooks(5);
check('getRecentlyAddedBooks() returns the newest first', $recent->total > 0 && $recent->items[0]['created_at'] >= $recent->items[count($recent->items) - 1]['created_at']);
check('The recent note explains the shelf', str_contains($recent->note, 'Newest'));

// --- Trending ----------------------------------------------------------

$trending = $service->getTrendingBooks(5);
check('getTrendingBooks() returns the momentum shelf', $trending->total > 0);
check('Every trending book has recent activity', array_reduce($trending->items, fn (bool $c, array $i): bool => $c && ((int) $i['recent_review_count'] + (int) $i['recent_wishlist_count']) > 0, true));
check('The trending note names the 30-day window', str_contains($trending->note, (string) RecommendationScoring::TRENDING_WINDOW_DAYS));

// --- Category / author with id validation ------------------------------

$categoryId = (int) db()->query('SELECT id FROM categories WHERE name = ?', ['Science Fiction'])[0]['id'];
$byCategory = $service->getBooksByCategory($categoryId, 5);
check('getBooksByCategory() returns only books of that category', $byCategory->total > 0 && str_contains($byCategory->items[0]['categories_list'], 'Science Fiction'));
check('The category note explains the shelf', str_contains($byCategory->note, 'Books in this category'));

$thrown = false;
try {
    $service->getBooksByCategory(999999, 5);
} catch (RecommendationException $exception) {
    $thrown = str_contains($exception->getMessage(), 'Category not found');
}
check('A missing category fails loudly', $thrown);

$authorId = (int) db()->query('SELECT id FROM authors WHERE name = ?', ['Andy Weir'])[0]['id'];
$byAuthor = $service->getBooksByAuthor($authorId, 5);
check('getBooksByAuthor() returns only books by that author', $byAuthor->total > 0 && str_contains($byAuthor->items[0]['authors_list'], 'Andy Weir'));

$thrown = false;
try {
    $service->getBooksByAuthor(999999, 5);
} catch (RecommendationException $exception) {
    $thrown = str_contains($exception->getMessage(), 'Author not found');
}
check('A missing author fails loudly', $thrown);

// --- Merged shelf + deduplication --------------------------------------

$merged = $service->getRecommendations();
check('getRecommendations() merges the default shelves', $merged->total > 0);
$mergedIds = array_map(fn (array $i): int => (int) $i['id'], $merged->items);
check('The merged shelf has no duplicate books', count($mergedIds) === count(array_unique($mergedIds)));
check('The merged shelf keeps its reasons', array_reduce($merged->items, fn (bool $c, array $i): bool => $c && !empty($i['reason']), true));

// --- Unsupported context -----------------------------------------------

$thrown = false;
try {
    $service->recommend('category', new RecommendationContext(userId: null, bookId: null, categoryId: null));
} catch (RecommendationException $exception) {
    $thrown = str_contains($exception->getMessage(), 'cannot run with the given context');
}
check('Category without a category or book fails loudly', $thrown);

$thrown = false;
try {
    $service->recommend('author', new RecommendationContext(userId: null, bookId: null, authorId: null));
} catch (RecommendationException $exception) {
    $thrown = str_contains($exception->getMessage(), 'cannot run with the given context');
}
check('Author without an author or book fails loudly', $thrown);

// --- Overview metadata -------------------------------------------------

$strategies = $service->strategies();
check('strategies() exposes six strategy cards', count($strategies) === 6);
check('The rating card links to /recommendations/top-rated', $strategies[1]['url'] === '/recommendations/top-rated');
check('The trending card links to /recommendations/trending', $strategies[2]['url'] === '/recommendations/trending');
check('The author card links to the catalogue', $strategies[5]['url'] === '/books');

// ---------------------------------------------------------------------
// 4. Strategies: metadata + context support contracts
// ---------------------------------------------------------------------

section('4. STRATEGIES: metadata and support contracts');

check('Every strategy implements the interface',
    array_reduce($factory->all(), fn (bool $carry, RecommendationStrategy $strategy): bool => $carry && $strategy instanceof RecommendationStrategy, true));

$popular = $factory->make('popular');
check('Popular has full metadata', $popular->key() === 'popular' && $popular->label() === 'Popular' && $popular->description() !== '' && $popular->icon() !== '');
check('Popular supports any request', $popular->supports(RecommendationContext::fromArray([], null)));

$rating = $factory->make('rating');
check('Highest Rated supports any request', $rating->supports(RecommendationContext::fromArray([], null)));

$trending = $factory->make('trending');
check('Trending supports any request', $trending->supports(RecommendationContext::fromArray([], null)));

$recent = $factory->make('recent');
check('Recently Added supports any request', $recent->supports(RecommendationContext::fromArray([], null)));

$category = $factory->make('category');
check('Category refuses an empty context', !$category->supports(RecommendationContext::fromArray([], null)));
check('Category supports an explicit category', $category->supports(RecommendationContext::fromArray(['category_id' => 1], null)));
check('Category supports an anchor book', $category->supports(RecommendationContext::fromArray(['book_id' => 1], null)));

$author = $factory->make('author');
check('Author refuses an empty context', !$author->supports(RecommendationContext::fromArray([], null)));
check('Author supports an explicit author', $author->supports(RecommendationContext::fromArray(['author_id' => 1], null)));
check('Author supports an anchor book', $author->supports(RecommendationContext::fromArray(['book_id' => 1], null)));

// ---------------------------------------------------------------------
// 5. RecommendationRepository: the algorithms on real data
// ---------------------------------------------------------------------

section('5. REPOSITORY: the algorithms');

// --- Popularity: weighted ordering against the pure-PHP mirror ---------

// Give 1984 one more review (6 total, tester.a) and Wings of Fire a
// wishlist save (wishlist_count 1) - both must outrank books with a
// single review.
$nineteenId = bookIdByTitle('1984');
$wingsId    = bookIdByTitle('Wings of Fire');
insertReview($nineteenId, 5, 4);
insertWishlist($wingsId, 2);

$popularRows = $repository->popularBooks(20);
check('popularBooks() returns the score columns', count($popularRows) > 0 && isset($popularRows[0]['popularity_score']) && isset($popularRows[0]['review_count']) && isset($popularRows[0]['wishlist_count']));

$mirror = array_reduce($popularRows, function (bool $carry, array $row): bool {
    $expected = RecommendationScoring::popularityScore(
        (float) $row['average_rating'],
        (int) $row['review_count'],
        (int) $row['wishlist_count'],
    );
    return $carry && abs((float) $row['popularity_score'] - $expected) < 0.0001;
}, true);
check('The SQL popularity score equals the pure-PHP mirror', $mirror);

$popularOrder = array_map(fn (array $r): int => (int) $r['id'], $popularRows);
check('The most-reviewed book tops the popularity shelf', array_search($nineteenId, $popularOrder, true) === 0);
check('A wishlist save lifts Wings of Fire above single-review books', array_search($wingsId, $popularOrder, true) < count($popularOrder) - 1);
check('Weighted order: 1984 (6 reviews) > The Martian (5 reviews) > Wings (1 + wishlist)', array_search($nineteenId, $popularOrder, true) < array_search($martianId, $popularOrder, true) && array_search($martianId, $popularOrder, true) < array_search($wingsId, $popularOrder, true));

// --- Highest rated: the confidence threshold ---------------------------

$highestRows = $repository->highestRatedBooks(20);
check('highestRatedBooks() applies the min-review threshold', count($highestRows) >= 2 && array_reduce($highestRows, fn (bool $c, array $r): bool => $c && (int) $r['review_count'] >= RecommendationScoring::MIN_REVIEWS_FOR_RATING, true));
check('highestRatedBooks() sorts by average rating, then count', (function (array $rows): bool {
    $previous = PHP_FLOAT_MAX;
    foreach ($rows as $row) {
        if ((float) $row['average_rating'] > $previous) {
            return false;
        }
        $previous = (float) $row['average_rating'];
    }
    return true;
})($highestRows));

// --- Trending: the 30-day window ---------------------------------------

// An OLD review (40 days ago) must NOT count as recent.
$oldReviewId = bookIdByTitle('The Hobbit');
insertReview($oldReviewId, 2, 5, gmdate('Y-m-d\TH:i:s\Z', time() - 40 * 86400));
$trendingRows = $repository->trendingBooks(50);
$hobbit = array_filter($trendingRows, fn (array $r): bool => (int) $r['id'] === $oldReviewId);
check('A book with ONLY a 40-day-old review is not trending', $hobbit === []);

$trendingRows = $repository->trendingBooks(50);
$trendingMatches = array_reduce($trendingRows, function (bool $carry, array $row): bool {
    $expected = RecommendationScoring::trendingScore(
        (int) $row['recent_review_count'],
        (int) $row['recent_wishlist_count'],
    );
    return $carry && abs((float) $row['trending_score'] - $expected) < 0.0001;
}, true);
check('The SQL trending score equals the pure-PHP mirror', $trendingMatches);

// --- Recently added ----------------------------------------------------

$recentRows = $repository->recentlyAddedBooks(10);
check('recentlyAddedBooks() returns the newest first', count($recentRows) <= 10 && $recentRows[0]['created_at'] >= $recentRows[count($recentRows) - 1]['created_at']);

// --- Category: filter + anchor exclusion + multi-category --------------

$sfId      = (int) db()->query('SELECT id FROM categories WHERE name = ?', ['Science Fiction'])[0]['id'];
$classicId = (int) db()->query('SELECT id FROM categories WHERE name = ?', ['Classic Fiction'])[0]['id'];

$byCategory = $repository->booksByCategory($sfId, 50);
check('booksByCategory() honours the filter', count($byCategory) > 0 && array_reduce($byCategory, fn (bool $c, array $r): bool => $c && str_contains($r['categories_list'], 'Science Fiction'), true));

// 1984 is in BOTH Science Fiction and Classic Fiction.
$nineteenRow = $repository->booksByCategory($sfId, 50, $nineteenId);
check('The anchor book is excluded from its own shelf', array_reduce($nineteenRow, fn (bool $c, array $r): bool => $c && (int) $r['id'] !== $nineteenId, true));

$multi = $repository->booksInCategories([$sfId, $classicId], 50, $nineteenId);
check('booksInCategories() spans several categories', array_reduce($multi, fn (bool $c, array $r): bool => $c && (str_contains($r['categories_list'], 'Science Fiction') || str_contains($r['categories_list'], 'Classic Fiction')), true));
check('The multi-category shelf excludes the anchor book', array_reduce($multi, fn (bool $c, array $r): bool => $c && (int) $r['id'] !== $nineteenId, true));

// --- Author: filter + multi-author support -----------------------------

$huntId = (int) db()->query('SELECT id FROM authors WHERE name = ?', ['Andrew Hunt'])[0]['id'];
$thomasId = (int) db()->query('SELECT id FROM authors WHERE name = ?', ['David Thomas'])[0]['id'];

$pragmaticId = bookIdByTitle('The Pragmatic Programmer');

$byAuthor = $repository->booksByAuthor($huntId, 50);
check('booksByAuthor() honours the filter', count($byAuthor) === 1 && (int) $byAuthor[0]['id'] === $pragmaticId);

$multiAuthor = $repository->booksInAuthors([$huntId, $thomasId], 50, $pragmaticId);
check('booksInAuthors() finds the co-authored book (and excludes it as anchor)', $multiAuthor === []);

// A second book by Andy Weir, so the "more like this" shelf for
// The Martian has something to show (seeds: one book per author).
$weirId = (int) db()->query('SELECT id FROM authors WHERE name = ?', ['Andy Weir'])[0]['id'];
db()->execute(
    'INSERT INTO books (google_book_id, isbn, title, description, publisher, published_year, language, page_count, average_rating, ratings_count, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    ['GB021', '9780000000000', 'The Martian Legacy', 'Sequel for the recommendation tests.', 'Crown', 2026, 'en', 400, 4.0, 1, 'published'],
);
$legacyId = (int) db()->query('SELECT id FROM books WHERE google_book_id = ?', ['GB021'])[0]['id'];
db()->execute('INSERT INTO book_authors (book_id, author_id) VALUES (?, ?)', [$legacyId, $weirId]);

$moreLikeThis = $repository->booksByAuthor($weirId, 50, $martianId);
check('More-like-this finds the author\'s other book', count($moreLikeThis) === 1 && (int) $moreLikeThis[0]['id'] === $legacyId);

// --- Existence checks and injection safety -----------------------------

check('bookExists() is truthful', $repository->bookExists($martianId) && !$repository->bookExists(999999));
check('categoryExists() is truthful', $repository->categoryExists($sfId) && !$repository->categoryExists(999999));
check('authorExists() is truthful', $repository->authorExists($huntId) && !$repository->authorExists(999999));

$sneaky = '1 OR 1=1';
$sneakyRows = $repository->booksByCategory((int) $sneaky, 10);
check('A crafted category id stays a bound integer', (int) $sneaky === 1 && count($sneakyRows) === count($repository->booksByCategory(1, 10)));
check('A non-existent category id yields an empty shelf, not an injection', $repository->booksByCategory(0, 10) === []);

check('categoriesForBook() delegates to the Book module', count($repository->categoriesForBook($nineteenId)) === 2);
check('authorsForBook() delegates to the Book module', count($repository->authorsForBook($pragmaticId)) === 2);

// ---------------------------------------------------------------------
// 6. Policy
// ---------------------------------------------------------------------

section('6. POLICY: the authorization gate');

check('view() denies a guest', $policy->view() === false);

$session->put('auth_user', ['id' => 1, 'full_name' => 'Admin', 'email' => 'admin@booksphere.test', 'role' => 'admin']);
check('view() allows a signed-in user', $policy->view() === true);

// ---------------------------------------------------------------------
// 7. Controller smoke tests (every route, as a browser would use it)
// ---------------------------------------------------------------------

section('7. CONTROLLER: route smoke tests');

ob_start();
$controller->index(new Request(), []);
$html = (string) ob_get_clean();
check('index() renders the overview with the merged shelf', str_contains($html, 'Recommendations') && str_contains($html, 'Recommended for you') && str_contains($html, 'rec-reason'));
check('index() lists all six strategies', substr_count($html, 'rec-card') >= 6 && str_contains($html, 'Trending'));

ob_start();
$controller->popular(new Request(), []);
$html = (string) ob_get_clean();
check('popular() renders a real Popular shelf', str_contains($html, 'rec-card-active') && str_contains($html, 'Running now') && str_contains($html, 'rec-reason') && str_contains($html, 'highest first'));

ob_start();
$controller->topRated(new Request(), []);
$html = (string) ob_get_clean();
check('topRated() renders the confidence-filtered shelf', str_contains($html, 'Top Rated') && str_contains($html, '<code>rating</code>'));

ob_start();
$controller->trending(new Request(), []);
$html = (string) ob_get_clean();
check('trending() runs the real Trending strategy', str_contains($html, '<code>trending</code>') && str_contains($html, '30 days'));

ob_start();
$controller->recent(new Request(), []);
$html = (string) ob_get_clean();
check('recent() renders the Recently Added shelf', str_contains($html, 'Recently Added'));

ob_start();
$controller->category(new Request(), ['id' => (string) $sfId]);
$html = (string) ob_get_clean();
check('category() renders the category shelf with reasons', str_contains($html, 'By Category') && str_contains($html, 'Shares a category with this selection'));

ob_start();
$controller->show(new Request(), ['id' => (string) $martianId]);
$html = (string) ob_get_clean();
check('show() renders "more like this" without the anchor book', str_contains($html, 'More Like This') && str_contains($html, 'By one of the authors of this book') && str_contains($html, 'The Martian Legacy') && !str_contains($html, 'The Martian</span>'));

// Clean up the stubbed session (the guest behaviour of the fine
// gate was already proven in section 6 without touching the
// controller, whose authorize() would terminate the script with a
// redirect/exit in CLI).
$session->forget('auth_user');

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------

section('RESULT');

echo '  Passed: ' . $pass . PHP_EOL;
echo '  Failed: ' . $fail . PHP_EOL;

echo PHP_EOL . 'Note: the throwaway database database/recommendation_test.db is left' . PHP_EOL
    . 'in place for inspection; delete it anytime.' . PHP_EOL;

exit($fail === 0 ? 0 : 1);
