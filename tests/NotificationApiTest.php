<?php

declare(strict_types=1);

/**
 * NotificationApiTest — CLI test suite for Phase 9.3 (Notification API)
 *
 * Verifies the backend API of the notification system built on the
 * Phase 9.2 stack:
 *
 *     1. Controller reads  - GET /notifications (the paginate()
 *                            payload: newest first, the tab filter,
 *                            the page/perPage bounds, the foreign-row
 *                            exclusion)
 *     2. Controller writes - markRead / markAllRead / destroy /
 *                            deleteAll: the ok answers, the idempotent
 *                            re-read, the RESTful 404 of a missing,
 *                            FOREIGN or already-deleted id (the IDOR
 *                            shield)
 *     3. Dual answer       - the no-JS fallback: redirect + flash
 *                            instead of JSON (subprocess probes), and
 *                            the AuthMiddleware guest gate
 *     4. Router            - all five routes registered and
 *                            dispatched (GET + the PATCH/DELETE
 *                            _method override), exact vs parameterized
 *                            patterns
 *     5. review_reacted    - the first "helpful" vote pings the
 *                            review's OWNER once; re-voting and an
 *                            opted-out recipient do not
 *     6. library_milestone - finishing a book pings once (status
 *                            change AND the 100% auto-finish); other
 *                            transitions do not
 *     7. recommendation_ready - a fresh shelf generation pings a
 *                            user WITH signals; a cache hit and a
 *                            signal-free user do not
 *     8. Regression        - the follow ping and the other modules
 *                            keep working next to the new API
 *
 * Run from the project root:
 *
 *     php tests/NotificationApiTest.php
 *
 * The throwaway database (database/notification_api_test.db) is
 * migrated, seeded and left in place for inspection; delete it
 * anytime.
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Controllers\NotificationController;
use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Core\Logger;
use BookSphere\App\Core\MiddlewarePipeline;
use BookSphere\App\Core\Migrator;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Router;
use BookSphere\App\Core\Seeder;
use BookSphere\App\Core\Session;
use BookSphere\App\Middleware\AuthMiddleware;
use BookSphere\App\Models\Author;
use BookSphere\App\Models\AuthorFollow;
use BookSphere\App\Models\Book;
use BookSphere\App\Models\Notification;
use BookSphere\App\Models\Review;
use BookSphere\App\Models\User;
use BookSphere\App\Models\UserLibrary;
use BookSphere\App\Repositories\BookRepository;
use BookSphere\App\Repositories\RecommendationRepository;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\FollowService;
use BookSphere\App\Services\LibraryService;
use BookSphere\App\Services\NotificationDispatcher;
use BookSphere\App\Services\NotificationFormatter;
use BookSphere\App\Services\NotificationService;
use BookSphere\App\Services\PersonalizationCache;
use BookSphere\App\Services\RecommendationFactory;
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

$dbPath = root_path('database/notification_api_test.db');

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
$session = new Session('notification_api_test');
$session->start();
$auth = new AuthService($session, new User());
AuthService::setInstance($auth);

$logFile = sys_get_temp_dir() . '/booksphere_notification_api_test.log';
if (is_file($logFile)) {
    unlink($logFile);
}

// ---------------------------------------------------------------------
// Shared fixtures (resolved from the seed data by email / name).
// ---------------------------------------------------------------------

$users   = new User();
$admin   = $users->findByEmail('admin@booksphere.test');
$riya    = $users->findByEmail('riya@booksphere.test');
$riyaId  = (int) $riya['id'];
$adminId = (int) $admin['id'];

// Two seeded books for the integration pings (the titles travel in
// the formatted messages, so the test proves the REAL substitution).
$books = db()->query('SELECT id, title FROM books ORDER BY id');
$bookA = $books[0];
$bookB = $books[1];
$bookC = $books[2];

$authorId = fn (string $name): int => (int) db()->query('SELECT id FROM authors WHERE name = ?', [$name])[0]['id'];
$harper = $authorId('Harper Lee');    // id 1 == the admin's user id (never self-follow)
$jk     = $authorId('J.K. Rowling');

// The module stack, wired EXACTLY like routes/web.php: ONE dispatcher
// shared by the service, the controller, the review service, the
// library service, the recommendation service and the follow service.
$notifications = new Notification();
$formatter     = new NotificationFormatter();
$dispatcher    = new NotificationDispatcher($notifications, $formatter, new Logger($logFile));
$service       = new NotificationService($notifications, $dispatcher, new Logger($logFile));
$controller    = new NotificationController($service);

// The API tests act as riya (the session user of every endpoint).
$session->put('auth_user_id', $riyaId);
$session->put('auth_user', ['id' => $riyaId, 'full_name' => 'Riya Sharma', 'email' => 'riya@booksphere.test', 'role' => 'user']);

$section = fn (string $title): string => "\n------------------------------------------------------------------------\n{$title}\n------------------------------------------------------------------------";
$check   = function (string $label, bool $ok): void {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . PHP_EOL;
    $GLOBALS['failures'] = ($GLOBALS['failures'] ?? 0) + ($ok ? 0 : 1);
    $GLOBALS['checks']   = ($GLOBALS['checks'] ?? 0) + 1;
};
$capture = function (callable $fn): string {
    ob_start();
    $fn();

    return (string) ob_get_clean();
};
$json = function (callable $fn) use ($capture): array {
    $decoded = json_decode($capture($fn), true);

    return is_array($decoded) ? $decoded : [];
};
$failures = 0;
$checks   = 0;

$fetch = function (): void {
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';
};
$noFetch = function (): void {
    unset($_SERVER['HTTP_X_REQUESTED_WITH']);
};

// A clean slate for the API sections: no leftover rows from the seed.
db()->execute('DELETE FROM notifications');

// ---------------------------------------------------------------------
// 1. CONTROLLER: the GET /notifications read
// ---------------------------------------------------------------------

echo $section('1. CONTROLLER: the notification feed (GET /notifications)');

$mine = [
    $service->notifyFor($riyaId, 'admin_alert', []),
    $service->notifyFor($riyaId, 'admin_alert', []),
    $service->notifyFor($riyaId, 'admin_alert', []),
];
$foreign = $service->notifyFor($adminId, 'system_announcement', ['title' => 'Maintenance', 'message' => 'Tonight']);

$fetch();
$payload = $json(fn () => $controller->index(new Request()));
$noFetch();

$check('index() answers the paginate() payload', isset($payload['items'], $payload['total'], $payload['pages'], $payload['has_next']));
$check('The feed counts only the caller\'s rows', ($payload['total'] ?? 0) === 3 && count($payload['items']) === 3);
$check('The foreign row never leaks into the feed', array_column($payload['items'], 'id') === array_values(array_reverse($mine)));
$check('The feed is newest first', (int) $payload['items'][0]['id'] === $mine[2]);

$_GET = ['per_page' => '2', 'page' => '2'];
$fetch();
$payload = $json(fn () => $controller->index(new Request()));
$noFetch();
$check('?per_page + ?page paginate the feed', ($payload['total'] ?? 0) === 3 && count($payload['items']) === 1 && ($payload['has_prev'] ?? null) === true && ($payload['has_next'] ?? null) === false);

$_GET = ['per_page' => '2', 'page' => '99'];
$fetch();
$payload = $json(fn () => $controller->index(new Request()));
$noFetch();
$check('An out-of-range page clamps to the last page', ($payload['page'] ?? 0) === 2);

$_GET = ['per_page' => '999'];
$fetch();
$payload = $json(fn () => $controller->index(new Request()));
$noFetch();
$check('per_page clamps to the 50 bound', ($payload['per_page'] ?? 0) === 50);

$service->markRead($mine[1], $riyaId);

$_GET = ['tab' => 'unread'];
$fetch();
$payload = $json(fn () => $controller->index(new Request()));
$noFetch();
$check('?tab=unread filters to the unread rows', ($payload['total'] ?? 0) === 2);

$_GET = ['tab' => 'read'];
$fetch();
$payload = $json(fn () => $controller->index(new Request()));
$noFetch();
$check('?tab=read filters to the read rows', ($payload['total'] ?? 0) === 1 && (int) $payload['items'][0]['id'] === $mine[1]);

$_GET = ['tab' => 'junk'];
$fetch();
$payload = $json(fn () => $controller->index(new Request()));
$noFetch();
$check('An unknown tab falls back to the full feed', ($payload['total'] ?? 0) === 3);

$_GET = [];
$fetch();
$payload = $json(fn () => $controller->index(new Request()));
$noFetch();
$check('The feed items carry the formatted content', isset($payload['items'][0]['title'], $payload['items'][0]['message'], $payload['items'][0]['icon'], $payload['items'][0]['color'])
    && array_key_exists('action_url', $payload['items'][0]));

// ---------------------------------------------------------------------
// 2. CONTROLLER: the state-changing writes
// ---------------------------------------------------------------------

echo $section('2. CONTROLLER: the read/delete endpoints');

$fetch();

$payload = $json(fn () => $controller->markRead(new Request(), ['id' => (string) $mine[0]]));
$dbRow = db()->query('SELECT is_read FROM notifications WHERE id = ?', [$mine[0]])[0];
$check('markRead() answers {ok: true}', ($payload['ok'] ?? null) === true);
$check('markRead() persists the read flag', (int) $dbRow['is_read'] === 1);

$payload = $json(fn () => $controller->markRead(new Request(), ['id' => (string) $mine[0]]));
$check('Re-reading an already-read row stays ok (idempotent)', ($payload['ok'] ?? null) === true);

$payload = $json(fn () => $controller->markRead(new Request(), ['id' => (string) $foreign]));
$check('markRead() on a FOREIGN row answers 404', ($payload['error'] ?? '') === 'Notification not found.');

$payload = $json(fn () => $controller->markRead(new Request(), ['id' => '999999']));
$check('markRead() on a missing row answers 404', ($payload['error'] ?? '') === 'Notification not found.');

$payload = $json(fn () => $controller->markRead(new Request(), ['id' => 'junk']));
$check('markRead() on a tampered id answers 404', ($payload['error'] ?? '') === 'Notification not found.');

$payload = $json(fn () => $controller->markAllRead(new Request()));
$check('markAllRead() answers {ok: true, changed: N}', ($payload['ok'] ?? null) === true && ($payload['changed'] ?? 0) === 1);
$left = (int) db()->query('SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0', [$riyaId])[0]['c'];
$check('markAllRead() reads every remaining row', $left === 0);

$payload = $json(fn () => $controller->markAllRead(new Request()));
$check('A second markAllRead changes 0 and stays ok', ($payload['changed'] ?? 1) === 0 && ($payload['ok'] ?? null) === true);

$payload = $json(fn () => $controller->destroy(new Request(), ['id' => (string) $mine[2]]));
$gone = (int) db()->query('SELECT COUNT(*) AS c FROM notifications WHERE id = ?', [$mine[2]])[0]['c'];
$check('destroy() answers {ok: true} and removes the row', ($payload['ok'] ?? null) === true && $gone === 0);

$payload = $json(fn () => $controller->destroy(new Request(), ['id' => (string) $mine[2]]));
$check('destroy() on an already-gone row answers 404', ($payload['error'] ?? '') === 'Notification not found.');

$payload = $json(fn () => $controller->destroy(new Request(), ['id' => (string) $foreign]));
$check('destroy() on a FOREIGN row answers 404', ($payload['error'] ?? '') === 'Notification not found.');

$payload = $json(fn () => $controller->deleteAll(new Request()));
$check('deleteAll() answers {ok: true, deleted: N}', ($payload['ok'] ?? null) === true && ($payload['deleted'] ?? 0) === 2);

$payload = $json(fn () => $controller->deleteAll(new Request()));
$check('A second deleteAll deletes 0 and stays ok', ($payload['deleted'] ?? 1) === 0 && ($payload['ok'] ?? null) === true);

$noFetch();

// ---------------------------------------------------------------------
// 3. PROBES: the no-JS fallback and the guest gate
// ---------------------------------------------------------------------

echo $section('3. PROBES: the no-JS redirect + flash and the guest 403');

$probeRoot = root_path();
$probePath = sys_get_temp_dir() . '/booksphere_notification_api_probe.php';
$probeHead = '<?php' . PHP_EOL
    . 'declare(strict_types=1);' . PHP_EOL . PHP_EOL
    . 'require ' . var_export($probeRoot . '/bootstrap/constants.php', true) . ';' . PHP_EOL
    . 'require ' . var_export($probeRoot . '/vendor/autoload.php', true) . ';' . PHP_EOL . PHP_EOL
    . 'use BookSphere\\App\\Controllers\\NotificationController;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Database;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Environment;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Request;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Session;' . PHP_EOL
    . 'use BookSphere\\App\\Middleware\\AuthMiddleware;' . PHP_EOL
    . 'use BookSphere\\App\\Models\\Notification;' . PHP_EOL
    . 'use BookSphere\\App\\Models\\User;' . PHP_EOL
    . 'use BookSphere\\App\\Services\\AuthService;' . PHP_EOL
    . 'use BookSphere\\App\\Services\\NotificationDispatcher;' . PHP_EOL
    . 'use BookSphere\\App\\Services\\NotificationFormatter;' . PHP_EOL
    . 'use BookSphere\\App\\Services\\NotificationService;' . PHP_EOL . PHP_EOL
    . '(new Environment(root_path(\'.env\')))->load();' . PHP_EOL
    . 'Database::instance(' . var_export($dbPath, true) . ');' . PHP_EOL
    . '$session = new Session(\'notification_api_probe\');' . PHP_EOL
    . '$session->start();' . PHP_EOL
    . '$auth = new AuthService($session, new User());' . PHP_EOL
    . 'AuthService::setInstance($auth);' . PHP_EOL
    . 'register_shutdown_function(function (): void {' . PHP_EOL
    . '    $flash = session()->getFlash(\'success\') ?? session()->getFlash(\'error\');' . PHP_EOL
    . '    echo $flash === null ? \'NO_FLASH\' : (string) $flash;' . PHP_EOL
    . '});' . PHP_EOL
    . '$dispatcher = new NotificationDispatcher(new Notification(), new NotificationFormatter());' . PHP_EOL
    . '$controller = new NotificationController(new NotificationService(new Notification(), $dispatcher));' . PHP_EOL;

// Guest: the AuthMiddleware gate of every notification route.
$probeGuest = $probeHead
    . '(new AuthMiddleware($auth))->handle(new Request(), static fn (): string => \'authorized\');' . PHP_EOL;
file_put_contents($probePath, $probeGuest);
$out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' guest 2>&1');
$check('A guest hits the login flash, never the feed', str_contains($out, 'Please log in to continue.') && !str_contains($out, 'authorized'));

// Signed-in + no fetch header: the no-JS mark-all-read answer.
$probeAllRead = $probeHead
    . '$session->put(\'auth_user_id\', ' . $riyaId . ');' . PHP_EOL
    . '$session->put(\'auth_user\', [\'id\' => ' . $riyaId . ', \'full_name\' => \'Riya Sharma\', \'role\' => \'user\']);' . PHP_EOL
    . '$controller->markAllRead(new Request());' . PHP_EOL;
file_put_contents($probePath, $probeAllRead);
$out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' allread 2>&1');
$check('The no-JS mark-all-read answers a flash, no JSON body', str_contains($out, 'Notifications updated.') && !str_contains($out, 'ok'));

// Signed-in + no fetch header + a missing id: the 404 flash.
$probeMissing = $probeHead
    . '$session->put(\'auth_user_id\', ' . $riyaId . ');' . PHP_EOL
    . '$session->put(\'auth_user\', [\'id\' => ' . $riyaId . ', \'full_name\' => \'Riya Sharma\', \'role\' => \'user\']);' . PHP_EOL
    . '$controller->markRead(new Request(), [\'id\' => \'999999\']);' . PHP_EOL;
file_put_contents($probePath, $probeMissing);
$out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' missing 2>&1');
$check('The no-JS missing-id answer flashes the 404 message', str_contains($out, 'Notification not found.'));
unlink($probePath);

// ---------------------------------------------------------------------
// 4. ROUTER: the five registered routes dispatch
// ---------------------------------------------------------------------

echo $section('4. ROUTER: the notification routes');

$fresh = $service->notifyFor($riyaId, 'admin_alert', []);
$service->notifyFor($riyaId, 'admin_alert', []);

$dispatch = function (string $uri, string $method, array $post = []) use ($json, $fetch, $noFetch): array {
    $_SERVER['REQUEST_URI']    = $uri;
    $_SERVER['REQUEST_METHOD'] = $method;
    $_POST                     = $post;
    $fetch();

    $router = new Router(new Request(), new MiddlewarePipeline());
    $router->get('/notifications', [new NotificationController(new NotificationService(new Notification(), new NotificationDispatcher(new Notification(), new NotificationFormatter()))), 'index']);
    $router->patch('/notifications/read-all', [new NotificationController(new NotificationService(new Notification(), new NotificationDispatcher(new Notification(), new NotificationFormatter()))), 'markAllRead']);
    $router->patch('/notifications/{id}/read', [new NotificationController(new NotificationService(new Notification(), new NotificationDispatcher(new Notification(), new NotificationFormatter()))), 'markRead']);
    $router->delete('/notifications', [new NotificationController(new NotificationService(new Notification(), new NotificationDispatcher(new Notification(), new NotificationFormatter()))), 'deleteAll']);
    $router->delete('/notifications/{id}', [new NotificationController(new NotificationService(new Notification(), new NotificationDispatcher(new Notification(), new NotificationFormatter()))), 'destroy']);

    $payload = $json(fn () => $router->dispatch());
    $noFetch();

    return $payload;
};

// GET /notifications -> index
$payload = $dispatch('/notifications', 'GET');
$check('GET /notifications dispatches the feed', isset($payload['items'], $payload['total']));

// PATCH /notifications/{id}/read via the _method override
$payload = $dispatch('/notifications/' . $fresh . '/read', 'POST', ['_method' => 'PATCH']);
$check('PATCH /notifications/{id}/read dispatches markRead', ($payload['ok'] ?? null) === true);
$read = (int) db()->query('SELECT is_read FROM notifications WHERE id = ?', [$fresh])[0]['is_read'];
$check('The dispatched markRead really wrote', $read === 1);

// PATCH /notifications/read-all via the override (the literal beats
// the {id} pattern)
$payload = $dispatch('/notifications/read-all', 'POST', ['_method' => 'PATCH']);
$check('PATCH /notifications/read-all dispatches markAllRead', ($payload['ok'] ?? null) === true && ($payload['changed'] ?? 0) === 1);

// DELETE /notifications/{id} via the override
$payload = $dispatch('/notifications/' . $fresh . '', 'POST', ['_method' => 'DELETE']);
$check('DELETE /notifications/{id} dispatches destroy', ($payload['ok'] ?? null) === true);
$gone = (int) db()->query('SELECT COUNT(*) AS c FROM notifications WHERE id = ?', [$fresh])[0]['c'];
$check('The dispatched destroy really deleted', $gone === 0);

// DELETE /notifications via the override
$service->notifyFor($riyaId, 'admin_alert', []);
$payload = $dispatch('/notifications', 'POST', ['_method' => 'DELETE']);
$check('DELETE /notifications dispatches deleteAll', ($payload['ok'] ?? null) === true && ($payload['deleted'] ?? 0) >= 1);

$_GET = [];
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';

// ---------------------------------------------------------------------
// 5. INTEGRATION: the review_reacted ping
// ---------------------------------------------------------------------

echo $section('5. INTEGRATION: review_reacted (the helpful vote)');

db()->execute('DELETE FROM notifications');

// The wired service mirrors routes/web.php (dispatcher + User model).
$reviewService = new ReviewService(new Review(), new Book(), null, new Logger($logFile), new User(), $dispatcher);

$ownerReview = (new Review())->create([
    'book_id' => (int) $bookA['id'],
    'user_id' => $adminId,
    'rating'  => 5,
    'title'   => 'A great read',
    'review'  => 'A rich, thoughtful review body that is long enough.',
    'status'  => 'approved',
]);

$reviewService->markHelpful($ownerReview, $riyaId);

$ping = db()->query("SELECT * FROM notifications WHERE user_id = ? AND type = 'review_reacted'", [$adminId])[0] ?? null;
$check('A first helpful vote pings the review owner', is_array($ping));
$check('The ping carries the formatted title', is_array($ping) && $ping['title'] === 'Review appreciated');
$check('The ping names the actor and the book', is_array($ping) && str_contains((string) $ping['message'], 'Riya Sharma found your review of ' . $bookA['title'] . ' helpful'));
$check('The ping jumps to the book reviews', is_array($ping) && $ping['action_url'] === '/books/' . $bookA['id'] . '/reviews');

$reviewService->markHelpful($ownerReview, $riyaId);
$count = (int) db()->query("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND type = 'review_reacted'", [$adminId])[0]['c'];
$check('A repeated vote does not re-ping', $count === 1);

// An opted-out recipient receives nothing (the dispatcher gate).
$service->updatePreference($adminId, 'community', false);
$optReview = (new Review())->create([
    'book_id' => (int) $bookB['id'],
    'user_id' => $adminId,
    'rating'  => 4,
    'title'   => 'Also good',
    'review'  => 'Another rich, thoughtful review body that is long enough.',
    'status'  => 'approved',
]);
$reviewService->markHelpful($optReview, $riyaId);
$count = (int) db()->query("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND type = 'review_reacted'", [$adminId])[0]['c'];
$check('An opted-out recipient never receives the ping', $count === 1);
$service->updatePreference($adminId, 'community', true);

// ---------------------------------------------------------------------
// 6. INTEGRATION: the library_milestone ping
// ---------------------------------------------------------------------

echo $section('6. INTEGRATION: library_milestone (finishing a book)');

db()->execute('DELETE FROM notifications');

$libraryService = new LibraryService(new UserLibrary(), new Book(), null, new Logger($logFile), $dispatcher);

$statusBook = (new UserLibrary())->create([
    'user_id'            => $riyaId,
    'book_id'            => (int) $bookA['id'],
    'library_status'     => 'currently_reading',
    'is_favorite'        => 0,
    'progress_percentage' => 10,
]);

$libraryService->updateStatus($riyaId, (int) $bookA['id'], 'finished');
$ping = db()->query("SELECT * FROM notifications WHERE user_id = ? AND type = 'library_milestone'", [$riyaId])[0] ?? null;
$check('updateStatus() to finished pings the milestone', is_array($ping));
$check('The milestone names the finished book', is_array($ping) && str_contains((string) $ping['message'], 'You finished ' . $bookA['title'] . '. Well read!'));
$check('The milestone jumps to the library', is_array($ping) && $ping['action_url'] === '/library');

$libraryService->updateStatus($riyaId, (int) $bookA['id'], 'finished');
$count = (int) db()->query("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND type = 'library_milestone'", [$riyaId])[0]['c'];
$check('Re-setting an already-finished record does not re-ping', $count === 1);

$progressBook = (new UserLibrary())->create([
    'user_id'            => $riyaId,
    'book_id'            => (int) $bookB['id'],
    'library_status'     => 'want_to_read',
    'is_favorite'        => 0,
    'progress_percentage' => 0,
]);

$libraryService->updateProgress($riyaId, (int) $bookB['id'], 50);
$count = (int) db()->query("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND type = 'library_milestone'", [$riyaId])[0]['c'];
$check('A partial progress update never pings', $count === 1);

$libraryService->updateProgress($riyaId, (int) $bookB['id'], 100);
$count = (int) db()->query("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND type = 'library_milestone'", [$riyaId])[0]['c'];
$check('The 100% auto-finish pings the milestone', $count === 2);

$libraryService->updateProgress($riyaId, (int) $bookB['id'], 100);
$count = (int) db()->query("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND type = 'library_milestone'", [$riyaId])[0]['c'];
$check('Re-setting 100% on a finished record does not re-ping', $count === 2);

$plainBook = (new UserLibrary())->create([
    'user_id'            => $riyaId,
    'book_id'            => (int) $bookC['id'],
    'library_status'     => 'want_to_read',
    'is_favorite'        => 0,
    'progress_percentage' => 0,
]);
$libraryService->updateStatus($riyaId, (int) $bookC['id'], 'currently_reading');
$count = (int) db()->query("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND type = 'library_milestone'", [$riyaId])[0]['c'];
$check('A non-finish status change never pings', $count === 2);

// ---------------------------------------------------------------------
// 7. INTEGRATION: the recommendation_ready ping
// ---------------------------------------------------------------------

echo $section('7. INTEGRATION: recommendation_ready (a fresh shelf)');

db()->execute('DELETE FROM notifications');

$recCacheDir = sys_get_temp_dir() . '/booksphere_notification_api_cache_' . time();
if (!is_dir($recCacheDir)) {
    mkdir($recCacheDir, 0777, true);
}

$recRepository = new RecommendationRepository(new BookRepository());
$recService = new RecommendationService(
    new RecommendationFactory(
        new PopularBooksStrategy($recRepository),
        new HighestRatedStrategy($recRepository),
        new TrendingBooksStrategy($recRepository),
        new SameCategoryStrategy($recRepository),
        new RecentlyAddedStrategy($recRepository),
        new SameAuthorStrategy($recRepository),
    ),
    $recRepository,
    new PersonalizationCache($recCacheDir, 1800, true),
    new Logger($logFile),
    $dispatcher,
);

// A scratch user WITH a signal (a high rating leaves a review + a
// favourite category behind, so the profile is never empty).
db()->execute("INSERT INTO users (full_name, email, password, role) VALUES ('Rec Ready', 'rec-ready@api.test', 'x', 'user')");
$recUser = (int) db()->query("SELECT id FROM users WHERE email = 'rec-ready@api.test'")[0]['id'];
(new Review())->create([
    'book_id' => (int) $bookA['id'],
    'user_id' => $recUser,
    'rating'  => 5,
    'title'   => 'Loved it',
    'review'  => 'A rich, thoughtful review body that is long enough.',
    'status'  => 'approved',
]);

$recService->getPersonalizedRecommendations($recUser, 5);
$count = (int) db()->query("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND type = 'recommendation_ready'", [$recUser])[0]['c'];
$check('A fresh shelf generation pings a user with signals', $count === 1);
$ping = db()->query("SELECT * FROM notifications WHERE user_id = ? AND type = 'recommendation_ready'", [$recUser])[0] ?? null;
$check('The ping carries the ready title', is_array($ping) && $ping['title'] === 'Your picks are ready');
$check('The ping jumps to the recommendations', is_array($ping) && $ping['action_url'] === '/recommendations');

$recService->getPersonalizedRecommendations($recUser, 5);
$count = (int) db()->query("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND type = 'recommendation_ready'", [$recUser])[0]['c'];
$check('A cached shelf (cache hit) does not re-ping', $count === 1);

// A scratch user WITHOUT any signal gets the honest cold-start pool.
db()->execute("INSERT INTO users (full_name, email, password, role) VALUES ('Cold Start', 'cold-start@api.test', 'x', 'user')");
$plainUser = (int) db()->query("SELECT id FROM users WHERE email = 'cold-start@api.test'")[0]['id'];
$recService->getPersonalizedRecommendations($plainUser, 5);
$count = (int) db()->query("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND type = 'recommendation_ready'", [$plainUser])[0]['c'];
$check('A signal-free user never gets the "your picks" ping', $count === 0);

// ---------------------------------------------------------------------
// 8. REGRESSION (the other modules next to the API)
// ---------------------------------------------------------------------

echo $section('8. REGRESSION: the shared wiring stays green');

db()->execute('DELETE FROM notifications');

$check('The follow ping still rides along', (new FollowService(new AuthorFollow(), new Author(), $dispatcher))->follow($riyaId, $jk) > 0
    && (int) db()->query("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND type = 'author_followed'", [$riyaId])[0]['c'] === 1);

$check('The library module still writes', $plainBook > 0 && $statusBook > 0 && $progressBook > 0);
$check('The review module still writes', $ownerReview > 0 && $optReview > 0);

// The user cascade still cleans the notifications of a deleted user.
db()->execute('DELETE FROM users WHERE id = ?', [$recUser]);
$recOrphans = (int) db()->query('SELECT COUNT(*) AS c FROM notifications WHERE user_id = ?', [$recUser])[0]['c'];
$check('Deleting a user still cascades their notifications', $recOrphans === 0);

$check('The personal feed keeps answering after all writes', isset($json(fn () => $controller->index(new Request()))['items']));

// ---------------------------------------------------------------------
// RESULT
// ---------------------------------------------------------------------

echo "\n------------------------------------------------------------------------\nRESULT\n------------------------------------------------------------------------\n";
echo sprintf("  Checks: %d\n  Failed: %d\n", $GLOBALS['checks'], $GLOBALS['failures']);
echo "\nNote: the throwaway database database/notification_api_test.db and the log file $logFile are left in place for inspection; delete them anytime.\n";

exit($GLOBALS['failures'] > 0 ? 1 : 0);