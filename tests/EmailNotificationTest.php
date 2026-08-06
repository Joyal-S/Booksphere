<?php

declare(strict_types=1);

/**
 * EmailNotificationTest — CLI test suite for Phase 9.5 (Email
 * Notification System)
 *
 * Verifies the optional email channel end to end:
 *
 *     1. Config           - config/email.php loads with sane defaults,
 *                           EmailMessage validates its payload (bad
 *                           address, header injection, over-long
 *                           subject)
 *     2. Preferences      - the five toggles default on, save, and
 *                           reject an unknown key (service + facade)
 *     3. Templates        - subjectFor() and htmlFor() render every
 *                           catalog type: escaped content, the CTA,
 *                           the settings/unsubscribe footer, the brand
 *     4. Service pipeline - dispatch() with the log transport: sent
 *                           row + audit + file; the dedupe key stops
 *                           re-fires; an opted-out subject is skipped
 *                           (audited); a disabled module is a no-op; a
 *                           missing recipient is logged, not fatal
 *     5. Queue mode       - generation is separated from delivery: a
 *                           pending row + 'queued' audit;
 *                           processQueue() delivers it (sent + audit)
 *     6. SMTP failure     - a dead server degrades to a 'failed' row
 *                           and log entries - it can never throw
 *     7. SMTP success     - a tiny SMTP server accepts a full
 *                           handshake (EHLO / MAIL / RCPT / DATA /
 *                           QUIT) through SmtpTransport
 *     8. Dispatcher hook  - the real follow flow emails through the
 *                           shared dispatcher; a broken SMTP does NOT
 *                           break the notification; library milestones
 *                           (no email type) never email
 *     9. Settings page    - the GET renders five toggles; the fetch
 *                           POST saves with JSON; the no-JS POST
 *                           flashes (probe); guests are gated (probe)
 *    10. Env override     - a subprocess probe proves the env vars
 *                           drive config/email.php
 *
 * Run from the project root:
 *
 *     php tests/EmailNotificationTest.php
 *
 * The throwaway database (database/email_notification_test.db) is
 * migrated and seeded and left in place for inspection; delete it
 * anytime.
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
use BookSphere\App\Controllers\SettingsController;
use BookSphere\App\Exceptions\EmailException;
use BookSphere\App\Mail\EmailMessage;
use BookSphere\App\Mail\EmailType;
use BookSphere\App\Mail\Mailer;
use BookSphere\App\Mail\SmtpTransport;
use BookSphere\App\Middleware\AuthMiddleware;
use BookSphere\App\Models\Author;
use BookSphere\App\Models\AuthorFollow;
use BookSphere\App\Models\EmailLog;
use BookSphere\App\Models\EmailPreference;
use BookSphere\App\Models\EmailQueue;
use BookSphere\App\Models\Notification;
use BookSphere\App\Models\User;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\EmailNotificationService;
use BookSphere\App\Services\FollowService;
use BookSphere\App\Services\NotificationDispatcher;
use BookSphere\App\Services\NotificationFormatter;

// ---------------------------------------------------------------------
// 0. Boot: fresh throwaway database, migrated and seeded.
// ---------------------------------------------------------------------

(new Environment(root_path('.env')))->load();

$dbPath = root_path('database/email_notification_test.db');

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
$session = new Session('email_notification_test');
$session->start();
$auth = new AuthService($session, new User());
AuthService::setInstance($auth);

$appLog = sys_get_temp_dir() . '/booksphere_email_app_test.log';
$outLog = sys_get_temp_dir() . '/booksphere_email_output_test.log';
foreach ([$appLog, $outLog] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

// Shared fixtures from the seed data.
$users  = new User();
$riya   = $users->findByEmail('riya@booksphere.test');
$riyaId = (int) $riya['id'];

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

/**
 * The standard service under test. $overrides patches the base email
 * config (array_replace_recursive), so every section can flip the
 * transport, the queue or the master switch.
 */
$makeService = function (array $overrides = [], bool $enabled = true) use ($appLog, $outLog): EmailNotificationService {
    $config = array_replace_recursive([
        'enabled'     => $enabled,
        'from'        => ['address' => 'no-reply@booksphere.test', 'name' => 'BookSphere'],
        'transport'   => 'log',
        'smtp'        => ['host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'auth' => false, 'username' => '', 'password' => '', 'timeout' => 2],
        'queue'       => ['enabled' => false, 'batch' => 25],
        'log_file'    => $outLog,
        'app_url'     => 'http://localhost:8000',
    ], $overrides);

    $mailer = new Mailer($config, new Logger($appLog));

    return new EmailNotificationService(
        new User(),
        new EmailPreference(),
        new EmailLog(),
        new EmailQueue(),
        $mailer,
        $config,
        new Logger($appLog),
    );
};

/** A formatted in-app content row exactly as the dispatcher passes it. */
$followContent = fn (): array => [
    'type'       => 'author_followed',
    'title'      => 'Following J.K. Rowling',
    'message'    => 'You started following J.K. Rowling.',
    'icon'       => 'fa-solid fa-user-plus',
    'color'      => 'primary',
    'action_url' => '/authors/2',
];

$logCount = fn (string $type, int $userId): int => (int) db()->query(
    'SELECT COUNT(*) AS c FROM email_logs WHERE type = ? AND user_id = ?',
    [$type, $userId],
)[0]['c'];

$outWritten = fn (): bool => is_file($outLog) && str_contains((string) file_get_contents($outLog), 'riya@booksphere.test');

// ---------------------------------------------------------------------
// 1. CONFIG: defaults load, message validation
// ---------------------------------------------------------------------

echo $section('1. CONFIG LOADING & MESSAGE VALIDATION');

$loaded = (array) config('email', []);

$check('config(\'email.enabled\') defaults to false', (bool) ($loaded['enabled'] ?? true) === false);
$check('config(\'email.transport\') defaults to log', ($loaded['transport'] ?? '') === 'log');
$check('config(\'email.from.address\') defaults to the sender', (($loaded['from'] ?? [])['address'] ?? '') === 'no-reply@booksphere.test');
$check('config(\'email.queue.enabled\') defaults to false', (bool) (($loaded['queue'] ?? [])['enabled'] ?? true) === false);
$check('config(\'email.log_file\') lives inside storage/logs', str_contains((string) ($loaded['log_file'] ?? ''), 'storage/logs'));

// EmailMessage validation.
$valid = new EmailMessage('riya@booksphere.test', 'Riya Sharma', 'A subject', '<p>Hi</p>');
$check('A valid message passes', $valid->to() === 'riya@booksphere.test');

$injectionCaught = false;
try {
    new EmailMessage('riya@booksphere.test', 'Riya Sharma', "Good\r\nBcc: evil@attacker.test", '<p>Hi</p>');
} catch (EmailException $e) {
    $injectionCaught = str_contains($e->getMessage(), 'injection');
}
$check('A CR/LF in the subject is rejected as injection', $injectionCaught);

$injectionTo = false;
try {
    new EmailMessage("riya@booksphere.test\r\nBcc: evil@attacker.test", 'Riya', 'Subject', '<p>Hi</p>');
} catch (EmailException $e) {
    $injectionTo = str_contains($e->getMessage(), 'injection');
}
$check('A CR/LF in the recipient is rejected as injection', $injectionTo);

$invalidAddress = false;
try {
    new EmailMessage('not-an-address', 'Riya', 'Subject', '<p>Hi</p>');
} catch (EmailException $e) {
    $invalidAddress = true;
}
$check('A malformed recipient address is rejected', $invalidAddress);

$longSubject = false;
try {
    new EmailMessage('riya@booksphere.test', 'Riya', str_repeat('x', 201), '<p>Hi</p>');
} catch (EmailException $e) {
    $longSubject = true;
}
$check('An over-long subject is rejected', $longSubject);

// ---------------------------------------------------------------------
// 2. PREFERENCES (defaults, save, unknown key)
// ---------------------------------------------------------------------

echo $section('2. EMAIL PREFERENCES');

$prefs = (new EmailPreference())->preferences($riyaId);
$check('All five toggles default ON', array_sum(array_map('intval', array_values($prefs))) === 5);

$thisService = $makeService();
$thisService->updatePreference($riyaId, 'follow', false);
$after = (new EmailPreference())->preferences($riyaId);
$check('updatePreference() persists a toggle off', (int) $after['follow'] === 0);
$thisService->updatePreference($riyaId, 'follow', true);
$after = (new EmailPreference())->preferences($riyaId);
$check('updatePreference() flips the toggle back on', (int) $after['follow'] === 1);

$unknownThrown = false;
try {
    $thisService->updatePreference($riyaId, 'spam', true);
} catch (EmailException $e) {
    $unknownThrown = str_contains($e->getMessage(), 'email preference');
}
$check('An unknown preference key raises EmailException', $unknownThrown);

// The repository's column allowlist silently ignores a tampered key
// (the last line of defence behind the service's validation).
(new EmailPreference())->updatePreference($riyaId, 'DROP TABLE users', false);
$usersTable = db()->query("SELECT COUNT(*) AS c FROM sqlite_master WHERE type = 'table' AND name = 'users'")[0]['c'];
$check('A tampered repository key cannot touch the schema', $usersTable === 1);

// ---------------------------------------------------------------------
// 3. TEMPLATES (subject + html for every catalog type)
// ---------------------------------------------------------------------

echo $section('3. TEMPLATE RENDERING');

$tpl = $makeService();
$check('The follow subject reuses the in-app title', $tpl->subjectFor(EmailType::FOLLOW, $followContent()) === 'Following J.K. Rowling');

$html = $tpl->htmlFor(EmailType::FOLLOW, $followContent(), 'Riya Sharma');
$check('The layout is a full HTML document', str_starts_with($html, '<!DOCTYPE html>'));
$check('The body names the recipient', str_contains($html, 'Riya Sharma'));
$check('The body carries the message', str_contains($html, 'You started following J.K. Rowling.'));
$check('The CTA button links the author page', str_contains($html, 'http://localhost:8000/authors/2') && str_contains($html, 'View author'));
$check('The footer carries the settings (unsubscribe) link', str_contains($html, '/settings'));
$check('The footer carries the copyright', str_contains($html, '&copy;'));
$check('The header carries the brand', str_contains($html, 'BookSphere'));

foreach (EmailType::all() as $catalogType) {
    $tryHtml = $tpl->htmlFor($catalogType, $followContent(), 'Riya Sharma');
    $check("Template renders for {$catalogType}", str_contains($tryHtml, '<!DOCTYPE html>'));
}
$check('The welcome email greets the recipient', str_contains($tpl->htmlFor(EmailType::WELCOME, [], 'Riya'), 'Riya'));
$check('Password reset has its fixed subject', $tpl->subjectFor(EmailType::PASSWORD_RESET, []) === 'Reset your BookSphere password');
$check('Verification has its fixed subject', $tpl->subjectFor(EmailType::EMAIL_VERIFICATION, []) === 'Verify your BookSphere email address');

// User content is escaped before it ever reaches the HTML.
$malicious           = $followContent();
$malicious['message'] = '<script>alert("xss")</script>';
$escapedHtml         = $tpl->htmlFor(EmailType::FOLLOW, $malicious, 'Riya');
$check('User content is escaped in the email body', str_contains($escapedHtml, '&lt;script&gt;') && !str_contains($escapedHtml, '<script>alert'));

// ---------------------------------------------------------------------
// 4. THE SERVICE PIPELINE (log transport)
// ---------------------------------------------------------------------

echo $section('4. THE SERVICE PIPELINE WITH THE LOG TRANSPORT');

$thisService = $makeService();
$thisService->dispatch('author_followed', ['author' => 'J.K. Rowling', 'author_id' => 2], $riyaId, $followContent());
$check('A follow event writes an email_logs row', $logCount(EmailType::FOLLOW, $riyaId) === 1);
$logged = db()->query("SELECT * FROM email_logs WHERE type = 'follow' AND user_id = ?", [$riyaId])[0] ?? null;
$check('The audit row carries the recipient + subject snapshot', is_array($logged) && $logged['to_address'] === 'riya@booksphere.test' && $logged['subject'] === 'Following J.K. Rowling' && $logged['status'] === 'sent');
$check('The log transport wrote the message to the output file', $outWritten());
$check('The application log records email.sent', str_contains((string) file_get_contents($appLog), 'email.sent'));

// Dedupe: the same EVENT re-fired can only ever email once.
$thisService->dispatch('author_followed', ['author' => 'J.K. Rowling', 'author_id' => 2], $riyaId, $followContent());
$check('A re-fired event does not double-send', $logCount(EmailType::FOLLOW, $riyaId) === 1);
$check('A re-fired event logs the dedupe skip', str_contains((string) file_get_contents($appLog), 'email.dedupe_skipped'));

// A DIFFERENT event (different context) sends normally.
$thisService->dispatch('author_followed', ['author' => 'Harper Lee', 'author_id' => 1], $riyaId, [
    'type'       => 'author_followed',
    'title'      => 'Following Harper Lee',
    'message'    => 'You started following Harper Lee.',
    'icon'       => 'fa-solid fa-user-plus',
    'color'      => 'primary',
    'action_url' => '/authors/1',
]);
$check('A different event still sends', $logCount(EmailType::FOLLOW, $riyaId) === 2);

// An opted-out subject is skipped (audited as 'skipped', never sent).
$thisService->updatePreference($riyaId, 'follow', false);
$sentLinesBefore = substr_count((string) file_get_contents($appLog), 'email.sent');
$thisService->dispatch('author_followed', ['author' => 'Harlan Coben', 'author_id' => 3], $riyaId, $followContent());
$skipped = db()->query("SELECT * FROM email_logs WHERE type = 'follow' AND user_id = ? AND status = 'skipped'", [$riyaId])[0] ?? null;
$check('An opted-out event is audited as skipped', is_array($skipped));
$check('An opted-out event sends nothing', $sentLinesBefore === substr_count((string) file_get_contents($appLog), 'email.sent'));
$thisService->updatePreference($riyaId, 'follow', true);

// An event without an email type (a library milestone) never emails.
$thisService->dispatch('library_milestone', ['title' => 'Dune'], $riyaId, [
    'type'       => 'library_milestone',
    'title'      => 'Milestone',
    'message'    => 'You finished Dune.',
    'icon'       => 'fa-solid fa-flag-checkered',
    'color'      => 'success',
    'action_url' => '/books/1',
]);
$check('A library milestone (no email type) never emails', $logCount('library_milestone', $riyaId) === 0);

// A missing recipient is logged, not fatal.
$ghostId = 999999;
$thisService->dispatch('author_followed', ['author' => 'Ghost', 'author_id' => 1], $ghostId, $followContent());
$check('A missing recipient is logged as a warning', str_contains((string) file_get_contents($appLog), 'email.recipient_missing'));

// The disabled module is a complete no-op (the OPTIONAL promise).
// Note: the two sent rows + the one skipped row make three follow
// rows before this dispatch - the disabled module adds none.
$disabled = $makeService([], false);
$countBeforeDisabled = $logCount(EmailType::FOLLOW, $riyaId);
$disabled->dispatch('author_followed', ['author' => 'J.K. Rowling', 'author_id' => 2], $riyaId, $followContent());
$check('A disabled module dispatches nothing', $logCount(EmailType::FOLLOW, $riyaId) === $countBeforeDisabled);

// ---------------------------------------------------------------------
// 5. QUEUE MODE (generation separated from delivery)
// ---------------------------------------------------------------------

echo $section('5. THE QUEUE (GENERATION SEPARATED FROM DELIVERY)');

$queued = $makeService(['queue' => ['enabled' => true]]);
$queued->dispatch('review_reacted', ['actor' => 'Arjun Patel', 'book' => 'Dune', 'book_id' => 1], $riyaId, [
    'type'       => 'review_reacted',
    'title'      => 'Review appreciated',
    'message'    => 'Arjun Patel found your review of Dune helpful.',
    'icon'       => 'fa-solid fa-thumbs-up',
    'color'      => 'success',
    'action_url' => '/books/1/reviews',
]);

$pendingRows = (int) db()->query("SELECT COUNT(*) AS c FROM email_queue WHERE status = 'pending'")[0]['c'];
$check('The queue holds a pending row', $pendingRows === 1);
$check('The audit row is queued, not sent', $logCount(EmailType::REVIEW, $riyaId) === 1
    && (db()->query("SELECT status FROM email_logs WHERE type = 'review' AND user_id = ?", [$riyaId])[0]['status'] ?? '') === 'queued');
$check('Nothing was delivered yet', !str_contains((string) file_get_contents($appLog), 'email.queued_sent'));

$result = $queued->processQueue();
$check('processQueue() reports one sent', $result['sent'] === 1 && $result['failed'] === 0);
$check('The queue row is marked sent', (int) db()->query("SELECT COUNT(*) AS c FROM email_queue WHERE status = 'sent'")[0]['c'] === 1);
$check('The audit row flipped to sent', (db()->query("SELECT status FROM email_logs WHERE type = 'review' AND user_id = ?", [$riyaId])[0]['status'] ?? '') === 'sent');
$check('The worker delivered the message', str_contains((string) file_get_contents($appLog), 'email.queued_sent'));

$check('A second worker run has nothing left', $queued->processQueue()['sent'] === 0);

// ---------------------------------------------------------------------
// 6. SMTP FAILURE: a dead server degrades, never throws
// ---------------------------------------------------------------------

echo $section('6. SMTP FAILURE IS GRACEFUL');

$deadConfig = [
    'transport' => 'smtp',
    'smtp'      => ['host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'auth' => false, 'username' => '', 'password' => '', 'timeout' => 2],
];
$smtpMailer = new Mailer(array_replace_recursive([
    'enabled'   => true,
    'from'      => ['address' => 'no-reply@booksphere.test', 'name' => 'BookSphere'],
    'transport' => 'log',
    'queue'     => ['enabled' => false, 'batch' => 25],
    'log_file'  => $outLog,
    'app_url'   => 'http://localhost:8000',
], $deadConfig), new Logger($appLog));

$check('A dead SMTP server answers false, not an exception', $smtpMailer->send(new EmailMessage('riya@booksphere.test', 'Riya', 'Hi', '<p>Hi</p>')) === false);
$check('The transport explains the failure', is_string($smtpMailer->lastError()) && $smtpMailer->lastError() !== '');
$check('The application log records the failure', str_contains((string) file_get_contents($appLog), 'email.send_failed'));

// The same dead server through the SERVICE: the audit row flips to
// failed and the caller never sees an exception.
$brokenService = $makeService($deadConfig);
$brokenService->dispatch('author_followed', ['author' => 'Dead Server Author', 'author_id' => 99], $riyaId, $followContent());
$failedRow = db()->query("SELECT * FROM email_logs WHERE type = 'follow' AND user_id = ? AND status = 'failed'", [$riyaId])[0] ?? null;
$check('A dead server produces a failed audit row', is_array($failedRow) && (string) ($failedRow['error'] ?? '') !== '');

// ---------------------------------------------------------------------
// 7. SMTP SUCCESS: a real SMTP handshake through SmtpTransport
// ---------------------------------------------------------------------

echo $section('7. SMTP SUCCESS AGAINST A TINY SMTP SERVER');

$workerPath = sys_get_temp_dir() . '/booksphere_smtp_worker.php';
$portFile   = sys_get_temp_dir() . '/booksphere_smtp_port.txt';

if (is_file($portFile)) {
    unlink($portFile);
}

// A tiny SMTP server: binds to an ephemeral port, writes it to the
// port file, then answers one client through the full dialogue.
$workerCode = '<?php' . PHP_EOL
    . 'declare(strict_types=1);' . PHP_EOL
    . '$server = stream_socket_server(\'tcp://127.0.0.1:0\', $errno, $errstr);' . PHP_EOL
    . 'if ($server === false) { exit(1); }' . PHP_EOL
    . '$name = stream_socket_get_name($server, false);' . PHP_EOL
    . 'file_put_contents(' . var_export($portFile, true) . ', (string) (int) substr(strrchr($name, \':\'), 1));' . PHP_EOL
    . '$client = stream_socket_accept($server, 15);' . PHP_EOL
    . 'if ($client === false) { exit(2); }' . PHP_EOL
    . 'stream_set_timeout($client, 10);' . PHP_EOL
    . 'fwrite($client, "220 booksphere-test ESMTP ready\r\n");' . PHP_EOL
    . '$inData = false;' . PHP_EOL
    . 'while (($line = fgets($client)) !== false) {' . PHP_EOL
    . '    $line = trim($line);' . PHP_EOL
    . '    if ($inData) {' . PHP_EOL
    . '        if ($line === \'.\') { fwrite($client, "250 2.0.0 message accepted\r\n"); $inData = false; }' . PHP_EOL
    . '        continue;' . PHP_EOL
    . '    }' . PHP_EOL
    . '    $upper = strtoupper($line);' . PHP_EOL
    . '    if (str_starts_with($upper, \'EHLO\')) { fwrite($client, "250-booksphere-test\r\n250 8BITMIME\r\n"); }' . PHP_EOL
    . '    elseif ($upper === \'DATA\') { fwrite($client, "354 go ahead\r\n"); $inData = true; }' . PHP_EOL
    . '    elseif (str_starts_with($upper, \'MAIL\')) { fwrite($client, "250 2.1.0 sender ok\r\n"); }' . PHP_EOL
    . '    elseif (str_starts_with($upper, \'RCPT\')) { fwrite($client, "250 2.1.5 recipient ok\r\n"); }' . PHP_EOL
    . '    elseif ($upper === \'QUIT\') { fwrite($client, "221 2.0.0 goodbye\r\n"); break; }' . PHP_EOL
    . '    else { fwrite($client, "250 ok\r\n"); }' . PHP_EOL
    . '}' . PHP_EOL
    . 'fclose($client);' . PHP_EOL
    . 'fclose($server);' . PHP_EOL;
file_put_contents($workerPath, $workerCode);

$workerProc = null;
$smtpSuccess = false;
$liveError = 'not-run';

try {
    $workerProc = proc_open(
        [PHP_BINARY, $workerPath],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        sys_get_temp_dir(),
    );

    // Wait for the worker to publish its port (bounded poll).
    $port = null;
    $deadline = microtime(true) + 6;
    while ($port === null && microtime(true) < $deadline) {
        if (is_file($portFile)) {
            $port = (int) trim((string) file_get_contents($portFile));
        } else {
            usleep(50000);
        }
    }

    if ($port !== null && $port > 0) {
        $liveConfig = array_replace_recursive([
            'enabled'   => true,
            'from'      => ['address' => 'no-reply@booksphere.test', 'name' => 'BookSphere'],
            'transport' => 'log',
            'queue'     => ['enabled' => false, 'batch' => 25],
            'log_file'  => $outLog,
            'app_url'   => 'http://localhost:8000',
        ], [
            'transport' => 'smtp',
            'smtp'      => ['host' => '127.0.0.1', 'port' => $port, 'encryption' => 'none', 'auth' => false, 'username' => '', 'password' => '', 'timeout' => 8],
        ]);

        $liveMailer = new Mailer($liveConfig, new Logger($appLog));
        $liveError = null;
        $smtpSuccess = $liveMailer->send(new EmailMessage('riya@booksphere.test', 'Riya Sharma', 'SMTP works', '<p>Delivered</p>'));
    }
} finally {
    if (is_resource($workerProc)) {
        proc_terminate($workerProc);
        proc_close($workerProc);
    }
    @unlink($workerPath);
}

$check('A full SMTP handshake delivers the message', $smtpSuccess === true);
$check('A successful handshake leaves no error', $liveError === null);
$check('The application log records the SMTP send', str_contains((string) file_get_contents($appLog), 'email.sent'));

// ---------------------------------------------------------------------
// 8. THE DISPATCHER HOOK (the real flow, additive and unbreakable)
// ---------------------------------------------------------------------

echo $section('8. THE DISPATCHER HOOK');

db()->execute('DELETE FROM notifications');
db()->execute('DELETE FROM email_logs');
db()->execute('DELETE FROM email_queue');
if (is_file($outLog)) {
    unlink($outLog);
}

// A fresh author to follow (never followed before).
db()->execute("INSERT INTO authors (name) VALUES ('Email Test Author')");
$emailAuthor = (int) db()->query("SELECT id FROM authors WHERE name = 'Email Test Author'")[0]['id'];

// The shared wiring of routes/web.php: ONE dispatcher with the email
// stack. The email config points at the LOG transport here.
$liveEmail = $makeService();
$dispatcher = new NotificationDispatcher(new Notification(), new NotificationFormatter(), new Logger($appLog), $liveEmail);

$followService = new FollowService(new AuthorFollow(), new Author(), $dispatcher);
$followId = $followService->follow($riyaId, $emailAuthor);

$check('The follow created the in-app notification', (int) db()->query("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND type = 'author_followed'", [$riyaId])[0]['c'] === 1);
$check('The same follow emailed through the dispatcher', $logCount(EmailType::FOLLOW, $riyaId) === 1 && $outWritten());
$check('The email subject matches the in-app title', (string) (db()->query("SELECT subject FROM email_logs WHERE type = 'follow' AND user_id = ?", [$riyaId])[0]['subject'] ?? '') === 'Following Email Test Author');
$check('The dispatcher itself stays email-agnostic', $followId > 0);

// A BROKEN SMTP server must not break the notification flow.
$brokenDispatcher = new NotificationDispatcher(new Notification(), new NotificationFormatter(), new Logger($appLog), $makeService([
    'transport' => 'smtp',
    'smtp'      => ['host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'auth' => false, 'username' => '', 'password' => '', 'timeout' => 2],
]));
db()->execute("INSERT INTO authors (name) VALUES ('Broken Server Author')");
$brokenAuthor = (int) db()->query("SELECT id FROM authors WHERE name = 'Broken Server Author'")[0]['id'];
$brokenFollow = (new FollowService(new AuthorFollow(), new Author(), $brokenDispatcher))->follow($riyaId, $brokenAuthor);

$check('A broken SMTP never breaks the follow', $brokenFollow > 0);
$check('The in-app notification still landed', (int) db()->query("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND type = 'author_followed'", [$riyaId])[0]['c'] === 2);
$failed = db()->query("SELECT * FROM email_logs WHERE type = 'follow' AND user_id = ? AND status = 'failed'", [$riyaId])[0] ?? null;
$check('The failed email is audited, not fatal', is_array($failed));

// A dispatcher WITHOUT the email service behaves exactly as in 9.2-9.4.
$bareDispatcher = new NotificationDispatcher(new Notification(), new NotificationFormatter());
db()->execute("INSERT INTO authors (name) VALUES ('Bare Author')");
$bareAuthor = (int) db()->query("SELECT id FROM authors WHERE name = 'Bare Author'")[0]['id'];
$bareFollow = (new FollowService(new AuthorFollow(), new Author(), $bareDispatcher))->follow($riyaId, $bareAuthor);
$check('The email-less dispatcher still notifies', $bareFollow > 0
    && (int) db()->query("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND type = 'author_followed'", [$riyaId])[0]['c'] === 3);
$check('The email-less dispatcher emails nothing', $logCount(EmailType::FOLLOW, $riyaId) === 2);

// ---------------------------------------------------------------------
// 9. THE SETTINGS PAGE (render, fetch save, no-JS flash, guest gate)
// ---------------------------------------------------------------------

echo $section('9. THE SETTINGS PAGE & ENDPOINT');

$settingsController = new SettingsController($makeService());

// Sign the session in as Riya: auth()->id() is the only identity the
// controller ever trusts, so the save must land on her row, not id 0.
$session->put('auth_user_id', $riyaId);
$session->put('auth_user', ['id' => $riyaId, 'full_name' => 'Riya Sharma', 'email' => 'riya@booksphere.test', 'role' => 'user']);

// The page render shows the five toggles (plus the intro).
$pageHtml = $capture(fn () => $settingsController->show(new Request()));
$check('The settings page renders', str_contains($pageHtml, 'Account preferences'));
$check('The settings page shows five toggles', substr_count($pageHtml, 'data-email-toggle') === 5);
$check('The settings page shows the email section', str_contains($pageHtml, 'Email notifications'));
$check('The settings page posts to the preferences endpoint', str_contains($pageHtml, '/settings/email-preferences'));
$check('The settings page carries the CSRF token', str_contains($pageHtml, 'name="_token"'));

// The fetch save answers JSON and persists. Checkbox semantics: a
// checked box sends its key, an unchecked box is ABSENT - so follow
// is turned off simply by not being posted.
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';
$_POST = ['review' => '1', 'reply' => '1', 'recommendations' => '1', 'newsletter' => '1'];
$payload = $json(fn () => $settingsController->emailPreferences(new Request()));
$check('The fetch save answers ok', ($payload['ok'] ?? false) === true);
$check('The fetch save persists the toggles', ($payload['preferences']['follow'] ?? 1) === 0 && ($payload['preferences']['newsletter'] ?? 1) === 1);
$savedRow = (new EmailPreference())->preferences($riyaId);
$check('The database row reflects the form', (int) $savedRow['follow'] === 0 && (int) $savedRow['reply'] === 1);
unset($_SERVER['HTTP_X_REQUESTED_WITH'], $_POST);

// The no-JS POST answers a redirect + flash (subprocess probe).
$probeRoot = root_path();
$probePath = sys_get_temp_dir() . '/booksphere_email_settings_probe.php';
$probeCode = '<?php' . PHP_EOL
    . 'declare(strict_types=1);' . PHP_EOL . PHP_EOL
    . 'require ' . var_export($probeRoot . '/bootstrap/constants.php', true) . ';' . PHP_EOL
    . 'require ' . var_export($probeRoot . '/vendor/autoload.php', true) . ';' . PHP_EOL . PHP_EOL
    . 'use BookSphere\\App\\Core\\Database;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Environment;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Logger;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Request;' . PHP_EOL
    . 'use BookSphere\\App\\Core\\Session;' . PHP_EOL
    . 'use BookSphere\\App\\Controllers\\SettingsController;' . PHP_EOL
    . 'use BookSphere\\App\\Mail\\Mailer;' . PHP_EOL
    . 'use BookSphere\\App\\Middleware\\AuthMiddleware;' . PHP_EOL
    . 'use BookSphere\\App\\Models\\EmailLog;' . PHP_EOL
    . 'use BookSphere\\App\\Models\\EmailPreference;' . PHP_EOL
    . 'use BookSphere\\App\\Models\\EmailQueue;' . PHP_EOL
    . 'use BookSphere\\App\\Models\\User;' . PHP_EOL
    . 'use BookSphere\\App\\Services\\AuthService;' . PHP_EOL
    . 'use BookSphere\\App\\Services\\EmailNotificationService;' . PHP_EOL . PHP_EOL
    . '(new Environment(root_path(\'.env\')))->load();' . PHP_EOL
    . 'Database::instance(' . var_export($dbPath, true) . ');' . PHP_EOL
    . '$session = new Session(\'email_settings_probe\');' . PHP_EOL
    . '$session->start();' . PHP_EOL
    . '$auth = new AuthService($session, new User());' . PHP_EOL
    . 'AuthService::setInstance($auth);' . PHP_EOL
    . 'register_shutdown_function(function (): void {' . PHP_EOL
    . '    $flash = session()->getFlash(\'success\') ?? session()->getFlash(\'error\');' . PHP_EOL
    . '    echo $flash === null ? \'NO_FLASH\' : (string) $flash;' . PHP_EOL
    . '});' . PHP_EOL
    . '$config = [\'enabled\' => false, \'queue\' => [\'enabled\' => false]];' . PHP_EOL
    . '$service = new EmailNotificationService(new User(), new EmailPreference(), new EmailLog(), new EmailQueue(), new Mailer($config, new Logger(sys_get_temp_dir() . \'/booksphere_email_probe.log\')), $config);' . PHP_EOL
    . '$controller = new SettingsController($service);' . PHP_EOL;

// Guest: the AuthMiddleware gate of both settings routes.
$probeGuest = $probeCode
    . '(new AuthMiddleware($auth))->handle(new Request(), static fn (): string => \'authorized\');' . PHP_EOL;
file_put_contents($probePath, $probeGuest);
$guestOut = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' guest 2>&1');
$check('A guest hits the login flash on the settings routes', str_contains($guestOut, 'Please log in to continue.') && !str_contains($guestOut, 'authorized'));

// Signed-in + no fetch header: the no-JS save answers a flash.
$probeNoJs = $probeCode
    . '$session->put(\'auth_user_id\', ' . $riyaId . ');' . PHP_EOL
    . '$session->put(\'auth_user\', [\'id\' => ' . $riyaId . ', \'full_name\' => \'Riya Sharma\', \'role\' => \'user\']);' . PHP_EOL
    . '$_POST = [\'follow\' => \'1\', \'review\' => \'1\', \'reply\' => \'1\', \'recommendations\' => \'1\', \'newsletter\' => \'1\'];' . PHP_EOL
    . '$controller->emailPreferences(new Request());' . PHP_EOL;
file_put_contents($probePath, $probeNoJs);
$noJsOut = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' nojs 2>&1');
$check('The no-JS save answers a flash, no JSON body', str_contains($noJsOut, 'Email preferences saved.') && !str_contains($noJsOut, '"ok"'));
unlink($probePath);

// ---------------------------------------------------------------------
// 10. ENV OVERRIDE (a subprocess proves .env drives the config)
// ---------------------------------------------------------------------

echo $section('10. ENVIRONMENT OVERRIDES DRIVE THE CONFIG');

$probePath = sys_get_temp_dir() . '/booksphere_email_env_probe.php';
$probeEnv = '<?php' . PHP_EOL
    . 'declare(strict_types=1);' . PHP_EOL . PHP_EOL
    . 'require ' . var_export($probeRoot . '/bootstrap/constants.php', true) . ';' . PHP_EOL
    . 'require ' . var_export($probeRoot . '/vendor/autoload.php', true) . ';' . PHP_EOL . PHP_EOL
    . 'use BookSphere\\App\\Core\\Environment;' . PHP_EOL . PHP_EOL
    . '// Simulate a machine that has email switched ON before the' . PHP_EOL
    . '// config directory is ever loaded (config() caches per request).' . PHP_EOL
    . '$_ENV[\'EMAIL_ENABLED\'] = \'true\';' . PHP_EOL
    . '$_ENV[\'EMAIL_TRANSPORT\'] = \'smtp\';' . PHP_EOL
    . '$_ENV[\'SMTP_HOST\'] = \'mail.example.com\';' . PHP_EOL
    . '$_ENV[\'SMTP_PORT\'] = \'465\';' . PHP_EOL
    . '$_ENV[\'EMAIL_QUEUE_ENABLED\'] = \'true\';' . PHP_EOL . PHP_EOL
    . '$email = (array) config(\'email\', []);' . PHP_EOL
    . 'echo json_encode([' . PHP_EOL
    . '    \'enabled\' => (bool) $email[\'enabled\'],' . PHP_EOL
    . '    \'transport\' => (string) $email[\'transport\'],' . PHP_EOL
    . '    \'host\' => (string) $email[\'smtp\'][\'host\'],' . PHP_EOL
    . '    \'port\' => (int) $email[\'smtp\'][\'port\'],' . PHP_EOL
    . '    \'queue\' => (bool) $email[\'queue\'][\'enabled\'],' . PHP_EOL
    . ']);' . PHP_EOL;
file_put_contents($probePath, $probeEnv);
$envOut = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' env 2>&1');
$env = json_decode($envOut, true);
$check('EMAIL_ENABLED=true flips the config', is_array($env) && ($env['enabled'] ?? false) === true);
$check('EMAIL_TRANSPORT=smtp reaches the config', is_array($env) && ($env['transport'] ?? '') === 'smtp');
$check('The SMTP host/port come from the env', is_array($env) && ($env['host'] ?? '') === 'mail.example.com' && ($env['port'] ?? 0) === 465);
$check('EMAIL_QUEUE_ENABLED=true reaches the config', is_array($env) && ($env['queue'] ?? false) === true);
unlink($probePath);

// ---------------------------------------------------------------------
// RESULT
// ---------------------------------------------------------------------

echo "\n------------------------------------------------------------------------\nRESULT\n------------------------------------------------------------------------\n";
echo sprintf("  Checks: %d\n  Failed: %d\n", $GLOBALS['checks'], $GLOBALS['failures']);
echo "\nNote: the throwaway database database/email_notification_test.db and the log files are left in place for inspection; delete them anytime.\n";

exit($GLOBALS['failures'] > 0 ? 1 : 0);