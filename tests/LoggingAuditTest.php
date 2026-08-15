<?php

declare(strict_types=1);

/**
 * LoggingAuditTest — Dedicated test suite for Phase 13.5 Logging & Observability
 *
 * Verifies structured JSON logging, request correlation IDs, sensitive data redaction,
 * log injection protection, log rotation, and failure resilience.
 *
 * Run from project root:
 *     php tests/LoggingAuditTest.php
 */

require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Logger;
use BookSphere\App\Core\ErrorHandler;

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
echo "PHASE 13.5 LOGGING & OBSERVABILITY TEST SUITE\n";
echo "========================================================================\n";

$tempDir  = rtrim(sys_get_temp_dir(), '/\\') . '/booksphere_log_test_' . uniqid();
$logFile  = $tempDir . '/test_app.log';

$logger = new Logger($logFile, 1024, 3); // 1 KB max size for fast rotation testing

// 1. STRUCTURED JSON FORMATTING & REQUEST CORRELATION ID
echo "\n1. STRUCTURED JSON FORMATTING & REQUEST CORRELATION ID\n";
Logger::setRequestId('req_test_abc123');
$logger->info('Application booted', ['env' => 'testing']);

$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$check('Log file was created', is_array($lines) && count($lines) === 1);

$decoded = json_decode($lines[0] ?? '', true);
$check('Log entry is valid JSON', is_array($decoded));
$check('Log entry contains ISO 8601 timestamp', isset($decoded['time']));
$check('Log entry contains correct request_id', ($decoded['request_id'] ?? '') === 'req_test_abc123');
$check('Log entry contains level "info"', ($decoded['level'] ?? '') === 'info');
$check('Log entry contains message "Application booted"', ($decoded['message'] ?? '') === 'Application booted');

// 2. SENSITIVE DATA REDACTION
echo "\n2. SENSITIVE DATA REDACTION\n";
$logger->warning('Login attempt', [
    'user'            => 'riya@booksphere.test',
    'password'        => 'super_secret_pass',
    'token'           => 'token_xyz789',
    'csrf_token'      => 'csrf_abc',
    'cookie'          => 'session_id=123',
    'remember_token'  => 'remember_456',
    'nested'          => [
        'authorization' => 'Bearer secret_auth_hdr',
        'safe_field'    => 'ok_data',
    ],
]);

$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$lastLine = json_decode(end($lines), true);
$context  = $lastLine['context'] ?? [];

$check('Top-level password field is [REDACTED]', ($context['password'] ?? '') === '[REDACTED]');
$check('Top-level token field is [REDACTED]', ($context['token'] ?? '') === '[REDACTED]');
$check('Top-level csrf_token field is [REDACTED]', ($context['csrf_token'] ?? '') === '[REDACTED]');
$check('Top-level cookie field is [REDACTED]', ($context['cookie'] ?? '') === '[REDACTED]');
$check('Nested authorization field is [REDACTED]', ($context['nested']['authorization'] ?? '') === '[REDACTED]');
$check('Non-sensitive field is preserved', ($context['nested']['safe_field'] ?? '') === 'ok_data');

// 3. LOG INJECTION PROTECTION
echo "\n3. LOG INJECTION PROTECTION\n";
$logger->error("User query failed\r\n[2026-01-01T00:00:00Z] [CRITICAL] Fake forged log line", [
    'input' => "Line 1\nLine 2\rLine 3\tTabbed",
]);

$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$check('Log injection message sanitized into a single line', count($lines) === 3);

$lastEntry = json_decode(end($lines), true);
$check('CRLF removed from message string', !str_contains($lastEntry['message'] ?? '', "\r") && !str_contains($lastEntry['message'] ?? '', "\n"));

// 4. LOG ROTATION
echo "\n4. LOG ROTATION\n";
// Write extra entries to trigger 1 KB rotation threshold
for ($i = 0; $i < 20; $i++) {
    $logger->info('Rotation filler payload entry number ' . $i, ['data' => str_repeat('A', 100)]);
}
$check('Rotated log backup file .1 exists', is_file($logFile . '.1'));

// 5. FAIL-SAFE RESILIENCE
echo "\n5. FAIL-SAFE RESILIENCE\n";
$badLogger = new Logger('/invalid_path_denied/sub/test.log');
$didThrow = false;
try {
    $badLogger->error('Write should fail silently');
} catch (\Throwable) {
    $didThrow = true;
}
$check('Logger write failure does not crash application', !$didThrow);

// Clean up test files
array_map('unlink', glob("{$tempDir}/*") ?: []);
@rmdir($tempDir);

echo "\n------------------------------------------------------------------------\n";
echo "SUMMARY: Checks {$checks} | Passed {$passed} | Failed " . ($checks - $passed) . "\n";
echo "------------------------------------------------------------------------\n\n";

if ($passed !== $checks) {
    exit(1);
}
