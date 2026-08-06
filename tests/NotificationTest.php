<?php

declare(strict_types=1);

/**
 * NotificationTest — CLI test suite for Phase 9.2 (Notification System)
 *
 * Verifies the complete backend of the notification module: the three
 * schemas (migrations 0023-0025), the pure content layer
 * (NotificationFormatter), the single creation door
 * (NotificationDispatcher with its preference gates and the batched
 * fan-outs), the orchestrating service (NotificationService: the
 * center reads, the owner-scoped state changes, the preference
 * toggles and the retention sweep), the IDOR guard (findOwnedBy),
 * the Notification facade and its relationship helper, the CHECK +
 * CASCADE constraints as the database's last line of defence and a
 * light regression of the follow tables. The notification CENTER UI
 * (controller + views) is deliberately deferred to a later phase, so
 * this suite is pure data + logic.
 *
 *     1. Schema       - notifications / notification_preferences /
 *                       notification_deliveries (columns, CHECKs, the
 *                       indexes, CASCADE FKs, the empty outbox)
 *     2. Formatter    - the TEMPLATES catalog (10 types), the
 *                       placeholder substitution, the action_url
 *                       collapse, invalidType
 *     3. Dispatcher   - notify() + the preference gates + force, the
 *                       ungated transactional types, fanOutByUsers and
 *                       fanOutForAuthor (one INSERT ... SELECT, gates
 *                       inside the SQL)
 *     4. Service      - types(), notifyFor(), page() (tabs, clamps,
 *                       pagination shape), unreadCount(), markRead /
 *                       markAllRead / delete / deleteAll, preferences()
 *                       / updatePreference(), prune()
 *     5. IDOR         - findOwnedBy scopes a foreign row to "missing"
 *     6. Model        - the Notification facade + user() relationship
 *     7. Database     - the CHECK constraints and the ON DELETE
 *                       CASCADE (user -> notifications/preferences,
 *                       notification -> deliveries)
 *     8. Regression   - the author_follows module keeps working
 *
 * Run from the project root:
 *
 *     php tests/NotificationTest.php
 *
 * The throwaway database (database/notification_test.db) is migrated,
 * seeded and left in place for inspection; delete it anytime.
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Core\Logger;
use BookSphere\App\Core\Migrator;
use BookSphere\App\Core\Seeder;
use BookSphere\App\Exceptions\NotificationException;
use BookSphere\App\Models\Author;
use BookSphere\App\Models\AuthorFollow;
use BookSphere\App\Models\Notification;
use BookSphere\App\Models\User;
use BookSphere\App\Repositories\NotificationRepository;
use BookSphere\App\Services\FollowService;
use BookSphere\App\Services\NotificationDispatcher;
use BookSphere\App\Services\NotificationFormatter;
use BookSphere\App\Services\NotificationService;

// ---------------------------------------------------------------------
// 0. Boot: fresh throwaway database, migrated and seeded.
// ---------------------------------------------------------------------

(new Environment(root_path('.env')))->load();

$dbPath = root_path('database/notification_test.db');

foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

Database::instance($dbPath);
(new Migrator(db(), root_path('database/migrations')))->run();
(new Seeder(db(), root_path('database/seeds')))->run();

$logFile = sys_get_temp_dir() . '/booksphere_notification_test.log';
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

$authorId = fn (string $name): int => (int) db()->query('SELECT id FROM authors WHERE name = ?', [$name])[0]['id'];
$harper = $authorId('Harper Lee');    // id 1 == the admin's user id (never self-follow)
$george = $authorId('George Orwell'); // id 2 == riya's user id (never self-follow)
$jk     = $authorId('J.K. Rowling');

// The module stack, wired EXACTLY like routes/web.php: the service
// receives ONE dispatcher built on ONE formatter.
$notifications = new Notification();
$formatter     = new NotificationFormatter();
$dispatcher    = new NotificationDispatcher($notifications, $formatter, new Logger($logFile));
$repository    = new NotificationRepository();
$facade        = new Notification();
$service       = new NotificationService($facade, $dispatcher, new Logger($logFile));

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

$wipe = function (): void {
    db()->execute('DELETE FROM notifications');
    db()->execute('DELETE FROM notification_preferences');
    db()->execute('DELETE FROM notification_deliveries');
    db()->execute('DELETE FROM author_follows');
};

// Fresh notification rows for the read tab (old truths are stored
// formatted, so raw inserts carry the CHECK-catalog types).
$insert = function (int $userId, string $type, ?string $createdAt = null): int {
    db()->execute(
        'INSERT INTO notifications (user_id, type, title, message, icon, color, action_url, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NULL, ?)',
        [$userId, $type, 'T', 'M', 'fa-solid fa-bell', 'primary', $createdAt ?? gmdate('Y-m-d\TH:i:s\Z')],
    );

    return (int) db()->lastInsertId();
};

// ---------------------------------------------------------------------
// 1. SCHEMA (migrations 0023-0025: the three notification tables)
// ---------------------------------------------------------------------

echo $section('1. SCHEMA: the notification tables (0023-0025)');

$notificationColumns = array_column(db()->query('PRAGMA table_info(notifications)'), 'name');
$check('The notifications table carries every stored column', array_diff(
    ['id', 'user_id', 'type', 'title', 'message', 'icon', 'color', 'action_url', 'is_read', 'read_at', 'created_at'],
    $notificationColumns,
) === []);
$check('notifications has no updated_at (immutable rows)', !in_array('updated_at', $notificationColumns, true));

$notificationSql = (string) db()->query('SELECT sql FROM sqlite_master WHERE type = \'table\' AND name = \'notifications\'')[0]['sql'];
$check('The type CHECK constrains the catalog', str_contains($notificationSql, 'CHECK (type IN'));
$check('The is_read CHECK allows only 0/1', str_contains($notificationSql, 'CHECK (is_read IN (0, 1))'));
$check('The user_id foreign key cascades', str_contains(preg_replace('/\s+/', ' ', $notificationSql), 'REFERENCES users (id) ON DELETE CASCADE'));

$notificationIndexes = array_column(db()->query('PRAGMA index_list(notifications)'), 'name');
$check('The (user_id, created_at) covering index exists (Phase 9.6 - replaces the bare (user_id) index)', in_array('idx_notifications_user_created', $notificationIndexes, true));
$check('The (user_id, is_read, created_at) covering index exists', in_array('idx_notifications_user_read_created', $notificationIndexes, true));
$check('The (created_at) index exists', in_array('idx_notifications_created', $notificationIndexes, true));

$preferenceColumns = array_column(db()->query('PRAGMA table_info(notification_preferences)'), 'name');
$check('The preferences table carries all seven toggles', array_diff(
    ['user_id', 'author_followed', 'author_activity', 'community', 'recommendations', 'wishlist_reminders', 'system_announcements', 'updated_at'],
    $preferenceColumns,
) === []);
$check('A preference toggle is the 0/1 CHECK model', str_contains(
    (string) db()->query('SELECT sql FROM sqlite_master WHERE type = \'table\' AND name = \'notification_preferences\'')[0]['sql'],
    'CHECK (author_followed IN (0, 1))',
));
$check('The preferences user_id is a cascading FK', str_contains(preg_replace('/\s+/', ' ', (string) db()->query('SELECT sql FROM sqlite_master WHERE type = \'table\' AND name = \'notification_preferences\'')[0]['sql']), 'REFERENCES users (id) ON DELETE CASCADE'));

$deliveryColumns = array_column(db()->query('PRAGMA table_info(notification_deliveries)'), 'name');
$check('The deliveries table carries the outbox columns', array_diff(
    ['id', 'notification_id', 'user_id', 'channel', 'status', 'sent_at', 'error'],
    $deliveryColumns,
) === []);
$deliverySql = (string) db()->query('SELECT sql FROM sqlite_master WHERE type = \'table\' AND name = \'notification_deliveries\'')[0]['sql'];
$check('The channel CHECK allows email/push/in_app', str_contains($deliverySql, "CHECK (channel IN ('email', 'push', 'in_app'))"));
$check('The deliveries table ships empty (the 9.2 outbox is a no-op)', (int) db()->query('SELECT COUNT(*) AS c FROM notification_deliveries')[0]['c'] === 0);

// ---------------------------------------------------------------------
// 2. FORMATTER (the pure template catalog)
// ---------------------------------------------------------------------

echo $section('2. FORMATTER: the template catalog');

$check('The catalog carries the ten blueprint types', count(NotificationFormatter::TEMPLATES) === 10);
$check('types() enumerates the catalog', $service->types() === array_keys(NotificationFormatter::TEMPLATES));
$check('Every template ships title/message/icon/color/action', array_reduce(
    NotificationFormatter::TEMPLATES,
    fn (bool $carry, array $template): bool => $carry
        && array_key_exists('title', $template)
        && array_key_exists('message', $template)
        && array_key_exists('icon', $template)
        && array_key_exists('color', $template)
        && array_key_exists('action', $template),
    true,
));

$content = $formatter->format('author_followed', ['author' => 'Harper Lee', 'author_id' => $harper]);
$check('format() fills the title from the placeholder', $content['title'] === 'Following Harper Lee');
$check('format() fills the message from the placeholder', $content['message'] === 'You started following Harper Lee.');
$check('format() ships the icon class', $content['icon'] === 'fa-solid fa-user-plus');
$check('format() ships the accent token', $content['color'] === 'primary');
$check('format() builds the action URL', $content['action_url'] === '/authors/' . $harper);

$release = $formatter->format('author_new_release', ['author' => 'George Orwell', 'book' => 'Animal Farm', 'book_id' => 3]);
$check('A title with several placeholders substitutes each', $release['title'] === 'George Orwell published');
$check('The message substitutes every placeholder', $release['message'] === 'Animal Farm by George Orwell is here.');
$check('The action uses its own placeholder set', $release['action_url'] === '/books/3');

$announcement = $formatter->format('system_announcement', ['title' => 'Winter break', 'message' => 'Read on!']);
$check('A missing action_url collapses to null', $announcement['action_url'] === null);
$announcement = $formatter->format('system_announcement', ['title' => 'Winter break', 'message' => 'Read on!', 'action_url' => '/browse']);
$check('A present action_url survives', $announcement['action_url'] === '/browse');
$alert = $formatter->format('admin_alert', []);
$check('A null template action stays null', $alert['action_url'] === null);
$check('A missing placeholder substitutes as empty', $formatter->format('account_notice', [])['message'] === '');

$check('An unknown type raises invalidType', $throws(NotificationException::class, fn () => $formatter->format('not_a_type', [])));

// ---------------------------------------------------------------------
// 3. DISPATCHER (the single creation door)
// ---------------------------------------------------------------------

echo $section('3. DISPATCHER: notify, fans-out and the preference gates');

$id = $dispatcher->notify('author_followed', ['author' => 'Harper Lee', 'author_id' => $harper], $riyaId);
$row = $facade->find($id);
$check('notify() returns the new row id', is_int($id) && $id > 0);
$check('notify() stores the formatted content', ($row['title'] ?? '') === 'Following Harper Lee' && ($row['message'] ?? '') === 'You started following Harper Lee.');
$check('notify() stores the catalog type', ($row['type'] ?? '') === 'author_followed');
$check('notify() opens the row unread', (int) ($row['is_read'] ?? 1) === 0);

$facade->updatePreference($riyaId, 'author_followed', false);
$check('An opted-out category suppresses notify()', $dispatcher->notify('author_followed', [], $riyaId) === null);
$check('The suppressed notification was not written', (int) db()->query('SELECT COUNT(*) AS c FROM notifications WHERE user_id = ' . $riyaId)[0]['c'] === 1);
$check('force bypasses the preference gate', $dispatcher->notify('author_followed', [], $riyaId, true) !== null);
$facade->updatePreference($riyaId, 'author_followed', true);

// The ungated transactional types deliver regardless of the toggles.
$facade->updatePreference($riyaId, 'system_announcements', false);
$check('A transactional announcement still delivers', $dispatcher->notify('system_announcement', ['title' => 'News', 'message' => 'Hi'], $riyaId) !== null);
$facade->updatePreference($riyaId, 'system_announcements', true);

$check('An unknown type fails through the dispatcher', $throws(NotificationException::class, fn () => $dispatcher->notify('bogus', [], $riyaId)));

db()->execute('DELETE FROM notifications');
db()->execute('DELETE FROM notification_preferences');

// The arbitrary-recipient fan-out.
$created = $dispatcher->fanOut('review_reacted', ['actor' => 'Arjun', 'book' => '1984', 'book_id' => 2], [$riyaId, $adminId]);
$check('fanOut() writes one row per listed recipient', $created === 2);
$check('The fan-out assigns each row its own user', (int) db()->query('SELECT COUNT(*) AS c FROM notifications WHERE user_id IN (?, ?)', [$riyaId, $adminId])[0]['c'] === 2);

$facade->updatePreference($adminId, 'community', false);
$gated = $dispatcher->fanOut('review_reacted', ['actor' => 'Arjun', 'book' => '1984', 'book_id' => 2], [$riyaId, $adminId]);
$check('The gate skips an opted-out recipient inside the SQL', $gated === 1);
$forced = $dispatcher->fanOut('review_reacted', ['actor' => 'Arjun', 'book' => '1984', 'book_id' => 2], [$riyaId, $adminId], true);
$check('force fans out to everyone', $forced === 2);
$check('An empty recipient list writes nothing', $dispatcher->fanOut('review_reacted', [], []) === 0);
$facade->updatePreference($adminId, 'community', true);

db()->execute('DELETE FROM notifications');
db()->execute('DELETE FROM notification_preferences');
db()->execute('DELETE FROM author_follows');

// The author fan-out: one INSERT ... SELECT FROM author_follows.
db()->execute('INSERT INTO author_follows (user_id, author_id) VALUES (?, ?)', [$riyaId, $jk]);
db()->execute('INSERT INTO author_follows (user_id, author_id) VALUES (?, ?)', [$adminId, $jk]);
$created = $dispatcher->fanOutForAuthor('author_new_release', ['author' => 'J.K. Rowling', 'book' => 'A New Tale', 'book_id' => 9], $jk);
$check('fanOutForAuthor() creates one row per follower', $created === 2);
$check('The author rows carry the formatted content', (int) db()->query('SELECT COUNT(*) AS c FROM notifications WHERE title = \'J.K. Rowling published\'')[0]['c'] === 2);

$facade->updatePreference($adminId, 'author_activity', false);
$gated = $dispatcher->fanOutForAuthor('author_new_release', ['author' => 'J.K. Rowling', 'book' => 'A New Tale', 'book_id' => 9], $jk);
$check('The author fan-out gate applies inside the SQL', $gated === 1);
$forced = $dispatcher->fanOutForAuthor('author_new_release', ['author' => 'J.K. Rowling', 'book' => 'A New Tale', 'book_id' => 9], $jk, true);
$check('The author fan-out force delivers to everyone', $forced === 2);

// ---------------------------------------------------------------------
// 4. SERVICE (the orchestration + the center reads)
// ---------------------------------------------------------------------

echo $section('4. SERVICE: the center reads and the state changes');

$wipe();

// A readable page: 26 rows of one user, read and unread mixed.
for ($i = 0; $i < 26; $i++) {
    $service->notifyFor($riyaId, 'recommendation_ready', ['x' => $i]);
}
$first = (int) $facade->forUser($riyaId, 'all', 0, 1)[0]['id'];
$service->markRead($first, $riyaId);

$check('preferences() defaults every category to on', array_reduce($service->preferences($riyaId), fn (bool $c, int $v): bool => $c && $v === 1, true));
$check('unreadCount() is the badge denominator', $service->unreadCount($riyaId) === 25);

$page = $service->page($riyaId, 'all', 1, 10);
$check('page() returns the paginate() shape', isset($page['items'], $page['total'], $page['page'], $page['pages'], $page['per_page'], $page['has_prev'], $page['has_next']));
$check('page() reports the totals', $page['total'] === 26 && $page['pages'] === 3 && $page['per_page'] === 10);
$check('page() fills the page with the newest rows', count($page['items']) === 10 && (int) $page['items'][0]['id'] === $first);

$page2 = $service->page($riyaId, 'all', 2, 10);
$check('The page clamp keeps the page in range', $page2['page'] === 2 && $page2['has_prev'] === true && $page2['has_next'] === true);
$page99 = $service->page($riyaId, 'all', 99, 10);
$check('A page beyond the last clamps to the last page', $page99['page'] === 3 && $page99['has_next'] === false);

$unread = $service->page($riyaId, 'unread', 1, 50);
$check('The unread tab filters the rows', $unread['total'] === 25 && array_reduce($unread['items'], fn (bool $c, array $r): bool => $c && (int) $r['is_read'] === 0, true));
$read = $service->page($riyaId, 'read', 1, 50);
$check('The read tab holds exactly the read rows', $read['total'] === 1);
$junk = $service->page($riyaId, 'junk', 1, 10);
$check('An unknown tab falls back to all', $junk['total'] === 26);
$big = $service->page($riyaId, 'all', 1, 500);
$check('perPage clamps to the max', $big['per_page'] === 50);

$check('markRead() answers false on a second read', $service->markRead($first, $riyaId) === false);
$check('markAllRead() flips every row', $service->markAllRead($riyaId) === 25);
$check('The badge drops to zero', $service->unreadCount($riyaId) === 0);

db()->execute('DELETE FROM notifications WHERE user_id = ?', [$riyaId]);
$mark = $service->notifyFor($riyaId, 'recommendation_ready', []);
$check('delete() (owner) removes one row', $service->delete($mark, $riyaId) === true);
$check('delete() answers false for a missing row', $service->delete($mark, $riyaId) === false);

$wipe();
$created = $service->notifyFor($riyaId, 'wishlist_reminder', ['title' => 'The Hobbit']);
$service->notifyFor($riyaId, 'wishlist_reminder', ['title' => 'Dune']);
$check('deleteAll() clears the history', $service->deleteAll($riyaId) === 2);
$check('The history is really gone', (int) db()->query('SELECT COUNT(*) AS c FROM notifications WHERE user_id = ' . $riyaId)[0]['c'] === 0);

// navigate to a fresh user for the preference section
$service->updatePreference($riyaId, 'author_activity', false);
$check('updatePreference() flips a toggle', (int) $service->preferences($riyaId)['author_activity'] === 0);
$service->updatePreference($riyaId, 'author_activity', true);
$check('The toggle flips back', (int) $service->preferences($riyaId)['author_activity'] === 1);
$check('An unknown category raises invalidPreference', $throws(NotificationException::class, fn () => $service->updatePreference($riyaId, 'spam_yourself', true)));

$wipe();
$insert($riyaId, 'system_announcement', '2020-01-01T00:00:00Z');
$insert($riyaId, 'system_announcement', '2020-06-01T00:00:00Z');
$fresh = $insert($riyaId, 'system_announcement');
$pruned = $service->prune(30);
$check('prune() removes the rows older than the window', $pruned === 2);
$check('The fresh row survives the sweep', $facade->find($fresh) !== null);

// ---------------------------------------------------------------------
// 5. IDOR (findOwnedBy never leaks a foreign row)
// ---------------------------------------------------------------------

echo $section('5. IDOR: findOwnedBy');

$wipe();
$mine = $service->notifyFor($riyaId, 'account_notice', ['message' => 'Update your email']);
$check('The owner reads their own row', $facade->findOwnedBy($mine, $riyaId) !== null);
$check('A foreign row answers null (no existence leak)', $facade->findOwnedBy($mine, $adminId) === null);
$check('A missing id answers null', $facade->findOwnedBy(999999, $riyaId) === null);
$check('The unscoped find() still sees it', $facade->find($mine) !== null);

// ---------------------------------------------------------------------
// 6. MODEL (the facade + the relationship helper)
// ---------------------------------------------------------------------

echo $section('6. MODEL: the Notification facade');

$check('create()/find() answer through the facade', ($facade->find($facade->create([
    'user_id' => $riyaId,
    'type'    => 'admin_alert',
    'title'   => 'System alert',
    'message' => 'Attention needed.',
    'icon'    => 'fa-solid fa-triangle-exclamation',
    'color'   => 'danger',
]))['type'] ?? '') === 'admin_alert');
$check('forUser()/countForUser() answer through the facade', is_array($facade->forUser($riyaId, 'all', 0, 5)) && $facade->countForUser($riyaId, 'all') > 0);

$probe = $facade->find($mine);
$userRow = $facade->user($probe);
$check('user() resolves the recipient', is_array($userRow) && (string) $userRow['email'] === 'riya@booksphere.test');

// The reserved outbox hook writes a pending channel row.
$parsed = $facade->find($mine);
$repository->enqueueDelivery((int) $parsed['id'], $riyaId, 'email');
$delivery = db()->query('SELECT * FROM notification_deliveries WHERE notification_id = ?', [(int) $parsed['id']])[0];
$check('enqueueDelivery() queues a pending row', ($delivery['channel'] ?? '') === 'email' && ($delivery['status'] ?? '') === 'pending');

// ---------------------------------------------------------------------
// 7. DATABASE (the CHECK + CASCADE last line of defence)
// ---------------------------------------------------------------------

echo $section('7. DATABASE: the CHECKs and the CASCADE');

$check('The type CHECK rejects a foreign catalog value', $throws(PDOException::class, fn () => db()->execute(
    'INSERT INTO notifications (user_id, type, title, message, icon, color, action_url) VALUES (?, ?, ?, ?, ?, ?, NULL)',
    [$riyaId, 'not_in_catalog', 'T', 'M', 'fa-solid fa-bell', 'primary'],
)));
$check('The is_read CHECK rejects 2', $throws(PDOException::class, fn () => db()->execute(
    "INSERT INTO notifications (user_id, type, title, message, icon, color, is_read) VALUES (?, 'admin_alert', 'T', 'M', 'x', 'x', 2)",
    [$riyaId],
)));
$check('A preference toggle CHECK rejects 2', $throws(PDOException::class, fn () => db()->execute(
    'INSERT INTO notification_preferences (user_id, community) VALUES (?, 2)',
    [$riyaId],
)));
$check('The channel CHECK rejects sms', $throws(PDOException::class, fn () => db()->execute(
    "INSERT INTO notification_deliveries (notification_id, user_id, channel) VALUES (?, ?, 'sms')",
    [(int) $parsed['id'], $riyaId],
)));

// CASCADE on its own scratch user (never a seeded account): the
// notifications, the preferences row and the deliveries all vanish.
db()->execute("INSERT INTO users (full_name, email, password, role) VALUES ('Scratch', 'scratch@notify.test', 'x', 'user')");
$scratch = (int) db()->query("SELECT id FROM users WHERE email = 'scratch@notify.test'")[0]['id'];
$scratchNotification = $facade->create(['user_id' => $scratch, 'type' => 'admin_alert', 'title' => 'T', 'message' => 'M', 'icon' => 'x', 'color' => 'x']);
db()->execute('INSERT INTO notification_preferences (user_id, community) VALUES (?, 1)', [$scratch]);
db()->execute('INSERT INTO notification_deliveries (notification_id, user_id, channel) VALUES (?, ?, \'push\')', [$scratchNotification, $scratch]);
db()->execute('DELETE FROM users WHERE id = ?', [$scratch]);
$orphans = [
    (int) db()->query('SELECT COUNT(*) AS c FROM notifications WHERE user_id = ?', [$scratch])[0]['c'],
    (int) db()->query('SELECT COUNT(*) AS c FROM notification_preferences WHERE user_id = ?', [$scratch])[0]['c'],
    (int) db()->query('SELECT COUNT(*) AS c FROM notification_deliveries WHERE user_id = ?', [$scratch])[0]['c'],
];
$check('Deleting a user cascades notifications + preferences + deliveries', array_sum($orphans) === 0);

// The delivery rows cascade away with their notification.
$keep = $service->notifyFor($riyaId, 'admin_alert', []);
db()->execute('INSERT INTO notification_deliveries (notification_id, user_id, channel) VALUES (?, ?, \'push\')', [$keep, $riyaId]);
$service->delete($keep, $riyaId);
$cascadedDelivery = (int) db()->query('SELECT COUNT(*) AS c FROM notification_deliveries WHERE notification_id = ?', [$keep])[0]['c'];
$check('Deleting a notification cascades its delivery rows', $cascadedDelivery === 0);

// ---------------------------------------------------------------------
// 8. REGRESSION (the follow module next to the new tables)
// ---------------------------------------------------------------------

echo $section('8. REGRESSION: the follow module');

db()->execute('DELETE FROM notifications');

$check('The follow service still writes through the dispatcher', (new FollowService(
    new AuthorFollow(),
    new Author(),
    $dispatcher,
))->follow($riyaId, $harper) > 0);
$check('The follow row persisted next to the tables', (bool) db()->query('SELECT COUNT(*) AS c FROM author_follows WHERE user_id = ? AND author_id = ?', [$riyaId, $harper])[0]['c'] > 0);
$check('The confirmation ping rode along', (int) db()->query("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND type = 'author_followed'", [$riyaId])[0]['c'] === 1);

// ---------------------------------------------------------------------
// RESULT
// ---------------------------------------------------------------------

echo "\n------------------------------------------------------------------------\nRESULT\n------------------------------------------------------------------------\n";
echo sprintf("  Checks: %d\n  Failed: %d\n", $GLOBALS['checks'], $GLOBALS['failures']);
echo "\nNote: the throwaway database database/notification_test.db and the log file $logFile are left in place for inspection; delete them anytime.\n";

exit($GLOBALS['failures'] > 0 ? 1 : 0);