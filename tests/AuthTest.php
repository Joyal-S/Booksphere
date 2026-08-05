<?php

declare(strict_types=1);

/**
 * AuthTest — CLI test suite for the Authentication module
 *
 * Verifies the four authentication screens (login, register, forgot
 * password, reset password) inside the standalone layouts.auth shell
 * plus the security-critical behaviour behind them:
 *
 *     1. Structure  - every screen renders the split brand panel,
 *                     the auth card, exactly one <h1>, real POST
 *                     forms with the CSRF token, no inline styles
 *     2. Screens    - the login/register tabs, the remember checkbox,
 *                     the strength meter, the forgot success state
 *                     (with the demo-mode reset link) and the
 *                     invalid-token reset state
 *     3. Reset      - the single-use, expiring, hashed reset token
 *                     lifecycle (create -> valid -> consume -> dead)
 *     4. Remember   - the remember-me cookie round trip: issue,
 *                     restore, single-use rotation, logout revoke
 *     5. Controller - the non-redirecting controller paths: valid
 *                     forgot request yields a link, invalid input
 *                     re-renders with errors, reset with a dead
 *                     token shows the invalid state
 *
 * Run from the project root:
 *
 *     php tests/AuthTest.php
 *
 * The throwaway database (database/auth_test.db) is migrated,
 * seeded and left in place for inspection; delete it anytime.
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Controllers\AuthController;
use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Core\Logger;
use BookSphere\App\Core\Migrator;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Core\Seeder;
use BookSphere\App\Core\Session;
use BookSphere\App\Models\PasswordResetToken;
use BookSphere\App\Models\User;
use BookSphere\App\Services\AuthService;

// ---------------------------------------------------------------------
// 0. Boot: fresh throwaway database, migrated and seeded.
// ---------------------------------------------------------------------

(new Environment(root_path('.env')))->load();

$dbPath = root_path('database/auth_test.db');

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
$session = new Session('auth_test');
$session->start();
$auth = new AuthService($session, new User());
AuthService::setInstance($auth);

$logFile = sys_get_temp_dir() . '/booksphere_auth_test.log';
if (is_file($logFile)) {
    unlink($logFile);
}

$checks = 0;
$failed = 0;
$output = [];

/**
 * Run one check and print PASS/FAIL (output is buffered and flushed
 * at the end, so the cookie/session calls below run before any
 * output - setcookie() and session_regenerate_id() refuse to work
 * once headers have been sent).
 */
$check = function (bool $ok, string $label) use (&$checks, &$failed, &$output): void {
    $checks++;
    if (!$ok) {
        $failed++;
    }
    $output[] = sprintf("  %s  %s\n", $ok ? 'PASS' : 'FAIL', $label);
};

/**
 * Queue a section header line.
 */
$section = function (string $label) use (&$output): void {
    $output[] = "\n" . $label . "\n";
};

/**
 * Flush whatever has been collected so far (also runs if the script
 * dies early, so a crash never hides the checks that already ran).
 */
$flush = function () use (&$output): void {
    echo implode('', $output);
    $output = [];
};

register_shutdown_function($flush);

/**
 * Render an auth view inside the auth layout and return the HTML.
 */
$render = function (string $view, array $data = []): string {
    ob_start();
    Response::view($view, $data, 200, 'layouts.auth');

    return (string) ob_get_clean();
};

/**
 * Run a callable (e.g. a controller action) and capture its output.
 */
$capture = function (callable $fn): string {
    ob_start();
    $fn();

    return (string) ob_get_clean();
};

$users       = new User();
$resetTokens = new PasswordResetToken();
$riya        = $users->findByEmail('riya@booksphere.test');
$riyaId      = (int) $riya['id'];

// ---------------------------------------------------------------------
// 1. STRUCTURE: the shared auth layout
// ---------------------------------------------------------------------

$section('1. STRUCTURE: the shared auth layout');

$loginHtml = $render('auth.login', [
    'title'  => 'Log in',
    'active' => 'login',
    'tabs'   => true,
    'old'    => ['email' => ''],
    'errors' => [],
]);

$check(str_starts_with($loginHtml, '<!doctype html>'), 'The auth layout emits a full HTML document');
$check(str_contains($loginHtml, '<html lang="en"'), 'The document declares lang="en"');
$check(str_contains($loginHtml, 'class="auth-page"'), 'The auth page body shell renders');
$check(str_contains($loginHtml, 'class="auth-skip"'), 'A skip-to-content link is present');
$check(str_contains($loginHtml, 'css/auth.css'), 'The auth stylesheet is linked');
$check(str_contains($loginHtml, 'js/auth.js'), 'The auth script is loaded');
$check(str_contains($loginHtml, 'class="auth-brand"'), 'The brand panel renders');
$check(str_contains($loginHtml, 'auth-brand-stats'), 'The brand stats row renders');
$check(str_contains($loginHtml, 'id="auth-theme-toggle"'), 'The theme toggle button renders');
$check(str_contains($loginHtml, 'class="auth-card"'), 'The auth card renders');
$check(str_contains($loginHtml, 'class="auth-footer"'), 'The page footer renders');
$check(preg_match('/\sstyle="/', $loginHtml) === 0, 'No inline style attributes anywhere');

// ---------------------------------------------------------------------
// 2. SCREENS
// ---------------------------------------------------------------------

$section('2. SCREENS: login / register / forgot / reset');

$check(substr_count($loginHtml, '<h1') === 1, 'The login screen has exactly one <h1>');
$check(str_contains($loginHtml, 'Welcome back'), 'The login title renders');
$check(str_contains($loginHtml, 'action="/login"'), 'The login form posts to /login');
$check(str_contains($loginHtml, 'name="_token"'), 'The login form carries the CSRF token');
$check(str_contains($loginHtml, 'name="remember"'), 'The remember-me checkbox renders');
$check(str_contains($loginHtml, 'href="/forgot-password"'), 'The forgot-password link renders');
$check(str_contains($loginHtml, 'data-auth-eye="field-password"'), 'The password visibility toggle renders');
$check(str_contains($loginHtml, 'href="/register"'), 'The register tab / switch link renders');
$check(str_contains($loginHtml, 'href="/login"'), 'The login tab renders');
$check(str_contains($loginHtml, 'auth-tab--active'), 'The active tab is marked');

$registerHtml = $render('auth.register', [
    'title'  => 'Create an account',
    'active' => 'register',
    'tabs'   => true,
    'old'    => [],
    'errors' => [],
]);

$check(substr_count($registerHtml, '<h1') === 1, 'The register screen has exactly one <h1>');
$check(str_contains($registerHtml, 'Create Account'), 'The register title renders');
$check(str_contains($registerHtml, 'action="/register"'), 'The register form posts to /register');
$check(str_contains($registerHtml, 'name="full_name"'), 'The full name field renders');
$check(str_contains($registerHtml, 'name="password_confirmation"'), 'The confirm-password field renders');
$check(str_contains($registerHtml, 'data-auth-strength'), 'The password strength meter renders');
$check(substr_count($registerHtml, 'data-auth-eye') === 2, 'Both register password fields have eye toggles');
$check(str_contains($registerHtml, 'name="terms"'), 'The terms checkbox renders');

$forgotHtml = $render('auth.forgot-password', [
    'title'      => 'Reset your password',
    'active'     => 'forgot',
    'tabs'       => false,
    'old'        => ['email' => ''],
    'errors'     => [],
    'sent'       => false,
    'sent_to'    => '',
    'reset_link' => null,
]);

$check(substr_count($forgotHtml, '<h1') === 1, 'The forgot screen has exactly one <h1>');
$check(str_contains($forgotHtml, 'Back to Login'), 'The forgot screen has a back link');
$check(str_contains($forgotHtml, 'action="/forgot-password"'), 'The forgot form posts to /forgot-password');
$check(!str_contains($forgotHtml, 'auth-tabs'), 'The forgot screen hides the login/register tabs');
$check(!str_contains($forgotHtml, 'auth-success'), 'The fresh forgot screen shows the form, not a success state');

$forgotSentHtml = $render('auth.forgot-password', [
    'title'      => 'Reset your password',
    'active'     => 'forgot',
    'tabs'       => false,
    'old'        => ['email' => 'riya@booksphere.test'],
    'errors'     => [],
    'sent'       => true,
    'sent_to'    => 'riya@booksphere.test',
    'reset_link' => '/reset-password?token=abc123',
]);

$check(str_contains($forgotSentHtml, 'auth-success'), 'The sent forgot screen shows the success card');
$check(str_contains($forgotSentHtml, 'riya@booksphere.test'), 'The success card echoes the submitted address');
$check(str_contains($forgotSentHtml, '/reset-password?token=abc123'), 'The demo-mode reset link is surfaced');

$forgotNeutralHtml = $render('auth.forgot-password', [
    'title'      => 'Reset your password',
    'active'     => 'forgot',
    'tabs'       => false,
    'old'        => ['email' => 'ghost@test.dev'],
    'errors'     => [],
    'sent'       => true,
    'sent_to'    => 'ghost@test.dev',
    'reset_link' => null,
]);

$check(str_contains($forgotNeutralHtml, 'auth-success'), 'An unknown address still gets the neutral success card');
$check(!str_contains($forgotNeutralHtml, 'reset_link'), 'No reset link is leaked for unknown addresses');

$resetHtml = $render('auth.reset-password', [
    'title'   => 'Choose a new password',
    'active'  => 'reset',
    'tabs'    => false,
    'token'   => 'abc123',
    'old'     => [],
    'errors'  => [],
    'invalid' => false,
]);

$check(substr_count($resetHtml, '<h1') === 1, 'The reset screen has exactly one <h1>');
$check(str_contains($resetHtml, 'action="/reset-password"'), 'The reset form posts to /reset-password');
$check(str_contains($resetHtml, 'name="token"'), 'The reset form carries the hidden token');
$check(str_contains($resetHtml, 'data-auth-strength'), 'The reset screen has a strength meter');

$resetInvalidHtml = $render('auth.reset-password', [
    'title'   => 'Choose a new password',
    'active'  => 'reset',
    'tabs'    => false,
    'token'   => 'abc123',
    'old'     => [],
    'errors'  => [],
    'invalid' => true,
]);

$check(str_contains($resetInvalidHtml, 'Reset link invalid'), 'A dead token renders the invalid state');

// ---------------------------------------------------------------------
// 3. RESET TOKENS: single-use, expiring, hashed
// ---------------------------------------------------------------------

$section('3. RESET TOKENS: the token lifecycle');

$rawToken  = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);

$tokenId = $resetTokens->create($riyaId, $tokenHash);
$check($tokenId > 0, 'A reset token is issued');

$valid = $resetTokens->findValid($tokenHash);
$check($valid !== null && (int) $valid['user_id'] === $riyaId, 'The fresh token is redeemable');

$check($resetTokens->findValid(hash('sha256', 'wrong-token')) === null, 'A wrong token is refused');

$check($resetTokens->consume((int) $valid['id']), 'The token is consumed (single-use)');
$check($resetTokens->findValid($tokenHash) === null, 'A consumed token is dead forever');

// Expiry: insert a token with a past expires_at, then try to redeem it.
$oldTokenId = $resetTokens->create($riyaId, hash('sha256', 'expired-token'));
db()->execute(
    'UPDATE password_resets SET expires_at = ? WHERE id = ?',
    [gmdate('Y-m-d\TH:i:s\Z', time() - 10), $oldTokenId],
);
$check($resetTokens->findValid(hash('sha256', 'expired-token')) === null, 'An expired token is refused');

// One outstanding token per user: issuing a new one kills the old.
$resetTokens->create($riyaId, hash('sha256', 'first-token'));
$resetTokens->create($riyaId, hash('sha256', 'second-token'));
$check($resetTokens->findValid(hash('sha256', 'first-token')) === null, 'Issuing a new token revokes the previous one');
$check($resetTokens->findValid(hash('sha256', 'second-token')) !== null, 'The newest token stays valid');
$resetTokens->deleteForUser($riyaId);
$check($resetTokens->findValid(hash('sha256', 'second-token')) === null, 'deleteForUser() clears every outstanding token');

// ---------------------------------------------------------------------
// 4. REMEMBER ME: issue, restore, rotate, revoke
// ---------------------------------------------------------------------

$section('4. REMEMBER ME: the persistent-login round trip');

$raw = $auth->rememberUser($riya);
$storedHash = $users->findById($riyaId)['remember_token'];
$check($storedHash !== null && $storedHash === hash('sha256', $raw), 'The remember token is stored as a hash');

$_COOKIE[AuthService::REMEMBER_COOKIE] = $riyaId . ':' . $raw;
$restored = $auth->restoreFromRememberCookie();
$check($restored === true, 'A valid remember cookie restores the session');
$check($session->get('auth_user')['id'] === $riyaId, 'The restored session belongs to the right user');
$check($auth->check() === true, 'check() accepts the restored session');

$rotatedHash = $users->findById($riyaId)['remember_token'];
$check($rotatedHash !== $storedHash, 'The restored token is rotated (single-use)');
$check($auth->restoreFromRememberCookie() === false, 'The OLD cookie is dead after rotation');

$_COOKIE[AuthService::REMEMBER_COOKIE] = $riyaId . ':forged-token';
$check($auth->restoreFromRememberCookie() === false, 'A forged cookie is refused');

unset($_COOKIE[AuthService::REMEMBER_COOKIE]);

$auth->logout();
$check($session->get('auth_user') === null, 'logout() ends the session');
$check($users->findById($riyaId)['remember_token'] === null, 'logout() revokes the stored remember token');
$check($auth->check() === false, 'check() is false for guests without a cookie');

// attempt() with the remember flag issues the token too.
$auth->attempt('riya@booksphere.test', 'User@123', true);
$check($session->get('auth_user')['id'] === $riyaId, 'attempt() logs the user in');
$check($users->findById($riyaId)['remember_token'] !== null, 'attempt(remember: true) issues a remember token');
$auth->logout();

// ---------------------------------------------------------------------
// 5. CONTROLLER: the non-redirecting paths
// ---------------------------------------------------------------------

$section('5. CONTROLLER: forgot / reset behaviour');

$controller = new AuthController($auth, $users, $resetTokens);

$_POST = ['email' => 'riya@booksphere.test'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$html = $capture(fn () => $controller->forgotPassword(new Request()));
$check(str_contains($html, 'auth-success'), 'forgotPassword() shows the success card for a known account');
$check(str_contains($html, '/reset-password?token='), 'forgotPassword() issues a demo reset link');

$_POST = ['email' => 'not-an-email'];
$html = $capture(fn () => $controller->forgotPassword(new Request()));
$check(str_contains($html, 'Enter a valid email address.'), 'forgotPassword() rejects an invalid email');
$check(!str_contains($html, 'auth-success'), 'The form (not the success card) is re-rendered on error');

$_POST = ['token' => 'totally-dead-token', 'password' => 'BrandNew@123', 'password_confirmation' => 'BrandNew@123'];
$html = $capture(fn () => $controller->resetPassword(new Request()));
$check(str_contains($html, 'Reset link invalid'), 'resetPassword() refuses a dead token');

$html = $capture(fn () => $controller->showResetPassword(new Request()));
$check(str_contains($html, 'Reset link invalid'), 'showResetPassword() rejects a missing token');

$_POST = [];
$html = $capture(fn () => $controller->showRegister(new Request()));
$check(str_contains($html, 'Create Account'), 'showRegister() renders the register screen');

// ---------------------------------------------------------------------
// RESULT
// ---------------------------------------------------------------------

$output[] = "\n------------------------------------------------------------------------\n";
$output[] = "RESULT\n";
$output[] = "------------------------------------------------------------------------\n";
$output[] = sprintf("  Checks: %d\n", $checks);
$output[] = sprintf("  Failed: %d\n", $failed);

$flush();

if ($failed > 0) {
    exit(1);
}

exit(0);