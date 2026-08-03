<?php

declare(strict_types=1);

/**
 * database/migrate.php
 *
 * Command-line runner for migrations and seeds.
 * Execute it from the project root with PHP:
 *
 *     php database/migrate.php             -> run pending migrations
 *     php database/migrate.php --rollback  -> undo the last batch
 *     php database/migrate.php --seed      -> run the seed files
 *
 * Migration file format (database/migrations/0001_name.php):
 *
 *     return [
 *         'up'   => 'CREATE TABLE books (...)',
 *         'down' => 'DROP TABLE books',
 *     ];
 *
 * Seed file format (database/seeds/001_name.php):
 *
 *     return function (Database $database): void {
 *         $database->execute('INSERT INTO books (title) VALUES (?)', ['Sample']);
 *     };
 */

use BookSphere\App\Core\Environment;
use BookSphere\App\Core\Migrator;
use BookSphere\App\Core\Seeder;

// 1. Constants and helpers (root_path, env, db, ...).
require __DIR__ . '/../bootstrap/constants.php';
require __DIR__ . '/../vendor/autoload.php';

// 2. Environment variables (DB_PATH may override the database location).
(new Environment(root_path('.env')))->load();

// 3. The shared connection plus the runners.
$migrator = new Migrator(db(), root_path('database/migrations'));
$seeder   = new Seeder(db(), root_path('database/seeds'));

// 4. Interpret the command-line argument.
$command = $argv[1] ?? 'migrate';

switch ($command) {
    case 'migrate':
        $applied = $migrator->run();
        echo $applied === []
            ? "No pending migrations.\n"
            : "Applied: " . implode(', ', $applied) . "\n";
        break;

    case '--rollback':
        $rolledBack = $migrator->rollback();
        echo $rolledBack === []
            ? "Nothing to roll back.\n"
            : "Rolled back: " . implode(', ', $rolledBack) . "\n";
        break;

    case '--seed':
        $seeded = $seeder->run();
        echo $seeded === []
            ? "No seed files found.\n"
            : "Seeded: " . implode(', ', $seeded) . "\n";
        break;

    default:
        exit("Usage: php database/migrate.php [migrate | --rollback | --seed]\n");
}
