<?php

declare(strict_types=1);

/**
 * ReviewTest — CLI test suite for Phase 7.1 (Reviews & Ratings
 * backend), Phase 7.2 (complete review CRUD), Phase 7.3 (the
 * interactive star rating component + rating analytics) and Phase
 * 7.4 (the professional review lists: server-side search, sort,
 * filters, pagination, statistics, the list presenter and the
 * shared review components)
 *
 * Verifies the Reviews module without touching real data (same
 * harness as every other suite):
 *
 *     1. Schema: migration 0014 applied - the title / status /
 *        is_edited / updated_at columns and the three lookup
 *        indexes exist, and the UNIQUE (user_id, book_id)
 *        constraint still blocks a second review per book
 *     2. Validation: StoreReviewRequest / UpdateReviewRequest
 *        enforce rating 1-5, title <= 120, review 20-2000 with
 *        friendly messages
 *     3. Repository: create/find/update/delete, findByBook /
 *        findByUser (with joined display columns), exists(),
 *        averageRating() / ratingCount() over approved reviews
 *        only, the scope reads (latest, oldest, highestRated,
 *        lowestRated, approved) and latestReviews()
 *     4. Service: store with book-exists + duplicate prevention,
 *        the automatic average-rating / review-count sync on
 *        create / update / delete, the is_edited flag, and the
 *        not-found exceptions
 *     5. Book integration: books.average_rating / ratings_count
 *        stay in step with the reviews table, and the Book module
 *        browse still works (regression)
 *     6. Policy: guests can never review; the owner may edit /
 *        delete their own review; other users cannot; admins can
 *        edit / delete any review
 *     7. Model: the Review facade forwards to the repository and
 *        its relationship methods resolve the book and the user
 *     8. Logging: every write is recorded with user id, book id
 *        and review id
 *     9. Phase 7.2: the named CRUD inventory (createReview /
 *        updateReview / deleteReview / validateReview,
 *        canUserReview / userHasReviewed, recalculateBookRating /
 *        recalculateReviewCount, userReview, ratingDistribution,
 *        repository insert / findByUserAndBook), the single-review
 *        page, the exact success messages, the shared delete
 *        modal and the book detail page integration (write form,
 *        "already reviewed" status, review list, CSRF token)
 *    10. Phase 7.3: the star-rating component (display + input
 *        modes, half stars, roving tabindex, no-JS fallback), the
 *        rating breakdown rows, the analytics aggregations
 *        (highest/lowest rated, unrated books, category averages,
 *        catalogue average + distribution, per-user profile stats,
 *        the admin analytics payload) and the live render of the
 *        distribution bars on the book page, the dashboard's real
 *        Top Rated Books shelf, the admin analytics page and the
 *        profile's rating activity block
 *    11. Phase 7.4: the paginated list (COUNT + SELECT sharing one
 *        WHERE builder), the sort allowlist with the SQL-injection
 *        fallback, the rating / edited / user filters and their
 *        combos, the server-side search over title / body /
 *        reviewer name, the truthful statistics (average, highest,
 *        lowest, latest, distribution), the service normalization
 *        gate, the presenter payloads, the controller renders of
 *        My Reviews / search / statistics / per-user / book pages
 *        and the shared components (card, toolbar, empty states,
 *        skeleton, search box, stats panel)
 *    12. Phase 7.7: the hardening inventory - the unique report
 *        constraint from migration 0016 (the database rejects a
 *        second report by the same user), the batched helpful-vote
 *        read (the N+1 fix), the UpdateReviewRequest::validate()
 *        regression (the missing Validator import used to throw a
 *        TypeError), the self-report exception wording, the
 *        configured write throttles and the live 429 gate (a
 *        subprocess probe, because Response::error() exits), and
 *        the shared date / avatar / distribution helpers
 *
 * Run from the project root:
 *
 *     php tests/ReviewTest.php
 *
 * How it works:
 *     - A throwaway SQLite database (database/review_test.db) is
 *       created, migrated and seeded.
 *     - A throwaway log file under sys_get_temp_dir() receives the
 *       review write audit trail (the default application log is
 *       never touched).
 *     - Every check prints PASS/FAIL; the summary line doubles as
 *       the Phase 7.1/7.2/7.3/7.4 testing checklist for the viva.
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
use BookSphere\App\Core\Validator;
use BookSphere\App\DTO\ReviewDTO;
use BookSphere\App\Exceptions\ReviewException;
use BookSphere\App\Models\Book;
use BookSphere\App\Models\Review;
use BookSphere\App\Models\User;
use BookSphere\App\Policies\ReviewPolicy;
use BookSphere\App\Repositories\ReviewRepository;
use BookSphere\App\Requests\ReportReviewRequest;
use BookSphere\App\Requests\StoreReviewRequest;
use BookSphere\App\Requests\UpdateReviewRequest;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\ReviewService;

// ---------------------------------------------------------------------
// 0. Boot: fresh throwaway database, migrated and seeded.
// ---------------------------------------------------------------------

(new Environment(root_path('.env')))->load();

$dbPath = root_path('database/review_test.db');

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
$session = new Session('review_test');
$session->start();
$auth = new AuthService($session, new User());
AuthService::setInstance($auth);

$logFile = sys_get_temp_dir() . '/booksphere_review_test.log';
if (is_file($logFile)) {
    unlink($logFile);
}

// ---------------------------------------------------------------------
// Shared fixtures (resolved from the seed data by email/title).
// ---------------------------------------------------------------------

$users = new User();
$admin = $users->findByEmail('admin@booksphere.test');
$riya  = $users->findByEmail('riya@booksphere.test');
$arjun = $users->findByEmail('arjun@booksphere.test');

// A book WITHOUT any seeded review, so the stats tests start clean.
$bookId = (int) db()->query(
    'SELECT b.id
     FROM books b
     WHERE b.deleted_at IS NULL
       AND NOT EXISTS (SELECT 1 FROM reviews r WHERE r.book_id = b.id)
     LIMIT 1',
)[0]['id'];

$bookModel = new Book();
$reviewModel = new Review();
$repository = new ReviewRepository();

$service = new ReviewService(
    $reviewModel,
    $bookModel,
    null,
    new Logger($logFile),
);

$policy = new ReviewPolicy();

$section   = fn (string $title): string => "\n------------------------------------------------------------------------\n{$title}\n------------------------------------------------------------------------";
$check     = function (string $label, bool $ok): void {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . PHP_EOL;
    $GLOBALS['failures'] = ($GLOBALS['failures'] ?? 0) + ($ok ? 0 : 1);
    $GLOBALS['checks']   = ($GLOBALS['checks'] ?? 0) + 1;
};
$failures  = 0;
$checks    = 0;

// ---------------------------------------------------------------------
// 1. SCHEMA: migration 0014 columns + indexes + unique constraint
// ---------------------------------------------------------------------

echo $section('1. SCHEMA: migration 0014 serves the Reviews module');

$columns = array_column(db()->query('PRAGMA table_info(reviews)'), 'name');
$check('The title column exists', in_array('title', $columns, true));
$check('The status column exists', in_array('status', $columns, true));
$check('The is_edited column exists', in_array('is_edited', $columns, true));
$check('The updated_at column exists', in_array('updated_at', $columns, true));

$indexes = array_column(db()->query('PRAGMA index_list(reviews)'), 'name');
$check('The user lookup index exists', in_array('idx_reviews_user', $indexes, true));
$check('The rating index exists', in_array('idx_reviews_rating', $indexes, true));
$check('The created_at index exists', in_array('idx_reviews_created', $indexes, true));
$check('The one-review-per-book unique index still exists', in_array('sqlite_autoindex_reviews_1', $indexes, true));

$duplicateBlocked = false;
try {
    db()->execute(
        'INSERT INTO reviews (book_id, user_id, rating, title, review, status, is_edited, created_at, updated_at)
         VALUES (?, ?, 5, \'Test\', \'This review body is definitely long enough to pass.\', \'approved\', 0, \'2026-01-01T00:00:00Z\', \'2026-01-01T00:00:00Z\')',
        [$bookId, (int) $riya['id']],
    );
    db()->execute(
        'INSERT INTO reviews (book_id, user_id, rating, title, review, status, is_edited, created_at, updated_at)
         VALUES (?, ?, 4, \'Test\', \'This review body is definitely long enough to pass.\', \'approved\', 0, \'2026-01-01T00:00:00Z\', \'2026-01-01T00:00:00Z\')',
        [$bookId, (int) $riya['id']],
    );
} catch (\Throwable) {
    $duplicateBlocked = true;
}
$check('The database blocks a second review of the same book by the same user', $duplicateBlocked);
db()->execute('DELETE FROM reviews WHERE book_id = ? AND user_id = ?', [$bookId, (int) $riya['id']]);

// ---------------------------------------------------------------------
// 2. VALIDATION: rating 1-5, title <= 120, review 20-2000
// ---------------------------------------------------------------------

echo $section('2. VALIDATION: the request rules');

$valid = ['rating' => '4', 'title' => 'A solid read', 'review' => 'This review body is definitely long enough to pass the minimum length.'];
$check('A valid review passes', StoreReviewRequest::passes($valid));

$errors = StoreReviewRequest::validate(['rating' => '', 'title' => '', 'review' => ''])->errors();
$check('An empty review fails with all three required rules', isset($errors['rating'], $errors['title'], $errors['review']));

$errors = StoreReviewRequest::validate(['rating' => '0', 'title' => 'x', 'review' => $valid['review']])->errors();
$check('Rating 0 is rejected', isset($errors['rating']));
$errors = StoreReviewRequest::validate(['rating' => '6', 'title' => 'x', 'review' => $valid['review']])->errors();
$check('Rating 6 is rejected', isset($errors['rating']));
$errors = StoreReviewRequest::validate(['rating' => '3.5', 'title' => 'x', 'review' => $valid['review']])->errors();
$check('Rating 3.5 (not a whole number) is rejected', isset($errors['rating']));

$errors = StoreReviewRequest::validate(['rating' => '3', 'title' => str_repeat('a', 121), 'review' => $valid['review']])->errors();
$check('A 121-character title is rejected', isset($errors['title']));

$errors = StoreReviewRequest::validate(['rating' => '3', 'title' => 'x', 'review' => str_repeat('a', 19)])->errors();
$check('A 19-character review is rejected', isset($errors['review']));
$errors = StoreReviewRequest::validate(['rating' => '3', 'title' => 'x', 'review' => str_repeat('a', 2001)])->errors();
$check('A 2001-character review is rejected', isset($errors['review']));

$check('UpdateReviewRequest shares the same rules (delegation)', UpdateReviewRequest::passes($valid));
$check('UpdateReviewRequest rejects a bad rating too', !UpdateReviewRequest::passes(['rating' => '7', 'title' => 'x', 'review' => $valid['review']]));

// ---------------------------------------------------------------------
// 3. REPOSITORY: every query works
// ---------------------------------------------------------------------

echo $section('3. REPOSITORY: the data layer');

$reviewId = $repository->create([
    'book_id' => $bookId,
    'user_id' => (int) $riya['id'],
    'rating'  => 5,
    'title'   => 'A real page turner',
    'review'  => 'This review body is definitely long enough to pass the minimum length.',
    'status'  => 'approved',
]);
$check('create() returns the new id', $reviewId > 0);

$found = $repository->find($reviewId);
$check('find() returns the stored row', $found !== null && (int) $found['rating'] === 5 && $found['status'] === 'approved');
$check('find() reports is_edited = 0 for a fresh review', $found !== null && (int) $found['is_edited'] === 0);

$check('exists() is true after the create', $repository->exists((int) $riya['id'], $bookId));
$check('exists() is false for another user', !$repository->exists((int) $arjun['id'], $bookId));

$byBook = $repository->findByBook($bookId);
$check('findByBook() returns the review with the reviewer name', count($byBook) === 1 && $byBook[0]['user_name'] !== '');

$byUser = $repository->findByUser((int) $riya['id']);
$check('findByUser() returns the review with the book title', count($byUser) >= 1 && $byUser[0]['book_title'] !== '');

$check('averageRating() of a single 5-star review is 5.0', $repository->averageRating($bookId) === 5.0);
$check('ratingCount() of a single review is 1', $repository->ratingCount($bookId) === 1);

$secondId = $repository->create([
    'book_id' => $bookId,
    'user_id' => (int) $arjun['id'],
    'rating'  => 3,
    'title'   => 'Not for me',
    'review'  => 'A second review body that is comfortably long enough for the rules.',
    'status'  => 'approved',
]);
$check('averageRating() mixes 5 + 3 into 4.0', $repository->averageRating($bookId) === 4.0);
$check('ratingCount() counts both reviews', $repository->ratingCount($bookId) === 2);

$check('update() changes the rating', $repository->update($reviewId, ['rating' => 4, 'title' => 'Still good', 'review' => 'An updated body that is comfortably long enough for the rules.', 'is_edited' => 1]));
$check('The updated rating is stored', (int) $repository->find($reviewId)['rating'] === 4);

$latest = $repository->latestReviews(5);
$check(
    'latestReviews() returns the newest approved review first',
    count($latest) >= 1 && in_array($latest[0]['title'], ['Still good', 'Not for me'], true),
);

$scopes = [
    'latest'       => $repository->latest(5),
    'oldest'       => $repository->oldest(5),
    'highestRated' => $repository->highestRated(5),
    'lowestRated'  => $repository->lowestRated(5),
    'approved'     => $repository->approved(5),
];
foreach ($scopes as $name => $rows) {
    $check("The {$name} scope returns review rows", is_array($rows) && count($rows) >= 1 && isset($rows[0]['user_name'], $rows[0]['book_title']));
}
$check('highestRated() puts a 5-star review first', (int) $scopes['highestRated'][0]['rating'] === 5);

// ---------------------------------------------------------------------
// 4. SERVICE: business rules + automatic book sync
// ---------------------------------------------------------------------

echo $section('4. SERVICE: rules, stats sync, exceptions');

$freshBookId = (int) db()->query(
    'SELECT b.id
     FROM books b
     WHERE b.deleted_at IS NULL
       AND NOT EXISTS (SELECT 1 FROM reviews r WHERE r.book_id = b.id)
       AND b.id != ?
     LIMIT 1',
    [$bookId],
)[0]['id'];

$dto1 = ReviewDTO::fromArray(['book_id' => $freshBookId, 'rating' => 5, 'title' => 'Loved it', 'review' => 'This review body is definitely long enough to pass the minimum length.'], (int) $riya['id']);
$createdId = $service->store($dto1);
$check('store() creates a review and returns its id', $createdId > 0);

$stats = $repository->ratingStats($freshBookId);
$check('The book average jumps to the new rating (5.0)', $stats['average'] === 5.0);
$check('The book review count becomes 1', $stats['count'] === 1);

try {
    $service->store(ReviewDTO::fromArray(['book_id' => $freshBookId, 'rating' => 4, 'title' => 'Second try', 'review' => 'This review body is definitely long enough to pass the minimum length.'], (int) $riya['id']));
    $duplicateCaught = false;
} catch (ReviewException $exception) {
    $duplicateCaught = str_contains($exception->getMessage(), 'already reviewed');
}
$check('A duplicate review raises ReviewException', $duplicateCaught);

try {
    $service->store(ReviewDTO::fromArray(['book_id' => 999999, 'rating' => 4, 'title' => 'Ghost', 'review' => 'This review body is definitely long enough to pass the minimum length.'], (int) $riya['id']));
    $bookCaught = false;
} catch (ReviewException $exception) {
    $bookCaught = str_contains($exception->getMessage(), 'Book not found');
}
$check('A missing book raises ReviewException', $bookCaught);

$service->store(ReviewDTO::fromArray(['book_id' => $freshBookId, 'rating' => 1, 'title' => 'Disappointing', 'review' => 'A second review body that is comfortably long enough for the rules.'], (int) $arjun['id']));
$stats = $repository->ratingStats($freshBookId);
$check('A second rating shifts the average to (5 + 1) / 2 = 3.0', $stats['average'] === 3.0);
$check('The review count grows to 2', $stats['count'] === 2);

$service->update($createdId, ReviewDTO::fromArray(['book_id' => $freshBookId, 'rating' => 4, 'title' => 'Reread it', 'review' => 'An updated body that is comfortably long enough for the rules.'], (int) $riya['id']));
$stats = $repository->ratingStats($freshBookId);
$check('An update recomputes the average ((4 + 1) / 2 = 2.5)', $stats['average'] === 2.5);
$check('An update with changed content sets is_edited', (int) $repository->find($createdId)['is_edited'] === 1);

$service->update($createdId, ReviewDTO::fromArray(['book_id' => $freshBookId, 'rating' => 4, 'title' => 'Reread it', 'review' => 'An updated body that is comfortably long enough for the rules.'], (int) $riya['id']));
$check('An unchanged re-save keeps is_edited untouched', (int) $repository->find($createdId)['is_edited'] === 1);

$service->delete($createdId);
$stats = $repository->ratingStats($freshBookId);
$check('A delete recomputes the average to the remaining review (1.0)', $stats['average'] === 1.0);
$check('A delete drops the review count to 1', $stats['count'] === 1);

try {
    $service->delete(999999);
    $missingCaught = false;
} catch (ReviewException $exception) {
    $missingCaught = str_contains($exception->getMessage(), 'Review not found');
}
$check('Updating/deleting a missing review raises ReviewException', $missingCaught);

$summary = $service->statsForBook($freshBookId);
$check('statsForBook() returns the average + count pair', $summary === ['average' => 1.0, 'count' => 1]);
$check('errorsFor() reports the friendly validation messages', $service->errorsFor(['rating' => '0', 'title' => 'x', 'review' => 'short']) !== []);

// Service reads route through the MODEL facade (the same path the
// controller uses) - these checks would have caught a missing
// passthrough like the findByUser() gap found live.
$serviceBook = $service->reviewsForBook($freshBookId);
$check('reviewsForBook() serves the review with the reviewer name', count($serviceBook) === 1 && $serviceBook[0]['user_name'] !== '');
$serviceUser = $service->reviewsByUser((int) $arjun['id']);
$check('reviewsByUser() serves the review with the book title', count($serviceUser) >= 1 && $serviceUser[0]['book_title'] !== '');
$serviceLatest = $service->latestReviews(5);
$check('latestReviews() serves the newest reviews through the model', count($serviceLatest) >= 1 && isset($serviceLatest[0]['user_name']));
$check('find() through the service resolves a stored review', $service->find($secondId) !== null);

// ---------------------------------------------------------------------
// 5. BOOK INTEGRATION: the Book module still works (regression)
// ---------------------------------------------------------------------

echo $section('5. BOOK INTEGRATION: browse regression');

$browse = (new Book())->browse(['q' => '', 'status' => null, 'category_id' => null, 'author_id' => null, 'publisher' => null, 'language' => null, 'year_from' => null, 'year_to' => null, 'min_rating' => null, 'sort' => ['column' => 'created_at', 'order' => 'DESC', 'nullsLast' => false], 'perPage' => 10, 'offset' => 0]);
$check('The browse query still returns books', (int) $browse['total'] > 0 && count($browse['items']) > 0);
$check('Browse rows still carry the rating columns', isset($browse['items'][0]['average_rating'], $browse['items'][0]['ratings_count']));

// ---------------------------------------------------------------------
// 6. POLICY: guest / owner / other user / admin
// ---------------------------------------------------------------------
// The auth state is simulated with direct session writes (the same
// keys AuthService::login() writes) instead of login()/logout(),
// because those regenerate the session id, which PHP refuses after
// CLI output has been sent.

echo $section('6. POLICY: who may review, edit and delete');

$session->forget('auth_user_id');
$session->forget('auth_user');
$check('A guest cannot review', !$policy->canReview());

$session->put('auth_user_id', (int) $riya['id']);
$session->put('auth_user', ['id' => (int) $riya['id'], 'full_name' => $riya['full_name'], 'email' => $riya['email'], 'role' => $riya['role']]);
$check('An authenticated user can review', $policy->canReview());

$ownerReview = ['id' => 1, 'user_id' => (int) $riya['id'], 'book_id' => $bookId];
$otherReview = ['id' => 2, 'user_id' => (int) $arjun['id'], 'book_id' => $bookId];

$check('The owner can edit their own review', $policy->canEdit($ownerReview));
$check('The owner can delete their own review', $policy->canDelete($ownerReview));
$check('Another user cannot edit a review they did not write', !$policy->canEdit($otherReview));
$check('Another user cannot delete a review they did not write', !$policy->canDelete($otherReview));

$session->put('auth_user_id', (int) $admin['id']);
$session->put('auth_user', ['id' => (int) $admin['id'], 'full_name' => $admin['full_name'], 'email' => $admin['email'], 'role' => $admin['role']]);
$check('An admin can edit any review', $policy->canEdit($otherReview));
$check('An admin can delete any review', $policy->canDelete($otherReview));

// ---------------------------------------------------------------------
// 7. MODEL: the facade forwards and resolves relationships
// ---------------------------------------------------------------------

echo $section('7. MODEL: facade + relationships + scopes');

$model = new Review();
$check('The model finds a review by id', $model->find($secondId) !== null);
$check('The model lists a book\'s reviews', count($model->findByBook($bookId)) >= 1);
$check('The model lists a user\'s reviews', count($model->findByUser((int) $riya['id'])) >= 1);
$check('The model resolves the reviewed book (belongsTo)', ($model->book(['book_id' => $freshBookId])['id'] ?? null) === $freshBookId);
$check('The model resolves the author (belongsTo)', ($model->user(['user_id' => (int) $riya['id']])['id'] ?? null) === (int) $riya['id']);
$check('The latest scope returns rows', count($model->latest(5)) >= 1);
$check('The oldest scope returns rows', count($model->oldest(5)) >= 1);
$check('The highestRated scope returns rows', count($model->highestRated(5)) >= 1);
$check('The lowestRated scope returns rows', count($model->lowestRated(5)) >= 1);
$check('The approved scope returns rows', count($model->approved(5)) >= 1);
$check('The model reports duplicate reviews', $model->exists((int) $arjun['id'], $freshBookId) && !$model->exists((int) $riya['id'], $freshBookId));

// ---------------------------------------------------------------------
// 8. CONTROLLER: the thin wiring layer (fetch/JSON + render paths)
// ---------------------------------------------------------------------
// The redirect paths cannot run in CLI (Response::redirect() exits),
// so the store() success/failure flows are exercised through their
// fetch/JSON answers - the same dual-path pattern the dashboard
// suite uses for the wishlist toggle.

echo $section('8. CONTROLLER: the wiring layer');

$controller = new \BookSphere\App\Controllers\ReviewController($service, $policy);

$session->put('auth_user_id', (int) $riya['id']);
$session->put('auth_user', ['id' => (int) $riya['id'], 'full_name' => $riya['full_name'], 'email' => $riya['email'], 'role' => $riya['role']]);

$_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';
$_POST = [
    'rating' => '4',
    'title'  => 'Controller-driven',
    'review' => 'A body written by the controller test that is long enough to pass the minimum.',
];

ob_start();
$controller->store(new Request(), ['id' => (string) $freshBookId]);
$json = (string) ob_get_clean();
unset($_POST['rating'], $_POST['title'], $_POST['review']);

$decoded = json_decode($json, true);
$check('store() answers JSON on the fetch path', ($decoded['ok'] ?? false) === true);
$check('store() routes the book id from the URL into the review', $model->exists((int) $riya['id'], $freshBookId));
$stats = $repository->ratingStats($freshBookId);
$check('The fetch store updated the book stats too', $stats['count'] === 2 && $stats['average'] === 2.5);

$_POST = ['rating' => '6', 'title' => 'Bad', 'review' => 'short'];
ob_start();
$controller->store(new Request(), ['id' => (string) $freshBookId]);
$invalidJson = (string) ob_get_clean();
unset($_POST);
$decoded = json_decode($invalidJson, true);
$check('A validation failure answers 422 with the field errors', ($decoded['ok'] ?? true) === false && isset($decoded['errors']['rating']));

ob_start();
$controller->index(new Request(), []);
$indexHtml = (string) ob_get_clean();
$check('index() renders "My Reviews"', str_contains($indexHtml, 'My Reviews'));

ob_start();
$controller->bookReviews(new Request(), ['id' => (string) $freshBookId]);
$bookHtml = (string) ob_get_clean();
$check('bookReviews() renders the book page with its reviews', str_contains($bookHtml, $bookModel->findById($freshBookId)['title']) && str_contains($bookHtml, 'Controller-driven'));

unset($_SERVER['HTTP_X_REQUESTED_WITH']);

// ---------------------------------------------------------------------
// 8. LOGGING: every write leaves an audit trail
// ---------------------------------------------------------------------

echo $section('9. LOGGING: the write audit trail');

$log = is_file($logFile) ? (string) file_get_contents($logFile) : '';
$check('A review create is logged', str_contains($log, 'review.created'));
$check('A review update is logged', str_contains($log, 'review.updated'));
$check('A review delete is logged', str_contains($log, 'review.deleted'));
$check('The log carries the user id', str_contains($log, (string) (int) $riya['id']));
$check('The log carries the book id', str_contains($log, (string) $freshBookId));
$check('The log carries the review id', str_contains($log, '"review_id"'));
$check('The log entries are structured JSON', str_contains($log, '"level":"info"'));

// ---------------------------------------------------------------------
// 10. PHASE 7.2: named CRUD API, book-page integration, single review
// ---------------------------------------------------------------------

echo $section('10. PHASE 7.2: the complete review CRUD');

// A fresh book keeps the stats math predictable for this section.
$phase72BookId = (int) db()->query(
    'SELECT b.id
     FROM books b
     WHERE b.deleted_at IS NULL
       AND NOT EXISTS (SELECT 1 FROM reviews r WHERE r.book_id = b.id)
       AND b.id NOT IN (?, ?)
     LIMIT 1',
    [$bookId, $freshBookId],
)[0]['id'];

// --- 10a. The named service operations (createReview / updateReview /
// ---      deleteReview) run the full store/update/delete pipeline.

$created72 = $service->createReview(ReviewDTO::fromArray(['book_id' => $phase72BookId, 'rating' => 5, 'title' => 'Phase 7.2 create', 'review' => 'A review body that is comfortably long enough for the rules.'], (int) $riya['id']));
$check('createReview() creates a review and returns its id', $created72 > 0);
$stats72 = $repository->ratingStats($phase72BookId);
$check('createReview() syncs the book average to the new rating (5.0)', $stats72['average'] === 5.0);
$check('createReview() syncs the review count to 1', $stats72['count'] === 1);

$service->createReview(ReviewDTO::fromArray(['book_id' => $phase72BookId, 'rating' => 3, 'title' => 'Phase 7.2 second', 'review' => 'A second review body that is comfortably long enough for the rules.'], (int) $arjun['id']));
$stats72 = $repository->ratingStats($phase72BookId);
$check('A second review shifts the average to (5 + 3) / 2 = 4.0', $stats72['average'] === 4.0);

$service->updateReview($created72, ReviewDTO::fromArray(['book_id' => $phase72BookId, 'rating' => 1, 'title' => 'Phase 7.2 update', 'review' => 'An updated body that is comfortably long enough for the rules.'], (int) $riya['id']));
$stats72 = $repository->ratingStats($phase72BookId);
$check('updateReview() recomputes the average ((1 + 3) / 2 = 2.0)', $stats72['average'] === 2.0);
$check('updateReview() sets is_edited on the changed review', (int) $repository->find($created72)['is_edited'] === 1);

$check('deleteReview() returns true', $service->deleteReview($created72) === true);
$stats72 = $repository->ratingStats($phase72BookId);
$check('deleteReview() recomputes the average to the remaining review (3.0)', $stats72['average'] === 3.0);
$check('deleteReview() drops the review count to 1', $stats72['count'] === 1);

// --- 10b. The rule reads powering the book page's form decision.

$check('userHasReviewed() is true for a user who reviewed the book', $service->userHasReviewed((int) $arjun['id'], $phase72BookId));
$check('userHasReviewed() is false for a fresh pair', !$service->userHasReviewed((int) $admin['id'], $phase72BookId));
$check('canUserReview() is false once the user reviewed the book', !$service->canUserReview((int) $arjun['id'], $phase72BookId));
$check('canUserReview() is true for a fresh pair', $service->canUserReview((int) $admin['id'], $phase72BookId));

$check('validateReview() passes a valid review', $service->validateReview($valid) === []);
$check('validateReview() rejects an invalid review', $service->validateReview(['rating' => '0', 'title' => '', 'review' => 'short']) !== []);

// --- 10c. The recalculate methods restore corrupted denormalized
// ---      columns (the single atomic repository statement).

db()->execute('UPDATE books SET average_rating = 9.9, ratings_count = 99 WHERE id = ?', [$phase72BookId]);
$service->recalculateBookRating($phase72BookId);
$stats72 = $repository->ratingStats($phase72BookId);
$check('recalculateBookRating() restores the average (3.0)', $stats72['average'] === 3.0);
$check('recalculateBookRating() also restores the count (1)', $stats72['count'] === 1);

db()->execute('UPDATE books SET ratings_count = 99 WHERE id = ?', [$phase72BookId]);
$service->recalculateReviewCount($phase72BookId);
$check('recalculateReviewCount() restores the review count', $repository->ratingStats($phase72BookId)['count'] === 1);

// --- 10d. userReview + ratingDistribution + the repository aliases.

$myReview72 = $service->userReview((int) $arjun['id'], $phase72BookId);
$check('userReview() returns the user\'s review with the book title', $myReview72 !== null && $myReview72['book_title'] !== '');
$check('userReview() returns null for a user without a review', $service->userReview((int) $admin['id'], $phase72BookId) === null);

$distribution = $repository->ratingDistribution($phase72BookId);
$check('ratingDistribution() counts the approved 3-star review', ($distribution[3] ?? 0) === 1);
$check('ratingDistribution() has no other stars', array_sum($distribution) === 1);

db()->execute('UPDATE reviews SET status = \'pending\' WHERE id = ?', [(int) $myReview72['id']]);
$check('ratingDistribution() ignores non-approved reviews', $repository->ratingDistribution($phase72BookId) === []);
$check('averageRating() ignores non-approved reviews too', $repository->averageRating($phase72BookId) === null);
db()->execute('UPDATE reviews SET status = \'approved\' WHERE id = ?', [(int) $myReview72['id']]);

$insertedId = $repository->insert([
    'book_id' => $phase72BookId,
    'user_id' => (int) $admin['id'],
    'rating'  => 4,
    'title'   => 'Inserted via insert()',
    'review'  => 'A review body that is comfortably long enough for the rules.',
    'status'  => 'approved',
]);
$check('insert() is create() under its CRUD name', $insertedId > 0 && $repository->find($insertedId) !== null);
$check('findByUserAndBook() finds the inserted row', $repository->findByUserAndBook((int) $admin['id'], $phase72BookId) !== null);
$service->delete($insertedId);

// --- 10e. The exact success message + the single-review page.

$_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';
$_POST = ['rating' => '2', 'title' => 'JSON message', 'review' => 'A body written by the controller test that is long enough to pass the minimum.'];
ob_start();
$controller->store(new Request(), ['id' => (string) $phase72BookId]);
$json = (string) ob_get_clean();
unset($_POST);
$decoded = json_decode($json, true);
$check('store() answers the Phase 7.2 success message on the fetch path', ($decoded['message'] ?? '') === 'Review submitted successfully.');

// The fetch store was a real write (session user = riya): clean it
// up so the book-page tests below start from a known state.
$jsonReview = $repository->findByUserAndBook((int) $riya['id'], $phase72BookId);
$service->delete((int) $jsonReview['id']);

// The single-review page as its OWNER: arjun's review, arjun's
// session, so the owner-only Edit action renders.
$session->put('auth_user_id', (int) $arjun['id']);
$session->put('auth_user', ['id' => (int) $arjun['id'], 'full_name' => $arjun['full_name'], 'email' => $arjun['email'], 'role' => $arjun['role']]);
ob_start();
$controller->show(new Request(), ['id' => (string) $myReview72['id']]);
$showHtml = (string) ob_get_clean();
$check('show() renders the single review page', str_contains($showHtml, 'By ') && str_contains($showHtml, 'Phase 7.2 second'));
$check('show() links back to the reviewed book', str_contains($showHtml, '/books/' . $phase72BookId));
$check('show() offers the Edit action to the owner', str_contains($showHtml, '/reviews/' . (int) $myReview72['id'] . '/edit'));

// --- 10f. The book detail page integration (BookController::show).

$bookService = new \BookSphere\App\Services\BookService(new Book(), new \BookSphere\App\Models\Author(), new \BookSphere\App\Models\Category());
$bookController = new \BookSphere\App\Controllers\BookController($bookService, null, $service);

// riya: has NOT reviewed this book -> the write form, no manage
// controls on other users' rows.
$session->put('auth_user_id', (int) $riya['id']);
$session->put('auth_user', ['id' => (int) $riya['id'], 'full_name' => $riya['full_name'], 'email' => $riya['email'], 'role' => $riya['role']]);
ob_start();
$bookController->show(new Request(), ['id' => (string) $phase72BookId]);
$bookHtml = (string) ob_get_clean();
$check('The book page renders the Reviews & Ratings section', str_contains($bookHtml, 'Reviews &amp; Ratings'));
$check('A user without a review sees the Write Review entry point', str_contains($bookHtml, 'Write Review'));
$check('The review form posts to the book reviews route', str_contains($bookHtml, 'action="/books/' . $phase72BookId . '/reviews"'));
$check('The review form carries a CSRF token', str_contains($bookHtml, 'name="_token"'));
$check('The book page lists the approved reviews', str_contains($bookHtml, 'Phase 7.2 second'));
$check('A non-owner viewer gets no review delete controls', !str_contains($bookHtml, 'reviewDeleteModal') && !str_contains($bookHtml, 'data-delete-url'));

// arjun: HAS reviewed this book -> the "already reviewed" panel
// and the delete modal (his own row is manageable).
$session->put('auth_user_id', (int) $arjun['id']);
$session->put('auth_user', ['id' => (int) $arjun['id'], 'full_name' => $arjun['full_name'], 'email' => $arjun['email'], 'role' => $arjun['role']]);
ob_start();
$bookController->show(new Request(), ['id' => (string) $phase72BookId]);
$arjunHtml = (string) ob_get_clean();
$check('A user who reviewed the book sees the already-reviewed message', str_contains($arjunHtml, 'You have already reviewed this book.'));
$check('The already-reviewed panel links to the review and its edit form', str_contains($arjunHtml, '/reviews/' . (int) $myReview72['id']) && str_contains($arjunHtml, '/reviews/' . (int) $myReview72['id'] . '/edit'));
$check('The owner sees the review delete modal on the book page', str_contains($arjunHtml, 'reviewDeleteModal'));
$check('The book page still shows the shared review list', str_contains($arjunHtml, 'Phase 7.2 second'));

// A book with NO reviews at all renders the empty state.
$emptyBookId = (int) db()->query(
    'SELECT b.id
     FROM books b
     WHERE b.deleted_at IS NULL
       AND NOT EXISTS (SELECT 1 FROM reviews r WHERE r.book_id = b.id)
       AND b.id NOT IN (?, ?, ?)
     LIMIT 1',
    [$bookId, $freshBookId, $phase72BookId],
)[0]['id'];
ob_start();
$bookController->show(new Request(), ['id' => (string) $emptyBookId]);
$emptyHtml = (string) ob_get_clean();
$check('A book without reviews shows the empty state', str_contains($emptyHtml, 'No reviews yet'));

// ---------------------------------------------------------------------
// 11. PHASE 7.3: star-rating component, breakdown bars, analytics
// ---------------------------------------------------------------------

echo $section('11. PHASE 7.3: the interactive star rating & analytics');

// --- 11a. The reusable star-rating component (display mode).

$captureRating = function (array $starRating): string {
    ob_start();
    require root_path('app/Views/components/star-rating.php');
    return (string) ob_get_clean();
};

$displayHtml = $captureRating(['rating' => 4.5, 'count' => 12, 'size' => 'md', 'tooltip' => false]);
$check('The component renders five stars in display mode', substr_count($displayHtml, '<i class="') === 5);
$check('A 4.5 rating renders four filled stars and one half star', substr_count($displayHtml, 'fa-star is-filled') === 4 && substr_count($displayHtml, 'fa-star-half-stroke is-half') === 1);
$check('The numeric value renders next to the stars', str_contains($displayHtml, 'star-rating-value') && str_contains($displayHtml, '4.5'));
$check('The review count renders ("Based on 12 reviews")', str_contains($displayHtml, 'Based on 12 reviews'));
$check('A zero count hides the count suffix', !str_contains($captureRating(['rating' => 3.0, 'count' => null, 'tooltip' => false]), 'Based on'));

// --- 11b. The interactive input mode (form usage).

$inputHtml = $captureRating(['rating' => 3, 'readOnly' => false, 'name' => 'rating', 'label' => 'Your rating']);
$check('Input mode renders a five-star radio group', substr_count($inputHtml, 'role="radio"') === 5);
$check('A pre-selected rating of 3 checks three radios', substr_count($inputHtml, 'aria-checked="true"') === 3);
$check('The roving tabindex leaves exactly one tab stop', substr_count($inputHtml, 'tabindex="0"') === 1);
$check('The hidden input carries the star value for submission', str_contains($inputHtml, 'name="rating"') && str_contains($inputHtml, 'value="3"') && str_contains($inputHtml, 'data-star-value'));
$check('The live preview announces the current selection', str_contains($inputHtml, 'aria-live="polite"') && str_contains($inputHtml, 'You selected ★★★☆☆ 3 Stars'));
$check('The input keeps a no-JavaScript fallback value', str_contains($captureRating(['rating' => 0, 'readOnly' => false]), 'Select a rating to continue'));

// --- 11c. ratingBreakdown(): the display-ready distribution rows.

$phase73BookId = (int) db()->query(
    'SELECT b.id
     FROM books b
     WHERE b.deleted_at IS NULL
       AND NOT EXISTS (SELECT 1 FROM reviews r WHERE r.book_id = b.id)
       AND b.id NOT IN (?, ?, ?, ?)
     LIMIT 1',
    [$bookId, $freshBookId, $phase72BookId, $emptyBookId],
)[0]['id'];

$service->createReview(ReviewDTO::fromArray(['book_id' => $phase73BookId, 'rating' => 5, 'title' => 'P73 five', 'review' => 'A Phase 7.3 review body that is comfortably long enough for the rules.'], (int) $riya['id']));
$service->createReview(ReviewDTO::fromArray(['book_id' => $phase73BookId, 'rating' => 3, 'title' => 'P73 three', 'review' => 'A Phase 7.3 review body that is comfortably long enough for the rules.'], (int) $arjun['id']));

$breakdown73 = $service->ratingBreakdown($phase73BookId);
$check('ratingBreakdown() returns one row per star, 5 down to 1', array_column($breakdown73, 'stars') === [5, 4, 3, 2, 1]);
$check('ratingBreakdown() totals the approved reviews', (int) $breakdown73[0]['total'] === 2);
$check('ratingBreakdown() counts each star correctly', array_column($breakdown73, 'count') === [1, 0, 1, 0, 0]);
$percentSum = array_sum(array_column($breakdown73, 'percent'));
$check('The percentages sum to ~100', $percentSum >= 90 && $percentSum <= 100);

$emptyBreakdown = $service->ratingBreakdown((int) $emptyBookId);
$check('An unrated book yields a zeroed breakdown', array_sum(array_column($emptyBreakdown, 'percent')) === 0 && array_sum(array_column($emptyBreakdown, 'count')) === 0);

// --- 11d. The analytics aggregations.

$highest = $service->highestRatedBooks(5);
$check('highestRatedBooks() orders by average DESC', count($highest) >= 2 && (float) $highest[0]['average'] >= (float) $highest[1]['average']);
$check('The highest-rated rows carry average + count', isset($highest[0]['average'], $highest[0]['count'], $highest[0]['id'], $highest[0]['title']));

$lowest = $service->lowestRatedBooks(5);
$check('lowestRatedBooks() orders by average ASC', count($lowest) >= 2 && (float) $lowest[0]['average'] <= (float) $lowest[1]['average']);
$check('Books without ratings exclude the rated ones', in_array($emptyBookId, array_map('intval', array_column($service->booksWithoutRatings(10), 'id')), true) && !in_array($phase73BookId, array_map('intval', array_column($service->booksWithoutRatings(10), 'id')), true));

$categories = $service->categoryAverage();
$check('categoryAverage() rows carry name + average', $categories !== [] && isset($categories[0]['name'], $categories[0]['average']));

$overall = $service->overallAverage();
$check('overallAverage() sits on the 1-5 scale (or null)', $overall === null || ((float) $overall >= 1.0 && (float) $overall <= 5.0));

$distribution73 = $service->overallDistribution();
$totalApproved = (int) db()->query("SELECT COUNT(*) AS c FROM reviews WHERE status = 'approved'")[0]['c'];
$check('overallDistribution() covers stars 5 down to 1', array_keys($distribution73) === [5, 4, 3, 2, 1]);
$check('overallDistribution() sums to the approved review total', array_sum($distribution73) === $totalApproved);

$profile = $service->profileStats((int) $riya['id']);
$check('profileStats() counts the user\'s approved reviews', (int) $profile['count'] >= 1);
$check('profileStats() reports the average rating given', $profile['average'] === null || ((float) $profile['average'] >= 1.0 && (float) $profile['average'] <= 5.0));
$check('profileStats() resolves the highest-rated book title', is_string($profile['highest']) && $profile['highest'] !== '');
$check('profileStats() resolves the latest rating', is_array($profile['latest']) && isset($profile['latest']['title'], $profile['latest']['rating']));

$analytics = $service->adminAnalytics();
$check('adminAnalytics() carries all six blocks', isset($analytics['overallAverage'], $analytics['distribution'], $analytics['highestRated'], $analytics['lowestRated'], $analytics['booksWithoutRatings'], $analytics['categoryAverage']));

// --- 11e. The live render: distribution bars on the book pages.

// The ADMIN has NOT reviewed the phase73 book, so the page shows the
// full review section with the write form AND the breakdown (2
// approved reviews: 5 + 3 -> 50% each).
$session->put('auth_user_id', (int) $admin['id']);
$session->put('auth_user', ['id' => (int) $admin['id'], 'full_name' => $admin['full_name'], 'email' => $admin['email'], 'role' => $admin['role']]);
ob_start();
$bookController->show(new Request(), ['id' => (string) $phase73BookId]);
$bookHtml73 = (string) ob_get_clean();
$check('The book page renders the rating distribution panel', str_contains($bookHtml73, 'data-rating-distribution'));
$check('The distribution bars carry the animation target width', str_contains($bookHtml73, 'data-bar-percent="50"'));
$check('The book page summary shows the review count', str_contains($bookHtml73, 'Based on 2 reviews'));
$check('The review form uses the interactive star input', str_contains($bookHtml73, 'data-star-input') && str_contains($bookHtml73, 'role="radiogroup"'));
// Phase 7.3 removed the rating <select> in favour of the star input;
// Phase 7.4 adds the review toolbar, so <select> elements legitimately
// exist again (sort + per-page) - but never one named "rating".
$check('The review form no longer renders a rating <select>', !str_contains($bookHtml73, '<select name="rating"'));

// The standalone /books/{id}/reviews page shows the same panel.
ob_start();
$controller->bookReviews(new Request(), ['id' => (string) $phase73BookId]);
$bookReviewsHtml = (string) ob_get_clean();
$check('The /books/{id}/reviews page renders the distribution', str_contains($bookReviewsHtml, 'data-rating-distribution') && str_contains($bookReviewsHtml, 'data-bar-percent'));

// --- 11f. The dashboard / admin / profile surfaces.

$dashboardController = new \BookSphere\App\Controllers\DashboardController($service);
$session->put('auth_user_id', (int) $riya['id']);
$session->put('auth_user', ['id' => (int) $riya['id'], 'full_name' => $riya['full_name'], 'email' => $riya['email'], 'role' => $riya['role']]);
ob_start();
$dashboardController->index(new Request());
$dashboardHtml = (string) ob_get_clean();
$check('The dashboard renders the real Top Rated Books shelf', str_contains($dashboardHtml, 'Top Rated Books'));
$check('The top-rated shelf lists a genuinely reviewed book', str_contains($dashboardHtml, 'The Martian') || str_contains($dashboardHtml, '1984'));
$check('The top-rated cards render the star component', substr_count($dashboardHtml, 'star-rating-visual') >= 1);

$adminController = new \BookSphere\App\Controllers\AdminController(null, $service);
$session->put('auth_user_id', (int) $admin['id']);
$session->put('auth_user', ['id' => (int) $admin['id'], 'full_name' => $admin['full_name'], 'email' => $admin['email'], 'role' => $admin['role']]);
ob_start();
$adminController->index(new Request());
$adminHtml = (string) ob_get_clean();
$check('The admin page renders the Rating Analytics block', str_contains($adminHtml, 'Rating Analytics'));
$check('The admin distribution bars animate from the same data', str_contains($adminHtml, 'data-bar-percent'));
$check('The admin page lists the books without ratings', str_contains($adminHtml, 'Without ratings'));

$userController = new \BookSphere\App\Controllers\UserController($auth, $users, $service);
$session->put('auth_user_id', (int) $riya['id']);
$session->put('auth_user', ['id' => (int) $riya['id'], 'full_name' => $riya['full_name'], 'email' => $riya['email'], 'role' => $riya['role']]);
ob_start();
$userController->show(new Request());
$profileHtml = (string) ob_get_clean();
$check('The profile page renders the rating activity block', str_contains($profileHtml, 'My rating activity'));
$check('The profile shows the user\'s review count', str_contains($profileHtml, 'Reviews written'));

// ---------------------------------------------------------------------
// 12. PHASE 7.4: professional review lists - pagination, sort, search,
//     filters, statistics, the presenter, the controller pages and the
//     shared components
// ---------------------------------------------------------------------

echo $section('12. PHASE 7.4: professional review lists (search, sort, filters, pagination, statistics)');

// --- 12a. A controlled dataset: one fresh book, six reviewers, one
// review each with known ratings, dates and edited flags.

$p74BookId = (int) db()->query(
    'SELECT b.id
     FROM books b
     WHERE b.deleted_at IS NULL
       AND NOT EXISTS (SELECT 1 FROM reviews r WHERE r.book_id = b.id)
     LIMIT 1',
)[0]['id'];

$p74Users = [];
foreach ([
    ['Uma Rani', 'uma74@test.dev'],
    ['Vikram Rao', 'vikram74@test.dev'],
    ['Zoya Khan', 'zoya74@test.dev'],
    ['Ishaan Mehta', 'ishaan74@test.dev'],
    ['Aisha Rao', 'aisha74@test.dev'],
    ['Dev Sharma', 'dev74@test.dev'],
] as [$name, $email]) {
    db()->execute(
        'INSERT INTO users (full_name, email, password, role, created_at, updated_at)
         VALUES (?, ?, ?, \'user\', \'2026-01-01T00:00:00Z\', \'2026-01-01T00:00:00Z\')',
        [$name, $email, password_hash('Test@123', PASSWORD_DEFAULT)],
    );
    $p74Users[] = (int) db()->lastInsertId();
}

// [user, rating, title, body, is_edited, created_at]
$p74Reviews = [
    [$p74Users[0], 5, 'Quantum delight', 'The quantum mechanics chapter is a revelation - every page felt essential and the prose stayed sharp throughout the entire reading experience.', 0, '2026-01-15T10:00:00Z'],
    [$p74Users[1], 4, 'Very good, not great', 'A solid read about quantum history with some pacing issues in the middle chapters.', 1, '2026-02-10T10:00:00Z'],
    [$p74Users[2], 3, 'Middle of the road', 'Quantum concepts are explained clearly but the story drags towards the end.', 0, '2026-03-05T10:00:00Z'],
    [$p74Users[3], 2, 'Disappointing', 'I expected more from a book that promises quantum physics for everyone.', 0, '2026-04-01T10:00:00Z'],
    [$p74Users[4], 5, 'Masterpiece', 'Uma recommended this one and she was right - the quantum sections are superb.', 1, '2026-05-20T10:00:00Z'],
    [$p74Users[5], 1, 'Not for me', 'Quantum jargon overload from the very first chapter.', 0, '2026-06-30T10:00:00Z'],
];

foreach ($p74Reviews as [$userId, $rating, $title, $body, $edited, $date]) {
    db()->execute(
        'INSERT INTO reviews (book_id, user_id, rating, title, review, status, is_edited, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, \'approved\', ?, ?, ?)',
        [$p74BookId, $userId, $rating, $title, $body, $edited, $date, $date],
    );
}

$check('The Phase 7.4 dataset seeds six approved reviews on a fresh book', (int) db()->query('SELECT COUNT(*) AS c FROM reviews WHERE book_id = ?', [$p74BookId])[0]['c'] === 6);

// --- 12b. The paginated list (one COUNT + one SELECT, same WHERE).

$p74Page1 = $repository->paginate(['book_id' => $p74BookId], 2, 1);
$check('paginate() reports the true total', $p74Page1['total'] === 6);
$check('paginate() computes the page count', $p74Page1['pages'] === 3);
$check('paginate() returns the requested window size', count($p74Page1['items']) === 2);
$check('The default sort is newest first', $p74Page1['items'][0]['title'] === 'Not for me');

$p74Page2 = $repository->paginate(['book_id' => $p74BookId], 2, 2);
$check('Page two continues the timeline', $p74Page2['page'] === 2 && $p74Page2['items'][0]['title'] === 'Disappointing');

$p74Page99 = $repository->paginate(['book_id' => $p74BookId], 2, 99);
$check('An out-of-range page clamps to the last page', $p74Page99['page'] === 3);

$p74All = $repository->paginate(['book_id' => $p74BookId], 50, 1);
$check('A large page size returns everything on one page', $p74All['pages'] === 1 && count($p74All['items']) === 6);

// --- 12c. The sort allowlist (newest / oldest / highest / lowest /
// relevant, unknown keys fall back to newest).

$p74Oldest = $repository->paginate(['book_id' => $p74BookId, 'sort' => 'oldest'], 50, 1);
$check('The oldest-first sort works', $p74Oldest['items'][0]['title'] === 'Quantum delight');

$p74Highest = $repository->paginate(['book_id' => $p74BookId, 'sort' => 'highest'], 50, 1);
$check('The highest-rated sort works (newest wins the ties)', $p74Highest['items'][0]['title'] === 'Masterpiece' && (int) $p74Highest['items'][0]['rating'] === 5);

$p74Lowest = $repository->paginate(['book_id' => $p74BookId, 'sort' => 'lowest'], 50, 1);
$check('The lowest-rated sort works', (int) $p74Lowest['items'][0]['rating'] === 1);

$p74Relevant = $repository->paginate(['book_id' => $p74BookId, 'sort' => 'relevant'], 50, 1);
$check('The relevant sort matches the highest-rated order', $p74Relevant['items'][0]['title'] === $p74Highest['items'][0]['title']);

$p74Bogus = $repository->paginate(['book_id' => $p74BookId, 'sort' => 'x; DROP TABLE reviews; --'], 50, 1);
$check('An unknown sort falls back to newest and cannot inject SQL', $p74Bogus['items'][0]['title'] === 'Not for me' && $p74Bogus['total'] === 6);

// --- 12d. The filters (rating, edited, user scope) and their combo.

$p74Five = $repository->paginate(['book_id' => $p74BookId, 'rating' => 5], 50, 1);
$check('The rating filter works', $p74Five['total'] === 2);

$p74Edited = $repository->paginate(['book_id' => $p74BookId, 'edited' => true], 50, 1);
$check('The edited-only filter works', $p74Edited['total'] === 2);

$p74Combo = $repository->paginate(['book_id' => $p74BookId, 'rating' => 5, 'edited' => true], 50, 1);
$check('The filters combine (5 stars AND edited)', $p74Combo['total'] === 1 && $p74Combo['items'][0]['title'] === 'Masterpiece');

$p74Mine = $repository->paginate(['book_id' => $p74BookId, 'user_id' => $p74Users[0]], 50, 1);
$check('The user scope works', $p74Mine['total'] === 1 && $p74Mine['items'][0]['title'] === 'Quantum delight');

// --- 12e. The server-side search (title / body / reviewer name).

$p74QAll = $repository->search('quantum', ['book_id' => $p74BookId], 50, 1);
$check('The keyword matches the review bodies', $p74QAll['total'] === 6);

$p74QTitle = $repository->search('disappointing', ['book_id' => $p74BookId], 50, 1);
$check('The keyword matches the review titles', $p74QTitle['total'] === 1 && $p74QTitle['items'][0]['title'] === 'Disappointing');

$p74QName = $repository->search('mehta', ['book_id' => $p74BookId], 50, 1);
$check('The keyword matches the reviewer name through the join', $p74QName['total'] === 1 && $p74QName['items'][0]['user_name'] === 'Ishaan Mehta');

$p74QCase = $repository->search('QUANTUM', ['book_id' => $p74BookId], 50, 1);
$check('The keyword match is case-insensitive', $p74QCase['total'] === 6);

$p74QNone = $repository->search('zzz-no-such-review', ['book_id' => $p74BookId], 50, 1);
$check('A keyword without matches returns an empty page', $p74QNone['total'] === 0 && $p74QNone['items'] === []);

$p74QFiltered = $repository->search('quantum', ['book_id' => $p74BookId, 'rating' => 5], 50, 1);
$check('The search combines with the filters', $p74QFiltered['total'] === 2);

// --- 12f. The statistics (aggregate + distribution, same WHERE).

$p74Stats = $repository->statistics(['book_id' => $p74BookId]);
$check('statistics() reports the total', $p74Stats['total'] === 6);
$check('statistics() computes the true average', abs((float) $p74Stats['average'] - (20 / 6)) < 0.001);
$check('statistics() resolves the highest and lowest ratings', $p74Stats['highest'] === 5 && $p74Stats['lowest'] === 1);
$check('statistics() resolves the latest review date', $p74Stats['latest'] === '2026-06-30T10:00:00Z');
$check('statistics() counts every star of the distribution', $p74Stats['distribution'] === [5 => 2, 4 => 1, 3 => 1, 2 => 1, 1 => 1]);

$p74StatsUser = $repository->statistics(['book_id' => $p74BookId, 'user_id' => $p74Users[0]]);
$check('The statistics honour the user scope', $p74StatsUser['total'] === 1 && (float) $p74StatsUser['average'] === 5.0);

$p74StatsRating = $repository->statistics(['book_id' => $p74BookId, 'rating' => 5]);
$check('The statistics honour the rating filter', $p74StatsRating['total'] === 2 && (float) $p74StatsRating['average'] === 5.0);

// --- 12g. The service layer (normalization gate + delegations).

$check('sortReviews() returns the allowed keys unchanged', $service->sortReviews('oldest') === 'oldest' && $service->sortReviews('relevant') === 'relevant');
$check('sortReviews() falls back for unknown keys', $service->sortReviews('random') === 'newest');

$p74Defaults = $service->normalizeListOptions([]);
$check('normalizeListOptions() applies the safe defaults', $p74Defaults['sort'] === 'newest' && $p74Defaults['perPage'] === 10 && $p74Defaults['page'] === 1 && $p74Defaults['rating'] === 0 && $p74Defaults['edited'] === false && $p74Defaults['mine'] === false && $p74Defaults['q'] === '');

$p74Normalized = $service->normalizeListOptions(['sort' => 'lowest', 'perPage' => '20', 'page' => '3', 'rating' => '4', 'edited' => '1', 'mine' => '1', 'q' => '  quantum  ']);
$check('normalizeListOptions() casts and trims the input', $p74Normalized['sort'] === 'lowest' && $p74Normalized['perPage'] === 20 && $p74Normalized['page'] === 3 && $p74Normalized['rating'] === 4 && $p74Normalized['edited'] === true && $p74Normalized['mine'] === true && $p74Normalized['q'] === 'quantum');

$p74Junk = $service->normalizeListOptions(['sort' => 'x', 'per_page' => '15', 'page' => '0', 'rating' => '9']);
$check('Invalid values become safe defaults', $p74Junk['sort'] === 'newest' && $p74Junk['perPage'] === 10 && $p74Junk['page'] === 1 && $p74Junk['rating'] === 0);

$p74SvcPage = $service->paginateReviews(['book_id' => $p74BookId], 2, 2);
$check('paginateReviews() delegates the pagination', $p74SvcPage['total'] === 6 && $p74SvcPage['page'] === 2 && $p74SvcPage['pages'] === 3);

$p74SvcSearch = $service->searchReviews('mehta', ['book_id' => $p74BookId], 50, 1);
$check('searchReviews() matches the reviewer name', $p74SvcSearch['total'] === 1 && $p74SvcSearch['items'][0]['user_name'] === 'Ishaan Mehta');

$p74SvcUser = $service->userReviews($p74Users[4], [], 50, 1);
$check('userReviews() returns one reviewer\'s rows', $p74SvcUser['total'] === 1 && $p74SvcUser['items'][0]['title'] === 'Masterpiece');

$p74Top = $service->highestRatedReviews(3);
$check('highestRatedReviews() lists the 5-star reviews first', count($p74Top) === 3 && (int) $p74Top[0]['rating'] === 5);

$p74Breakdown = $service->distributionBreakdown($p74Stats['distribution']);
$check('distributionBreakdown() fills every star row', count($p74Breakdown) === 5 && $p74Breakdown[0]['stars'] === 5 && $p74Breakdown[4]['stars'] === 1);
$check('distributionBreakdown() computes the percentages', $p74Breakdown[0]['count'] === 2 && $p74Breakdown[0]['percent'] === 33 && $p74Breakdown[0]['total'] === 6);

// --- 12h. The presenter (state / toolbar / pagination payloads).

$presenter = new \BookSphere\App\Presenters\ReviewListPresenter($service);

$_GET = ['sort' => 'oldest', 'per_page' => '20', 'page' => '2', 'q' => 'quantum', 'rating' => '5', 'edited' => '1'];
$p74State = $presenter->state(new Request());
$check('The presenter normalizes the request state', $p74State['sort'] === 'oldest' && $p74State['perPage'] === 20 && $p74State['page'] === 2 && $p74State['rating'] === 5 && $p74State['edited'] === true && $p74State['q'] === 'quantum');

$p74Toolbar = $presenter->toolbar($p74State, '/reviews/search', ['showMine' => true]);
$check('The presenter builds the toolbar payload', $p74Toolbar['base'] === '/reviews/search' && $p74Toolbar['showMine'] === true && $p74Toolbar['sorts'] === \BookSphere\App\Services\ReviewService::SORT_OPTIONS && $p74Toolbar['perPages'] === \BookSphere\App\Services\ReviewService::PER_PAGE_OPTIONS);

$p74Pagination = $presenter->pagination($p74State, $service->searchReviews('quantum', $p74State, 20, 1), '/reviews/search');
$check('The presenter builds the pagination payload', $p74Pagination['total'] === 1 && $p74Pagination['pages'] === 1 && $p74Pagination['perPage'] === 20);
$check('The pager preserves the toolbar state without the page', isset($p74Pagination['params']['sort'], $p74Pagination['params']['q'], $p74Pagination['params']['rating'], $p74Pagination['params']['edited']) && !isset($p74Pagination['params']['page']));

$_GET = [];
$p74CleanParams = $presenter->pagination($service->normalizeListOptions(['q' => 'x']), ['page' => 1, 'pages' => 1, 'total' => 3, 'perPage' => 10], '/reviews');
$check('The pager drops empty params from the query string', !isset($p74CleanParams['params']['rating']));

// --- 12i. The controller pages render the professional lists.

$reviewController = new \BookSphere\App\Controllers\ReviewController($service, $policy, $presenter);
$session->put('auth_user_id', (int) $riya['id']);
$session->put('auth_user', ['id' => (int) $riya['id'], 'full_name' => $riya['full_name'], 'email' => $riya['email'], 'role' => $riya['role']]);

$_GET = ['sort' => 'oldest', 'per_page' => '20'];
ob_start();
$reviewController->index(new Request());
$myReviewsHtml = (string) ob_get_clean();
$check('"My Reviews" renders the stats panel', str_contains($myReviewsHtml, 'My Reviews') && str_contains($myReviewsHtml, 'Total reviews'));
$check('"My Reviews" renders the toolbar', str_contains($myReviewsHtml, 'data-review-toolbar') && str_contains($myReviewsHtml, 'Oldest first'));
$check('"My Reviews" renders the timeline cards with the owner actions', str_contains($myReviewsHtml, 'data-review-card') && str_contains($myReviewsHtml, 'data-delete-url'));
$check('"My Reviews" renders the pagination line', str_contains($myReviewsHtml, 'Showing'));

$_GET = ['q' => 'quantum', 'rating' => '5'];
ob_start();
$reviewController->search(new Request());
$searchHtml = (string) ob_get_clean();
$check('The search page applies the keyword and the filter', str_contains($searchHtml, 'Review Search') && str_contains($searchHtml, 'Quantum delight') && str_contains($searchHtml, 'Masterpiece'));
$check('The search page carries the active filter into the toolbar', str_contains($searchHtml, 'name="rating" value="5"'));
$check('The search page renders the results line', str_contains($searchHtml, 'Showing 1&ndash;2 of 2 reviews'));

$_GET = ['q' => 'quantum', 'mine' => '1'];
ob_start();
$reviewController->search(new Request());
$mySearchHtml = (string) ob_get_clean();
$check('The "my reviews only" search scopes to the signed-in user', str_contains($mySearchHtml, 'No reviews match your search') && str_contains($mySearchHtml, 'Clear search'));

$_GET = [];
ob_start();
$reviewController->search(new Request());
$timelineHtml = (string) ob_get_clean();
$check('The empty query doubles as the community timeline', str_contains($timelineHtml, 'Review Search') && str_contains($timelineHtml, 'approved reviews'));

ob_start();
$reviewController->statistics(new Request());
$statsHtml = (string) ob_get_clean();
$check('The statistics page renders the platform tiles', str_contains($statsHtml, 'Review Statistics') && str_contains($statsHtml, 'Total reviews'));
$check('The statistics page renders the signed-in user\'s activity', str_contains($statsHtml, 'My Review Activity'));
$check('The statistics page renders the community shelves', str_contains($statsHtml, 'Highest Rated Reviews') && str_contains($statsHtml, 'Latest Reviews'));

ob_start();
$reviewController->userReviews(new Request(), ['id' => (string) $p74Users[0]]);
$userReviewsHtml = (string) ob_get_clean();
$check('The per-user page shows the reviewer and their reviews', str_contains($userReviewsHtml, 'Uma Rani') && str_contains($userReviewsHtml, 'Quantum delight'));
$check('The per-user page paginates the reviewer\'s reviews', str_contains($userReviewsHtml, 'Showing 1&ndash;1 of 1 review'));

ob_start();
$reviewController->bookReviews(new Request(), ['id' => (string) $p74BookId]);
$p74BookHtml = (string) ob_get_clean();
$check('The /books/{id}/reviews page lists the six approved reviews', str_contains($p74BookHtml, 'Based on 6 reviews') && substr_count($p74BookHtml, 'data-review-card') === 6);
$check('The book reviews page carries the toolbar', str_contains($p74BookHtml, 'data-review-toolbar'));
$_GET = [];

// --- 12j. The shared components (card, toolbar, empty states,
// skeleton, search box, stats panel).

$p74Card = [
    'id'         => 101,
    'user_id'    => $p74Users[1],
    'user_name'  => 'Vikram Rao',
    'book_id'    => $p74BookId,
    'book_title' => 'Test Book',
    'rating'     => 4,
    'title'      => 'Very good',
    'review'     => 'A solid read about quantum history with some pacing issues in the middle chapters.',
    'is_edited'  => 1,
    'created_at' => '2026-02-10T10:00:00Z',
    'status'     => 'approved',
];

$review  = $p74Card;
$manage  = false;
$compact = false;
ob_start();
require root_path('app/Views/components/review-card.php');
$cardHtml = (string) ob_get_clean();
$check('The review card links the avatar to the reviewer', str_contains($cardHtml, '/reviews/user/' . $p74Users[1]) && str_contains($cardHtml, 'VR'));
$check('The review card shows the Edited badge', str_contains($cardHtml, 'Edited'));
$check('The review card keeps the read-more body', str_contains($cardHtml, 'data-review-body'));
$check('The review card renders the live Helpful and Report actions for a signed-in reader', str_contains($cardHtml, 'Helpful') && str_contains($cardHtml, 'Report') && str_contains($cardHtml, 'data-helpful-form') && str_contains($cardHtml, 'data-report-id'));
$check('The review card hides the owner actions for a reader', !str_contains($cardHtml, 'data-delete-url'));

$review  = $p74Card;
$manage  = true;
$compact = false;
ob_start();
require root_path('app/Views/components/review-card.php');
$cardHtml = (string) ob_get_clean();
$check('The owner sees the Edit / Delete actions', str_contains($cardHtml, '/reviews/101/edit') && str_contains($cardHtml, 'data-delete-url="/reviews/101/delete"'));

$review  = $p74Card;
$manage  = false;
$compact = true;
ob_start();
require root_path('app/Views/components/review-card.php');
$compactHtml = (string) ob_get_clean();
$check('The compact card keeps the dashboard look', str_contains($compactHtml, 'review-card-time') && str_contains($compactHtml, 'star-row'));

$toolbar = $presenter->toolbar($service->normalizeListOptions(['sort' => 'lowest', 'per_page' => '50', 'q' => 'quantum', 'rating' => '3']), '/reviews');
ob_start();
require root_path('app/Views/reviews/partials/_toolbar.php');
$toolbarHtml = (string) ob_get_clean();
$check('The toolbar renders one form with the selects and the active filter', str_contains($toolbarHtml, 'data-review-toolbar') && str_contains($toolbarHtml, 'Lowest rated') && str_contains($toolbarHtml, 'name="rating" value="3"'));
$check('The toolbar renders the filter chips', str_contains($toolbarHtml, '5★') && str_contains($toolbarHtml, 'review-filters'));

ob_start();
$toolbar = $presenter->toolbar($service->normalizeListOptions(['q' => 'nope']), '/reviews/search');
$emptyBase = ['title' => 'No reviews yet', 'message' => '', 'action' => null];
require root_path('app/Views/reviews/partials/_empty.php');
$emptySearchHtml = (string) ob_get_clean();
$check('The search empty state offers to clear the search', str_contains($emptySearchHtml, 'No reviews match your search') && str_contains($emptySearchHtml, 'Clear search'));

ob_start();
$toolbar = $presenter->toolbar($service->normalizeListOptions(['rating' => '5', 'edited' => '1']), '/reviews');
require root_path('app/Views/reviews/partials/_empty.php');
$emptyFilterHtml = (string) ob_get_clean();
$check('The filter empty state offers to reset the filters', str_contains($emptyFilterHtml, 'No reviews match your filters') && str_contains($emptyFilterHtml, 'Reset filters'));

ob_start();
$toolbar = null;
require root_path('app/Views/reviews/partials/_empty.php');
$emptyBaseHtml = (string) ob_get_clean();
$check('The base empty state falls back to the page copy', str_contains($emptyBaseHtml, 'No reviews yet') && str_contains($emptyBaseHtml, 'empty-state--review'));

ob_start();
$skeletons = ['count' => 3];
require root_path('app/Views/components/loading-skeleton.php');
$skeletonHtml = (string) ob_get_clean();
$check('The loading skeleton renders the requested number of cards', substr_count($skeletonHtml, 'review-skeleton-card') === 3);

ob_start();
$search = ['q' => 'quantum', 'name' => 'q'];
require root_path('app/Views/components/review-search.php');
$searchBoxHtml = (string) ob_get_clean();
$check('The search box carries the keyword into the toolbar', str_contains($searchBoxHtml, 'name="q"') && str_contains($searchBoxHtml, 'value="quantum"'));

$p74Stats['breakdown'] = $p74Breakdown;
ob_start();
$stats = $p74Stats;
require root_path('app/Views/components/review-stats.php');
$statsPanelHtml = (string) ob_get_clean();
$check('The stats panel renders the five truthful tiles', str_contains($statsPanelHtml, 'Total reviews') && str_contains($statsPanelHtml, 'Average rating') && str_contains($statsPanelHtml, 'Highest rating') && str_contains($statsPanelHtml, 'Lowest rating') && str_contains($statsPanelHtml, 'Latest review'));
$check('The stats panel renders the distribution bars', str_contains($statsPanelHtml, 'data-rating-distribution'));

// ---------------------------------------------------------------------
// 13. PHASE 7.5: helpful votes, reports, community stats, moderation
// ---------------------------------------------------------------------

echo $section('13. PHASE 7.5: engagement (votes, reports, reputation)');

// --- 13a. Schema: migration 0015 created both engagement tables
// with their unique constraints, CHECK enums and lookup indexes.

$p75VoteColumns = array_column(db()->query('PRAGMA table_info(review_helpful_votes)'), 'name');
$check('The review_helpful_votes table exists', in_array('review_id', $p75VoteColumns, true) && in_array('user_id', $p75VoteColumns, true));

$p75VoteIndexes = array_column(db()->query('PRAGMA index_list(review_helpful_votes)'), 'name');
$check('The one-vote-per-user unique index exists', in_array('idx_review_helpful_votes_unique', $p75VoteIndexes, true));
$check('The vote lookup index on the review exists', in_array('idx_review_helpful_votes_review', $p75VoteIndexes, true));

$p75ReportColumns = array_column(db()->query('PRAGMA table_info(review_reports)'), 'name');
$check('The review_reports table exists', in_array('reason', $p75ReportColumns, true) && in_array('status', $p75ReportColumns, true) && in_array('description', $p75ReportColumns, true));

$p75ReportIndexes = array_column(db()->query('PRAGMA index_list(review_reports)'), 'name');
$check('The report lookup indexes exist', in_array('idx_review_reports_status', $p75ReportIndexes, true) && in_array('idx_review_reports_review', $p75ReportIndexes, true) && in_array('idx_review_reports_reported_by', $p75ReportIndexes, true));

$p75BadReason = false;
try {
    db()->execute(
        'INSERT INTO review_reports (review_id, reported_by, reason, description, status, created_at, updated_at)
         VALUES (?, ?, \'Not a reason\', \'\', \'pending\', \'2026-01-01T00:00:00Z\', \'2026-01-01T00:00:00Z\')',
        [1, 1],
    );
} catch (\Throwable) {
    $p75BadReason = true;
}
$check('The database rejects an unknown report reason', $p75BadReason);

$p75BadStatus = false;
try {
    db()->execute(
        'INSERT INTO review_reports (review_id, reported_by, reason, description, status, created_at, updated_at)
         VALUES (?, ?, \'Spam\', \'\', \'banana\', \'2026-01-01T00:00:00Z\', \'2026-01-01T00:00:00Z\')',
        [1, 1],
    );
} catch (\Throwable) {
    $p75BadStatus = true;
}
$check('The database rejects an unknown report status', $p75BadStatus);

// --- 13b. Repository: votes (idempotent insert, remove, counts).

$p75Review = $repository->findByBook($p74BookId, 1)[0];
$p75ReviewId = (int) $p75Review['id'];
$p75Reviewer = (int) $p75Review['user_id'];
$p75Voter    = (int) $riya['id'];

$check('The list reads carry the truthful helpful_count', (int) $p75Review['helpful_count'] === 0);

$repository->addHelpfulVote($p75ReviewId, $p75Voter);
$check('addHelpfulVote() records the vote', $repository->helpfulCount($p75ReviewId) === 1);
$check('userHasHelpfulVote() sees the voter', $repository->userHasHelpfulVote($p75ReviewId, $p75Voter));
$check('userHasHelpfulVote() stays false for a non-voter', !$repository->userHasHelpfulVote($p75ReviewId, (int) $arjun['id']));

$repository->addHelpfulVote($p75ReviewId, $p75Voter);
$check('A repeated vote cannot double-count', $repository->helpfulCount($p75ReviewId) === 1);

$repository->addHelpfulVote($p75ReviewId, (int) $arjun['id']);
$check('A second user votes too', $repository->helpfulCount($p75ReviewId) === 2);

$repository->removeHelpfulVote($p75ReviewId, $p75Voter);
$check('removeHelpfulVote() removes only the own vote', $repository->helpfulCount($p75ReviewId) === 1 && !$repository->userHasHelpfulVote($p75ReviewId, $p75Voter));
$check('The remaining vote belongs to the second user', $repository->userHasHelpfulVote($p75ReviewId, (int) $arjun['id']));

$repository->removeHelpfulVote($p75ReviewId, (int) $arjun['id']);
$check('The list read reflects the removed votes', (int) $repository->findByBook($p74BookId, 1)[0]['helpful_count'] === 0);

// --- 13c. Repository: reports (create with pending default, lookup,
// status transition, the queue reads, statistics, hidden reviews).

$p75ReportId = $repository->createReport([
    'review_id'   => $p75ReviewId,
    'reported_by' => $p75Voter,
    'reason'      => 'Spam',
    'description' => 'This review contains advertisement links.',
]);
$check('createReport() returns the new report id', $p75ReportId > 0);
$check('A report starts with the pending status', ($repository->findReport($p75ReportId)['status'] ?? '') === 'pending');
$check('userReportedReview() detects the duplicate reporter', $repository->userReportedReview($p75Voter, $p75ReviewId) && !$repository->userReportedReview((int) $arjun['id'], $p75ReviewId));

$repository->updateReportStatus($p75ReportId, 'resolved');
$check('updateReportStatus() moves the report along', ($repository->findReport($p75ReportId)['status'] ?? '') === 'resolved');

$check('reportsByStatus() returns only the matching status', count($repository->reportsByStatus('pending')) === 0 && count($repository->reportsByStatus('resolved')) === 1);
$check('pendingReports() is the pending slice', $repository->pendingReports() === []);

$p75Stats = $repository->reportStatistics();
$check('reportStatistics() reports the totals', $p75Stats['total'] === 1 && $p75Stats['statuses'][0]['status'] === 'resolved' && $p75Stats['statuses'][0]['count'] === 1);
$check('reportStatistics() reports the reason breakdown', $p75Stats['reasons'][0]['reason'] === 'Spam' && (int) $p75Stats['reasons'][0]['count'] === 1);

// The moderation foundation: hide a review and watch it leave the
// approved reads while the report stays untouched.

$p75HiddenReviewId = (int) $repository->findByBook($p74BookId, 50)[5]['id'];
$repository->updateStatus($p75HiddenReviewId, 'hidden');
$check('updateStatus() hides the review', (count($repository->findByBook($p74BookId, 50))) === 5);
$check('hiddenReviews() lists the hidden review', count($repository->hiddenReviews()) === 1);
$check('The hidden review is not in the approved scope', !in_array($p75HiddenReviewId, array_column($repository->approved(50), 'id'), true));
$repository->updateStatus($p75HiddenReviewId, 'approved');
$check('Restoring the review returns it to the lists', count($repository->findByBook($p74BookId, 50)) === 6);

// --- 13d. Repository: community stats + reputation reads.

$p75Community = $repository->communityStats($p74BookId);
$check('communityStats() reads the approved totals', $p75Community['totalReviews'] === 6 && $p75Community['helpfulVotes'] === 0);
$check('communityStats() spots the highest rated review', ($p75Community['highestRated']['rating'] ?? 0) === 5);
$check('communityStats() spots the newest review', ($p75Community['newest']['title'] ?? '') === 'Not for me');
$check('communityStats() computes the truthful average', abs((float) $p75Community['averageRating'] - (20 / 6)) < 0.001);

$repository->addHelpfulVote($p75ReviewId, (int) $arjun['id']);
$repository->addHelpfulVote((int) $repository->findByBook($p74BookId, 50)[4]['id'], (int) $arjun['id']);
$p75Community2 = $repository->communityStats($p74BookId);
$check('communityStats() counts the votes live', $p75Community2['helpfulVotes'] === 2);
$check('communityStats() promotes the most helpful review', ($p75Community2['mostHelpful']['id'] ?? 0) === $p75ReviewId);

$p75Reputation = $repository->reviewReputation($p75Reviewer);
$check('reviewReputation() counts the helpful votes received', $p75Reputation['helpfulReceived'] === 1 && $p75Reputation['reviewsWritten'] === 1);
$check('reviewReputation() names the most helpful review', ($p75Reputation['mostHelpful']['id'] ?? 0) === $p75ReviewId);

// --- 13e. Service: the engagement rules (self-vote, duplicates,
// hide with book-stats sync, report lifecycle).

$p75Target = $repository->findByBook($p74BookId, 1)[0];

$p75VoteState = $service->markHelpful((int) $p75Target['id'], $p75Voter);
$check('markHelpful() returns the fresh vote state', $p75VoteState['voted'] === true && $p75VoteState['count'] === 2);
$check('markHelpful() is idempotent', $service->markHelpful((int) $p75Target['id'], $p75Voter)['count'] === 2);
$check('hasUserVoted() reflects the service state', $service->hasUserVoted((int) $p75Target['id'], $p75Voter) && !$service->hasUserVoted((int) $p75Target['id'], (int) $p75Reviewer));

$p75SelfVote = false;
try {
    $service->markHelpful((int) $p75Target['id'], $p75Reviewer);
} catch (ReviewException $e) {
    $p75SelfVote = str_contains($e->getMessage(), 'own review');
}
$check('The review owner cannot vote on their own review', $p75SelfVote);

$p75Removed = $service->removeHelpful((int) $p75Target['id'], $p75Voter);
$check('removeHelpful() returns the off state', $p75Removed['voted'] === false && $p75Removed['count'] === 1);
$check('helpfulCount() matches the repository truth', $service->helpfulCount((int) $p75Target['id']) === 1);

$p75VotedRows = $service->attachVoteState([$p75Target], $p75Voter);
$check('attachVoteState() sets the count, the vote and the ownership', (int) $p75VotedRows[0]['helpful_count'] === 1 && $p75VotedRows[0]['helpful_voted'] === false && $p75VotedRows[0]['is_owner'] === false);

$p75SelfReport = false;
try {
    $service->reportReview((int) $p75Target['id'], $p75Reviewer, 'Spam', '');
} catch (ReviewException $e) {
    $p75SelfReport = str_contains($e->getMessage(), 'own review');
}
$check('The review owner cannot report their own review', $p75SelfReport);

// The voter filed the Spam report in 13c, so the pending report is
// filed by a FRESH reporter - arjun - and the duplicate check below
// repeats the voter, who is still on the record from 13c.

$p75NewReport = $service->reportReview((int) $p75Target['id'], (int) $arjun['id'], 'Harassment', 'The review attacks the author personally.');
$check('reportReview() files a pending report', ($repository->findReport($p75NewReport)['status'] ?? '') === 'pending');

$p75DuplicateReport = false;
try {
    $service->reportReview((int) $p75Target['id'], $p75Voter, 'Other', '');
} catch (ReviewException $e) {
    $p75DuplicateReport = str_contains($e->getMessage(), 'already reported');
}
$check('A duplicate report is rejected with a clear message', $p75DuplicateReport);

// Hide / restore with the book rating sync (the hidden review leaves
// the averages; the restore brings its rating back). The baseline is
// refreshed first - the direct updateStatus() of 13c never touched
// the denormalized book columns.

$repository->updateBookRatingStats($p74BookId);
$p75BookStatsBefore = $repository->ratingStats($p74BookId);
$service->hideReview((int) $p75Target['id']);
$p75BookStatsAfter = $repository->ratingStats($p74BookId);
$check('hideReview() removes the review from the approved set', $p75BookStatsAfter['count'] === $p75BookStatsBefore['count'] - 1 && $p75BookStatsAfter['count'] === 5);
$check('hideReview() recomputes the average', abs((float) $p75BookStatsAfter['average'] - (19 / 5)) < 0.001);
$check('hideReview() marks the review hidden', ($repository->find((int) $p75Target['id'])['status'] ?? '') === 'hidden');

$service->hideReview((int) $p75Target['id'], false);
$check('Unhiding restores the review to the approved set', $repository->ratingStats($p74BookId)['count'] === $p75BookStatsBefore['count']);

$p75BadStatus = false;
try {
    $service->updateReportStatus($p75NewReport, 'banana');
} catch (ReviewException $e) {
    $p75BadStatus = str_contains($e->getMessage(), 'Invalid report status');
}
$check('updateReportStatus() rejects unknown statuses', $p75BadStatus);

$p75MissingReport = false;
try {
    $service->updateReportStatus(999999, 'resolved');
} catch (ReviewException $e) {
    $p75MissingReport = str_contains($e->getMessage(), 'Report not found');
}
$check('updateReportStatus() rejects missing reports', $p75MissingReport);

$check('reportReview() surfaces via the moderation reads', count($service->pendingReports()) === 1 && count($service->reportsByStatus('resolved')) === 1);
$check('reviewReports() lists the reports of one review', count($service->reviewReports((int) $p75Target['id'])) === 2);
$check('reviewReputation() travels through the service', ($service->reviewReputation($p75Reviewer)['helpfulReceived'] ?? 0) === 1);
$check('communityStats() travels through the service', $service->communityStats($p74BookId)['totalReviews'] === 6);

// --- 13f. Request validation: the report form rules.

$check('A valid report passes the field rules', ReportReviewRequest::passes(['reason' => 'Spam', 'description' => '']));
$check('An empty reason fails the required rule', !ReportReviewRequest::passes(['reason' => '', 'description' => '']));
$check('An unknown reason is rejected', !ReportReviewRequest::passes(['reason' => 'Bogus', 'description' => '']));
$check('A too-long description is rejected', !ReportReviewRequest::passes(['reason' => 'Other', 'description' => str_repeat('a', 1001)]));
$check('A one-word description is rejected once provided', !ReportReviewRequest::passes(['reason' => 'Other', 'description' => 'short']));
$check('The description is optional', ReportReviewRequest::passes(['reason' => 'Duplicate', 'description' => '']));

// --- 13g. Policy: the engagement gates.

$session->forget('auth_user_id');
$session->forget('auth_user');
$p75OtherReview = $p75Target;
$check('A guest cannot vote', !$policy->canVote($p75OtherReview));
$check('A guest cannot report', !$policy->canReport($p75OtherReview));
$check('A guest cannot moderate', !$policy->canModerate());

$session->put('auth_user_id', (int) $p75Reviewer);
$session->put('auth_user', ['id' => (int) $p75Reviewer, 'full_name' => 'Owner', 'email' => 'owner75@test.dev', 'role' => 'user']);
$check('The review owner cannot vote on their own review (policy)', !$policy->canVote($p75OtherReview));
$check('The review owner cannot report their own review (policy)', !$policy->canReport($p75OtherReview));

$session->put('auth_user_id', (int) $p75Voter);
$session->put('auth_user', ['id' => (int) $p75Voter, 'full_name' => 'Voter', 'email' => 'voter75@test.dev', 'role' => 'user']);
$check('Another user can vote', $policy->canVote($p75OtherReview));
$check('Another user can report', $policy->canReport($p75OtherReview));
$check('A regular user cannot moderate', !$policy->canModerate() && !$policy->canResolveReport() && !$policy->canHideReview());

$session->put('auth_user_id', (int) $admin['id']);
$session->put('auth_user', ['id' => (int) $admin['id'], 'full_name' => $admin['full_name'], 'email' => $admin['email'], 'role' => $admin['role']]);
$check('An admin can moderate', $policy->canModerate() && $policy->canResolveReport() && $policy->canHideReview());

// --- 13h. Controller: the fetch/JSON wiring of helpful / report.

$session->put('auth_user_id', (int) $p75Voter);
$session->put('auth_user', ['id' => (int) $p75Voter, 'full_name' => 'Voter', 'email' => 'voter75@test.dev', 'role' => 'user']);
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';

ob_start();
$reviewController->helpful(new Request(), ['id' => (string) $p75Target['id']]);
$helpfulJson = (string) ob_get_clean();
$p75HelpfulDecoded = json_decode($helpfulJson, true);
$check('helpful() answers the fetch toggle with the new state', ($p75HelpfulDecoded['voted'] ?? false) === true && (int) ($p75HelpfulDecoded['count'] ?? 0) === 2);

ob_start();
$reviewController->removeHelpful(new Request(), ['id' => (string) $p75Target['id']]);
$removeJson = (string) ob_get_clean();
$p75RemoveDecoded = json_decode($removeJson, true);
$check('removeHelpful() answers the off state', ($p75RemoveDecoded['voted'] ?? true) === false && (int) ($p75RemoveDecoded['count'] ?? 0) === 1);

// The voter (13c) and arjun (13e) already reported this review, so
// the report flow is exercised as a FRESH reporter - Vikram, one of
// the section-12 users - for the success and validation paths, then
// as Vikram again for the duplicate.

$p75FreshReporter = (int) $p74Users[1];
$session->put('auth_user_id', $p75FreshReporter);
$session->put('auth_user', ['id' => $p75FreshReporter, 'full_name' => 'Vikram Rao', 'email' => 'vikram@test.dev', 'role' => 'user']);

$_POST = ['reason' => 'Spam', 'description' => 'Clearly promotional content in the review body.'];
ob_start();
$reviewController->report(new Request(), ['id' => (string) $p75Target['id']]);
$reportOkJson = (string) ob_get_clean();
unset($_POST);
$p75ReportOk = json_decode($reportOkJson, true);
$check('report() answers the thank-you message', ($p75ReportOk['message'] ?? '') === 'Thank you. Your report has been submitted.');

$_POST = ['reason' => '', 'description' => ''];
ob_start();
$reviewController->report(new Request(), ['id' => (string) $p75Target['id']]);
$reportBadJson = (string) ob_get_clean();
unset($_POST);
$p75ReportBad = json_decode($reportBadJson, true);
$check('report() answers 422 with the field errors', isset($p75ReportBad['errors']['reason']));

$_POST = ['reason' => 'Other', 'description' => ''];
ob_start();
$reviewController->report(new Request(), ['id' => (string) $p75Target['id']]);
$reportDupJson = (string) ob_get_clean();
unset($_POST);
$p75ReportDup = json_decode($reportDupJson, true);
$check('report() answers 409 for a duplicate report', isset($p75ReportDup['error']) && str_contains($p75ReportDup['error'], 'already reported'));

// The self-report and guest paths end in Response::error() (exit),
// which cannot run in CLI - the same rule gates are covered by the
// policy checks in 13g and the service checks in 13e.

// --- 13i. The admin console page (queue tabs, stats, hidden list).

$adminController = new \BookSphere\App\Controllers\AdminController(null, $service, $policy);
ob_start();
$_GET = [];
$adminController->reports(new Request());
$adminReportsHtml = (string) ob_get_clean();
$check('The admin console renders the overview cards', str_contains($adminReportsHtml, 'Review Management') && str_contains($adminReportsHtml, 'Total reports') && str_contains($adminReportsHtml, 'Hidden reviews'));
$check('The admin console renders the status tabs', str_contains($adminReportsHtml, 'Pending') && str_contains($adminReportsHtml, 'Resolved'));
$check('The admin console lists the pending report with its context', str_contains($adminReportsHtml, 'Spam') && str_contains($adminReportsHtml, 'Reported by'));
$check('The admin console offers the moderation actions', str_contains($adminReportsHtml, '/resolve') && str_contains($adminReportsHtml, '/dismiss') && str_contains($adminReportsHtml, '/hide'));
$check('The admin console shows the empty hidden-reviews state', str_contains($adminReportsHtml, 'No hidden reviews'));

// --- 13j. The shared views: report modal, community panel, card states.

ob_start();
require root_path('app/Views/reviews/partials/_report-modal.php');
$reportModalHtml = (string) ob_get_clean();
$check('The report modal renders the six reasons', substr_count($reportModalHtml, '<option') === 7);
$check('The report modal carries the CSRF token', str_contains($reportModalHtml, 'name="_token"'));
$check('The report modal prepares the thank-you state', str_contains($reportModalHtml, 'Thank you. Your report has been submitted.'));

$session->forget('auth_user_id');
$session->forget('auth_user');
$review  = $p74Card;
$manage  = false;
$compact = false;
ob_start();
require root_path('app/Views/components/review-card.php');
$guestCardHtml = (string) ob_get_clean();
$check('A guest gets disabled Helpful and Report buttons', str_contains($guestCardHtml, 'disabled') && str_contains($guestCardHtml, 'Sign in to mark reviews as helpful'));

$session->put('auth_user_id', $p75Reviewer);
$session->put('auth_user', ['id' => $p75Reviewer, 'full_name' => 'Owner', 'email' => 'owner75@test.dev', 'role' => 'user']);
$review  = array_merge($p74Card, ['user_id' => $p75Reviewer, 'helpful_count' => 3, 'helpful_voted' => true]);
ob_start();
require root_path('app/Views/components/review-card.php');
$ownerCardHtml = (string) ob_get_clean();
$check('The owner sees disabled engagement controls', str_contains($ownerCardHtml, 'You cannot mark your own review as helpful') && str_contains($ownerCardHtml, 'disabled'));
$check('The card renders the truthful vote count', str_contains($ownerCardHtml, 'data-helpful-count>3'));

$session->put('auth_user_id', $p75Voter);
$session->put('auth_user', ['id' => $p75Voter, 'full_name' => 'Voter', 'email' => 'voter75@test.dev', 'role' => 'user']);
$review  = array_merge($p74Card, ['helpful_count' => 2, 'helpful_voted' => true]);
ob_start();
require root_path('app/Views/components/review-card.php');
$voterCardHtml = (string) ob_get_clean();
$check('A voter sees the pressed state', str_contains($voterCardHtml, 'is-active') && str_contains($voterCardHtml, 'aria-pressed="true"'));

$communityStats = $service->communityStats($p74BookId);
ob_start();
require root_path('app/Views/reviews/partials/_community-stats.php');
$communityHtml = (string) ob_get_clean();
$check('The community panel renders the six tiles', str_contains($communityHtml, 'Total Reviews') && str_contains($communityHtml, 'Helpful Votes') && str_contains($communityHtml, 'Average Rating') && str_contains($communityHtml, 'Most Helpful') && str_contains($communityHtml, 'Newest Review') && str_contains($communityHtml, 'Highest Rated'));
$check('The community panel links the spotlight reviews', str_contains($communityHtml, '/reviews/' . (int) $communityStats['mostHelpful']['id']));

$communityStats = ['totalReviews' => 0, 'helpfulVotes' => 0, 'averageRating' => null, 'mostHelpful' => null, 'newest' => null, 'highestRated' => null];
ob_start();
require root_path('app/Views/reviews/partials/_community-stats.php');
$communityEmptyHtml = (string) ob_get_clean();
$check('The community panel renders nothing without reviews', trim($communityEmptyHtml) === '');

// ---------------------------------------------------------------------
// 14. PHASE 7.7: hardening (unique report constraint, batched votes,
// request-validation regression, self-report exception, write
// throttles with the 429 gate, and the shared view/date helpers)
// ---------------------------------------------------------------------

echo $section('14. PHASE 7.7: hardening (unique report constraint, batched votes, throttles, helpers)');

// --- 14a. Migration 0016: the database itself rejects a duplicate
// report - the same user may file one report per review, ever.

$p77ReportIndexes = array_column(db()->query('PRAGMA index_list(review_reports)'), 'name');
$check('Migration 0016 adds the unique (reported_by, review_id) index', in_array('idx_review_reports_unique', $p77ReportIndexes, true));

$p77DuplicateBlocked = false;
try {
    db()->execute(
        'INSERT INTO review_reports (review_id, reported_by, reason, description, status, created_at, updated_at)
         VALUES (?, ?, \'Spam\', \'\', \'pending\', \'2026-01-01T00:00:00Z\', \'2026-01-01T00:00:00Z\')',
        [$p75ReviewId, (int) $arjun['id']],
    );
    db()->execute(
        'INSERT INTO review_reports (review_id, reported_by, reason, description, status, created_at, updated_at)
         VALUES (?, ?, \'Harassment\', \'\', \'pending\', \'2026-01-01T00:00:00Z\', \'2026-01-01T00:00:00Z\')',
        [$p75ReviewId, (int) $arjun['id']],
    );
} catch (\Throwable) {
    $p77DuplicateBlocked = true;
}
$check('The database blocks a second report of the same review by the same user', $p77DuplicateBlocked);

// --- 14b. Repository: the batched helpful-vote read (the Phase 7.7
// N+1 fix) answers one map for many reviews.

$p77List = $repository->findByBook($p74BookId, 4);
$p77Ids  = array_map(static fn (array $row): int => (int) $row['id'], $p77List);
$repository->addHelpfulVote($p77Ids[0], (int) $riya['id']);
$repository->addHelpfulVote($p77Ids[1], (int) $riya['id']);
$p77VoteMap = $repository->userHelpfulVotes((int) $riya['id'], $p77Ids);
$check('userHelpfulVotes() maps every voted review to true', ($p77VoteMap[$p77Ids[0]] ?? false) === true && ($p77VoteMap[$p77Ids[1]] ?? false) === true);
$check('userHelpfulVotes() leaves the unvoted reviews out of the map', !array_key_exists($p77Ids[2], $p77VoteMap) && !array_key_exists($p77Ids[3], $p77VoteMap));
$check('userHelpfulVotes() tolerates missing review ids', $repository->userHelpfulVotes((int) $riya['id'], [999999]) === []);

// --- 14c. Validation: UpdateReviewRequest::validate() returns the
// Validator (the Phase 7.7 regression fix - the missing import used
// to throw a TypeError against the Validator return type).

$p77UpdateValidator = UpdateReviewRequest::validate(['rating' => '4', 'title' => 'A solid read', 'review' => $valid['review']]);
$check('UpdateReviewRequest::validate() returns a Validator without errors', $p77UpdateValidator instanceof Validator && $p77UpdateValidator->errors() === []);
$p77BadUpdate = UpdateReviewRequest::validate(['rating' => '9', 'title' => 'x', 'review' => 'short']);
$check('UpdateReviewRequest::validate() still reports the field errors', $p77BadUpdate instanceof Validator && isset($p77BadUpdate->errors()['rating']));

// --- 14d. Exceptions: the self-report failure has its own wording.

$p77SelfReport = ReviewException::selfReport($p75ReviewId);
$check('selfReport() names the review without the vote wording', $p77SelfReport instanceof ReviewException && str_contains($p77SelfReport->getMessage(), 'You cannot report your own review ' . $p75ReviewId));

$p77SelfReportThrown = false;
try {
    $service->reportReview($p75ReviewId, $p75Reviewer, 'Spam', '');
} catch (ReviewException $e) {
    $p77SelfReportThrown = str_contains($e->getMessage(), 'You cannot report your own review');
}
$check('reportReview() on the author throws the self-report exception', $p77SelfReportThrown);

// --- 14e. Config: the review write throttles are configured
// (config/recommendations.php -> security.rate_limit).

$p77Limits = (array) config('recommendations.security.rate_limit', []);
$check('The config carries all three review buckets', isset($p77Limits['review_write'], $p77Limits['review_vote'], $p77Limits['review_report']));
$check('review_write allows 20 writes per hour', (int) ($p77Limits['review_write']['limit'] ?? 0) === 20 && (int) ($p77Limits['review_write']['window_seconds'] ?? 0) === 3600);
$check('review_vote allows 60 toggles per minute', (int) ($p77Limits['review_vote']['limit'] ?? 0) === 60 && (int) ($p77Limits['review_vote']['window_seconds'] ?? 0) === 60);
$check('review_report allows 10 reports per hour', (int) ($p77Limits['review_report']['limit'] ?? 0) === 10 && (int) ($p77Limits['review_report']['window_seconds'] ?? 0) === 3600);

// --- 14f. The 429 gate: a wired limiter makes store() answer the
// plain 429 text once the review_write bucket is exhausted, and let
// a request through while the bucket is below the limit. The probe
// runs in a subprocess because Response::error() exits the process.

$probeRoot = root_path();
$probePath = sys_get_temp_dir() . '/booksphere_review_throttle_probe.php';
$probeCode = '<?php' . PHP_EOL
    . 'declare(strict_types=1);' . PHP_EOL . PHP_EOL
    . 'require ' . var_export($probeRoot . '/bootstrap/constants.php', true) . ';' . PHP_EOL
    . 'require ' . var_export($probeRoot . '/vendor/autoload.php', true) . ';' . PHP_EOL . PHP_EOL
    . 'use BookSphere\\App\\Core\\Database;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Environment;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Logger;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\RateLimiter;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Request;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Session;' . PHP_EOL
    . 'use BookSphere\\App\\Models\\Book;' . PHP_EOL
    . 'use BookSphere\\App\\Models\\Review;' . PHP_EOL
    . 'use BookSphere\\App\\Models\\User;' . PHP_EOL
    . 'use BookSphere\\App\\Policies\\ReviewPolicy;' . PHP_EOL
    . 'use BookSphere\\App\\Controllers\\ReviewController;' . PHP_EOL
    . 'use BookSphere\\App\\Services\\AuthService;' . PHP_EOL
    . 'use BookSphere\\App\\Services\\ReviewService;' . PHP_EOL . PHP_EOL
    . '(new Environment(root_path(\'.env\')))->load();' . PHP_EOL
    . 'Database::instance(root_path(\'database/review_test.db\'));' . PHP_EOL
    . '$session = new Session(\'review_test_probe\');' . PHP_EOL
    . '$session->start();' . PHP_EOL
    . '$auth = new AuthService($session, new User());' . PHP_EOL
    . 'AuthService::setInstance($auth);' . PHP_EOL
    . '$probeUserId = (int) ($argv[2] ?? \'0\');' . PHP_EOL
    . '$session->put(\'auth_user_id\', $probeUserId);' . PHP_EOL
    . '$session->put(\'auth_user\', [\'id\' => $probeUserId, \'full_name\' => \'Probe User\', \'email\' => \'probe@test.dev\', \'role\' => \'user\']);' . PHP_EOL
    . '$limiter = new RateLimiter($session);' . PHP_EOL
    . '$probeService = new ReviewService(new Review(), new Book(), null, new Logger(sys_get_temp_dir() . \'/booksphere_review_probe.log\'));' . PHP_EOL
    . '$probeController = new ReviewController($probeService, new ReviewPolicy(), null, $limiter);' . PHP_EOL . PHP_EOL
    . '$mode = $argv[1] ?? \'write\';' . PHP_EOL
    . 'if ($mode === \'write\') {' . PHP_EOL
    . '    for ($i = 0; $i < 20; $i++) {' . PHP_EOL
    . '        $limiter->allow(\'review_write\', 20, 3600);' . PHP_EOL
    . '    }' . PHP_EOL
    . '}' . PHP_EOL
    . '$_SERVER[\'HTTP_X_REQUESTED_WITH\'] = \'fetch\';' . PHP_EOL
    . '$_POST = [\'rating\' => \'4\', \'title\' => \'Probe\', \'review\' => \'A probe review body that is long enough to pass the minimum length.\'];' . PHP_EOL
    . '$probeController->store(new Request(), [\'id\' => ' . var_export((string) $freshBookId, true) . ']);' . PHP_EOL;
file_put_contents($probePath, $probeCode);

$probeRiya  = (int) $riya['id'];
$probeFresh = (int) $admin['id'];

$probeOutput = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' write ' . $probeRiya . ' 2>&1');
$check('A review write over the limit answers 429', $probeOutput === 'Too many requests - please try again in a minute.');

// The pass-mode probe writes a review, so it must use a user without
// a review on the probe book yet (riya and arjun both reviewed it in
// the earlier sections; the admin has not).
$probeOutput = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' pass ' . $probeFresh . ' 2>&1');
$check('A review write under the limit passes the gate', str_contains($probeOutput, '"ok":true'));

unlink($probePath);

// --- 14g. Shared helpers and partials (the Phase 7.7 dedup).

$check('format_review_date() renders the short display shape', preg_match('/^[A-Z][a-z]{2} \d{1,2}, \d{4}$/', format_review_date('2026-12-31T10:00:00Z')) === 1);
$check('format_review_date() turns empty values into an empty string', format_review_date('') === '' && format_review_date(null) === '');
$check('format_review_date() swallows invalid values', format_review_date('not-a-date') === '');

ob_start();
$avatarName = 'Riya Sharma';
$avatarHref = '/reviews/user/' . $probeRiya;
require root_path('app/Views/reviews/partials/_avatar.php');
$avatarHtml = (string) ob_get_clean();
$check('The avatar partial renders the initials link', str_contains($avatarHtml, 'RS') && str_contains($avatarHtml, 'href="/reviews/user/' . $probeRiya . '"'));
$check('The avatar tone is one of the six deterministic keys', preg_match('/class="avatar avatar-[1-6]"/', $avatarHtml) === 1);

ob_start();
$avatarName = '';
$avatarHref = '';
require root_path('app/Views/reviews/partials/_avatar.php');
$avatarFallbackHtml = (string) ob_get_clean();
$check('A nameless avatar falls back to a question mark', str_contains($avatarFallbackHtml, '>?</span>') && str_contains($avatarFallbackHtml, 'aria-hidden="true"'));

$p77Breakdown = [
    ['stars' => 5, 'count' => 2, 'percent' => 67, 'total' => 3],
    ['stars' => 4, 'count' => 1, 'percent' => 33, 'total' => 3],
];

ob_start();
$breakdown = $p77Breakdown;
$title = '';
require root_path('app/Views/reviews/partials/_rating-distribution.php');
$distNoTitle = (string) ob_get_clean();
$check('The distribution partial renders rows without a duplicate heading', str_contains($distNoTitle, 'data-bar-percent="67"') && !str_contains($distNoTitle, 'rating-distribution-title'));

unset($title);
ob_start();
$breakdown = $p77Breakdown;
require root_path('app/Views/reviews/partials/_rating-distribution.php');
$distDefault = (string) ob_get_clean();
$check('The distribution partial keeps the default heading', str_contains($distDefault, 'Rating breakdown'));

ob_start();
$breakdown = [];
$title = '';
$empty = 'Nothing here yet.';
require root_path('app/Views/reviews/partials/_rating-distribution.php');
$distEmpty = (string) ob_get_clean();
$check('The distribution partial honours the custom empty state', str_contains($distEmpty, 'Nothing here yet.'));

// ---------------------------------------------------------------------
// RESULT
// ---------------------------------------------------------------------

echo $section('RESULT');
echo '  Checks: ' . $checks . PHP_EOL;
echo '  Failed: ' . $failures . PHP_EOL;

echo PHP_EOL . 'Note: the throwaway database database/review_test.db and the log file ' . $logFile . ' are left in place for inspection; delete them anytime.' . PHP_EOL;

exit($failures === 0 ? 0 : 1);
