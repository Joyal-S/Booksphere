<?php

declare(strict_types=1);

/**
 * RecommendationLibraryIntegrationTest — CLI test suite for Phase 8.5
 * (the Personal Library inside the Recommendation Engine)
 *
 * Verifies the complete Phase 8.5 stack end to end: the
 * recommendation_logs schema (migration 0019), the config-driven
 * weights (RecommendationConfig), the scoring mirrors
 * (RecommendationScoring), the library/log SQL (RecommendationRepository),
 * the five public service surfaces (libraryRecommendations /
 * bookRecommendations / libraryPageRecommendations /
 * profileRecommendationInsights / logRecommendations), the per-section
 * PersonalizationCache files, the explainable reasons, the exclusions
 * and the live renders of the four wired pages. Same
 * throwaway-database harness as every other suite:
 *
 *     1. Config       - RecommendationConfig reads the library block
 *                       (weights, section limits, retention, hidden
 *                       gems, accuracy window, similarity bands)
 *     2. Scoring      - the libraryScore / ratingQuality /
 *                       collaborativeScore mirrors on 0-100
 *     3. Schema       - the recommendation_logs table (columns,
 *                       indexes, FK cascade)
 *     4. Repository   - the library reads (ids, top categories /
 *                       authors, collaborative, gems, similarity)
 *                       and the log reads (insert / annotate / prune)
 *     5. Service      - libraryRecommendations: sections, guest
 *                       behaviour, the unknown-section exception, the
 *                       library/wishlist exclusion, the sorted
 *                       explainable items and the signal logging
 *     6. Book page    - bookRecommendations: the six sections, the
 *                       anchor exclusion, dedupe, the guest snapshot
 *                       and the bookNotFound exception
 *     7. Library page - libraryPageRecommendations: the five
 *                       sections, the own-library exclusion, the
 *                       collaborative + named-category shelves and the
 *                       skip of the re-logged signal
 *     8. Profile      - profileRecommendationInsights: categories,
 *                       authors, the Recommendation Accuracy math and
 *                       the influencing books
 *     9. Logging      - logRecommendations: no-op for guests/empty,
 *                       one row per served book, retention pruning
 *    10. Cache        - the per-section files, the cache-hit result,
 *                       invalidate() / flush() dropping them
 *    11. Views        - dashboard / book / library / profile render
 *                       the real Phase 8.5 shelves and blocks
 *
 * Run from the project root:
 *
 *     php tests/RecommendationLibraryIntegrationTest.php
 *
 * The throwaway database (database/library_recommendation_test.db) is
 * migrated, seeded and left in place for inspection; delete it anytime.
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Environment;
use BookSphere\App\Core\Database;
use BookSphere\App\Core\Logger;
use BookSphere\App\Core\Migrator;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Seeder;
use BookSphere\App\Core\Session;
use BookSphere\App\DTO\LibraryItemDTO;
use BookSphere\App\Exceptions\RecommendationException;
use BookSphere\App\Models\Author;
use BookSphere\App\Models\Book;
use BookSphere\App\Models\Category;
use BookSphere\App\Models\Review;
use BookSphere\App\Models\User;
use BookSphere\App\Models\UserLibrary;
use BookSphere\App\Policies\LibraryPolicy;
use BookSphere\App\Repositories\BookRepository;
use BookSphere\App\Repositories\LibraryRepository;
use BookSphere\App\Repositories\RecommendationRepository;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\BookService;
use BookSphere\App\Services\LibraryService;
use BookSphere\App\Services\PersonalizationCache;
use BookSphere\App\Services\RecommendationConfig;
use BookSphere\App\Services\RecommendationFactory;
use BookSphere\App\Services\RecommendationScoring;
use BookSphere\App\Services\RecommendationService;
use BookSphere\App\Services\ReviewService;
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

$dbPath = root_path('database/library_recommendation_test.db');

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
$session = new Session('library_recommendation_test');
$session->start();
$auth = new AuthService($session, new User());
AuthService::setInstance($auth);

$logFile = sys_get_temp_dir() . '/booksphere_library_recommendation_test.log';
if (is_file($logFile)) {
    unlink($logFile);
}

$cacheDir = sys_get_temp_dir() . '/booksphere_library_recommendation_cache';
if (is_dir($cacheDir)) {
    array_map('unlink', glob($cacheDir . '/*') ?: []);
    rmdir($cacheDir);
}

// ---------------------------------------------------------------------
// Shared services (identical wiring to routes/web.php).
// ---------------------------------------------------------------------

$users     = new User();
$admin     = $users->findByEmail('admin@booksphere.test');
$riya      = $users->findByEmail('riya@booksphere.test');
$riyaId    = (int) $riya['id'];
$adminId   = (int) $admin['id'];

$bookId = fn (string $title): int => (int) db()->query('SELECT id FROM books WHERE title = ?', [$title])[0]['id'];
$b1984    = $bookId('1984');
$bHobbit  = $bookId('The Hobbit');
$bHabits  = $bookId('Atomic Habits');
$bMartian = $bookId('The Martian');
$bDeepWork = $bookId('Deep Work');
$bMockingbird = $bookId('To Kill a Mockingbird');
$bPandP   = $bookId('Pride and Prejudice');
$bHunger  = $bookId('The Hunger Games');
$bGone    = $bookId('Gone Girl');

// A "hidden gem": a high-rated book with very few reviews. The seed
// catalogue's ratings_count values are all in the hundreds, so this
// is the ONLY book the config's hidden-gems filter can ever match.
db()->execute(
    'INSERT INTO books
        (google_book_id, isbn, title, description, publisher,
         published_year, language, page_count, cover_image,
         average_rating, ratings_count)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    ['GBTESTGEM', '9780000000001', 'The Quiet Gem', 'A masterpiece almost nobody has reviewed yet.', 'Test Press', 2024, 'en', 220, null, 4.6, 3],
);
$bGem = $bookId('The Quiet Gem');

// A second science-fiction title by Riya's top author (Andy Weir):
// she keeps The Martian but NOT this book, so the library page's
// "favourite category" / "favourite author" shelves have something
// real to serve after excluding her own library.
db()->execute(
    'INSERT INTO books
        (google_book_id, isbn, title, description, publisher,
         published_year, language, page_count, cover_image,
         average_rating, ratings_count)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    ['GBTESTPMH', '9780000000002', 'Project Hail Mary', 'A lone astronaut races to save Earth with science and wit.', 'Ballantine', 2021, 'en', 496, null, 4.3, 1000],
);
$bPmh = $bookId('Project Hail Mary');
$weirId = (int) db()->query('SELECT id FROM authors WHERE name = ?', ['Andy Weir'])[0]['id'];
$scifiId = (int) db()->query('SELECT id FROM categories WHERE name = ?', ['Science Fiction'])[0]['id'];
db()->execute('INSERT INTO book_authors (book_id, author_id) VALUES (?, ?)', [$bPmh, $weirId]);
db()->execute('INSERT INTO book_categories (book_id, category_id) VALUES (?, ?)', [$bPmh, $scifiId]);

$libraryModel      = new UserLibrary();
$libraryRepository = new LibraryRepository();
$libraryService    = new LibraryService($libraryModel, new Book(), null, new Logger($logFile));

// Riya's library: the Science-Fiction heavy reading profile that
// drives every deterministic assertion below.
$libraryService->addBook(LibraryItemDTO::fromArray(['book_id' => $b1984, 'status' => 'finished', 'favorite' => '1'], $riyaId));
$libraryService->addBook(LibraryItemDTO::fromArray(['book_id' => $bHobbit, 'status' => 'finished'], $riyaId));
$libraryService->addBook(LibraryItemDTO::fromArray(['book_id' => $bHabits, 'status' => 'want_to_read'], $riyaId));
$libraryService->addBook(LibraryItemDTO::fromArray(['book_id' => $bMartian, 'status' => 'currently_reading'], $riyaId));
$libraryService->addBook(LibraryItemDTO::fromArray(['book_id' => $bDeepWork, 'status' => 'want_to_read'], $riyaId));
$libraryService->addBook(LibraryItemDTO::fromArray(['book_id' => $bPandP, 'status' => 'want_to_read', 'favorite' => '1'], $riyaId));
$libraryService->addBook(LibraryItemDTO::fromArray(['book_id' => $bHunger, 'status' => 'want_to_read'], $riyaId));

// Arjun's library: shares 1984 and The Hunger Games with Riya, and
// adds Gone Girl - the neighbourhood a collaborative shelf needs.
$arjun = $users->findByEmail('arjun@booksphere.test');
$arjunId = (int) $arjun['id'];
$libraryService->addBook(LibraryItemDTO::fromArray(['book_id' => $b1984, 'status' => 'finished'], $arjunId));
$libraryService->addBook(LibraryItemDTO::fromArray(['book_id' => $bGone, 'status' => 'want_to_read'], $arjunId));
$libraryService->addBook(LibraryItemDTO::fromArray(['book_id' => $bHunger, 'status' => 'want_to_read'], $arjunId));

$repository = new RecommendationRepository(new BookRepository());
$factory = new RecommendationFactory(
    new PopularBooksStrategy($repository),
    new HighestRatedStrategy($repository),
    new TrendingBooksStrategy($repository),
    new SameCategoryStrategy($repository),
    new RecentlyAddedStrategy($repository),
    new SameAuthorStrategy($repository),
);

// The logic-testing service has NO cache, so every call is a fresh
// pipeline run. The cache is exercised separately through its own
// service instance (section 10).
$service = new RecommendationService($factory, $repository);
$cache   = new PersonalizationCache($cacheDir, 1800);
$cachedService = new RecommendationService($factory, $repository, $cache, new Logger($logFile));

$reviewService = new ReviewService(new Review(), new Book(), $service);
$bookService   = new BookService(new Book(), new Author(), new Category());

// ---------------------------------------------------------------------
// Test harness
// ---------------------------------------------------------------------

$section = fn (string $title): string => "\n------------------------------------------------------------------------\n{$title}\n------------------------------------------------------------------------";
$check   = function (string $label, bool $ok): void {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . PHP_EOL;
    $GLOBALS['failures'] = ($GLOBALS['failures'] ?? 0) + ($ok ? 0 : 1);
    $GLOBALS['checks']   = ($GLOBALS['checks'] ?? 0) + 1;
};
$throws  = function (string $expected, callable $fn): bool {
    try {
        $fn();
    } catch (Throwable $exception) {
        return $exception instanceof $expected;
    }

    return false;
};
$failures = 0;
$checks   = 0;

// ---------------------------------------------------------------------
// 1. CONFIG (RecommendationConfig reads the library block)
// ---------------------------------------------------------------------

echo $section('1. CONFIG: the library block');

$weights = RecommendationConfig::libraryWeights();
$check('libraryWeights() reads the config block', $weights === (array) config('recommendations.library.weights', []));
$check('The weights sum to 100', (int) array_sum($weights) === 100);
$check('Favourite category dominates the signal', $weights['favourite_category'] > $weights['reading_history']);
$check('The author weight is the second largest', $weights['favourite_author'] < $weights['favourite_category'] && $weights['favourite_author'] > $weights['reading_history']);

$check('sectionLimit() reads the dashboard limit', RecommendationConfig::sectionLimit('dashboard') === 6);
$check('sectionLimit() reads the profile limit', RecommendationConfig::sectionLimit('profile') === 5);
$check('sectionLimit() falls back for an unknown surface', RecommendationConfig::sectionLimit('swamp') === RecommendationConfig::SECTION_LIMIT_DEFAULT);
$check('sectionLimit() stays inside 1-50', RecommendationConfig::sectionLimit('dashboard', 999) <= 50);

$check('logRetention() reads the configured retention', RecommendationConfig::logRetention() === 200);
$check('logRetention() stays inside 1-10000', RecommendationConfig::logRetention(0) >= 1 && RecommendationConfig::logRetention(99999) <= 10000);

$gems = RecommendationConfig::hiddenGems();
$check('hiddenGems() reads the configured filter', (float) $gems['min_rating'] === 4.0 && (int) $gems['max_reviews'] === 8);
$check('accuracyWindowDays() reads the configured window', RecommendationConfig::accuracyWindowDays() === 30);

$similarity = RecommendationConfig::similarity();
$check('similarity() reads the rating band', (float) $similarity['rating_band'] === 0.5);
$check('similarity() reads the popularity factor', (float) $similarity['popularity_factor'] === 0.5);
$check('similarity() reads the discovery window', (int) $similarity['discovery_window_days'] === 30);

// ---------------------------------------------------------------------
// 2. SCORING (the Phase 8.5 mirrors of RecommendationScoring)
// ---------------------------------------------------------------------

echo $section('2. SCORING: libraryScore / ratingQuality / collaborativeScore');

$check('Scoring::libraryWeights() delegates to the config', RecommendationScoring::libraryWeights() === $weights);

$full = RecommendationScoring::libraryScore([
    'favourite_category' => 2,
    'favourite_author'   => 1,
    'reading_history'    => 3,
    'want_to_read'       => 3,
    'rating'             => 1.0,
    'popularity'         => 3.0,
]);
$check('A full signal scores 100', abs($full - 100) < 0.001);

$partial = RecommendationScoring::libraryScore(['favourite_category' => 1]);
$check('One shared favourite category scores half its weight', abs($partial - ($weights['favourite_category'] / 2)) < 0.001);

$nothing = RecommendationScoring::libraryScore([]);
$check('No signal scores 0', abs($nothing - 0) < 0.001);

$check('The author match is binary', abs(RecommendationScoring::libraryScore(['favourite_author' => 1]) - $weights['favourite_author']) < 0.001);
$check('The author factor caps at one', abs(RecommendationScoring::libraryScore(['favourite_author' => 5]) - $weights['favourite_author']) < 0.001);

$check('ratingQuality() maps 4.4 out of 5', abs(RecommendationScoring::ratingQuality(4.4, 3) - 0.88) < 0.001);
$check('ratingQuality() is zero without reviews', RecommendationScoring::ratingQuality(4.4, 0) === 0.0);
$check('ratingQuality() is zero without a rating', RecommendationScoring::ratingQuality(0.0, 5) === 0.0);

$check('collaborativeScore() is 0 for no neighbours', RecommendationScoring::collaborativeScore(0) === 0);
$check('collaborativeScore() is 50 for five co-saves', RecommendationScoring::collaborativeScore(5) === 50);
$check('collaborativeScore() is 100 at ten co-saves', RecommendationScoring::collaborativeScore(10) === 100);
$check('collaborativeScore() caps at 100', RecommendationScoring::collaborativeScore(40) === 100);

// ---------------------------------------------------------------------
// 3. SCHEMA (migration 0019: the recommendation_logs table)
// ---------------------------------------------------------------------

echo $section('3. SCHEMA: recommendation_logs');

$columns = array_column(db()->query('PRAGMA table_info(recommendation_logs)'), 'name');
foreach (['id', 'user_id', 'book_id', 'reason', 'score', 'signal', 'generated_at'] as $column) {
    $check("The table carries the {$column} column", in_array($column, $columns, true));
}

$indexNames = array_column(db()->query('PRAGMA index_list(recommendation_logs)'), 'name');
$check('The user/generated index exists', in_array('idx_recommendation_logs_user_generated', $indexNames, true));
$check('The book index exists', in_array('idx_recommendation_logs_book', $indexNames, true));

$fks = db()->query('PRAGMA foreign_key_list(recommendation_logs)');
$fkMap = [];
foreach ($fks as $fk) {
    $fkMap[$fk['from']] = $fk;
}
$check('user_id cascades with the user', ($fkMap['user_id']['table'] ?? '') === 'users' && ($fkMap['user_id']['on_delete'] ?? '') === 'CASCADE');
$check('book_id cascades with the book', ($fkMap['book_id']['table'] ?? '') === 'books' && ($fkMap['book_id']['on_delete'] ?? '') === 'CASCADE');
$check('The generated_at column exists', in_array('generated_at', $columns, true));

$defaults = [];
foreach (db()->query('PRAGMA table_info(recommendation_logs)') as $row) {
    $defaults[$row['name']] = (string) $row['dflt_value'];
}
$check('The signal default is an empty string', $defaults['signal'] === "''");
$check('The generated_at default stamps now', str_contains($defaults['generated_at'], 'strftime'));

// ---------------------------------------------------------------------
// 4. REPOSITORY (the library and log reads)
// ---------------------------------------------------------------------

echo $section('4. REPOSITORY: library reads and the log trail');

$riyaLibrary = $repository->libraryBookIds($riyaId, 50);
$check('libraryBookIds() lists all seven library books', count($riyaLibrary) === 7);
$check('libraryBookIds() honours the status filter', count($repository->libraryBookIds($riyaId, 50, 'finished')) === 2);

$favourites = $repository->favouriteBookIds($riyaId, 10);
$check('favouriteBookIds() returns the starred books', count($favourites) === 2 && in_array($b1984, $favourites, true) && in_array($bPandP, $favourites, true));

$finished = $repository->finishedBookIds($riyaId, 10);
$check('finishedBookIds() returns the finished books', count($finished) === 2 && in_array($b1984, $finished, true) && in_array($bHobbit, $finished, true));

$wantToRead = $repository->wantToReadBookIds($riyaId, 10);
$check('wantToReadBookIds() returns the wishlist shelf', count($wantToRead) === 4 && in_array($bHabits, $wantToRead, true) && in_array($bDeepWork, $wantToRead, true) && in_array($bPandP, $wantToRead, true) && in_array($bHunger, $wantToRead, true));

$topCategories = $repository->topLibraryCategories($riyaId, 5);
$check('topLibraryCategories() reports Science Fiction first', ($topCategories[0]['name'] ?? '') === 'Science Fiction' && (int) ($topCategories[0]['kept'] ?? 0) === 3);

$topAuthors = $repository->topLibraryAuthors($riyaId, 5);
$check('topLibraryAuthors() reports the top author', ($topAuthors[0]['name'] ?? '') === 'Andy Weir' && (int) ($topAuthors[0]['kept'] ?? 0) === 1);
$check('The author rows carry the shape', isset($topAuthors[0]['id'], $topAuthors[0]['name'], $topAuthors[0]['kept']));

$coSaved = $repository->coSavedBooks($b1984, 10);
$coSavedIds = array_map(fn (array $row): int => (int) $row['id'], $coSaved);
$check('coSavedBooks() returns the neighbours of 1984', in_array($bGone, $coSavedIds, true) && in_array($bHunger, $coSavedIds, true));
$check('coSavedBooks() excludes the anchor', !in_array($b1984, $coSavedIds, true));
$check('coSavedBooks() carries the saved_count', (int) ($coSaved[0]['saved_count'] ?? 0) >= 1);

$coLibrary = $repository->coSavedForLibrary($riyaId, 10);
$coLibraryIds = array_map(fn (array $row): int => (int) $row['id'], $coLibrary);
$check('coSavedForLibrary() surfaces the neighbourhood book', in_array($bGone, $coLibraryIds, true));
$check('coSavedForLibrary() never recommends the user\'s own books', array_intersect($coLibraryIds, $riyaLibrary) === []);

$recentlyDiscovered = $repository->recentlyDiscoveredBooks(20, gmdate('Y-m-d\TH:i:s\Z', time() - 30 * 86400));
$check('recentlyDiscoveredBooks() returns community saves', $recentlyDiscovered !== []);
$check('recentlyDiscoveredBooks() carries the discovery_count', isset($recentlyDiscovered[0]['discovery_count']));

$gems = $repository->hiddenGemBooks(4.0, 8, 10);
$gemIds = array_map(fn (array $row): int => (int) $row['id'], $gems);
$check('hiddenGemBooks() matches the seeded gem', $gemIds === [$bGem]);

$similarRating = $repository->booksSimilarByRating(4.2, 0.5, 20);
$check('booksSimilarByRating() stays inside the band', $similarRating !== [] && array_reduce($similarRating, fn (bool $ok, array $row): bool => $ok && (float) $row['average_rating'] >= 3.7 && (float) $row['average_rating'] <= 4.7, true));
$check('booksSimilarByRating() carries the rating_gap', isset($similarRating[0]['rating_gap']));

$similarPopularity = $repository->booksSimilarByPopularity(3100, 0.5, 20);
$check('booksSimilarByPopularity() stays inside the band', $similarPopularity !== [] && array_reduce($similarPopularity, fn (bool $ok, array $row): bool => $ok && (int) $row['ratings_count'] >= 1550 && (int) $row['ratings_count'] <= 4650, true));
$check('booksSimilarByPopularity() carries the count_gap', isset($similarPopularity[0]['count_gap']));

$anchor = $repository->anchorBook($b1984);
$check('anchorBook() resolves the anchor row', is_array($anchor) && (int) $anchor['id'] === $b1984);
$check('anchorBook() is null for a missing book', $repository->anchorBook(999999) === null);

$influencing = $repository->libraryProfileBooks($riyaId, 10);
$check('libraryProfileBooks() puts favourites first', (int) ($influencing[0]['is_favorite'] ?? 0) === 1);
$check('libraryProfileBooks() adds the categories_list', (string) ($influencing[0]['categories_list'] ?? '') !== '');
$check('libraryProfileBooks() stays inside the favourites + finished', in_array($bHobbit, array_map(fn (array $row): int => (int) $row['book_id'], $influencing), true) && !in_array($bHabits, array_map(fn (array $row): int => (int) $row['book_id'], $influencing), true));

$repository->logRecommendations($riyaId, [
    ['book_id' => $bGone, 'reason' => 'R1', 'score' => 50.0, 'signal' => 'test_signal'],
    ['book_id' => $bHunger, 'reason' => 'R2', 'score' => 60.0, 'signal' => 'test_signal'],
]);
$testLogs = db()->query('SELECT * FROM recommendation_logs WHERE signal = ?', ['test_signal']);
$check('logRecommendations() inserts one row per served book', count($testLogs) === 2);
$check('The logs carry the explainable reason and score', (string) $testLogs[0]['reason'] === 'R1' && (float) $testLogs[0]['score'] === 50.0);

$annotated = $repository->recommendationLogs($riyaId, gmdate('Y-m-d\TH:i:s\Z', 0), 10);
$check('recommendationLogs() joins the book title', (string) ($annotated[0]['title'] ?? '') !== '');
$check('recommendationLogs() annotates in_library/rated/saved', isset($annotated[0]['in_library'], $annotated[0]['rated'], $annotated[0]['saved']));
$seen = false;
foreach ($annotated as $row) {
    if ((int) $row['book_id'] === $bHunger && (int) $row['in_library'] === 1) {
        $seen = true;
    }
}
$check('recommendationLogs() reports the in-library flag', $seen);

$repository->pruneRecommendationLogs($riyaId, 2);
$check('pruneRecommendationLogs() keeps only the newest retention rows', count($repository->recommendationLogs($riyaId, gmdate('Y-m-d\TH:i:s\Z', 0), 100)) === 2);

// ---------------------------------------------------------------------
// 5. SERVICE: libraryRecommendations (the personal shelves)
// ---------------------------------------------------------------------

echo $section('5. SERVICE: libraryRecommendations');

$all = $service->librarySections();
$check('librarySections() exposes all eight sections', count($all) === 8 && isset($all['because_library'], $all['because_you_read'], $all['similar_favourites'], $all['continue_exploring'], $all['discover_new_authors'], $all['hidden_gems'], $all['recently_popular'], $all['fresh_arrivals']));

$check('An unknown section throws', $throws(RecommendationException::class, fn () => $service->libraryRecommendations($riyaId, 'plundered')));

$guest = $service->libraryRecommendations(0, 'because_library');
$check('A guest gets the placeholder for a personal section', $guest->items === [] && $guest->note !== '');
$guestPopular = $service->libraryRecommendations(0, 'recently_popular');
$check('A guest still gets the community shelves', $guestPopular->items !== []);
$guestFresh = $service->libraryRecommendations(0, 'fresh_arrivals');
$check('A guest still gets the fresh arrivals', $guestFresh->items !== []);

$becauseLibrary = $service->libraryRecommendations($riyaId, 'because_library');
$becauseIds = array_map(fn (array $item): int => (int) $item['id'], $becauseLibrary->items);
$check('because_library returns a real shelf', $becauseLibrary->items !== []);
$check('because_library never recommends the user\'s own library', array_intersect($becauseIds, $riyaLibrary) === []);
$check('because_library honours the dashboard limit', count($becauseIds) <= (int) config('recommendations.library.section_limits.dashboard', 6));
$check('The items carry the explainable reason, score and confidence', array_reduce($becauseLibrary->items, fn (bool $ok, array $item): bool => $ok && isset($item['reason']) && (string) $item['reason'] !== '' && isset($item['score'], $item['confidence']), true));
$scores = array_map(fn (array $item): float => (float) $item['score'], $becauseLibrary->items);
$sorted = $scores;
rsort($sorted);
$check('The shelf is sorted by score descending', $scores === $sorted);

// A small limit is honoured explicitly.
$check('The explicit limit is honoured', count($service->libraryRecommendations($riyaId, 'because_library', 2)->items) === 2);

$becauseRead = $service->libraryRecommendations($riyaId, 'because_you_read', 10);
$becauseReadIds = array_map(fn (array $item): int => (int) $item['id'], $becauseRead->items);
$check('because_you_read returns a real shelf', $becauseRead->items !== []);
$check('because_you_read excludes the library', array_intersect($becauseReadIds, $riyaLibrary) === []);

$gemsShelf = $service->libraryRecommendations($riyaId, 'hidden_gems');
$gemShelfIds = array_map(fn (array $item): int => (int) $item['id'], $gemsShelf->items);
$check('hidden_gems recommends only the seeded gem', in_array($bGem, $gemShelfIds, true) && count($gemShelfIds) <= 1);
$check('hidden_gems describes its note', str_contains($gemsShelf->note, 'Hidden gems'));

$fresh = $service->libraryRecommendations($riyaId, 'fresh_arrivals', 5);
$check('fresh_arrivals returns a real shelf', $fresh->items !== []);

// The logs the shelf writes carry the section key as the signal.
$shelfLogs = count($repository->recommendationLogs($riyaId, gmdate('Y-m-d\TH:i:s\Z', 0), 1000));
$check('The served shelf is logged with its signal', $shelfLogs > 0);
$becauseSignals = db()->query('SELECT COUNT(*) AS n FROM recommendation_logs WHERE user_id = ? AND signal = ?', [$riyaId, 'because_library']);
$check('The because_library signal was recorded', (int) $becauseSignals[0]['n'] > 0);

// ---------------------------------------------------------------------
// 6. SERVICE: bookRecommendations (the book-detail sections)
// ---------------------------------------------------------------------

echo $section('6. SERVICE: bookRecommendations');

$check('A missing anchor throws', $throws(RecommendationException::class, fn () => $service->bookRecommendations(999999)));

$sections = $service->bookRecommendations($b1984);
$check('The anchor yields all six sections', count($sections) === 6 && isset($sections['readers_also_enjoyed'], $sections['same_author'], $sections['same_category'], $sections['similar_rating'], $sections['similar_popularity'], $sections['recommended_for_you']));

$allSections = array_merge(...array_values($sections));
$allIds = array_map(fn (array $row): int => (int) ($row['id'] ?? 0), $allSections);
$check('The anchor book is excluded everywhere', !in_array($b1984, $allIds, true));

$readers = array_map(fn (array $row): int => (int) ($row['id'] ?? 0), $sections['readers_also_enjoyed']);
$check('readers_also_enjoyed returns the neighbourhood', array_intersect($readers, [$bGone, $bHunger]) !== []);
$check('The community sections carry reasons', array_reduce($allSections, fn (bool $ok, array $row): bool => $ok && isset($row['reason']) && (string) $row['reason'] !== '', true));
$check('Each section rolled up carries the score', array_reduce($allSections, fn (bool $ok, array $row): bool => $ok && isset($row['score']), true));

// No duplicates across the six sections once the DB rows are flattened.
$check('No duplicate book across the sections', count($allIds) === count(array_unique($allIds)));

// The guest snapshot has no personal shelf.
$guestSections = $service->bookRecommendations($b1984, 0);
$check('A guest gets no recommended_for_you shelf', $guestSections['recommended_for_you'] === []);

// A logged-in user's personal shelf is filtered down and present.
$riyaSections = $service->bookRecommendations($b1984, $riyaId);
$check('A logged-in user gets the personal shelf', count($riyaSections['recommended_for_you']) <= 6);

// ---------------------------------------------------------------------
// 7. SERVICE: libraryPageRecommendations (the library dashboard)
// ---------------------------------------------------------------------

echo $section('7. SERVICE: libraryPageRecommendations');

$page = $service->libraryPageRecommendations($riyaId);
$check('The library page yields all five sections', count($page) === 5 && isset($page['because_in_library'], $page['people_also_saved'], $page['favourite_category'], $page['favourite_author'], $page['recently_discovered']));

$pageIds = array_map(fn (array $row): int => (int) ($row['id'] ?? 0), array_merge(...array_values($page)));
$check('The library page never recommends the user\'s own books', array_intersect($pageIds, $riyaLibrary) === []);

$peopleAlsoSaved = array_map(fn (array $row): int => (int) ($row['id'] ?? 0), $page['people_also_saved']);
$check('people_also_saved surfaces the neighbourhood book', in_array($bGone, $peopleAlsoSaved, true));

$favouriteCategory = $page['favourite_category'];
$check('favourite_category names the real category', $favouriteCategory !== [] && str_contains((string) ($favouriteCategory[0]['reason'] ?? ''), 'Science Fiction'));

$favouriteAuthor = $page['favourite_author'];
$check('favourite_author names the real author', $favouriteAuthor !== [] && str_contains((string) ($favouriteAuthor[0]['reason'] ?? ''), 'Andy Weir'));

$check('favourite_category stays inside the limit', count($page['favourite_category']) <= (int) config('recommendations.library.section_limits.library', 6));

$peopleLogs = db()->query('SELECT COUNT(*) AS n FROM recommendation_logs WHERE user_id = ? AND signal = ?', [$riyaId, 'people_also_saved']);
$check('The people_also_saved signal was logged', (int) $peopleLogs[0]['n'] > 0);

// A user without a library gets empty sections, never fabricated ones.
$emptyUser = $users->findByEmail('meera@booksphere.test');
$emptySections = $service->libraryPageRecommendations((int) $emptyUser['id']);
$check('A library-less user gets empty sections', array_reduce($emptySections, fn (bool $ok, array $rows): bool => $ok && $rows === [], true));

// ---------------------------------------------------------------------
// 8. SERVICE: profileRecommendationInsights + the accuracy figure
// ---------------------------------------------------------------------

echo $section('8. PROFILE: recommendation insights + accuracy');

$insights = $service->profileRecommendationInsights($riyaId);

$check('The insights expose the top categories', ($insights['categories'][0]['name'] ?? '') === 'Science Fiction');
$check('The insights expose the top authors', ($insights['authors'][0]['name'] ?? '') === 'Andy Weir');
$check('The insights shape matches the profile block', isset($insights['categories'], $insights['authors'], $insights['accuracy'], $insights['influencing'], $insights['logs']));

$before = $insights['accuracy'];
$check('The accuracy reports a recommended set', (int) $before['recommended'] > 0);
$check('The accuracy stays mathematically consistent', (int) $before['acted'] <= (int) $before['recommended'] && (int) $before['percent'] === (int) round($before['acted'] / $before['recommended'] * 100));

// Act on a recommendation: save Gone Girl (previously only served,
// never kept) to the library. The accuracy must rise.
$libraryService->addBook(LibraryItemDTO::fromArray(['book_id' => $bGone, 'status' => 'want_to_read'], $riyaId));

$after = $service->profileRecommendationInsights($riyaId)['accuracy'];
$check('Acting on a recommendation raises the acted count', (int) $after['acted'] > (int) $before['acted']);
$check('The percent climbs above zero', (int) $after['percent'] > 0);
$check('The recommended set is unchanged by the action', (int) $after['recommended'] === (int) $before['recommended']);

$influencing = $service->profileRecommendationInsights($riyaId)['influencing'];
$check('Influencing stays favourites + finished only', !in_array($bGone, array_map(fn (array $row): int => (int) $row['book_id'], $influencing), true));
$check('The influencing rows carry the renderable shape', isset($influencing[0]['book_id'], $influencing[0]['title'], $influencing[0]['cover_image'], $influencing[0]['categories_list']));

$recentLogs = $service->profileRecommendationInsights($riyaId)['logs'];
$check('The logs are bounded by the profile limit', count($recentLogs) <= (int) config('recommendations.library.section_limits.profile', 5));

// ---------------------------------------------------------------------
// 9. SERVICE: logRecommendations (the no-ops and the retention)
// ---------------------------------------------------------------------

echo $section('9. SERVICE: logRecommendations');

$guestLogsBefore = (int) db()->query('SELECT COUNT(*) AS n FROM recommendation_logs')[0]['n'];
$service->logRecommendations(0, [['book_id' => $bGem, 'reason' => 'x', 'score' => 1.0, 'signal' => 'x']], 'guest_test');
$check('A guest is never logged', (int) db()->query('SELECT COUNT(*) AS n FROM recommendation_logs')[0]['n'] === $guestLogsBefore);

$emptyBefore = (int) db()->query('SELECT COUNT(*) AS n FROM recommendation_logs')[0]['n'];
$service->logRecommendations($riyaId, [], 'empty_signal');
$check('An empty item set is a quiet no-op', (int) db()->query('SELECT COUNT(*) AS n FROM recommendation_logs')[0]['n'] === $emptyBefore);

$service->logRecommendations($riyaId, [['reason' => 'no book id']], 'broken_item');
$check('An item without an id is skipped', db()->query('SELECT COUNT(*) AS n FROM recommendation_logs WHERE signal = ?', ['broken_item'])[0]['n'] == 0);

// The retention pruning keeps the newest rows per user.
$adminLogs = [];
for ($i = 1; $i <= 8; $i++) {
    $adminLogs[] = ['book_id' => $bGem, 'reason' => 'retention ' . $i, 'score' => 10.0, 'signal' => 'retention_test'];
}
$repository->logRecommendations($adminId, $adminLogs);
$repository->pruneRecommendationLogs($adminId, 3);
$kept = db()->query('SELECT reason FROM recommendation_logs WHERE signal = ? ORDER BY id', ['retention_test']);
$check('Pruning keeps only the newest rows', count($kept) === 3);
$check('The pruned rows keep the newest reasons', (string) $kept[0]['reason'] === 'retention 6');

// ---------------------------------------------------------------------
// 10. CACHE: the per-section files + invalidation
// ---------------------------------------------------------------------

echo $section('10. CACHE: per-section shelves');

$cachedResult = $cachedService->libraryRecommendations($riyaId, 'because_library');
$check('The cached service builds the shelf', $cachedResult->items !== []);
$sectionFile = $cacheDir . '/section_' . $riyaId . '_because_library.json';
$check('The section cache file was written', is_file($sectionFile));

$logsBeforeHit = (int) db()->query('SELECT COUNT(*) AS n FROM recommendation_logs WHERE user_id = ? AND signal = ?', [$riyaId, 'because_library'])[0]['n'];
$cacheHit = $cachedService->libraryRecommendations($riyaId, 'because_library');
$check('A cache hit returns the same shelf', count($cacheHit->items) === count($cachedResult->items));
$check('A cache hit does not re-log the shelf', (int) db()->query('SELECT COUNT(*) AS n FROM recommendation_logs WHERE user_id = ? AND signal = ?', [$riyaId, 'because_library'])[0]['n'] === $logsBeforeHit);

// The service's public invalidation drops the section files too.
$cachedService->invalidatePersonalization($riyaId);
$check('invalidatePersonalization() drops the section file', !is_file($sectionFile));

$cachedService->libraryRecommendations($riyaId, 'because_library');
$cachedService->libraryRecommendations($riyaId, 'similar_favourites');
$check('Two section files exist after two shelves', count(glob($cacheDir . '/section_' . $riyaId . '_*.json') ?: []) === 2);
$cachedService->flushPersonalization();
$check('flushPersonalization() clears every section file', (glob($cacheDir . '/section_*.json') ?: []) === []);

// ---------------------------------------------------------------------
// 11. VIEWS: the four wired pages render the Phase 8.5 blocks
// ---------------------------------------------------------------------

echo $section('11. VIEWS: dashboard, book, library, profile');

$session->put('auth_user_id', $riyaId);
$session->put('auth_user', ['id' => $riyaId, 'full_name' => 'Riya Sharma', 'email' => 'riya@booksphere.test', 'role' => 'user']);

$dashboardController = new \BookSphere\App\Controllers\DashboardController($reviewService, $libraryService, $service);
ob_start();
$dashboardController->index(new Request());
$dashboardHtml = (string) ob_get_clean();
$check('The dashboard renders Recommended for You', str_contains($dashboardHtml, 'Recommended for You'));
$check('The dashboard renders Because You Read', str_contains($dashboardHtml, 'Because You Read'));
$check('The dashboard renders Trending Books', str_contains($dashboardHtml, 'Trending Books'));
$check('The dashboard renders the rec cards', str_contains($dashboardHtml, 'rec-card'));

$bookController = new \BookSphere\App\Controllers\BookController($bookService, $service, $reviewService, $libraryService);
ob_start();
$bookController->show(new Request(), ['id' => (string) $b1984]);
$bookHtml = (string) ob_get_clean();
$check('The book page renders Readers Also Enjoyed', str_contains($bookHtml, 'Readers also enjoyed'));
$check('The book page renders the Similar sections', str_contains($bookHtml, 'Similar rating') && str_contains($bookHtml, 'Similar popularity'));
$check('The book page renders Recommended for You', str_contains($bookHtml, 'Recommended for you'));
$check('The book page renders the rec cards', str_contains($bookHtml, 'rec-card'));

$libraryController = new \BookSphere\App\Controllers\LibraryController($libraryService, new LibraryPolicy(), new \BookSphere\App\Core\RateLimiter($session), $service);
ob_start();
$libraryController->index(new Request());
$libraryHtml = (string) ob_get_clean();
$check('The library page renders the recommendation block', str_contains($libraryHtml, 'Recommended for your library'));
$check('The library page renders the neighbourhood shelf', str_contains($libraryHtml, 'People who saved this also liked'));
$check('The library page names the favourite category', str_contains($libraryHtml, 'Science Fiction'));

$userController = new \BookSphere\App\Controllers\UserController($auth, $users, $reviewService, $libraryService, $service);
ob_start();
$userController->show(new Request(), ['id' => (string) $riyaId]);
$profileHtml = (string) ob_get_clean();
$check('The profile renders the Recommendation Insights block', str_contains($profileHtml, 'Recommendation Insights'));
$check('The profile renders the favourite categories', str_contains($profileHtml, 'Favourite categories') && str_contains($profileHtml, 'Science Fiction'));
$check('The profile renders the Recommendation Accuracy tile', str_contains($profileHtml, 'Recommendation accuracy'));
$check('The profile renders the influencing books', str_contains($profileHtml, 'Books influencing your recommendations'));
$check('The profile renders the percentage', preg_match('/>\s*\d+%\s*</', $profileHtml) === 1);

// ---------------------------------------------------------------------
// RESULT
// ---------------------------------------------------------------------

echo $section('RESULT');
echo '  Checks: ' . $checks . "\n";
echo '  Failed: ' . $failures . "\n";

if (is_dir($cacheDir)) {
    array_map('unlink', glob($cacheDir . '/*') ?: []);
    rmdir($cacheDir);
}

echo "\nNote: the throwaway database database/library_recommendation_test.db and the log file C:\\Users\\joyal\\AppData\\Local\\Temp\\booksphere_library_recommendation_test.log are left in place for inspection; delete them anytime.\n";

exit($failures > 0 ? 1 : 0);