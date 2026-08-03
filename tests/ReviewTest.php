<?php

declare(strict_types=1);

/**
 * ReviewTest — CLI test suite for Phase 7.1 (Reviews & Ratings
 * backend) and Phase 7.2 (complete review CRUD)
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
 *       the Phase 7.1/7.2 testing checklist for the viva.
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
// RESULT
// ---------------------------------------------------------------------

echo $section('RESULT');
echo '  Checks: ' . $checks . PHP_EOL;
echo '  Failed: ' . $failures . PHP_EOL;

echo PHP_EOL . 'Note: the throwaway database database/review_test.db and the log file ' . $logFile . ' are left in place for inspection; delete them anytime.' . PHP_EOL;

exit($failures === 0 ? 0 : 1);
