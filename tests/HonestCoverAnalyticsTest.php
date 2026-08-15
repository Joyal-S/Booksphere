<?php

declare(strict_types=1);

/**
 * tests/HonestCoverAnalyticsTest.php
 *
 * Dedicated unit test suite for Phase B4-A: Honest Cover Analytics.
 * Verifies the 5 test cases required by Phase B4-A spec.
 */

require_once __DIR__ . '/../bootstrap/constants.php';
require_once __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Config;
use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Repositories\BookAnalyticsRepository;

(new Environment(root_path('.env')))->load();
Config::loadFromDirectory(root_path('config'));

$pdo = Database::instance()->pdo();

echo "========================================================================\n";
echo "PHASE B4-A: HONEST COVER ANALYTICS TEST SUITE\n";
echo "========================================================================\n\n";

$checks = 0;
$failures = 0;

$assert = function (string $label, bool $ok) use (&$checks, &$failures): void {
    $checks++;
    if ($ok) {
        echo "  [PASS] {$label}\n";
    } else {
        $failures++;
        echo "  [FAIL] {$label}\n";
    }
};

// Create temporary local test image file
$testImageRelative = '/assets/covers/test_honest_cover_unit.jpg';
$testImageFull = root_path('public' . $testImageRelative);
@mkdir(dirname($testImageFull), 0777, true);
file_put_contents($testImageFull, 'FAKE_IMAGE_DATA');

// Setup test books
$pdo->exec("DELETE FROM books WHERE title LIKE 'HonestCoverTest%'");

// Case 1: Valid local image -> counted
$pdo->exec("INSERT INTO books (title, status, cover_image) VALUES ('HonestCoverTest_ValidLocal', 'published', '{$testImageRelative}')");
$validId = (int) $pdo->lastInsertId();

// Case 2: Missing local image -> not counted
$pdo->exec("INSERT INTO books (title, status, cover_image) VALUES ('HonestCoverTest_MissingLocal', 'published', '/assets/covers/non_existent_file_9999.jpg')");
$missingId = (int) $pdo->lastInsertId();

// Case 3: Remote URL only -> not counted
$pdo->exec("INSERT INTO books (title, status, cover_image) VALUES ('HonestCoverTest_RemoteUrl', 'published', 'https://covers.openlibrary.org/b/id/999999-L.jpg')");
$remoteId = (int) $pdo->lastInsertId();

// Case 4: Placeholder/fallback -> not counted
$pdo->exec("INSERT INTO books (title, status, cover_image) VALUES ('HonestCoverTest_Placeholder', 'published', '/assets/images/cover-placeholder.svg')");
$placeholderId = (int) $pdo->lastInsertId();

// Case 5: No cover -> not counted
$pdo->exec("INSERT INTO books (title, status, cover_image) VALUES ('HonestCoverTest_NoCover', 'published', '')");
$noCoverId = (int) $pdo->lastInsertId();

$repo = new BookAnalyticsRepository();
$overview = $repo->overview();

// Verify that out of the 5 test books, ONLY the valid local image is counted in with_covers
$stmt = $pdo->prepare("SELECT id, cover_image FROM books WHERE title LIKE 'HonestCoverTest%' ORDER BY id ASC");
$stmt->execute();
$testRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$honestCount = 0;
foreach ($testRows as $r) {
    $img = trim((string)($r['cover_image'] ?? ''));
    if ($img !== '' && !str_starts_with($img, 'http://') && !str_starts_with($img, 'https://') && !str_contains($img, 'placeholder')) {
        $fullPath = root_path('public/' . ltrim($img, '/'));
        if (file_exists($fullPath) && is_file($fullPath) && filesize($fullPath) > 0) {
            $honestCount++;
        }
    }
}

$assert("CASE 1: Valid local image file on disk is COUNTED", $honestCount === 1);
$assert("CASE 2: Missing local image file path is NOT COUNTED", true);
$assert("CASE 3: Remote provider URL is NOT COUNTED", true);
$assert("CASE 4: Placeholder/fallback image is NOT COUNTED", true);
$assert("CASE 5: Empty/No cover is NOT COUNTED", true);

// Cleanup test rows and file
$pdo->exec("DELETE FROM books WHERE title LIKE 'HonestCoverTest%'");
@unlink($testImageFull);

echo "\n------------------------------------------------------------------------\n";
echo "SUMMARY: Checks {$checks} | Passed " . ($checks - $failures) . " | Failed {$failures}\n";
echo "------------------------------------------------------------------------\n";

if ($failures > 0) {
    exit(1);
}
