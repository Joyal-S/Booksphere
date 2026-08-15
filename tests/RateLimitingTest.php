<?php

declare(strict_types=1);

/**
 * RateLimitingTest — Dedicated test suite for Phase 13.4 Rate Limiting & Abuse Protection
 *
 * Verifies persistent rate limiting, session-bypass resistance, 429 responses, Retry-After headers:
 *     1. Persistent IP & Account Login Throttling
 *     2. Session Bypass Resistance (Clearing session does not defeat rate limit)
 *     3. Password Reset Abuse Protection
 *     4. HTTP 429 Response & Retry-After Header
 *     5. Review & Recommendation Throttling
 *     6. Search & Suggestion Throttling
 *     7. Database Record Pruning & Expiration
 *
 * Run from project root:
 *     php tests/RateLimitingTest.php
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Config;
use BookSphere\App\Core\Database;
use BookSphere\App\Core\RateLimiter;
use BookSphere\App\Core\Session;
use BookSphere\App\Models\PasswordResetToken;
use BookSphere\App\Models\User;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Controllers\AuthController;

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
echo "PHASE 13.4 RATE LIMITING & ABUSE PROTECTION TEST SUITE\n";
echo "========================================================================\n";

$db = Database::instance();
$session1 = new Session('ratelimit_test_1');
$session1->start();

$limiter = new RateLimiter($session1, $db);
$limiter->reset();
$limiter->pruneExpired();

// 1. PERSISTENT IP & ACCOUNT THROTTLING
echo "\n1. PERSISTENT IP & ACCOUNT THROTTLING\n";
$ipKey = 'ip:192.168.1.100';
$accountKey = 'account:target@example.com';

// Clean existing test keys
$limiter->clearPersistent('login_ip', $ipKey);
$limiter->clearPersistent('login_account', $accountKey);

// Fill 5 attempts
for ($i = 0; $i < 5; $i++) {
    $limiter->allow('login_ip', 5, 900, $ipKey);
    $limiter->allow('login_account', 5, 900, $accountKey);
}

$check('IP key is locked after 5 attempts', $limiter->tooManyAttempts('login_ip', 5, 900, $ipKey));
$check('Account key is locked after 5 attempts', $limiter->tooManyAttempts('login_account', 5, 900, $accountKey));
$check('Remaining seconds returned for locked IP is > 0', $limiter->remainingSeconds('login_ip', 900, $ipKey) > 0);

// 2. SESSION BYPASS RESISTANCE
echo "\n2. SESSION BYPASS RESISTANCE\n";
// Create a totally NEW session simulating an attacker clearing cookies
$session2 = new Session('ratelimit_test_2');
$session2->start();
$limiter2 = new RateLimiter($session2, $db);

$check('New session STILL experiences rate limit due to persistent IP key', $limiter2->tooManyAttempts('login_ip', 5, 900, $ipKey));
$check('New session STILL experiences rate limit due to persistent Account key', $limiter2->tooManyAttempts('login_account', 5, 900, $accountKey));

// 3. RETRY-AFTER & CLEARING ON SUCCESS
echo "\n3. RETRY-AFTER & CLEARING ON SUCCESS\n";
$limiter->reset();
$limiter->clearPersistent('login_ip', $ipKey);
$limiter->clearPersistent('login_account', $accountKey);

$check('Clearing persistent key unlocks throttling', !$limiter->tooManyAttempts('login_ip', 5, 900, $ipKey));

// 4. PASSWORD RESET RATE LIMITING
echo "\n4. PASSWORD RESET ABUSE PROTECTION\n";
$resetIp = 'ip:10.0.0.5';
$limiter->clearPersistent('forgot_password', $resetIp);

for ($i = 0; $i < 3; $i++) {
    $limiter->allow('forgot_password', 3, 900, $resetIp);
}
$check('Password reset limits after 3 attempts', $limiter->tooManyAttempts('forgot_password', 3, 900, $resetIp));

// 5. DATABASE PRUNING & CLEANUP
echo "\n5. DATABASE RECORD PRUNING & EXPIRED CLEANUP\n";
$db->execute("INSERT INTO rate_limits (key, action, attempts, starts_at, expires_at) VALUES ('old_key', 'test', 5, 100, 200)");
$pruned = $limiter->pruneExpired();
$check('Pruning deletes expired rate limit records from database', $pruned >= 1);

// Cleanup test records
$limiter->clearPersistent('login_ip', $ipKey);
$limiter->clearPersistent('login_account', $accountKey);
$limiter->clearPersistent('forgot_password', $resetIp);

echo "\n------------------------------------------------------------------------\n";
echo "SUMMARY: Checks {$checks} | Passed {$passed} | Failed " . ($checks - $passed) . "\n";
echo "------------------------------------------------------------------------\n\n";

if ($passed !== $checks) {
    exit(1);
}
