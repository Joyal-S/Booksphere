<?php

declare(strict_types=1);

/**
 * FollowTest — CLI test suite for Phase 9.2 (Follow Authors)
 *
 * Verifies the complete backend + surface of the follow module:
 * the author_follows schema (migration 0022), the layered stack
 * (DTO -> service -> repository -> facade), the business rules
 * (author exists / no self-follow / no duplicate / idempotent
 * unfollow), the author_followed notification hook through the
 * shared dispatcher, the policy matrix, the request rules, the
 * controller endpoints (JSON + no-JS redirect + the write throttle),
 * the router's _method override of the no-JS unfollow, the rendered
 * follow button and the two list pages, the database's last line of
 * defence (UNIQUE + CASCADE) and a light regression of the existing
 * modules. Same throwaway-database harness as every other suite:
 *
 *     1. Schema       - the author_follows table (columns, defaults,
 *                       the UNIQUE pair, the two indexes, CASCADE FKs)
 *     2. Repository   - create / find / exists / findForPair /
 *                       findForUser / findFollowersOf / followerCount /
 *                       delete / deleteForPair
 *     3. Service      - the rules (missing author / self-follow /
 *                       duplicate / idempotent unfollow) and reads
 *     4. Notification - follow() fires the actor's 'author_followed'
 *                       ping through the dispatcher; unfollow() and
 *                       opted-out categories never do
 *     5. DTO          - FollowDTO structural sanitization
 *     6. Requests     - FollowRequest rules (valid + invalid)
 *     7. Policy       - the canFollow / canUnfollow / canViewFollowerCount /
 *                       canViewList matrix (owner, other, admin, guest)
 *     8. Model        - the AuthorFollow facade + author()/user()
 *     9. Router       - Request::method() honouring _method=DELETE and
 *                       the patch()/delete() registrations + dispatch
 *    10. Controller   - follow / unfollow / followers JSON answers
 *    11. Probes       - the process-exiting answers in subprocesses:
 *                       the guest 403, the write-throttle 429 and the
 *                       no-JS redirect
 *    12. Views        - the follow button on the author page, the
 *                       followers page and the "Authors I follow" page
 *    13. Database     - the UNIQUE pair constraint and the ON DELETE
 *                       CASCADE of both foreign keys
 *    14. Regression   - the Book / Review / Library modules keep
 *                       working next to the new tables
 *
 * Run from the project root:
 *
 *     php tests/FollowTest.php
 *
 * The throwaway database (database/follow_test.db) is migrated,
 * seeded and left in place for inspection; delete it anytime.
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Controllers\AuthorController;
use BookSphere\App\Controllers\UserController;
use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Core\Logger;
use BookSphere\App\Core\MiddlewarePipeline;
use BookSphere\App\Core\Migrator;
use BookSphere\App\Core\RateLimiter;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Router;
use BookSphere\App\Core\Seeder;
use BookSphere\App\Core\Session;
use BookSphere\App\DTO\FollowDTO;
use BookSphere\App\Exceptions\FollowException;
use BookSphere\App\Models\Author;
use BookSphere\App\Models\AuthorFollow;
use BookSphere\App\Models\Book;
use BookSphere\App\Models\Notification;
use BookSphere\App\Models\User;
use BookSphere\App\Models\UserLibrary;
use BookSphere\App\Policies\FollowPolicy;
use BookSphere\App\Repositories\AuthorFollowRepository;
use BookSphere\App\Requests\FollowRequest;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\FollowService;
use BookSphere\App\Services\LibraryService;
use BookSphere\App\Services\NotificationDispatcher;
use BookSphere\App\Services\NotificationFormatter;

// ---------------------------------------------------------------------
// 0. Boot: fresh throwaway database, migrated and seeded.
// ---------------------------------------------------------------------

(new Environment(root_path('.env')))->load();

$dbPath = root_path('database/follow_test.db');

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
$session = new Session('follow_test');
$session->start();
$auth = new AuthService($session, new User());
AuthService::setInstance($auth);

$logFile = sys_get_temp_dir() . '/booksphere_follow_test.log';
if (is_file($logFile)) {
    unlink($logFile);
}

// ---------------------------------------------------------------------
// Shared fixtures (resolved from the seed data by email / name).
// ---------------------------------------------------------------------

$users = new User();
$admin = $users->findByEmail('admin@booksphere.test');
$riya  = $users->findByEmail('riya@booksphere.test');
$riyaId  = (int) $riya['id'];
$adminId = (int) $admin['id'];

$authorId = fn (string $name): int => (int) db()->query('SELECT id FROM authors WHERE name = ?', [$name])[0]['id'];
$harper  = $authorId('Harper Lee');       // id 1
$george  = $authorId('George Orwell');    // id 2 (== riya's user id)
$jk      = $authorId('J.K. Rowling');
$jane    = $authorId('Jane Austen');

// The module stack, wired EXACTLY like routes/web.php: the follow
// service receives ONE dispatcher built on ONE formatter.
$notifications = new Notification();
$formatter     = new NotificationFormatter();
$dispatcher    = new NotificationDispatcher($notifications, $formatter, new Logger($logFile));
$repository    = new AuthorFollowRepository();
$facade        = new AuthorFollow();
$service       = new FollowService($facade, new Author(), $dispatcher, new Logger($logFile));
$policy        = new FollowPolicy();
$limiter       = new RateLimiter($session);

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
$msg     = function (callable $fn): ?string {
    try {
        $fn();
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }

    return null;
};
$capture = function (callable $fn): string {
    ob_start();
    $fn();

    return (string) ob_get_clean();
};
$json    = function (callable $fn) use ($capture): array {
    $decoded = json_decode($capture($fn), true);

    return is_array($decoded) ? $decoded : [];
};
$failures = 0;
$checks   = 0;

// ---------------------------------------------------------------------
// 1. SCHEMA (migration 0022: the author_follows table)
// ---------------------------------------------------------------------

echo $section('1. SCHEMA: the author_follows table');

$columns = array_column(db()->query('PRAGMA table_info(author_follows)'), 'name');
foreach (['id', 'user_id', 'author_id', 'created_at'] as $column) {
    $check("The table carries the {$column} column", in_array($column, $columns, true));
}

$defaults = [];
foreach (db()->query('PRAGMA table_info(author_follows)') as $row) {
    $defaults[$row['name']] = $row['dflt_value'];
}
$check('The created_at default is the strftime stamp', str_contains((string) $defaults['created_at'], 'strftime'));

$indexNames = array_column(db()->query('PRAGMA index_list(author_follows)'), 'name');
$check('The UNIQUE (user_id, author_id) index exists', in_array('sqlite_autoindex_author_follows_1', $indexNames, true));
$check('The user_id supporting index exists', in_array('idx_author_follows_user', $indexNames, true));
$check('The author_id supporting index exists', in_array('idx_author_follows_author', $indexNames, true));

$tableSql = (string) (db()->query(
    "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'author_follows'",
)[0]['sql'] ?? '');
$normalizedSql = preg_replace('/\s+/', ' ', $tableSql) ?: '';
$check('The UNIQUE (user_id, author_id) constraint is declared', str_contains($tableSql, 'UNIQUE (user_id, author_id)'));
$check('user_id cascades to users', str_contains($normalizedSql, 'REFERENCES users (id) ON DELETE CASCADE'));
$check('author_id cascades to authors', str_contains($normalizedSql, 'REFERENCES authors (id) ON DELETE CASCADE'));

// ---------------------------------------------------------------------
// 2. REPOSITORY (the SQL layer)
// ---------------------------------------------------------------------

echo $section('2. REPOSITORY: the author_follows data layer');

$id = $repository->create(['user_id' => $riyaId, 'author_id' => $harper]);
$check('create() returns the new row id', $id > 0);

$row = $repository->find($id);
$check('find() returns the created row', is_array($row) && (int) $row['author_id'] === $harper);
$check('find() stores the UTC stamp', str_ends_with((string) $row['created_at'], 'Z'));

$check('exists() sees the pair', $repository->exists($riyaId, $harper) === true);
$check('exists() rejects a non-existent pair', $repository->exists($riyaId, $jk) === false);

$pair = $repository->findForPair($riyaId, $harper);
$check('findForPair() resolves the row for the policy gate', is_array($pair) && (int) $pair['user_id'] === $riyaId);
$check('findForPair() answers null for a non-existent pair', $repository->findForPair($riyaId, $jk) === null);

$repository->create(['user_id' => $riyaId, 'author_id' => $jk]);
$repository->create(['user_id' => $riyaId, 'author_id' => $jane]);

$following = $repository->findForUser($riyaId);
$check('findForUser() lists the followed authors', count($following) === 3);
$check('The list joins the author name', isset($following[0]['author_name']) && $following[0]['author_name'] !== '');
$check('The list joins the author book count', isset($following[0]['author_book_count']) && (int) $following[0]['author_book_count'] > 0);
$check('The list is newest first', (int) $following[0]['author_id'] === $jane && (int) $following[count($following) - 1]['author_id'] === $harper);

$followers = $repository->findFollowersOf($harper);
$check("findFollowersOf() lists an author's followers", count($followers) === 1);
$check('The follower list joins the user display name', isset($followers[0]['full_name']) && $followers[0]['full_name'] === 'Riya Sharma');
$check("followerCount() counts the author's followers", $repository->followerCount($harper) === 1);
$check('followerCount() counts zero without followers', $repository->followerCount($george) === 0);

$check('delete() removes a row by id', $repository->delete($id) === true);
$check('delete() answers false for a missing row', $repository->delete(999999) === false);

$check('deleteForPair() removes the pair', $repository->deleteForPair($riyaId, $jk) === true);
$check('deleteForPair() leaves the other pairs', $repository->exists($riyaId, $jane) === true && $repository->exists($riyaId, $harper) === false);
$check('deleteForPair() is idempotent (silent false)', $repository->deleteForPair($riyaId, $jk) === false);

// ---------------------------------------------------------------------
// 3. SERVICE (the business rules)
// ---------------------------------------------------------------------

echo $section('3. SERVICE: the follow rules');

$check('authorExists() resolves a real author', $service->authorExists($harper) === true);
$check('authorExists() rejects a missing author', $service->authorExists(999999) === false);

$err = $msg(fn () => $service->follow($riyaId, 999999));
$check('Following a missing author raises authorNotFound', str_contains((string) $err, 'Author not found') && $msg(fn () => $service->follow($riyaId, 999999)) !== null);
$check('The missing-author write wrote nothing', $facade->exists($riyaId, 999999) === false);

// The natural, deterministic self pair of the seed: riya has user id 2
// and George Orwell is author id 2.
$err = $msg(fn () => $service->follow($riyaId, $george));
$check('Following yourself raises cannotFollowSelf', str_contains((string) $err, 'cannot follow themselves'));
$check('The self-follow wrote nothing', $service->isFollowing($riyaId, $george) === false);

$id = $facade->create(['user_id' => $riyaId, 'author_id' => $george]);
$check('The duplicate guard rejects a pre-existing pair', $msg(fn () => $service->follow($riyaId, $george)) !== null);
$facade->delete($id);

$id = $service->follow($riyaId, $jk);
$check('follow() returns the row id on success', $id > 0);
$check('follow() persists the follow', $service->isFollowing($riyaId, $jk) === true);

$err = $msg(fn () => $service->follow($riyaId, $jk));
$check('A repeated follow raises duplicateFollow', str_contains((string) $err, 'already follow'));

$check('isFollowing() reads the button state', $service->isFollowing($riyaId, $harper) === false && $service->isFollowing($riyaId, $jk) === true);
$row = $service->followRow($riyaId, $jk);
$check('followRow() resolves the row (the unfollow gate)', is_array($row) && (int) $row['user_id'] === $riyaId);

$check('unfollow() removes a real pair', $service->unfollow($riyaId, $jk) === true);
$check('unfollow() is idempotent', $service->unfollow($riyaId, $jk) === false);
$check('unfollow() cleans the follow', $service->isFollowing($riyaId, $jk) === false);

$service->follow($riyaId, $jk);
$list = $service->followingList($riyaId);
$check('followingList() reads through the service', count($list) === 2);
$check('The followed rows carry the author columns', isset($list[0]['author_name']) && isset($list[0]['author_book_count']));

$list = $service->followersList($jk);
$check('followersList() reads through the service', count($list) === 1 && $list[0]['full_name'] === 'Riya Sharma');

// ---------------------------------------------------------------------
// 4. NOTIFICATION HOOK (the author_followed confirmation ping)
// ---------------------------------------------------------------------

echo $section('4. NOTIFICATION HOOK: the author_followed ping');

db()->execute('DELETE FROM notifications');
db()->execute('DELETE FROM author_follows');

$service->follow($riyaId, $jane);
$rows = db()->query(
    'SELECT * FROM notifications WHERE user_id = ? AND type = ? ORDER BY id',
    [$riyaId, 'author_followed'],
);
$check('follow() leaves an author_followed notification', count($rows) === 1);
$check('The notification belongs to the actor', (int) $rows[0]['user_id'] === $riyaId);
$check('The title embeds the author name', str_contains((string) $rows[0]['title'], 'Jane Austen'));
$check('The message embeds the author name', str_contains((string) $rows[0]['message'], 'Jane Austen'));
$check('The action_url points at the author page', $rows[0]['action_url'] === '/authors/' . $jane);
$check('The icon/color ship from the catalog', $rows[0]['icon'] === 'fa-solid fa-user-plus' && $rows[0]['color'] === 'primary');
$check('The notification starts unread', (int) $rows[0]['is_read'] === 0);

$service->unfollow($riyaId, $jane);
$count = (int) db()->query('SELECT COUNT(*) AS c FROM notifications WHERE user_id = ?', [$riyaId])[0]['c'];
$check('unfollow() creates no notification of its own', $count === 1);

// Opt-out: a user who silenced the author_followed category gets no ping.
// (George Orwell has id 2 - never the admin's own id 1, so the
// follow is a real, non-self follow.)
$notifications->updatePreference($adminId, 'author_followed', false);
$before = (int) db()->query('SELECT COUNT(*) AS c FROM notifications WHERE user_id = ?', [$adminId])[0]['c'];
$service->follow($adminId, $george);
$after = (int) db()->query('SELECT COUNT(*) AS c FROM notifications WHERE user_id = ?', [$adminId])[0]['c'];
$check('An opted-out user gets no confirmation ping', $after === $before);
$notifications->updatePreference($adminId, 'author_followed', true);

// ---------------------------------------------------------------------
// 5. DTO (structural sanitization)
// ---------------------------------------------------------------------

echo $section('5. DTO: FollowDTO');

// The 9.6 contract: the ACTOR id NEVER comes from the submitted
// payload (a crafted "user_id" field must not let user A act for
// user B) - it is always the session value handed to fromArray().
$dto = FollowDTO::fromArray(['user_id' => '5', 'author_id' => '7'], $riyaId);
$check('fromArray() ignores a payload user_id (session wins)', $dto->userId === $riyaId && $dto->authorId === 7);

$dto = FollowDTO::fromArray(['user_id' => 'junk', 'author_id' => '7'], $riyaId);
$check('A junk actor id is ignored, never a fallback', $dto->userId === $riyaId);
$check('The author_id survives', $dto->authorId === 7);

$dto = FollowDTO::fromArray(['author_id' => '0'], $riyaId);
$check('A zero author_id sanitizes to null', $dto->authorId === null);

$dto = FollowDTO::fromArray([], null);
$check('No ids at all answers nulls', $dto->userId === null && $dto->authorId === null);

// ---------------------------------------------------------------------
// 6. REQUEST (the form rules)
// ---------------------------------------------------------------------

echo $section('6. REQUEST: FollowRequest');

$check('A valid author_id passes', FollowRequest::passes(['author_id' => '7']) === true);
$check('A missing author_id fails', FollowRequest::passes([]) === false);
$check('A junk author_id fails', FollowRequest::passes(['author_id' => 'abc']) === false);
$check('A zero author_id fails', FollowRequest::passes(['author_id' => '0']) === false);
$check('A negative author_id fails', FollowRequest::passes(['author_id' => '-3']) === false);
$errors = FollowRequest::validate([])->errors();
$check('The errors are keyed by field', isset($errors['author_id']));

// ---------------------------------------------------------------------
// 7. POLICY (the fine gates)
// ---------------------------------------------------------------------

echo $section('7. POLICY: the FollowPolicy matrix');

$riyaFollow = ['id' => 1, 'user_id' => $riyaId, 'author_id' => $george];

$session->forget('auth_user_id');
$check('canFollow() rejects a guest', $policy->canFollow() === false);
$check('canViewFollowerCount() rejects a guest', $policy->canViewFollowerCount() === false);
$check('canUnfollow() rejects a null row', $policy->canUnfollow(null, $riyaId) === false);
$check('canViewList() rejects a guest', $policy->canViewList($riyaId, null) === false);

$session->put('auth_user_id', $riyaId);
$session->put('auth_user', ['id' => $riyaId, 'full_name' => 'Riya Sharma', 'email' => 'riya@booksphere.test', 'role' => 'user']);
$check('canFollow() allows an authenticated user', $policy->canFollow() === true);
$check('canViewFollowerCount() allows an authenticated user', $policy->canViewFollowerCount() === true);

$check('canUnfollow() allows the owner', $policy->canUnfollow($riyaFollow, $riyaId) === true);
$check('canUnfollow() rejects another user', $policy->canUnfollow($riyaFollow, $adminId) === false);
$check('canUnfollow() rejects an admin (no write override)', $policy->canUnfollow($riyaFollow, $adminId) === false);

$check('canViewList() allows the owner', $policy->canViewList($riyaId, $riyaId) === true);
$check('canViewList() rejects another user', $policy->canViewList($riyaId, $adminId) === false);

// The admin's read-only oversight.
$session->put('auth_user_id', $adminId);
$session->put('auth_user', ['id' => $adminId, 'full_name' => 'Admin User', 'email' => 'admin@booksphere.test', 'role' => 'admin']);
$check('canViewList() allows an admin for a foreign list', $policy->canViewList($riyaId, $adminId) === true);
$check('canUnfollow() still rejects the admin write', $policy->canUnfollow($riyaFollow, $adminId) === false);
$session->put('auth_user_id', $riyaId);
$session->put('auth_user', ['id' => $riyaId, 'full_name' => 'Riya Sharma', 'email' => 'riya@booksphere.test', 'role' => 'user']);

// ---------------------------------------------------------------------
// 8. MODEL (the facade + relationships)
// ---------------------------------------------------------------------

echo $section('8. MODEL: the AuthorFollow facade');

$facade->deleteForPair($riyaId, $harper); // reset the fixture pair
$modelId = $facade->create(['user_id' => $riyaId, 'author_id' => $harper]);
$modelFollow = $facade->find($modelId);
$check('create()/find() answer through the facade', is_array($modelFollow) && (int) $modelFollow['author_id'] === $harper);
$check('exists() answers through the facade', $facade->exists($riyaId, $harper) === true);
$check('isFollowing() alias answers', $facade->isFollowing($riyaId, $harper) === true);
$check('deleteForPair() answers through the facade', $facade->deleteForPair($riyaId, $harper) === true);

// The relationship probes need a real, current pair.
$service->follow($riyaId, $jane);
$probe = $facade->findForPair($riyaId, $jane);
$authorRow = $facade->author($probe);
$check('author() resolves the followed author', is_array($authorRow) && (string) $authorRow['name'] === 'Jane Austen');
$userRow = $facade->user($probe);
$check('user() resolves the follower', is_array($userRow) && (string) $userRow['full_name'] === 'Riya Sharma');

// ---------------------------------------------------------------------
// 9. ROUTER + REQUEST (the verbs and the _method override)
// ---------------------------------------------------------------------

echo $section('9. ROUTER: verbs and the _method override');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['_method' => 'DELETE'];
$check('_method=DELETE rewrites a POST into DELETE', (new Request())->method() === 'DELETE');
$_POST = ['_method' => 'PATCH'];
$check('_method=PATCH rewrites a POST into PATCH', (new Request())->method() === 'PATCH');
$_POST = ['_method' => 'GET'];
$check('The override allowlist rejects other verbs', (new Request())->method() === 'POST');
$_POST = [];
$check('A plain POST stays POST', (new Request())->method() === 'POST');

// The DELETE route matches through the override, extracting the param.
$_SERVER['REQUEST_URI'] = '/authors/5/follow';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['_method' => 'DELETE'];
$dispatched = [];
$router = new Router(new Request(), new MiddlewarePipeline());
$router->delete('/authors/{id}/follow', function (Request $r, array $params) use (&$dispatched): void {
    $dispatched = ['method' => $r->method(), 'id' => $params['id']];
});
$router->dispatch();
$check('The DELETE route receives the true method', ($dispatched['method'] ?? '') === 'DELETE');
$check('The DELETE route extracts the parameter', ($dispatched['id'] ?? '') === '5');

// The PATCH registration (the notification center verb of a later phase).
$_SERVER['REQUEST_URI'] = '/notifications/5/read';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['_method' => 'PATCH'];
$dispatched = [];
$router = new Router(new Request(), new MiddlewarePipeline());
$router->patch('/notifications/{id}/read', function (Request $r, array $params) use (&$dispatched): void {
    $dispatched = ['method' => $r->method(), 'id' => $params['id']];
});
$router->dispatch();
$check('A PATCH route dispatches through the override', ($dispatched['method'] ?? '') === 'PATCH' && ($dispatched['id'] ?? '') === '5');

$_POST = [];

// ---------------------------------------------------------------------
// 10. CONTROLLER (the JSON endpoints)
// ---------------------------------------------------------------------

echo $section('10. CONTROLLER: the follow endpoints');

$controller = new AuthorController(new Author(), null, $service, $policy, $limiter);

$_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';

$payload = $json(fn () => $controller->follow(new Request(), ['id' => (string) $harper]));
$check('follow() answers {following: true}', ($payload['following'] ?? null) === true);
$check('The follow really persisted', $service->isFollowing($riyaId, $harper) === true);

$payload = $json(fn () => $controller->follow(new Request(), ['id' => '999999']));
$check('A missing author answers {error}', ($payload['error'] ?? '') !== '');

$payload = $json(fn () => $controller->follow(new Request(), ['id' => (string) $harper]));
$check('A duplicate follow answers the 409 error', ($payload['error'] ?? '') !== '');

$payload = $json(fn () => $controller->follow(new Request(), ['id' => (string) $george]));
$check('A self-follow answers the 400 error', ($payload['error'] ?? '') !== '');

$payload = $json(fn () => $controller->unfollow(new Request(), ['id' => (string) $harper]));
$check('unfollow() answers {following: false}', ($payload['following'] ?? null) === false);
$check('The unfollow really persisted', $service->isFollowing($riyaId, $harper) === false);

$payload = $json(fn () => $controller->unfollow(new Request(), ['id' => (string) $harper]));
$check('A second unfollow is a silent no-op', ($payload['following'] ?? null) === false);

$payload = $json(fn () => $controller->follow(new Request(), ['id' => 'junk']));
$check('A tampered id answers validation', ($payload['error'] ?? '') !== '');

// ---------------------------------------------------------------------
// 11. PROBES (the process-exit answers, in subprocesses)
// ---------------------------------------------------------------------

echo $section('11. PROBES: the guest 403, the throttle 429, the no-JS redirect');

$probeRoot = root_path();
$probePath = sys_get_temp_dir() . '/booksphere_follow_probe.php';
$probeHead = '<?php' . PHP_EOL
    . 'declare(strict_types=1);' . PHP_EOL . PHP_EOL
    . 'require ' . var_export($probeRoot . '/bootstrap/constants.php', true) . ';' . PHP_EOL
    . 'require ' . var_export($probeRoot . '/vendor/autoload.php', true) . ';' . PHP_EOL . PHP_EOL
    . 'use BookSphere\\App\\Controllers\\AuthorController;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Database;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Environment;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\RateLimiter;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Request;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Session;' . PHP_EOL
    . 'use BookSphere\\App\\Models\\Author;' . PHP_EOL
    . 'use BookSphere\\App\\Models\\AuthorFollow;' . PHP_EOL
    . 'use BookSphere\\App\\Models\\Notification;' . PHP_EOL
    . 'use BookSphere\\App\\Models\\User;' . PHP_EOL
    . 'use BookSphere\\App\\Policies\\FollowPolicy;' . PHP_EOL
    . 'use BookSphere\\App\\Services\\AuthService;' . PHP_EOL
    . 'use BookSphere\\App\\Services\\FollowService;' . PHP_EOL
    . 'use BookSphere\\App\\Services\\NotificationDispatcher;' . PHP_EOL
    . 'use BookSphere\\App\\Services\\NotificationFormatter;' . PHP_EOL . PHP_EOL
    . '(new Environment(root_path(\'.env\')))->load();' . PHP_EOL
    . 'Database::instance(' . var_export($dbPath, true) . ');' . PHP_EOL
    . '$session = new Session(\'follow_test_probe\');' . PHP_EOL
    . '$session->start();' . PHP_EOL
    . '$auth = new AuthService($session, new User());' . PHP_EOL
    . 'AuthService::setInstance($auth);' . PHP_EOL
    . '$dispatcher = new NotificationDispatcher(new Notification(), new NotificationFormatter());' . PHP_EOL
    . '$controller = new AuthorController(new Author(), null, new FollowService(new AuthorFollow(), new Author(), $dispatcher), new FollowPolicy(), new RateLimiter($session));' . PHP_EOL
    . '$mode = (string) ($argv[1] ?? \'\');' . PHP_EOL
    . '$authorId = \'// untouched\';' . PHP_EOL;

$probeGuest = $probeHead
    . '$_SERVER[\'HTTP_X_REQUESTED_WITH\'] = \'fetch\';' . PHP_EOL
    . '$controller->follow(new Request(), [\'id\' => \'5\']);' . PHP_EOL;
file_put_contents($probePath, $probeGuest);
$out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' guest 2>&1');
$check('A guest follow exits with the 403 message', trim($out) === 'You are not allowed to follow authors.');

$probeThrottle = $probeHead
    . '$session->put(\'auth_user_id\', ' . $riyaId . ');' . PHP_EOL
    . '$session->put(\'auth_user\', [\'id\' => ' . $riyaId . ', \'full_name\' => \'Riya Sharma\', \'role\' => \'user\']);' . PHP_EOL
    . '$_SERVER[\'HTTP_X_REQUESTED_WITH\'] = \'fetch\';' . PHP_EOL
    . 'for ($i = 0; $i < 60; $i++) {' . PHP_EOL
    . '    $controller->follow(new Request(), [\'id\' => (string) (1000 + $i)]);' . PHP_EOL
    . '}' . PHP_EOL
    . '$controller->follow(new Request(), [\'id\' => \'1060\']);' . PHP_EOL;
file_put_contents($probePath, $probeThrottle);
$out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' throttle 2>&1');
$check('The 61st follow exits with the 429 message', str_contains($out, 'Too many requests - please try again in a minute.'));

// The no-JS path: the flash lands in the session and no JSON body is
// emitted. headers_list() is empty in the CLI SAPI, so the probe
// proves the flash instead of the Location header.
$probeRedirect = $probeHead
    . '(new RateLimiter($session))->clearPersistent(\'follow_write\', \'user:\' . ' . $riyaId . ');' . PHP_EOL
    . 'register_shutdown_function(function (): void {' . PHP_EOL
    . '    echo json_encode(session()->getFlash(\'success\'));' . PHP_EOL
    . '});' . PHP_EOL
    . '$session->put(\'auth_user_id\', ' . $riyaId . ');' . PHP_EOL
    . '$session->put(\'auth_user\', [\'id\' => ' . $riyaId . ', \'full_name\' => \'Riya Sharma\', \'role\' => \'user\']);' . PHP_EOL
    . '$controller->follow(new Request(), [\'id\' => \'1\']);' . PHP_EOL;
file_put_contents($probePath, $probeRedirect);
$out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' redirect 2>&1');
$check('The no-JS follow answers a redirect + flash', str_contains($out, 'You are now following Harper Lee'));
unlink($probePath);

// ---------------------------------------------------------------------
// 12. VIEWS (the follow surface)
// ---------------------------------------------------------------------

echo $section('12. VIEWS: the follow button and the list pages');

// A controlled starting point for the view assertions.
db()->execute('DELETE FROM author_follows');
db()->execute('DELETE FROM notifications');

// The not-following state of the button.
$html = $capture(fn () => $controller->show(new Request(), ['id' => (string) $jk]));
$check('The author page renders the follow control', str_contains($html, 'data-follow-control'));
$check('The follow button posts with the CSRF token', str_contains($html, 'name="_token"'));
$check('The follow action targets the author', str_contains($html, 'action="/authors/' . $jk . '/follow"'));
$check('The author page links the follower count', str_contains($html, 'href="/authors/' . $jk . '/followers"'));

// The following state of the button.
$service->follow($riyaId, $jk);
$html = $capture(fn () => $controller->show(new Request(), ['id' => (string) $jk]));
$check('The followed author renders the Following state', str_contains($html, 'btn-following'));
$check('The Following state carries the _method=DELETE input', str_contains($html, 'name="_method" value="DELETE"'));
$check('The Following state sets aria-pressed', str_contains($html, 'aria-pressed="true"'));

// The followers page: riya follows jk.
$html = $capture(fn () => $controller->followers(new Request(), ['id' => (string) $jk]));
$check('The followers page renders the header', str_contains($html, 'Followers'));
$check('The followers page lists the follower name', str_contains($html, 'Riya Sharma'));
$check('The follower row links to their activity', str_contains($html, '/reviews/user/' . $riyaId));

// The followers page of an author with no followers.
$html = $capture(fn () => $controller->followers(new Request(), ['id' => (string) $george]));
$check('The empty followers state renders', str_contains($html, 'No one follows this author yet'));

// The following page (the UserController action).
$userController = new UserController($auth, $users, null, null, null, $service, new FollowPolicy());
$html = $capture(fn () => $userController->following(new Request()));
$check('The following page renders its title', str_contains($html, 'Authors I follow'));
$check('The following page lists the followed author', str_contains($html, 'J.K. Rowling'));
$check('The following card carries the compact unfollow control', str_contains($html, 'follow-control--compact'));
$check('The following card links to the author page', str_contains($html, '/authors/' . $jk));

// ---------------------------------------------------------------------
// 13. DATABASE (the UNIQUE + CASCADE last line of defence)
// ---------------------------------------------------------------------

echo $section('13. DATABASE: the last lines of defence');

$unique = $throws(PDOException::class, fn () => $repository->create(['user_id' => $riyaId, 'author_id' => $jk]));
$check('The UNIQUE pair rejects a duplicate follow at the DB', $unique);

// CASCADE on its own scratch user (never a seeded account).
db()->execute("INSERT INTO users (full_name, email, password, role) VALUES ('Scratch', 'scratch@follow.test', 'x', 'user')");
$scratchId = (int) db()->query("SELECT id FROM users WHERE email = 'scratch@follow.test'")[0]['id'];
db()->execute('INSERT INTO author_follows (user_id, author_id) VALUES (?, ?)', [$scratchId, $harper]);
db()->execute('DELETE FROM users WHERE id = ?', [$scratchId]);
$orphan = (int) db()->query('SELECT COUNT(*) AS c FROM author_follows WHERE user_id = ?', [$scratchId])[0]['c'];
$check('Deleting a user cascades away their follow rows', $orphan === 0);

// ---------------------------------------------------------------------
// 14. REGRESSION (the existing modules next to the new tables)
// ---------------------------------------------------------------------

echo $section('14. REGRESSION: the existing modules');

$library = new LibraryService(new UserLibrary(), new Book(), null, new Logger($logFile));
$counts = $library->statusCounts($riyaId);
$check('The Library module still answers its counts', is_array($counts));
$bookCount = (int) db()->query('SELECT COUNT(*) AS c FROM books WHERE deleted_at IS NULL')[0]['c'];
$check('The catalogue is intact', $bookCount > 0);
$reviewCount = (int) db()->query('SELECT COUNT(*) AS c FROM reviews WHERE status = \'approved\'')[0]['c'];
$check('The review data is intact', $reviewCount > 0);

// ---------------------------------------------------------------------
// RESULT
// ---------------------------------------------------------------------

echo $section('RESULT');
echo '  Checks: ' . $checks . PHP_EOL;
echo '  Failed: ' . $failures . PHP_EOL;

echo PHP_EOL . 'Note: the throwaway database database/follow_test.db and the log file ' . $logFile . ' are left in place for inspection; delete them anytime.' . PHP_EOL;

exit($failures === 0 ? 0 : 1);