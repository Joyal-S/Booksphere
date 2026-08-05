<?php

declare(strict_types=1);

/**
 * LibraryTest — CLI test suite for Phase 8.1 (Wishlist & Personal
 * Reading Library backend)
 *
 * Verifies the complete backend architecture of the personal
 * library: the user_library schema (migration 0017), the layered
 * stack (DTO -> service -> repository), the five-shelf status
 * lifecycle with its automatic timestamps, favourites independent of
 * status, progress validation, the model facade and relationships,
 * the policy matrix, the request rules, the controller endpoints,
 * the Phase 8.5 recommendation hooks and the regression of the
 * existing modules. Same throwaway-database harness as every other
 * suite:
 *
 *     1. Schema      - the user_library table (columns, defaults,
 *                      CHECK constraints, UNIQUE, indexes, FKs)
 *     2. Repository  - create / find / update / delete / exists /
 *                      findByUser / findByBook + the shelf scopes
 *                      and statistics
 *     3. Add book    - the default want_to_read shelf, favourite +
 *                      progress on create, the duplicate guard and
 *                      the book-exists guard
 *     4. Status      - the lifecycle: currently_reading stamps
 *                      started_reading_at once, finished forces
 *                      100% + finished_reading_at, invalid statuses
 *                      and missing records fail loudly
 *     5. Progress    - 0-100 validation, auto-finish at 100
 *     6. Favourites  - toggle on/off, independent of the status
 *     7. Remove      - deletion + idempotence
 *     8. Statistics  - the per-user overview payload
 *     9. Model       - the UserLibrary facade, the belongsTo
 *                      relationships and the four scopes
 *    10. DTO         - LibraryItemDTO structural sanitization
 *    11. Requests    - Store / Update rule tables (valid + invalid)
 *    12. Policy      - guest / owner / other user / admin matrix
 *    13. Hooks       - the Phase 8.5 recommendation reads
 *    14. Controller  - the JSON endpoints (index, store, update,
 *                      destroy, the four shelves, statistics)
 *    15. Database    - the UNIQUE and CHECK constraints reject
 *                      duplicate rows and junk values at the last
 *                      line of defence
 *    16. Regression  - the Book module, the Review module and the
 *                      Recommendation engine keep working (the
 *                      legacy wishlist table is untouched)
 *    17. Phase 8.2   - the library UI reads: searchLibrary() (title /
 *                      author / category), statusCounts(), the generic
 *                      shelf() buckets, bookDetailsState(), the
 *                      search / toggleFavourite / updateProgress
 *                      controller endpoints, and the dashboard's
 *                      Continue Reading shelf (sorted by last
 *                      updated, rendered with the resume cards)
 *    18. Phase 8.3   - the library dashboard: the user_preferences
 *                      schema (migration 0018), the filtered grid
 *                      reads (filter / countFiltered / paginate /
 *                      filterOptions / the SORTS ordering), the
 *                      reading summary and the reading streak, the
 *                      service facades (filterLibrary / viewPreference
 *                      / libraryDashboard), and the filter / sort /
 *                      view-mode / continue-reading controller
 *                      endpoints
 *    19. Phase 8.4   - the Smart Collections rail (collectionStatistics:
 *                      count / average rating / last updated per shelf),
 *                      the recently-added / recently-updated reads, the
 *                      description-reach of the library search, the
 *                      Most Reviewed / Most Recommended sorts (and the
 *                      recommendation-set ordering), the bulk actions
 *                      (move / favourite / delete with the owner gate
 *                      and the invalid-status guard) and the dashboard
 *                      / profile integration (both rendered through the
 *                      SHARED LibraryService)
 *
 * Run from the project root:
 *
 *     php tests/LibraryTest.php
 *
 * The throwaway database (database/library_test.db) is migrated,
 * seeded and left in place for inspection; delete it anytime.
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Controllers\LibraryController;
use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Core\Logger;
use BookSphere\App\Core\Migrator;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Seeder;
use BookSphere\App\Core\Session;
use BookSphere\App\DTO\LibraryItemDTO;
use BookSphere\App\Exceptions\LibraryException;
use BookSphere\App\Models\Book;
use BookSphere\App\Models\Review;
use BookSphere\App\Models\User;
use BookSphere\App\Models\UserLibrary;
use BookSphere\App\Policies\LibraryPolicy;
use BookSphere\App\Repositories\BookRepository;
use BookSphere\App\Repositories\LibraryRepository;
use BookSphere\App\Repositories\RecommendationRepository;
use BookSphere\App\Requests\StoreLibraryRequest;
use BookSphere\App\Requests\UpdateLibraryRequest;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\LibraryService;
use BookSphere\App\Services\RecommendationService;

// ---------------------------------------------------------------------
// 0. Boot: fresh throwaway database, migrated and seeded.
// ---------------------------------------------------------------------

(new Environment(root_path('.env')))->load();

$dbPath = root_path('database/library_test.db');

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
$session = new Session('library_test');
$session->start();
$auth = new AuthService($session, new User());
AuthService::setInstance($auth);

$logFile = sys_get_temp_dir() . '/booksphere_library_test.log';
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

$bookId = fn (string $title): int => (int) db()->query('SELECT id FROM books WHERE title = ?', [$title])[0]['id'];
$b1984    = $bookId('1984');
$bHobbit  = $bookId('The Hobbit');
$bHabits  = $bookId('Atomic Habits');
$bMartian = $bookId('The Martian');
$bDeepWork = $bookId('Deep Work');
$bMockingbird = $bookId('To Kill a Mockingbird');

$model      = new UserLibrary();
$repository = new LibraryRepository();
$service    = new LibraryService(
    $model,
    new Book(),
    null,
    new Logger($logFile),
);
$policy = new LibraryPolicy();

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
// 1. SCHEMA (migration 0017: the user_library table)
// ---------------------------------------------------------------------

echo $section('1. SCHEMA: the user_library table');

$columns = array_column(db()->query('PRAGMA table_info(user_library)'), 'name');
foreach (['id', 'user_id', 'book_id', 'library_status', 'is_favorite', 'progress_percentage', 'started_reading_at', 'finished_reading_at', 'created_at', 'updated_at'] as $column) {
    $check("The table carries the {$column} column", in_array($column, $columns, true));
}

$defaults = [];
foreach (db()->query('PRAGMA table_info(user_library)') as $row) {
    $defaults[$row['name']] = $row['dflt_value'];
}
$check('The library_status default is want_to_read', str_contains((string) $defaults['library_status'], 'want_to_read'));
$check('The is_favorite default is 0', (string) $defaults['is_favorite'] === '0');
$check('The progress_percentage default is 0', (string) $defaults['progress_percentage'] === '0');

$indexNames = array_column(db()->query('PRAGMA index_list(user_library)'), 'name');
foreach (['idx_user_library_user', 'idx_user_library_book', 'idx_user_library_status', 'idx_user_library_favorite'] as $index) {
    $check("The {$index} index exists", in_array($index, $indexNames, true));
}

$tableSql = (string) (db()->query(
    "SELECT sql
     FROM sqlite_master
     WHERE type = 'table' AND name = 'user_library'",
)[0]['sql'] ?? '');
$check('The UNIQUE (user_id, book_id) constraint exists', str_contains($tableSql, 'UNIQUE') && str_contains($tableSql, 'user_id') && str_contains($tableSql, 'book_id'));

// ---------------------------------------------------------------------
// 2. REPOSITORY (the SQL layer)
// ---------------------------------------------------------------------

echo $section('2. REPOSITORY: the data layer');

$id = $repository->create([
    'user_id'            => $riyaId,
    'book_id'            => $b1984,
    'library_status'     => 'want_to_read',
    'is_favorite'        => 0,
    'progress_percentage' => 0,
    'started_reading_at' => null,
    'finished_reading_at' => null,
]);
$check('create() inserts a record and returns its id', $id > 0);

$found = $repository->find($id);
$check('find() returns the record', is_array($found) && (int) $found['id'] === $id);
$check('find() joins the book title and cover', $found['book_title'] === '1984' && array_key_exists('book_cover', $found));
$check('find() reports the default shelf', $found['library_status'] === 'want_to_read');

$check('exists() is true after the insert', $repository->exists($riyaId, $b1984));
$check('exists() is false for another book', !$repository->exists($riyaId, $bHobbit));

$check('findByBook() returns the user record for the book', $repository->findByBook($riyaId, $b1984)['id'] == $id);
$check('findByBook() is null for a book without a record', $repository->findByBook($riyaId, $bHobbit) === null);

$check('findByUser() lists one record for Riya', count($repository->findByUser($riyaId)) === 1);
$check('findByUser() is empty for a fresh user', $repository->findByUser($adminId) === []);

$check('update() flips the status', $repository->update($id, ['library_status' => 'currently_reading']) === true);
$check('The update is visible on the record', $repository->find($id)['library_status'] === 'currently_reading');
$check('A partial update leaves the untouched fields alone', $repository->find($id)['is_favorite'] == 0);

$check('delete() removes the record', $repository->delete($id) === true);
$check('The record is gone after delete', $repository->find($id) === null);

// ---------------------------------------------------------------------
// 3. ADD BOOK (the service's create path)
// ---------------------------------------------------------------------

echo $section('3. ADD BOOK: the create path');

$added = $service->addBook(LibraryItemDTO::fromArray(['book_id' => $b1984], $riyaId));
$check('addBook() defaults to the want_to_read shelf', $repository->find($added)['library_status'] === 'want_to_read');
$check('addBook() starts progress at 0', (int) $repository->find($added)['progress_percentage'] === 0);
$check('addBook() leaves the timestamps empty on want_to_read', $repository->find($added)['started_reading_at'] === null && $repository->find($added)['finished_reading_at'] === null);

$check('addBook() rejects a duplicate book', $throws(LibraryException::class, fn () => $service->addBook(LibraryItemDTO::fromArray(['book_id' => $b1984], $riyaId))));
$check('addBook() rejects a missing book', $throws(LibraryException::class, fn () => $service->addBook(LibraryItemDTO::fromArray(['book_id' => 999999], $riyaId))));
$check('addBook() rejects an invalid status', $throws(LibraryException::class, fn () => $service->addBook(LibraryItemDTO::fromArray(['book_id' => $bHobbit, 'status' => 'plundered'], $riyaId))));
$check('addBook() rejects a negative progress', $throws(LibraryException::class, fn () => $service->addBook(LibraryItemDTO::fromArray(['book_id' => $bHobbit, 'progress' => -5], $riyaId))));
$check('addBook() rejects a progress over 100', $throws(LibraryException::class, fn () => $service->addBook(LibraryItemDTO::fromArray(['book_id' => $bHobbit, 'progress' => 101], $riyaId))));

$reading = $service->addBook(LibraryItemDTO::fromArray(['book_id' => $bHobbit, 'status' => 'currently_reading', 'favorite' => '1', 'progress' => 40], $riyaId));
$row = $repository->find($reading);
$check('A currently_reading add stamps started_reading_at', $row['started_reading_at'] !== null);
$check('A currently_reading add keeps finished_reading_at empty', $row['finished_reading_at'] === null);
$check('The favourite flag is stored on create', (int) $row['is_favorite'] === 1);
$check('The initial progress is stored', (int) $row['progress_percentage'] === 40);

$finished = $service->addBook(LibraryItemDTO::fromArray(['book_id' => $bHabits, 'status' => 'finished'], $riyaId));
$row = $repository->find($finished);
$check('A finished add forces progress to 100', (int) $row['progress_percentage'] === 100);
$check('A finished add stamps finished_reading_at', $row['finished_reading_at'] !== null);

$auto = $service->addBook(LibraryItemDTO::fromArray(['book_id' => $bMartian, 'progress' => 100], $riyaId));
$check('A progress of 100 on add auto-finishes the record', $repository->find($auto)['library_status'] === 'finished');

// ---------------------------------------------------------------------
// 4. STATUS UPDATE (the lifecycle)
// ---------------------------------------------------------------------

echo $section('4. STATUS UPDATE: the lifecycle');

$service->updateStatus($riyaId, $b1984, 'currently_reading');
$check('want_to_read -> currently_reading stamps started_reading_at', $repository->findByBook($riyaId, $b1984)['started_reading_at'] !== null);

$firstStart = $repository->findByBook($riyaId, $b1984)['started_reading_at'];
usleep(1100000);
$service->updateStatus($riyaId, $b1984, 'currently_reading');
$check('Re-stamping currently_reading never overwrites the start date', $repository->findByBook($riyaId, $b1984)['started_reading_at'] === $firstStart);

$service->updateStatus($riyaId, $b1984, 'finished');
$row = $repository->findByBook($riyaId, $b1984);
$check('currently_reading -> finished forces progress 100', (int) $row['progress_percentage'] === 100);
$check('currently_reading -> finished stamps finished_reading_at', $row['finished_reading_at'] !== null);

$service->updateStatus($riyaId, $b1984, 'on_hold');
$row = $repository->findByBook($riyaId, $b1984);
$check('Moving to on_hold keeps the finish date as history', $row['finished_reading_at'] !== null);

$check('updateStatus() rejects an invalid status', $throws(LibraryException::class, fn () => $service->updateStatus($riyaId, $b1984, 'plundered')));
$check('updateStatus() fails loudly on a missing record', $throws(LibraryException::class, fn () => $service->updateStatus($riyaId, $bDeepWork, 'finished')));

// ---------------------------------------------------------------------
// 5. PROGRESS (bounds + auto-finish)
// ---------------------------------------------------------------------

echo $section('5. PROGRESS: the 0-100 rule');

$service->updateStatus($riyaId, $bHobbit, 'currently_reading');
$service->updateProgress($riyaId, $bHobbit, 75);
$check('updateProgress() stores the new value', (int) $repository->findByBook($riyaId, $bHobbit)['progress_percentage'] === 75);
$check('A progress under 100 keeps the record reading', $repository->findByBook($riyaId, $bHobbit)['library_status'] === 'currently_reading');

$service->updateProgress($riyaId, $bHobbit, 100);
$row = $repository->findByBook($riyaId, $bHobbit);
$check('updateProgress() to 100 auto-finishes the record', $row['library_status'] === 'finished' && (int) $row['progress_percentage'] === 100);
$check('updateProgress() to 100 stamps finished_reading_at', $row['finished_reading_at'] !== null);

$check('updateProgress() rejects a negative value', $throws(LibraryException::class, fn () => $service->updateProgress($riyaId, $b1984, -1)));
$check('updateProgress() rejects a value over 100', $throws(LibraryException::class, fn () => $service->updateProgress($riyaId, $b1984, 101)));
$check('updateProgress() fails loudly on a missing record', $throws(LibraryException::class, fn () => $service->updateProgress($riyaId, $bDeepWork, 50)));

// ---------------------------------------------------------------------
// 6. FAVOURITES (independent of the status)
// ---------------------------------------------------------------------

echo $section('6. FAVOURITES: the independent star');

$check('toggleFavorite() stars the book (false -> true)', $service->toggleFavorite($riyaId, $b1984) === true);
$check('The star is stored', (int) $repository->findByBook($riyaId, $b1984)['is_favorite'] === 1);
$check('toggleFavorite() un-stars the book (true -> false)', $service->toggleFavorite($riyaId, $b1984) === false);
$check('The un-star is stored', (int) $repository->findByBook($riyaId, $b1984)['is_favorite'] === 0);

// 1984 left the finished shelf in section 4 (on_hold); move it back
// so the favourite / status independence check matches section 8's
// statistics expectations (1984 counts as one of the three finished).
// The pause guarantees its finish stamp is strictly newer than The
// Hobbit's (finished in section 5), so the ordering checks of
// sections 8-14 see a deterministic "most recent" first.
usleep(1100000);
$service->updateStatus($riyaId, $b1984, 'finished');
$service->toggleFavorite($riyaId, $b1984);
$row = $repository->findByBook($riyaId, $b1984);
$check('A finished book can be a favourite too', $row['library_status'] === 'finished' && (int) $row['is_favorite'] === 1);
$check('toggleFavorite() fails loudly on a missing record', $throws(LibraryException::class, fn () => $service->toggleFavorite($riyaId, $bDeepWork)));

// ---------------------------------------------------------------------
// 7. REMOVE (deletion + idempotence)
// ---------------------------------------------------------------------

echo $section('7. REMOVE: deletion');

$service->removeBook($riyaId, $bHabits);
$check('removeBook() deletes the record', $repository->findByBook($riyaId, $bHabits) === null);
$check('removeBook() on a missing record is an idempotent no-op', $service->removeBook($riyaId, $bHabits) === false);
$check('The other records stay untouched', $repository->findByBook($riyaId, $b1984) !== null && $repository->findByBook($riyaId, $bMartian) !== null);

// ---------------------------------------------------------------------
// 8. STATISTICS (the per-user overview)
// ---------------------------------------------------------------------

echo $section('8. STATISTICS: the overview payload');

// Riya now owns: 1984 (finished, favourite), The Hobbit (finished),
// The Martian (finished). Two more for the shelf mix.
$service->addBook(LibraryItemDTO::fromArray(['book_id' => $bDeepWork], $riyaId));
$stats = $service->libraryStatistics($riyaId);
$check('The statistics report the total record count', $stats['total'] === 4);
$check('The statistics carry the per-status counts', $stats['statuses']['finished'] === 3 && $stats['statuses']['want_to_read'] === 1);
$check('The statistics count the favourites', $stats['favorites'] === 2);
$check('The statistics carry the started / finished keys', $stats['started'] === 0 && $stats['finished'] === 3);
$check('The statistics report the average progress', (float) $stats['average_progress'] > 0);
$check('A fresh user gets an empty overview', $service->libraryStatistics($adminId)['total'] === 0);

// ---------------------------------------------------------------------
// 9. MODEL FACADE (UserLibrary forwards + relationships + scopes)
// ---------------------------------------------------------------------

echo $section('9. MODEL: the UserLibrary facade');

$record = $model->findByBook($riyaId, $b1984);
$check('The model forwards findByBook()', is_array($record) && (int) $record['book_id'] === $b1984);
$check('The book() relationship resolves the belongsTo book', $model->book($record)['title'] === '1984');
$check('The user() relationship resolves the belongsTo user', $model->user($record)['full_name'] === 'Riya Sharma');
$check('The wishlist() scope returns the want_to_read shelf', count($model->wishlist($riyaId)) === 1 && $model->wishlist($riyaId)[0]['book_id'] == $bDeepWork);
$check('The currentlyReading() scope is empty now', $model->currentlyReading($riyaId) === []);
$check('The finished() scope returns the three finished books', count($model->finished($riyaId)) === 3);
$check('The finished() scope orders by the finish date', $model->finished($riyaId)[0]['book_id'] == $b1984);
$check('The favorites() scope returns the starred books', count($model->favorites($riyaId)) === 2 && $model->favorites($riyaId)[0]['book_id'] == $b1984);
$check('The model forwards statistics()', $model->statistics($riyaId)['total'] === 4);

// ---------------------------------------------------------------------
// 10. DTO (LibraryItemDTO sanitization)
// ---------------------------------------------------------------------

echo $section('10. DTO: LibraryItemDTO');

$dto = LibraryItemDTO::fromArray(['book_id' => '12', 'status' => '  finished  ', 'favorite' => '1', 'progress' => '60'], 7);
$check('The DTO casts the ids to integers', $dto->bookId === 12 && $dto->userId === 7);
$check('The DTO trims the status', $dto->status === 'finished');
$check('The DTO casts the favourite to a bool', $dto->isFavorite === true);
$check('The DTO casts the progress to an integer', $dto->progress === 60);

$dto = LibraryItemDTO::fromArray(['book_id' => 'junk', 'favorite' => 'nope', 'progress' => 'later'], 3);
$check('The DTO neutralizes junk ids', $dto->bookId === null);
$check('The DTO neutralizes junk favourites', $dto->isFavorite === null);
$check('The DTO neutralizes junk progress', $dto->progress === null);
$check('The DTO keeps the session user fallback', $dto->userId === 3);

$dto = LibraryItemDTO::fromArray([], null);
$check('An empty input yields an all-null DTO', $dto->userId === null && $dto->bookId === null && $dto->status === null && $dto->isFavorite === null && $dto->progress === null);

// ---------------------------------------------------------------------
// 11. REQUESTS (the declarative rules)
// ---------------------------------------------------------------------

echo $section('11. REQUESTS: the validation rules');

$check('A valid store form passes', StoreLibraryRequest::passes(['book_id' => '5', 'status' => 'currently_reading', 'progress' => '42', 'favorite' => '1']));
$check('A store form without a book fails', !StoreLibraryRequest::passes(['status' => 'finished']));
$check('A store form with a junk status fails', !StoreLibraryRequest::passes(['book_id' => '5', 'status' => 'plundered']));
$check('A store form with a progress over 100 fails', !StoreLibraryRequest::passes(['book_id' => '5', 'status' => 'finished', 'progress' => '101']));
$check('A store form with a progress under 0 fails', !StoreLibraryRequest::passes(['book_id' => '5', 'status' => 'finished', 'progress' => '-3']));
$check('A store form with a junk favourite fails', !StoreLibraryRequest::passes(['book_id' => '5', 'status' => 'finished', 'favorite' => 'maybe']));
$check('Every allowed status is accepted', array_reduce(
    StoreLibraryRequest::STATUSES,
    fn (bool $ok, string $status): bool => $ok && StoreLibraryRequest::passes(['book_id' => '5', 'status' => $status]),
    true,
));

$check('An empty update form passes (partial updates)', UpdateLibraryRequest::passes([]));
$check('An update with only the status passes', UpdateLibraryRequest::passes(['status' => 'dropped']));
$check('An update with a junk status fails', !UpdateLibraryRequest::passes(['status' => 'plundered']));
$check('An update with a junk progress fails', !UpdateLibraryRequest::passes(['progress' => '150']));

// ---------------------------------------------------------------------
// 12. POLICY (the authorization matrix)
// ---------------------------------------------------------------------

echo $section('12. POLICY: the authorization matrix');

$record = $repository->findByBook($riyaId, $b1984);

// Guest context: a fresh session with nobody logged in. The policy
// answers from the SESSION auth state, so the guest path needs a
// real guest session (canAccess() has no actor parameter).
$guestSession = new Session('library_test_guest');
$guestSession->start();
AuthService::setInstance(new AuthService($guestSession, new User()));
$check('A guest can never access the library', $policy->canAccess() === false);
AuthService::setInstance($auth);

// Owner context (Riya signed in): manage and view her own record.
$session->put('auth_user_id', $riyaId);
$session->put('auth_user', ['id' => $riyaId, 'full_name' => 'Riya Sharma', 'email' => 'riya@booksphere.test', 'role' => 'user']);
$check('The owner may manage their record', $policy->canManage($record, $riyaId) === true);
$check('Another user cannot manage the record', $policy->canManage($record, $adminId) === false);
$check('An admin cannot manage another user\'s record', $policy->canManage($record, $adminId) === false);
$check('The owner may view their record', $policy->canView($record, $riyaId) === true);

// Admin context: an administrator may VIEW any record (read-only
// oversight) but still never manage it.
$session->put('auth_user_id', $adminId);
$session->put('auth_user', ['id' => $adminId, 'full_name' => 'Admin User', 'email' => 'admin@booksphere.test', 'role' => 'admin']);
$check('An admin may view any record', $policy->canView($record, $adminId) === true);
$check('An admin may not manage another user\'s record', $policy->canManage($record, $adminId) === false);

// Stranger context: a regular (non-admin) actor without an admin
// session cannot view Riya's record.
$session->put('auth_user_id', $riyaId);
$session->put('auth_user', ['id' => $riyaId, 'full_name' => 'Riya Sharma', 'email' => 'riya@booksphere.test', 'role' => 'user']);
$check('A stranger cannot view the record', $policy->canView($record, 999) === false);

// ---------------------------------------------------------------------
// 13. RECOMMENDATION HOOKS (the Phase 8.5 preparation)
// ---------------------------------------------------------------------

echo $section('13. HOOKS: the Phase 8.5 recommendation reads');

$check('favoriteBooks() exposes the starred shelf', count($service->favoriteBooks($riyaId)) === 2);
$check('completedBooks() exposes the finished shelf', count($service->completedBooks($riyaId)) === 3);
$check('readingHistory() reads the finished shelf', count($service->readingHistory($riyaId)) === 3);
$check('readingHistory() shares the finished() query', $service->readingHistory($riyaId) === $service->finished($riyaId));

$genres = $service->preferredGenres($riyaId, 5);
$check('preferredGenres() derives the library genres', is_array($genres) && count($genres) > 0);
$check('The genre rows carry id, name and count', isset($genres[0]['id'], $genres[0]['name'], $genres[0]['count']));
$check('The genres are ordered most-kept first', count($genres) === 1 || (int) $genres[0]['count'] >= (int) $genres[1]['count']);
$check('A fresh user has no preferred genres', $service->preferredGenres($adminId) === []);

// ---------------------------------------------------------------------
// 14. CONTROLLER (the JSON endpoints)
// ---------------------------------------------------------------------

echo $section('14. CONTROLLER: the endpoints');

$controller = new LibraryController($service, $policy);

$session->put('auth_user_id', $riyaId);
$session->put('auth_user', ['id' => $riyaId, 'full_name' => 'Riya Sharma', 'email' => 'riya@booksphere.test', 'role' => 'user']);

$_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';

$respond = function (callable $action): array {
    ob_start();
    $action();
    $json = json_decode((string) ob_get_clean(), true);

    return is_array($json) ? $json : [];
};

$payload = $respond(fn () => $controller->index(new Request()));
$check('index() answers the full library as JSON', count($payload['library']) === 4);

$payload = $respond(fn () => $controller->wishlist(new Request()));
$check('wishlist() answers the want_to_read shelf', count($payload['items']) === 1);

$payload = $respond(fn () => $controller->finished(new Request()));
$check('finished() answers the finished shelf', count($payload['items']) === 3);

$payload = $respond(fn () => $controller->favorites(new Request()));
$check('favorites() answers the starred shelf', count($payload['items']) === 2);

$payload = $respond(fn () => $controller->currentlyReading(new Request()));
$check('currentlyReading() answers the reading shelf', $payload['items'] === []);

$payload = $respond(fn () => $controller->statistics(new Request()));
$check('statistics() answers the overview', $payload['statistics']['total'] === 4);
$check('statistics() ships the streak for the header chip', isset($payload['streak']['current'], $payload['streak']['longest']) && (int) $payload['streak']['current'] >= 0);

$_POST = ['book_id' => (string) $bHabits, 'status' => 'finished'];
$payload = $respond(fn () => $controller->store(new Request()));
$check('store() adds a book and answers ok', $payload['ok'] === true);
$check('The stored book really landed in the library', $repository->exists($riyaId, $bHabits) === true);

$record = $repository->findByBook($riyaId, $bHabits);
$_POST = ['status' => 'currently_reading', 'progress' => '25'];
$payload = $respond(fn () => $controller->update(new Request(), ['id' => (string) $record['id']]));
$check('update() moves the record and answers ok', $payload['ok'] === true);
$check('The status change really applied', $repository->findByBook($riyaId, $bHabits)['library_status'] === 'currently_reading');
$check('The progress change really applied', (int) $repository->findByBook($riyaId, $bHabits)['progress_percentage'] === 25);

$_POST = ['favorite' => '1'];
$respond(fn () => $controller->update(new Request(), ['id' => (string) $record['id']]));
$check('update() toggles the favourite', (int) $repository->findByBook($riyaId, $bHabits)['is_favorite'] === 1);

$_POST = [];
$payload = $respond(fn () => $controller->update(new Request(), ['id' => (string) $record['id']]));
$check('An empty update is a silent no-op', $payload['ok'] === true);

$_POST = ['status' => 'plundered'];
$payload = $respond(fn () => $controller->update(new Request(), ['id' => (string) $record['id']]));
$check('An invalid update answers 422', ($payload['errors'] ?? []) !== []);

$record = $repository->findByBook($riyaId, $bHabits);
$_POST = [];
$payload = $respond(fn () => $controller->destroy(new Request(), ['id' => (string) $record['id']]));
$check('destroy() removes the book and answers ok', $payload['ok'] === true);
$check('The removed book is gone from the library', $repository->exists($riyaId, $bHabits) === false);

$_POST = ['book_id' => (string) $b1984, 'status' => 'want_to_read'];
$payload = $respond(fn () => $controller->store(new Request()));
$check('store() rejects a duplicate with 409', ($payload['error'] ?? '') !== '');

$_POST = ['book_id' => '999999', 'status' => 'want_to_read'];
$payload = $respond(fn () => $controller->store(new Request()));
$check('store() rejects a missing book', ($payload['error'] ?? '') !== '');

$_POST = ['status' => 'want_to_read'];
$payload = $respond(fn () => $controller->store(new Request()));
$check('store() rejects a form without a book id', ($payload['errors'] ?? []) !== []);

// ---------------------------------------------------------------------
// 15. DATABASE DEFENCE (UNIQUE + CHECK constraints)
// ---------------------------------------------------------------------

echo $section('15. DATABASE: the last line of defence');

$duplicate = $throws(PDOException::class, fn () => db()->execute(
    'INSERT INTO user_library (user_id, book_id, library_status)
     VALUES (?, ?, \'want_to_read\')',
    [$riyaId, $b1984],
));
$check('The UNIQUE index rejects a second record for the same book', $duplicate);

$badStatus = $throws(PDOException::class, fn () => db()->execute(
    'INSERT INTO user_library (user_id, book_id, library_status)
     VALUES (?, ?, \'plundered\')',
    [$riyaId, $bDeepWork],
));
$check('The CHECK constraint rejects an unknown status', $badStatus);

$badProgress = $throws(PDOException::class, fn () => db()->execute(
    'INSERT INTO user_library (user_id, book_id, library_status, progress_percentage)
     VALUES (?, ?, \'want_to_read\', 150)',
    [$riyaId, $bDeepWork],
));
$check('The CHECK constraint rejects progress over 100', $badProgress);

// ---------------------------------------------------------------------
// 16. REGRESSION (the existing modules keep working)
// ---------------------------------------------------------------------

echo $section('16. REGRESSION: the existing modules');

$check('The Book module still reads books', (new Book())->findById($b1984)['title'] === '1984');
$check('The Review module still aggregates reviews', (new Review())->ratingCount($b1984) >= 1);

$recommendationRepository = new RecommendationRepository(new BookRepository());
$check('The recommendation engine still reads the legacy wishlist', $recommendationRepository->wishlistBookIds($riyaId) === []);
$check('The legacy wishlist table is untouched by the library module', db()->query('SELECT COUNT(*) AS count FROM wishlist')[0]['count'] == 0);

$recService = new RecommendationService(
    new BookSphere\App\Services\RecommendationFactory(
        new BookSphere\App\Strategies\PopularBooksStrategy($recommendationRepository),
        new BookSphere\App\Strategies\HighestRatedStrategy($recommendationRepository),
        new BookSphere\App\Strategies\TrendingBooksStrategy($recommendationRepository),
        new BookSphere\App\Strategies\SameCategoryStrategy($recommendationRepository),
        new BookSphere\App\Strategies\RecentlyAddedStrategy($recommendationRepository),
        new BookSphere\App\Strategies\SameAuthorStrategy($recommendationRepository),
    ),
    $recommendationRepository,
    null,
);
$result = $recService->getPersonalizedRecommendations($riyaId, 5);
$check('The recommendation engine still builds a personalized shelf', count($result->items) === 5);

// ---------------------------------------------------------------------
// 17. PHASE 8.2 (the library UI reads + the dashboard shelf)
// ---------------------------------------------------------------------

echo $section('17. PHASE 8.2: search, counters, endpoints, dashboard');

// --- 17a. The status counters (statusCounts + the generic shelf buckets)

$counts = $service->statusCounts($riyaId);
$check('statusCounts() carries every shelf key', isset($counts['total'], $counts['favorites'], $counts['want_to_read'], $counts['currently_reading'], $counts['finished'], $counts['on_hold'], $counts['dropped']));
$check('statusCounts() matches the current shelf mix', $counts['total'] === 4 && $counts['finished'] === 3 && $counts['favorites'] === 2);

$check('shelf() answers the generic on_hold bucket', $service->shelf($riyaId, 'on_hold') === []);
$check('shelf() answers the generic dropped bucket', $service->shelf($riyaId, 'dropped') === []);
$check('shelf() rejects an unknown bucket', $throws(LibraryException::class, fn () => $service->shelf($riyaId, 'plundered')));

// --- 17b. The library search (title / author / category)

$check('searchLibrary() matches the title', count($service->searchLibrary($riyaId, 'Hobbit')) === 1 && (int) $service->searchLibrary($riyaId, 'Hobbit')[0]['book_id'] === $bHobbit);
$check('searchLibrary() matches the author name', count($service->searchLibrary($riyaId, 'Tolkien')) === 1);
$category = db()->query(
    'SELECT c.name
     FROM book_categories bc
     JOIN categories c  ON c.id = bc.category_id
     JOIN books b       ON b.id = bc.book_id
     JOIN user_library l ON l.book_id = b.id
     WHERE l.user_id = ?
     LIMIT 1',
    [$riyaId],
)[0] ?? null;
$check('searchLibrary() matches the category name', $category === null || count($service->searchLibrary($riyaId, (string) $category['name'])) >= 1);
$check('searchLibrary() falls back to the whole library on an empty query', count($service->searchLibrary($riyaId, '')) === 4);
$check('searchLibrary() stays inside the user\'s own records', $service->searchLibrary($adminId, 'Hobbit') === []);

// --- 17c. The book-detail state read (Add vs Update panel)

$state = $service->bookDetailsState($riyaId, $b1984);
$check('bookDetailsState() returns the record for a library book', is_array($state) && (int) $state['book_id'] === $b1984);
$check('bookDetailsState() is null for a book outside the library', $service->bookDetailsState($riyaId, 999999) === null);

// --- 17d. The Continue Reading shelf (currently_reading, last-updated first)

// Re-add Atomic Habits (destroyed in section 14) to the reading shelf,
// then add a second currently-reading book so the ordering can be seen.
$service->addBook(LibraryItemDTO::fromArray(['book_id' => $bHabits, 'status' => 'currently_reading', 'progress' => 55], $riyaId));
$service->addBook(LibraryItemDTO::fromArray(['book_id' => $bMockingbird, 'status' => 'currently_reading'], $riyaId));

usleep(1100000);
$service->updateProgress($riyaId, $bHabits, 30);
$continue = $service->currentlyReading($riyaId);
$check('currentlyReading() lists the reading shelf', count($continue) === 2);
$check('currentlyReading() is sorted by last updated', (int) $continue[0]['book_id'] === $bHabits && (int) $continue[1]['book_id'] === $bMockingbird);
$check('The reading rows carry the progress the dashboard shows', (int) $continue[0]['progress_percentage'] === 30);

$counts = $service->statusCounts($riyaId);
$check('The counters reflect the two reading books', $counts['currently_reading'] === 2 && $counts['total'] === 6);

// --- 17e. The Phase 8.2 controller endpoints (search, favourite, progress)

$_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';

$_GET = ['q' => 'Hobbit'];
$_POST = [];
$payload = $respond(fn () => $controller->search(new Request()));
$check('search() answers a rendered results fragment', isset($payload['html'], $payload['total']) && $payload['total'] === 1 && str_contains((string) $payload['html'], 'The Hobbit'));

// 1984 is a favourite (section 6); toggle it off, then back on.
$record = $repository->findByBook($riyaId, $b1984);
$_POST = [];
$payload = $respond(fn () => $controller->toggleFavourite(new Request(), ['id' => (string) $record['id']]));
$check('toggleFavourite() answers the new state', $payload['favorite'] === false);
$check('The un-favourite really applied', (int) $repository->findByBook($riyaId, $b1984)['is_favorite'] === 0);
$payload = $respond(fn () => $controller->toggleFavourite(new Request(), ['id' => (string) $record['id']]));
$check('toggleFavourite() flips back to a favourite', $payload['favorite'] === true);
$check('The re-favourite really applied', (int) $repository->findByBook($riyaId, $b1984)['is_favorite'] === 1);

$record = $repository->findByBook($riyaId, $bHabits);
$_POST = ['progress' => '66'];
$payload = $respond(fn () => $controller->updateProgress(new Request(), ['id' => (string) $record['id']]));
$check('updateProgress() answers the fresh progress and status', $payload['progress'] === 66 && $payload['status'] === 'currently_reading');
$check('updateProgress() crossed the library', (int) $repository->findByBook($riyaId, $bHabits)['progress_percentage'] === 66);

$_POST = ['progress' => '101'];
$payload = $respond(fn () => $controller->updateProgress(new Request(), ['id' => (string) $record['id']]));
$check('updateProgress() rejects an out-of-range value', ($payload['errors'] ?? []) !== []);

// --- 17f. The dashboard "Continue Reading" shelf (Phase 8.2 view)

$dashboardController = new \BookSphere\App\Controllers\DashboardController(null, $service);
$session->put('auth_user_id', $riyaId);
$session->put('auth_user', ['id' => $riyaId, 'full_name' => 'Riya Sharma', 'email' => 'riya@booksphere.test', 'role' => 'user']);
ob_start();
$dashboardController->index(new Request());
$dashboardHtml = (string) ob_get_clean();
$check('The dashboard renders the Continue Reading section', str_contains($dashboardHtml, 'Continue Reading'));
$check('The dashboard lists the currently reading books', str_contains($dashboardHtml, 'Atomic Habits') && str_contains($dashboardHtml, 'To Kill a Mockingbird'));
$check('The continue cards show the progress bar', str_contains($dashboardHtml, 'continue-progress'));
$check('The continue cards carry a Resume link', str_contains($dashboardHtml, 'Resume'));

// A user with nothing in the reading shelf sees the empty state.
$session->put('auth_user_id', $adminId);
$session->put('auth_user', ['id' => $adminId, 'full_name' => 'Admin User', 'email' => 'admin@booksphere.test', 'role' => 'admin']);
ob_start();
$dashboardController->index(new Request());
$dashboardEmptyHtml = (string) ob_get_clean();
$check('An empty reading shelf renders the empty state', str_contains($dashboardEmptyHtml, 'You are not reading anything right now'));

// ---------------------------------------------------------------------
// 18. PHASE 8.3 (the library dashboard: grid reads, summaries, streaks,
//     preferences, filter/sort/view/continue-reading endpoints)
// ---------------------------------------------------------------------

echo $section('18. PHASE 8.3: dashboard grid, summaries, preferences, endpoints');

// --- 18a. The new user_preferences schema (migration 0018)

$prefColumns = array_column(db()->query('PRAGMA table_info(user_preferences)'), 'name');
$check('The preferences table carries its four columns', in_array('user_id', $prefColumns, true) && in_array('library_sort', $prefColumns, true) && in_array('library_view', $prefColumns, true) && in_array('updated_at', $prefColumns, true));

$prefInfo = [];
foreach (db()->query('PRAGMA table_info(user_preferences)') as $row) {
    $prefInfo[$row['name']] = $row;
}
$check('user_id is the primary key (one row per user)', (int) $prefInfo['user_id']['pk'] === 1);
$check('The library_sort default is newest_added', str_contains((string) $prefInfo['library_sort']['dflt_value'], 'newest_added'));
$check('The library_view default is grid', str_contains((string) $prefInfo['library_view']['dflt_value'], 'grid'));

$prefSql = (string) (db()->query(
    "SELECT sql
     FROM sqlite_master
     WHERE type = 'table' AND name = 'user_preferences'",
)[0]['sql'] ?? '');
$check('The library_view CHECK allows only grid and list', str_contains($prefSql, 'grid') && str_contains($prefSql, 'list'));

$prefFks = db()->query('PRAGMA foreign_key_list(user_preferences)');
$check('The preferences row cascades with the user', ($prefFks[0]['table'] ?? '') === 'users' && ($prefFks[0]['on_delete'] ?? '') === 'CASCADE');
$check('The preferences CHECK rejects a junk view at the database', $throws(\PDOException::class, fn () => db()->execute("INSERT INTO user_preferences (user_id, library_view) VALUES (?, 'bogus')", [$adminId])));

// --- 18b. The repository grid reads (filter / countFiltered / paginate)

$grid = $repository->filter($riyaId, ['q' => 'Hobbit']);
$check('filter() searches the title', count($grid) === 1 && (string) $grid[0]['book_title'] === 'The Hobbit');
$check('filter() searches the publisher', count($repository->filter($riyaId, ['q' => 'HarperCollins'])) === 1);
$check('countFiltered() matches the status shelf', $repository->countFiltered($riyaId, ['status' => 'currently_reading']) === 2);
$check('countFiltered() matches the favourites', $repository->countFiltered($riyaId, ['favorite' => 1]) === 2);
$check('countFiltered() matches an empty shelf', $repository->countFiltered($riyaId, ['status' => 'dropped']) === 0);
$check('countFiltered() ignores junk filters', $repository->countFiltered($riyaId, ['status' => 'plundered', 'category' => -7]) === 6);

$page = $repository->paginate($riyaId, [], 'title_asc', 1, 3);
$check('paginate() returns the full payload', isset($page['items'], $page['total'], $page['page'], $page['pages'], $page['per_page'], $page['has_prev'], $page['has_next']));
$check('paginate() splits the page math correctly', $page['total'] === 6 && $page['pages'] === 2 && $page['per_page'] === 3 && $page['page'] === 1 && $page['has_prev'] === false && $page['has_next'] === true);
$check('paginate() orders titles ascending', (string) $page['items'][0]['book_title'] === '1984');
$check('paginate() returns descending titles', (string) $repository->paginate($riyaId, [], 'title_desc', 1, 50)['items'][0]['book_title'] === 'To Kill a Mockingbird');
$check('paginate() clamps a wild page number', $repository->paginate($riyaId, [], 'newest_added', 999, 12)['page'] === 1);
$check('paginate() sorts by progress first', (int) $repository->paginate($riyaId, [], 'progress', 1, 50)['items'][0]['progress_percentage'] === 100);
$check('paginate() sorts by rating', (float) $repository->paginate($riyaId, [], 'highest_rated', 1, 50)['items'][0]['book_average_rating'] === 4.4);

$options = $repository->filterOptions($riyaId);
$check('filterOptions() lists the genres and authors', count($options['categories']) === 5 && count($options['authors']) === 6);

// --- 18c. The reading summary and the reading streak

$summary = $repository->readingSummary($riyaId);
$check('readingSummary() picks the most-kept genre', $summary['favourite_genre'] === 'Classic Fiction');
$check('readingSummary() picks the favourite author', $summary['favourite_author'] === 'Andy Weir');
$check('readingSummary() averages the approved reviews', $summary['average_rating_given'] === 4.7);
$check('readingSummary() averages the started progress', $summary['average_progress'] === 91.5);
$check('readingSummary() counts the finished shelf', $summary['finished'] === 3);

$streak = $repository->readingStreak($riyaId);
$check('readingStreak() counts the alive run', $streak['current'] >= 1 && $streak['longest'] >= 1 && $streak['current'] <= $streak['longest']);
$check('readingStreak() is empty for a bare user', $repository->readingStreak($adminId) === ['current' => 0, 'longest' => 0]);

// --- 18d. The service facade (filterLibrary / viewPreference / dashboard)

$page = $service->filterLibrary($riyaId, ['q' => 'Hobbit']);
$check('filterLibrary() answers the normalized page', $page['total'] === 1 && count($page['items']) === 1);
$check('filterLibrary() drops a junk status and a junk sort', $service->filterLibrary($riyaId, ['status' => 'plundered'], 'plundered')['total'] === 6);

$prefs = $service->viewPreference($riyaId);
$check('viewPreference() answers the defaults first', $prefs === ['sort' => 'newest_added', 'view' => 'grid']);
$prefs = $service->viewPreference($riyaId, 'title_asc', 'list');
$check('viewPreference() applies and persists a change', $prefs === ['sort' => 'title_asc', 'view' => 'list']);
$check('The stored sort really landed', $repository->preference($riyaId, 'library_sort') === 'title_asc');
$check('The stored view really landed', $repository->preference($riyaId, 'library_view') === 'list');
$prefs = $service->viewPreference($riyaId, 'bogus_sort', 'bogus_view');
$check('viewPreference() ignores junk values', $prefs === ['sort' => 'title_asc', 'view' => 'list']);
$prefs = $service->viewPreference($riyaId, 'highest_rated', 'grid');
$check('viewPreference() stores the next valid pair', $prefs === ['sort' => 'highest_rated', 'view' => 'grid']);
$check('savePreferences() left exactly one preferences row', (int) db()->query('SELECT COUNT(*) AS count FROM user_preferences WHERE user_id = ?', [$riyaId])[0]['count'] === 1);

// Phase 8.6: a preference change is audit-logged (and an ignored
// junk pair is NOT - the two real changes above are the only ones).
$prefLog = is_file($logFile) ? (string) file_get_contents($logFile) : '';
$check('A preference change leaves an audit entry', substr_count($prefLog, 'library.preference_changed') === 2);
$check('The preference log carries the user and the new pair', str_contains($prefLog, (string) $riyaId) && str_contains($prefLog, 'highest_rated') && str_contains($prefLog, '"sort"'));

$repoSummary = $service->readingSummary($riyaId);
$repoStreak   = $service->readingStreak($riyaId);
$dashboard    = $service->libraryDashboard($riyaId);
$check('libraryDashboard() composes statistics, summary and streak', isset($dashboard['statistics'], $dashboard['summary'], $dashboard['streak'], $dashboard['recommended']));
$check('The dashboard statistics match the library', $dashboard['statistics']['total'] === 6 && $dashboard['statistics']['favorites'] === 2);
$check('The dashboard carries the summary numbers', $dashboard['summary'] === $repoSummary && $dashboard['streak'] === $repoStreak);
$check('Without a recommendation engine the badge set is empty', $dashboard['recommended'] === []);
$check('continueReading() answers the reading shelf', count($service->continueReading($riyaId)) === 2);

// --- 18e. The Phase 8.3 controller endpoints (filter / sort / view-mode)

$_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';
$session->put('auth_user_id', $riyaId);
$session->put('auth_user', ['id' => $riyaId, 'full_name' => 'Riya Sharma', 'email' => 'riya@booksphere.test', 'role' => 'user']);

$_GET = ['q' => 'Hobbit'];
$_POST = [];
$payload = $respond(fn () => $controller->filter(new Request()));
$check('filter() answers the filtered fragment', ($payload['total'] ?? 0) === 1 && str_contains((string) ($payload['html'] ?? ''), 'The Hobbit'));

$_GET = ['sort' => 'title_asc'];
$_POST = [];
$payload = $respond(fn () => $controller->sort(new Request()));
$check('sort() answers the sorted fragment', ($payload['total'] ?? 0) === 6 && str_contains((string) ($payload['html'] ?? ''), 'To Kill a Mockingbird'));
$check('sort() persisted the picked sort', $repository->preference($riyaId, 'library_sort') === 'title_asc');

$_GET = [];
$_POST = ['view' => 'list'];
$payload = $respond(fn () => $controller->viewMode(new Request()));
$check('viewMode() answers the new view', ($payload['ok'] ?? false) === true && ($payload['view'] ?? '') === 'list');
$check('viewMode() persisted the view', $repository->preference($riyaId, 'library_view') === 'list');

$_POST = ['view' => 'bogus'];
$payload = $respond(fn () => $controller->viewMode(new Request()));
$check('viewMode() ignores a junk view', ($payload['view'] ?? '') === 'list' && $repository->preference($riyaId, 'library_view') === 'list');

$payload = $respond(fn () => $controller->continueReading(new Request()));
$check('continueReading() answers the resume fragment', ($payload['total'] ?? 0) === 2 && str_contains((string) ($payload['html'] ?? ''), 'Atomic Habits'));

$_POST = [];
$_GET = [];

// ---------------------------------------------------------------------
// 19. PHASE 8.4 (Smart Collections, extended search / sorts, bulk
//     actions, dashboard + profile integration)
// ---------------------------------------------------------------------

echo $section('19. PHASE 8.4: collections, search, sorts, bulk, integration');

// --- 19a. The Smart Collections statistics (collectionStatistics)

$collectionStats = $service->collectionStatistics($riyaId);
$check('collectionStatistics() carries every collection id', count(array_intersect(array_keys($collectionStats), ['all', 'want_to_read', 'currently_reading', 'finished', 'on_hold', 'dropped', 'favorites'])) === 7);
$check('Every collection row carries count / rating / updated', count(array_filter($collectionStats, fn (array $row): bool => array_key_exists('count', $row) && array_key_exists('average_rating', $row) && array_key_exists('last_updated', $row))) === count($collectionStats));
$check('The "all" collection counts the whole library', (int) $collectionStats['all']['count'] === 6);
$check('The collection counts match the shelves', (int) $collectionStats['finished']['count'] === 3 && (int) $collectionStats['favorites']['count'] === 2 && (int) $collectionStats['currently_reading']['count'] === 2);
$check('The average rating is rounded to one decimal', in_array($collectionStats['finished']['average_rating'], [4.3, 4.2], true) && is_float((float) $collectionStats['finished']['average_rating']));
$check('The empty shelves read zero', (int) $collectionStats['on_hold']['count'] === 0 && (int) $collectionStats['dropped']['count'] === 0);
$check('An empty library still answers every collection id', count(array_intersect(array_keys($service->collectionStatistics($adminId)), ['all', 'want_to_read', 'currently_reading', 'finished', 'on_hold', 'dropped', 'favorites'])) === 7 && (int) $service->collectionStatistics($adminId)['all']['count'] === 0);

// --- 19b. The recently added / recently updated reads

$recentAdded = $service->recentlyAdded($riyaId, 50);
$check('recentlyAdded() is newest-first', count($recentAdded) === 6 && (int) $recentAdded[0]['book_id'] === $bMockingbird);
$check('recentlyAdded() respects the limit', count($service->recentlyAdded($riyaId, 3)) === 3);
$recentUpdated = $service->recentlyUpdated($riyaId, 50);
$check('recentlyUpdated() leads with the last-touched book (Atomic Habits)', count($recentUpdated) === 6 && (int) $recentUpdated[0]['book_id'] === $bHabits);
$check('recentlyUpdated() shares the footer of the NEWEST_ADDED sort', (int) $repository->recentlyUpdated($riyaId)[0]['book_id'] === $bHabits);

// --- 19c. The Advanced Search now reaches the description

$descWord = 'totalitarian';
$bRow = db()->query('SELECT title, publisher, language FROM books WHERE id = ?', [$b1984])[0];
$bNonDescription = $bRow['title'] . ' ' . $bRow['publisher'] . ' ' . $bRow['language'];
$check('The seed word appears only in the description', stripos($bNonDescription, $descWord) === false);
$descHits = $service->searchLibrary($riyaId, $descWord);
$check('searchLibrary() matches the description', count($descHits) === 1 && (int) ($descHits[0]['book_id'] ?? 0) === $b1984);
$check('filterLibrary() reaches the description too', $service->filterLibrary($riyaId, ['q' => $descWord])['total'] === 1);
$check('The description search stays inside the user\'s own library', $service->searchLibrary($adminId, $descWord) === []);

// --- 19d. The two new sorts (Most Reviewed / Most Recommended)

$mostReviewed = $repository->paginate($riyaId, [], 'most_reviewed', 1, 50);
$check('most_reviewed() ranks the reviewed books first', (int) $mostReviewed['items'][0]['book_id'] === $bMockingbird && (int) $mostReviewed['items'][count($mostReviewed['items']) - 1]['book_id'] === $bHobbit);
$check('The book_review_count column ships with the rows', (int) $mostReviewed['items'][0]['book_review_count'] === 1);

$mostRecommended = $repository->paginate($riyaId, [], 'most_recommended', 1, 50);
$check('most_recommended degrades to ratings_count without an engine', (int) $mostRecommended['items'][0]['book_id'] === $bHabits);
$recommendedFirst = $repository->paginate($riyaId, [], 'most_recommended', 1, 50, [$b1984]);
$check('most_recommended honours the engine suggestion set', (int) $recommendedFirst['items'][0]['book_id'] === $b1984);
$check('filterLibrary() swallows an unknown sort', $service->filterLibrary($riyaId, [], 'plundered-ish')['total'] === 6);

// --- 19e. The bulk actions (move / favourite / delete)

// The two currently-reading rows (Atomic Habits + Mockingbird).
$bulkIds = array_map(fn (array $row): int => (int) $row['id'], $repository->currentlyReading($riyaId, 50));
$bulkIds = array_merge($bulkIds, [0, -7, 'junk']); // junk must be ignored, never crash
$check('bulkStatus() moves every owned record', $service->bulkStatus($riyaId, $bulkIds, 'finished') >= 2);
$check('The moved records really landed on the shelf', count($repository->findByStatus($riyaId, 'finished', 50)) === 5);
$check('bulkStatus() never stamps the lifecycle timestamps', $repository->findByBook($riyaId, $bHabits)['finished_reading_at'] === null);
$check('bulkStatus() rejects an unknown status', $throws(LibraryException::class, fn () => $service->bulkStatus($riyaId, [1], 'plundered')));

// Undo the move so the following counters see the original shelf mix.
$check('bulkStatus() restores the reading shelf', $service->bulkStatus($riyaId, $bulkIds, 'currently_reading') >= 2 && count($repository->currentlyReading($riyaId, 50)) === 2);

// A foreign record id in the list is skipped by the owner gate: plant a
// temporary Riya record and try to delete BOTH it and an admin's record.
$tempRiyaBook = $bookId('The God of Small Things');
$tempRiyaId   = $service->addBook(LibraryItemDTO::fromArray(['book_id' => $tempRiyaBook], $riyaId));
$foreignId    = $service->addBook(LibraryItemDTO::fromArray(['book_id' => $bDeepWork], $adminId));
$check('bulkDelete() removes only the caller\'s records', $service->bulkDelete($riyaId, [$tempRiyaId, $foreignId]) === 1);
$check('The deleted record is really gone', $repository->find($tempRiyaId) === null);
$check('The foreign record survived the bulk delete', $repository->find($foreignId) !== null);
$check('The rest of the library is untouched', count($repository->findByUser($riyaId, 50)) === 6);

// The favourite / un-favourite bulk.
$check('bulkFavorite() stars every owned book', $service->bulkFavorite($riyaId, $bulkIds, true) >= 2 && count($repository->favorites($riyaId)) === 4);
$check('bulkFavorite() un-stars again', $service->bulkFavorite($riyaId, $bulkIds, false) >= 2 && count($repository->favorites($riyaId)) === 2);

// --- 19f. The Phase 8.4 controller bulk endpoint

$_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';
$session->put('auth_user_id', $riyaId);
$session->put('auth_user', ['id' => $riyaId, 'full_name' => 'Riya Sharma', 'email' => 'riya@booksphere.test', 'role' => 'user']);

$_POST = ['ids' => $bulkIds, 'action' => 'favorite'];
$payload = $respond(fn () => $controller->bulk(new Request()));
$check('bulk() favourite answers the affected count', ($payload['ok'] ?? false) === true && ($payload['affected'] ?? 0) >= 2);
$check('The favourite bulk really applied', count($repository->favorites($riyaId)) === 4);

$_POST = ['ids' => $bulkIds, 'action' => 'unfavorite'];
$respond(fn () => $controller->bulk(new Request()));
$check('bulk() un-favourite answered', count($repository->favorites($riyaId)) === 2);

$_POST = ['ids' => [], 'action' => 'move_status', 'status' => 'want_to_read'];
$payload = $respond(fn () => $controller->bulk(new Request()));
$check('bulk() requires a non-empty selection', ($payload['errors']['ids'] ?? []) !== []);

$_POST = ['ids' => $bulkIds, 'action' => 'plundered'];
$payload = $respond(fn () => $controller->bulk(new Request()));
$check('bulk() rejects an unknown action', ($payload['errors']['action'] ?? []) !== []);

$_POST = ['ids' => $bulkIds, 'action' => 'move_status', 'status' => 'plundered'];
$payload = $respond(fn () => $controller->bulk(new Request()));
$check('bulk() rejects an invalid status', ($payload['error'] ?? '') !== '');

// --- 19g. The dashboard integration (recently added / favourites /
//           library overview / collections quick access)

$_GET = [];
$session->put('auth_user_id', $riyaId);
$session->put('auth_user', ['id' => $riyaId, 'full_name' => 'Riya Sharma', 'email' => 'riya@booksphere.test', 'role' => 'user']);
ob_start();
$dashboardController->index(new Request());
$dashboardHtml = (string) ob_get_clean();
$check('The dashboard renders the Recently Added shelf', str_contains($dashboardHtml, 'Recently Added'));
$check('The Recently Added shelf lists the newest additions', str_contains($dashboardHtml, 'Atomic Habits') || str_contains($dashboardHtml, 'To Kill a Mockingbird'));
$check('The dashboard renders the My Favourite Books shelf', str_contains($dashboardHtml, 'My Favourite Books'));
$check('The dashboard renders the Library Overview', str_contains($dashboardHtml, 'Library Overview'));
$check('The dashboard paints the overview numbers', str_contains($dashboardHtml, 'Total Books') && str_contains($dashboardHtml, 'Favourites'));
$check('The dashboard shows the collections quick access', str_contains($dashboardHtml, 'library-collections--quick'));

// The same shared LibraryService feeds the profile's "My Library" block.
$profileController = new \BookSphere\App\Controllers\UserController($auth, $users, null, $service);
ob_start();
$profileController->show(new Request());
$profileHtml = (string) ob_get_clean();
$check('The profile renders the My Library section', str_contains($profileHtml, 'My Library'));
$check('The profile lists the favourite books', str_contains($profileHtml, 'Favourite books'));
$check('The profile lists the recently added books', str_contains($profileHtml, 'Recently added'));
$check('The profile lists the recently finished books', str_contains($profileHtml, 'Recently finished'));

// Phase 8.6: a session that outlived its user row must answer a safe
// 404 instead of indexing a missing profile. The probe runs in a
// subprocess because Response::error() exits the process.
$probeRoot = root_path();
$probePath = sys_get_temp_dir() . '/booksphere_profile_404_probe.php';
$probeCode = '<?php' . PHP_EOL
    . 'declare(strict_types=1);' . PHP_EOL . PHP_EOL
    . 'require ' . var_export($probeRoot . '/bootstrap/constants.php', true) . ';' . PHP_EOL
    . 'require ' . var_export($probeRoot . '/vendor/autoload.php', true) . ';' . PHP_EOL . PHP_EOL
    . 'use BookSphere\\App\\Core\\Database;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Environment;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Request;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Session;' . PHP_EOL
    . 'use BookSphere\\App\\Models\\User;' . PHP_EOL
    . 'use BookSphere\\App\\Controllers\\UserController;' . PHP_EOL
    . 'use BookSphere\\App\\Services\\AuthService;' . PHP_EOL . PHP_EOL
    . '(new Environment(root_path(\'.env\')))->load();' . PHP_EOL
    . 'Database::instance(root_path(\'database/library_test.db\'));' . PHP_EOL
    . '$session = new Session(\'library_test_404_probe\');' . PHP_EOL
    . '$session->start();' . PHP_EOL
    . '$auth = new AuthService($session, new User());' . PHP_EOL
    . 'AuthService::setInstance($auth);' . PHP_EOL
    . '$missingUserId = (int) ($argv[1] ?? \'0\');' . PHP_EOL
    . '$session->put(\'auth_user_id\', $missingUserId);' . PHP_EOL
    . '$session->put(\'auth_user\', [\'id\' => $missingUserId, \'full_name\' => \'Ghost\', \'email\' => \'ghost@test.dev\', \'role\' => \'user\']);' . PHP_EOL
    . '(new UserController($auth, new User()))->show(new Request());' . PHP_EOL;
file_put_contents($probePath, $probeCode);

$missingUserId = ((int) db()->query('SELECT MAX(id) AS max_id FROM users')[0]['max_id']) + 1;
$probeOutput = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' ' . $missingUserId . ' 2>&1');
$check('A session without a user row answers 404, not a crash', trim($probeOutput) === 'Profile not found.');
unlink($probePath);

$_GET = [];

// ---------------------------------------------------------------------
// RESULT
// ---------------------------------------------------------------------

echo $section('RESULT');
echo '  Checks: ' . $checks . PHP_EOL;
echo '  Failed: ' . $failures . PHP_EOL;

echo PHP_EOL . 'Note: the throwaway database database/library_test.db and the log file ' . $logFile . ' are left in place for inspection; delete them anytime.' . PHP_EOL;

exit($failures === 0 ? 0 : 1);