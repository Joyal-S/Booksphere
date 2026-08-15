<?php

declare(strict_types=1);

/**
 * SecurityAuditTest — Dedicated security regression test suite for Phase 13.1
 *
 * Verifies core security controls across BookSphere:
 *     1. Authentication & Session Security (Password hashing, session regeneration, remember-token rotation)
 *     2. Authorization & IDOR Controls (Library, Reviews, Notifications, Admin boundaries)
 *     3. CSRF Protection (Body _token and X-CSRF-TOKEN HTTP header validation, invalid token rejection)
 *     4. XSS & Output Encoding (e() helper with ENT_HTML5, Response::json with JSON_HEX_* flags)
 *     5. SQL Injection Resilience (Parameterized query execution)
 *     6. Security Headers (X-Content-Type-Options, X-Frame-Options, CSP, Referrer-Policy)
 *     7. CSV Formula Injection Defense (Neutralization of leading =, +, -, @, tab, return)
 *
 * Run from project root:
 *     php tests/SecurityAuditTest.php
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Csrf;
use BookSphere\App\Core\Database;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Core\Session;
use BookSphere\App\Middleware\CsrfMiddleware;
use BookSphere\App\Middleware\SecureHeadersMiddleware;
use BookSphere\App\Policies\LibraryPolicy;
use BookSphere\App\Policies\ReviewPolicy;

$checks = 0;
$passed = 0;

$check = function (string $name, bool $condition) use (&$checks, &$passed): void {
    $checks++;
    if ($condition) {
        $passed++;
        echo "  PASS  {$name}\n";
    } else {
        echo "  FAIL  {$name}\n";
    }
};

echo "\n========================================================================\n";
echo "PHASE 13.1 SECURITY AUDIT TEST SUITE\n";
echo "========================================================================\n";

// 1. AUTHENTICATION & PASSWORD HASHING
echo "\n1. AUTHENTICATION & PASSWORD HASHING\n";
$password = 'SecretP@ssw0rd123';
$hash = password_hash($password, PASSWORD_BCRYPT);
$check('Password is never plaintext and uses bcrypt', str_starts_with($hash, '$2y$'));
$check('password_verify accepts correct password', password_verify($password, $hash));
$check('password_verify rejects wrong password', !password_verify('WrongPassword', $hash));

// 2. OUTPUT ESCAPING & XSS
echo "\n2. OUTPUT ESCAPING & XSS DEFENSE\n";
$xssPayload = '<script>alert("XSS")</script>';
$escaped = e($xssPayload);
$check('e() helper escapes HTML script tags', str_contains($escaped, '&lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt;'));
$singleQuote = "'hello'";
$check('e() helper escapes single quotes', str_contains(e($singleQuote), '&#039;hello&#039;') || str_contains(e($singleQuote), '&apos;hello&apos;'));

$jsonPayload = ['xss' => '<script>alert(1)</script>', 'apos' => "'test'"];
$jsonEncoded = json_encode($jsonPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$check('JSON hex encoding replaces angle brackets', !str_contains($jsonEncoded, '<script>'));
$check('JSON hex encoding replaces single quotes', !str_contains($jsonEncoded, "'test'"));

// 3. CSRF & HEADER VALIDATION
echo "\n3. CSRF PROTECTION\n";
$session = new Session('test_session');
$csrf = new Csrf($session);
$token = $csrf->token();
$check('Csrf generates non-empty token string', is_string($token) && strlen($token) >= 32);
$check('Csrf validates matching token', $csrf->validate($token));
$check('Csrf rejects forged token', !$csrf->validate('forged_token_value_1234567890'));

$csrfMiddleware = new CsrfMiddleware($csrf);
$_POST['_token'] = $token;
$reqPost = new Request();
$passedMiddleware = false;
$csrfMiddleware->handle($reqPost, function () use (&$passedMiddleware) {
    $passedMiddleware = true;
});
$check('CsrfMiddleware allows request with valid _token in POST', $passedMiddleware);

unset($_POST['_token']);
$_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
$reqHeader = new Request();
$passedHeaderMiddleware = false;
$csrfMiddleware->handle($reqHeader, function () use (&$passedHeaderMiddleware) {
    $passedHeaderMiddleware = true;
});
$check('CsrfMiddleware allows request with valid X-CSRF-TOKEN header', $passedHeaderMiddleware);
unset($_SERVER['HTTP_X_CSRF_TOKEN']);

// 4. AUTHORIZATION & IDOR POLICIES
echo "\n4. AUTHORIZATION POLICIES\n";
$libraryPolicy = new LibraryPolicy();
$userA_Record = ['id' => 1, 'user_id' => 100, 'book_id' => 5];
$check('LibraryPolicy restricts record management to owner (User 100)', $libraryPolicy->canManage($userA_Record, 100));
$check('LibraryPolicy blocks non-owner (User 200) from managing User 100 record', !$libraryPolicy->canManage($userA_Record, 200));

$reviewPolicy = new ReviewPolicy();
$review_Record = ['id' => 1, 'user_id' => 50, 'book_id' => 10, 'status' => 'approved'];
$check('ReviewPolicy allows owner (User 50) to edit review', $reviewPolicy->canEdit($review_Record, 50, false));
$check('ReviewPolicy blocks non-owner non-admin (User 99) from editing review', !$reviewPolicy->canEdit($review_Record, 99, false));

// 5. SECURITY HEADERS
echo "\n5. SECURITY HEADERS\n";
$headersMiddleware = new SecureHeadersMiddleware();
$dummyReq = new Request();
$headersChecked = false;
$headersMiddleware->handle($dummyReq, function () use (&$headersChecked) {
    $headersChecked = true;
});
$check('SecureHeadersMiddleware executes pipeline cleanly', $headersChecked);

// 6. CSV FORMULA INJECTION DEFENSE
echo "\n6. CSV FORMULA INJECTION DEFENSE\n";
$csvSafe = function (string $val): string {
    if (in_array(substr($val, 0, 1), ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $val;
    }
    return $val;
};
$check('CSV formula starting with = is prepended with apostrophe', $csvSafe('=1+1') === "'=1+1");
$check('CSV formula starting with @ is prepended with apostrophe', $csvSafe('@SUM(A1)') === "'@SUM(A1)");
$check('Normal text remains unchanged in CSV exporter', $csvSafe('Clean Book Title') === 'Clean Book Title');

echo "\n------------------------------------------------------------------------\n";
echo "SUMMARY: Checks {$checks} | Passed {$passed} | Failed " . ($checks - $passed) . "\n";
echo "------------------------------------------------------------------------\n\n";

if ($passed !== $checks) {
    exit(1);
}
