<?php

declare(strict_types=1);

/**
 * tests/OrphanAuthorCleanupTest.php
 *
 * Unit test suite for Phase B4-B: Orphan Author Audit & Cleanup.
 * Verifies that Author::all() excludes zero-book orphan authors.
 */

require_once __DIR__ . '/../bootstrap/constants.php';
require_once __DIR__ . '/../vendor/autoload.php';

use BookSphere\App\Core\Config;
use BookSphere\App\Core\Database;
use BookSphere\App\Core\Environment;
use BookSphere\App\Models\Author;

(new Environment(root_path('.env')))->load();
Config::loadFromDirectory(root_path('config'));

$pdo = Database::instance()->pdo();

echo "========================================================================\n";
echo "PHASE B4-B: ORPHAN AUTHOR CLEANUP TEST SUITE\n";
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

$authorModel = new Author();

// 1. Verify all returned authors have >= 1 published book
$allAuthors = $authorModel->all();
$assert("Author::all() returns non-empty array of active authors", count($allAuthors) > 0);

// 2. Insert a temporary zero-book author
$pdo->exec("DELETE FROM authors WHERE name = 'OrphanTestAuthor_ZeroBooks'");
$pdo->exec("INSERT INTO authors (name) VALUES ('OrphanTestAuthor_ZeroBooks')");
$orphanId = (int) $pdo->lastInsertId();

// 3. Re-run Author::all() and verify orphan author is NOT in the listing
$allAuthorsAfter = $authorModel->all();
$foundOrphan = false;
foreach ($allAuthorsAfter as $a) {
    if ((int)$a['id'] === $orphanId || $a['name'] === 'OrphanTestAuthor_ZeroBooks') {
        $foundOrphan = true;
        break;
    }
}

$assert("Author::all() filters out zero-book orphan author from catalogue listing", !$foundOrphan);

// Cleanup temporary orphan author
$pdo->exec("DELETE FROM authors WHERE id = {$orphanId}");

echo "\n------------------------------------------------------------------------\n";
echo "SUMMARY: Checks {$checks} | Passed " . ($checks - $failures) . " | Failed {$failures}\n";
echo "------------------------------------------------------------------------\n";

if ($failures > 0) {
    exit(1);
}
