<?php

declare(strict_types=1);

/**
 * NotificationCenterTest — CLI test suite for Phase 9.4 (Notification Center UI)
 *
 * Verifies the Phase 9.4 surface that consumes the Phase 9.3 API:
 *
 *     1. Center page - the rendered page inside the master layout:
 *                      the intro (unread lead), the filter chips, the
 *                      bulk bar, the results region, the skeleton
 *                      template, the confirmation modal - and the
 *                      XSS escaping of stored content
 *     2. Item card   - the full + compact variants: icon tile, title,
 *                      message, time, the action link labels, the
 *                      read/unread toggle form, the delete form, the
 *                      bulk checkbox
 *     3. List        - the four empty states and the pagination
 *                      ("Showing 1-25 of 30 notifications", the
 *                      preserved tab/filter params, no per-page
 *                      select)
 *     4. Navbar      - the bell (badge, panel, mark-all, footer), the
 *                      sidebar link + active state, the head/scripts
 *                      includes
 *     5. Helper      - format_notification_time's relative ages
 *     6. Controller  - unreadCount, markUnread (404 gating), the bulk
 *                      delete (owner scoping, foreign ids skipped,
 *                      422 on an empty selection), the fragment
 *                      answer (html + total + unread)
 *     7. Router      - all NINE routes dispatch (center / unread-count
 *                      / fragment GETs, the read + unread + read-all
 *                      PATCHes, the bulk POST, the single + full
 *                      DELETEs)
 *     8. Probes      - the no-JS fallback: the bulk and the unread
 *                      toggle answer a redirect + flash, not JSON,
 *                      and the guest gate holds on the new GETs
 *     9. Regression  - the shared dispatcher still creates the rows
 *                      the surface reads
 *
 * Run from the project root:
 *
 *     php tests/NotificationCenterTest.php
 *
 * The throwaway database (database/notification_center_test.db) is
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
use BookSphere\App\Core\View;
use BookSphere\App\Middleware\AuthMiddleware;
use BookSphere\App\Models\Notification;
use BookSphere\App\Models\User;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\NotificationDispatcher;
use BookSphere\App\Services\NotificationFormatter;
use BookSphere\App\Services\NotificationService;

// ---------------------------------------------------------------------
// 0. Boot: fresh throwaway database, migrated and seeded.
// ---------------------------------------------------------------------

(new Environment(root_path('.env')))->load();

$dbPath = root_path('database/notification_center_test.db');

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
$session = new Session('notification_center_test');
$session->start();
$auth = new AuthService($session, new User());
AuthService::setInstance($auth);

$logFile = sys_get_temp_dir() . '/booksphere_notification_center_test.log';
if (is_file($logFile)) {
    unlink($logFile);
}

// ---------------------------------------------------------------------
// Shared fixtures + the module stack (wired EXACTLY like routes/web.php).
// ---------------------------------------------------------------------

$users   = new User();
$riya    = $users->findByEmail('riya@booksphere.test');
$admin   = $users->findByEmail('admin@booksphere.test');
$riyaId  = (int) $riya['id'];
$adminId = (int) $admin['id'];

$notifications = new Notification();
$formatter     = new NotificationFormatter();
$dispatcher    = new NotificationDispatcher($notifications, $formatter, new Logger($logFile));
$service       = new NotificationService($notifications, $dispatcher, new Logger($logFile));
$controller    = new NotificationController($service);

// The tests act as riya (the session user of every endpoint).
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

// ---------------------------------------------------------------------
// 1. THE CENTER PAGE: the full master-layout render
// ---------------------------------------------------------------------

echo $section('1. VIEWS: the rendered center page');

db()->execute('DELETE FROM notifications');

$service->notifyFor($riyaId, 'system_announcement', ['title' => 'Maintenance window', 'message' => 'Tonight at 2am']);
$service->notifyFor($riyaId, 'review_reacted', ['actor' => 'Ada Lovelace', 'book' => 'Dune']);
$service->notifyFor($riyaId, 'system_announcement', ['title' => '<script>alert("x")</script>', 'message' => 'A malicious payload']);
$service->notifyFor($adminId, 'admin_alert', []); // a FOREIGN row (never listed)

$_GET = [];
$html = $capture(fn () => $controller->center(new Request()));

$check('The center renders inside the master layout', str_contains($html, 'app-shell') && str_contains($html, 'BookSphere'));
$check('The intro leads with the unread count', str_contains($html, 'data-notif-unread-text') && str_contains($html, '3 unread notifications'));
$check('The intro offers Mark all read + Clear all', str_contains($html, 'data-notif-mark-all') && str_contains($html, 'data-notif-clear-form'));
$check('The Mark all read button is enabled while unread rows exist', !str_contains($html, 'btn-primary btn-sm" data-notif-mark-all-btn disabled'));
$check('All nine filter chips render (3 tabs + 1 all-types + 5 groups)', substr_count($html, 'data-notif-chip') === 9);
$check('The unread tab carries its badge number', str_contains($html, 'Unread (3)'));
$check('The bulk bar renders with the selection controls', str_contains($html, 'notif-bulk-form') && str_contains($html, 'data-notif-select-all') && str_contains($html, 'data-notif-bulk-count'));
$check('The results region + the shared list partial render', str_contains($html, 'data-notif-results') && str_contains($html, 'notif-list'));
$check('The cards carry the bulk checkbox reference', str_contains($html, 'form="notif-bulk-form"'));
$check('The results are paginated ("Showing 1-3 of 3 notifications")', str_contains($html, 'of 3 notification'));
$check('The skeleton template ships for the fetch repaints', str_contains($html, 'data-notif-skeleton'));
$check('The confirmation modal ships (labelled, aria-hidden)', str_contains($html, 'notifConfirmModal') && str_contains($html, 'aria-labelledby="notifConfirmTitle"'));
$check('The aria-live status region ships', str_contains($html, 'data-notif-status'));
$check('A stored payload is escaped, never executed', str_contains($html, '&lt;script&gt;') && !str_contains($html, '<script>alert'));
$check('The foreign row never renders', !str_contains($html, 'System alert'));
$check('The sidebar marks Notifications active', str_contains($html, 'href="/notifications/center"') && str_contains($html, ' is-active'));
$check('The head includes the module stylesheet', str_contains($html, 'css/notifications.css'));
$check('The scripts include the module script', str_contains($html, 'js/notifications.js'));
$check('The bell dropdown ships in the navbar', str_contains($html, 'data-notif-trigger') && str_contains($html, 'data-notif-badge') && str_contains($html, 'notif-menu-panel'));

// The all-caught-up state: nothing unread flips the lead + the buttons.
$service->markAllRead($riyaId);
$_GET = [];
$html = $capture(fn () => $controller->center(new Request()));
$check('The all-read lead swaps to "all caught up"', str_contains($html, 'nothing unread') && !str_contains($html, '3 unread notifications'));
$check('The Mark all read button disables with nothing unread', str_contains($html, 'btn-primary btn-sm" data-notif-mark-all-btn disabled'));

// The fully-empty state: no rows at all.
db()->execute('DELETE FROM notifications');
$_GET = [];
$html = $capture(fn () => $controller->center(new Request()));
$check('The empty center renders the "No notifications yet" state', str_contains($html, 'No notifications yet'));
$check('The Clear all button disables with no history', str_contains($html, 'data-notif-clear-form') && str_contains($html, 'disabled'));

// ---------------------------------------------------------------------
// 2. THE ITEM CARD: the full + compact partials
// ---------------------------------------------------------------------

echo $section('2. VIEWS: the notification card');

$service->notifyFor($riyaId, 'review_reacted', ['actor' => 'Ada Lovelace', 'book' => 'Dune']);
$row = db()->query('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC', [$riyaId])[0];

$itemHtml = View::fragment('notifications.partials._item', ['item' => $row]);
$check('The full card renders the accent icon tile', str_contains($itemHtml, 'notif-icon notif-icon--success') && str_contains($itemHtml, 'fa-thumbs-up'));
$check('The full card renders the title + message + time', str_contains($itemHtml, 'Review appreciated') && str_contains($itemHtml, 'Ada Lovelace found your review of Dune helpful') && str_contains($itemHtml, 'just now'));
$check('The action link labels the destination', str_contains($itemHtml, 'View book') && str_contains($itemHtml, '/books/'));
$check('The unread card offers the read toggle', str_contains($itemHtml, 'data-notif-toggle') && str_contains($itemHtml, 'Mark as read') && str_contains($itemHtml, 'aria-pressed="false"'));
$check('The toggle posts the PATCH override', str_contains($itemHtml, 'name="_method"') && str_contains($itemHtml, 'PATCH'));
$check('The delete form posts the DELETE override with a token', str_contains($itemHtml, 'data-notif-delete-form') && str_contains($itemHtml, 'name="_method"') && str_contains($itemHtml, 'name="_token"'));
$check('The unread dot renders for the unread row', str_contains($itemHtml, 'notif-item-dot') && str_contains($itemHtml, ' is-unread'));

$service->markRead((int) $row['id'], $riyaId);
$row = db()->query('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC', [$riyaId])[0];
$itemHtml = View::fragment('notifications.partials._item', ['item' => $row]);
$check('The read card drops the unread accent', !str_contains($itemHtml, 'is-unread') && str_contains($itemHtml, 'Mark as unread'));
$check('The read card keeps a CSRF token on every write', substr_count($itemHtml, 'name="_token"') === 2);

$itemHtml = View::fragment('notifications.partials._item', ['item' => $row, 'compact' => true]);
$check('The compact variant drops the checkbox + actions', !str_contains($itemHtml, 'notif-item-check') && !str_contains($itemHtml, 'data-notif-toggle') && !str_contains($itemHtml, 'data-notif-delete-form'));
$check('The compact variant keeps the icon + title + message', str_contains($itemHtml, 'notif-icon') && str_contains($itemHtml, 'Review appreciated'));

// ---------------------------------------------------------------------
// 3. THE LIST: empty states + the pagination contract
// ---------------------------------------------------------------------

echo $section('3. VIEWS: the list fragment (empty states + pagination)');

$listData = ['payload' => ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => 25], 'tab' => 'all', 'filter' => ''];

$html = View::fragment('notifications.partials._list', array_merge($listData, ['tab' => 'all']));
$check('The all-empty list says "No notifications yet"', str_contains($html, 'No notifications yet'));

$html = View::fragment('notifications.partials._list', array_merge($listData, ['tab' => 'unread']));
$check('The unread-empty list says "You\'re all caught up"', str_contains($html, 'all caught up') && str_contains($html, 'No unread notifications right now'));

$html = View::fragment('notifications.partials._list', array_merge($listData, ['tab' => 'read']));
$check('The read-empty list says "Nothing read yet"', str_contains($html, 'Nothing read yet'));

$html = View::fragment('notifications.partials._list', array_merge($listData, ['filter' => 'follow']));
$check('The filtered-empty list says "Nothing in this type"', str_contains($html, 'Nothing in this type'));

// 30 rows: pagination renders with the notification label and NO
// per-page select (the notifications center keeps the 25 bound).
db()->execute('DELETE FROM notifications');
for ($i = 0; $i < 30; $i++) {
    $service->notifyFor($riyaId, 'admin_alert', []);
}
$payload = $service->page($riyaId, 'all', 2, 25);
$html = View::fragment('notifications.partials._list', [
    'payload' => $payload,
    'tab'     => 'all',
    'filter'  => '',
    'base'    => '/notifications/center',
]);
$check('The pagination line labels notifications', str_contains($html, 'Showing 26&ndash;30 of 30 notification'));
$check('No per-page select ships in the center', !str_contains($html, 'Per page'));
$check('The pager carries the notification landmark', str_contains($html, 'aria-label="Notification pages"'));
$check('The pager links preserve the tab', str_contains($html, 'tab=all'));
$check('The pager links target the center', str_contains($html, 'href="/notifications/center?tab=all"') && str_contains($html, 'Previous page'));

// ---------------------------------------------------------------------
// 4. THE NAVBAR: the bell, the sidebar, the head + scripts
// ---------------------------------------------------------------------

echo $section('4. VIEWS: the navbar surface');

$bellHtml = View::fragment('partials.header');
$check('The bell renders with the badge + panel + footer', str_contains($bellHtml, 'data-notif-trigger') && str_contains($bellHtml, 'data-notif-badge') && str_contains($bellHtml, 'data-notif-panel') && str_contains($bellHtml, 'notif-menu-footer'));
$check('The panel holds the skeleton + the mark-all form', str_contains($bellHtml, 'notif-skeleton-stack') && str_contains($bellHtml, 'data-notif-mark-all'));
$check('The footer links to the center', str_contains($bellHtml, 'href="/notifications/center"'));

$sidebarHtml = View::fragment('partials.sidebar', ['active' => 'notifications']);
$check('The sidebar lists Notifications', str_contains($sidebarHtml, 'href="/notifications/center"'));
$check('The sidebar marks it active', str_contains($sidebarHtml, ' is-active'));

$headHtml = View::fragment('partials.head');
$scriptsHtml = View::fragment('partials.scripts');
$check('head.php includes the module CSS after the app CSS', strpos($headHtml, 'css/notifications.css') > strpos($headHtml, 'css/app.css'));
$check('scripts.php includes the module JS', str_contains($scriptsHtml, 'js/notifications.js'));

// ---------------------------------------------------------------------
// 5. THE HELPER: relative notification times
// ---------------------------------------------------------------------

echo $section('5. HELPERS: format_notification_time');

$check('Null / empty values render empty', format_notification_time(null) === '' && format_notification_time('') === '');
$check('An unparseable value renders empty', format_notification_time('not-a-date') === '');
$check('Under a minute reads "just now"', format_notification_time(date('c', time() - 20)) === 'just now');
$check('Minutes read "Nm ago"', format_notification_time(date('c', time() - 300)) === '5m ago');
$check('Hours read "Nh ago"', format_notification_time(date('c', time() - (3 * 3600))) === '3h ago');
$check('Days read "Nd ago"', format_notification_time(date('c', time() - (2 * 86400))) === '2d ago');
$check('Past a week falls back to the short date', format_notification_time(date('c', time() - (8 * 86400))) === date('M j, Y', time() - (8 * 86400)));

// ---------------------------------------------------------------------
// 6. CONTROLLER: unreadCount, markUnread and the bulk delete
// ---------------------------------------------------------------------

echo $section('6. CONTROLLER: the Phase 9.4 endpoints');

db()->execute('DELETE FROM notifications');

$a = $service->notifyFor($riyaId, 'admin_alert', []);
$b = $service->notifyFor($riyaId, 'admin_alert', []);
$c = $service->notifyFor($riyaId, 'admin_alert', []);
$f = $service->notifyFor($adminId, 'admin_alert', []);

$fetch();
$payload = $json(fn () => $controller->unreadCount(new Request()));
$noFetch();
$check('unreadCount() answers the badge number', ($payload['count'] ?? null) === 3);

$fetch();
$payload = $json(fn () => $controller->markRead(new Request(), ['id' => (string) $b]));
$noFetch();
$fetch();
$payload = $json(fn () => $controller->markUnread(new Request(), ['id' => (string) $b]));
$noFetch();
$dbRow = db()->query('SELECT is_read FROM notifications WHERE id = ?', [$b])[0];
$check('markUnread() flips a read row back to unread', ($payload['ok'] ?? null) === true && (int) $dbRow['is_read'] === 0);

$fetch();
$payload = $json(fn () => $controller->markUnread(new Request(), ['id' => (string) $f]));
$noFetch();
$check('markUnread() on a FOREIGN row answers 404', ($payload['error'] ?? '') === 'Notification not found.');

$fetch();
$payload = $json(fn () => $controller->markUnread(new Request(), ['id' => '999999']));
$noFetch();
$check('markUnread() on a missing row answers 404', ($payload['error'] ?? '') === 'Notification not found.');

$_POST = ['ids' => [(string) $a, (string) $b, (string) $f, 'junk', '0']];
$fetch();
$payload = $json(fn () => $controller->bulkDestroy(new Request()));
$noFetch();
$_POST = [];
$ownLeft = (int) db()->query('SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND id IN (?, ?)', [$riyaId, $a, $b])[0]['c'];
$foreignIntact = (int) db()->query('SELECT COUNT(*) AS c FROM notifications WHERE id = ?', [$f])[0]['c'];
$check('bulkDestroy() deletes the owned selection', ($payload['ok'] ?? null) === true && ($payload['deleted'] ?? 0) === 2);
$check('The foreign id in the batch is skipped, never touched', $ownLeft === 0 && $foreignIntact === 1);

$_POST = ['ids' => []];
$fetch();
$payload = $json(fn () => $controller->bulkDestroy(new Request()));
$noFetch();
$_POST = [];
$check('bulkDestroy() with nothing selected answers 422', ($payload['error'] ?? '') === 'No notifications selected.');

// The fragment: HTML of the shared list + the repaint numbers.
$service->notifyFor($riyaId, 'review_reacted', ['actor' => 'Ada Lovelace', 'book' => 'Dune']);
$service->notifyFor($riyaId, 'admin_alert', []);
$service->notifyFor($riyaId, 'wishlist_reminder', ['title' => 'Dune']);
$_GET = ['tab' => 'all', 'filter' => 'review'];
$fetch();
$payload = $json(fn () => $controller->fragment(new Request()));
$noFetch();
$_GET = [];
$check('fragment() answers {html, total, unread}', isset($payload['html'], $payload['total'], $payload['unread']));
$check('The fragment renders the same list partial', str_contains((string) ($payload['html'] ?? ''), 'notif-list'));
$check('The type filter narrows the fragment', ($payload['total'] ?? 0) === 1 && str_contains((string) ($payload['html'] ?? ''), 'Review appreciated'));
$check('The unread number rides along for the repaint', ($payload['unread'] ?? -1) === 4);

$_GET = ['tab' => 'unread', 'filter' => ''];
$fetch();
$payload = $json(fn () => $controller->fragment(new Request()));
$noFetch();
$_GET = [];
$check('The tab filter narrows the fragment too', ($payload['total'] ?? 0) === 4);

// ---------------------------------------------------------------------
// 7. ROUTER: all nine routes register and dispatch
// ---------------------------------------------------------------------

echo $section('7. ROUTER: the notification surface routes');

$service->notifyFor($riyaId, 'admin_alert', []);
$target = $service->notifyFor($riyaId, 'admin_alert', []);

$dispatch = function (string $uri, string $method, array $post = []) use ($capture, $fetch, $noFetch): array {
    $_SERVER['REQUEST_URI']    = $uri;
    $_SERVER['REQUEST_METHOD'] = $method;
    $_POST                     = $post;
    $fetch();

    $make = static fn (): NotificationController => new NotificationController(new NotificationService(new Notification(), new NotificationDispatcher(new Notification(), new NotificationFormatter())));

    $router = new Router(new Request(), new MiddlewarePipeline());
    $router->get('/notifications', [$make(), 'index']);
    $router->get('/notifications/center', [$make(), 'center']);
    $router->get('/notifications/unread-count', [$make(), 'unreadCount']);
    $router->get('/notifications/fragment', [$make(), 'fragment']);
    $router->patch('/notifications/read-all', [$make(), 'markAllRead']);
    $router->patch('/notifications/{id}/read', [$make(), 'markRead']);
    $router->patch('/notifications/{id}/unread', [$make(), 'markUnread']);
    $router->post('/notifications/bulk', [$make(), 'bulkDestroy']);
    $router->delete('/notifications', [$make(), 'deleteAll']);
    $router->delete('/notifications/{id}', [$make(), 'destroy']);

    $out = $capture(fn () => $router->dispatch());
    $noFetch();

    $decoded = json_decode($out, true);

    return ['raw' => $out, 'json' => is_array($decoded) ? $decoded : []];
};

$out = $dispatch('/notifications/center', 'GET');
$check('GET /notifications/center dispatches the page', str_contains($out['raw'], 'notif-intro'));

$out = $dispatch('/notifications/unread-count', 'GET');
$check('GET /notifications/unread-count dispatches the badge', isset($out['json']['count']));

$out = $dispatch('/notifications/fragment?tab=all&filter=&page=1', 'GET');
$check('GET /notifications/fragment dispatches the list fragment', isset($out['json']['html'], $out['json']['total']));

$out = $dispatch('/notifications/' . $target . '/unread', 'POST', ['_method' => 'PATCH']);
$dbRow = db()->query('SELECT is_read FROM notifications WHERE id = ?', [$target])[0];
$check('PATCH /notifications/{id}/unread dispatches markUnread', ($out['json']['ok'] ?? null) === true && (int) $dbRow['is_read'] === 0);

$out = $dispatch('/notifications/' . $target . '/read', 'POST', ['_method' => 'PATCH']);
$dbRow = db()->query('SELECT is_read FROM notifications WHERE id = ?', [$target])[0];
$check('PATCH /notifications/{id}/read dispatches markRead', ($out['json']['ok'] ?? null) === true && (int) $dbRow['is_read'] === 1);

$out = $dispatch('/notifications/read-all', 'POST', ['_method' => 'PATCH']);
$check('PATCH /notifications/read-all dispatches markAllRead', ($out['json']['ok'] ?? null) === true);

$out = $dispatch('/notifications/bulk', 'POST', ['ids' => [(string) $target]]);
$gone = (int) db()->query('SELECT COUNT(*) AS c FROM notifications WHERE id = ?', [$target])[0]['c'];
$check('POST /notifications/bulk dispatches bulkDestroy', ($out['json']['ok'] ?? null) === true && ($out['json']['deleted'] ?? 0) === 1 && $gone === 0);

$keep = $service->notifyFor($riyaId, 'admin_alert', []);
$out = $dispatch('/notifications/' . $keep, 'POST', ['_method' => 'DELETE']);
$check('DELETE /notifications/{id} dispatches destroy', ($out['json']['ok'] ?? null) === true);

$out = $dispatch('/notifications', 'POST', ['_method' => 'DELETE']);
$check('DELETE /notifications dispatches deleteAll', ($out['json']['ok'] ?? null) === true && ($out['json']['deleted'] ?? 0) >= 1);

$_GET = [];
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';

// ---------------------------------------------------------------------
// 8. PROBES: the no-JS fallback + the guest gate
// ---------------------------------------------------------------------

echo $section('8. PROBES: the no-JS fallback and the guest gate');

$probeRoot = root_path();
$probePath = sys_get_temp_dir() . '/booksphere_notification_center_probe.php';
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
    . '$session = new Session(\'notification_center_probe\');' . PHP_EOL
    . '$session->start();' . PHP_EOL
    . '$auth = new AuthService($session, new User());' . PHP_EOL
    . 'AuthService::setInstance($auth);' . PHP_EOL
    . 'register_shutdown_function(function (): void {' . PHP_EOL
    . '    $flash = session()->getFlash(\'success\') ?? session()->getFlash(\'error\');' . PHP_EOL
    . '    echo $flash === null ? \'NO_FLASH\' : (string) $flash;' . PHP_EOL
    . '});' . PHP_EOL
    . '$controller = new NotificationController(new NotificationService(new Notification(), new NotificationDispatcher(new Notification(), new NotificationFormatter())));' . PHP_EOL;

// Guest: the AuthMiddleware gate of the new GET routes.
$probeGuest = $probeHead
    . '(new AuthMiddleware($auth))->handle(new Request(), static fn (): string => \'authorized\');' . PHP_EOL;
file_put_contents($probePath, $probeGuest);
$out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' guest 2>&1');
$check('A guest hits the login flash on the new routes too', str_contains($out, 'Please log in to continue.') && !str_contains($out, 'authorized'));

// Signed-in + no fetch header: the no-JS bulk delete answers a flash.
$probeBulk = $probeHead
    . '$session->put(\'auth_user_id\', ' . $riyaId . ');' . PHP_EOL
    . '$session->put(\'auth_user\', [\'id\' => ' . $riyaId . ', \'full_name\' => \'Riya Sharma\', \'role\' => \'user\']);' . PHP_EOL
    . '$_POST = [\'ids\' => [\'' . $target . '\']];' . PHP_EOL
    . '$controller->bulkDestroy(new Request());' . PHP_EOL;
file_put_contents($probePath, $probeBulk);
$out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' bulk 2>&1');
$check('The no-JS bulk answers a flash, no JSON body', str_contains($out, 'Notifications updated.') && !str_contains($out, 'ok'));

// Signed-in + no fetch header + a foreign id: the 404 flash.
$probeForeign = $probeHead
    . '$session->put(\'auth_user_id\', ' . $riyaId . ');' . PHP_EOL
    . '$session->put(\'auth_user\', [\'id\' => ' . $riyaId . ', \'full_name\' => \'Riya Sharma\', \'role\' => \'user\']);' . PHP_EOL
    . '$controller->markUnread(new Request(), [\'id\' => \'999999\']);' . PHP_EOL;
file_put_contents($probePath, $probeForeign);
$out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' unread 2>&1');
$check('The no-JS missing-id unread flashes the 404 message', str_contains($out, 'Notification not found.'));
unlink($probePath);

// ---------------------------------------------------------------------
// 9. REGRESSION: the shared dispatcher still feeds the surface
// ---------------------------------------------------------------------

echo $section('9. REGRESSION: the dispatcher -> surface loop');

db()->execute('DELETE FROM notifications');

$service->notifyFor($riyaId, 'library_milestone', ['title' => 'Dune']);
$count = (int) db()->query('SELECT COUNT(*) AS c FROM notifications WHERE user_id = ?', [$riyaId])[0]['c'];
$check('A milestone pings through the shared dispatcher', $count === 1);

$payload = $service->page($riyaId, 'all', 1, 25);
$html = View::fragment('notifications.partials._list', ['payload' => $payload, 'tab' => 'all', 'filter' => '']);
$check('The pings surface in the list fragment', str_contains($html, 'Library milestone'));

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------

echo PHP_EOL . str_repeat('-', 72) . PHP_EOL;
echo '  Notification Center (Phase 9.4): ' . $checks . ' checks, ' . $failures . ' failure' . ($failures === 1 ? '' : 's') . PHP_EOL;
echo str_repeat('-', 72) . PHP_EOL;

exit($failures === 0 ? 0 : 1);
