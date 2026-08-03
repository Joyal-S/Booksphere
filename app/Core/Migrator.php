<?php

declare(strict_types=1);

namespace BookSphere\App\Core;

use RuntimeException;
use Throwable;

/**
 * Migrator
 *
 * Runs database migrations: versioned schema changes that live in
 * database/migrations/ as PHP files.
 *
 * Each migration file returns an array with the SQL for both
 * directions:
 *
 *     return [
 *         'up'   => 'CREATE TABLE users (...)',
 *         'down' => 'DROP TABLE users',
 *     ];
 *
 * A "migrations" table tracks which files already ran (and in
 * which batch), so:
 *
 *     - run()      applies only the pending migrations, in order
 *     - rollback() undoes the most recent batch (down + removal)
 *
 * It exists so the database schema evolves in small, repeatable,
 * reviewable steps instead of being created by hand.
 */
final class Migrator
{
    public function __construct(
        private readonly Database $database,
        private readonly string $directory,
    ) {}

    /**
     * Apply every pending migration, in file name order.
     *
     * @return array<int, string> Names of the migrations that were applied
     */
    public function run(): array
    {
        $this->ensureTrackingTable();

        $applied = $this->appliedNames();
        $files   = $this->migrationFiles();
        $batch   = $this->nextBatchNumber();

        $newlyApplied = [];

        foreach ($files as $file) {
            $name = basename($file, '.php');

            if (in_array($name, $applied, true)) {
                continue;
            }

            $migration = $this->loadMigration($file);

            $this->runInTransaction($migration['up'], function () use ($name, $batch): void {
                $this->database->execute(
                    'INSERT INTO migrations (name, batch, ran_at) VALUES (?, ?, ?)',
                    [$name, $batch, gmdate('c')],
                );
            });

            $newlyApplied[] = $name;
        }

        return $newlyApplied;
    }

    /**
     * Undo the most recently applied batch of migrations.
     *
     * @return array<int, string> Names of the migrations that were rolled back
     */
    public function rollback(): array
    {
        $latestBatch = $this->database->query('SELECT MAX(batch) AS batch FROM migrations')[0]['batch'] ?? null;

        if ($latestBatch === null) {
            return [];
        }

        $rows = $this->database->query(
            'SELECT name FROM migrations WHERE batch = ? ORDER BY id DESC',
            [$latestBatch],
        );

        $rolledBack = [];

        foreach ($rows as $row) {
            $name   = $row['name'];
            $file   = $this->directory . '/' . $name . '.php';
            $migration = $this->loadMigration($file);

            $this->runInTransaction($migration['down'], function () use ($name): void {
                $this->database->execute('DELETE FROM migrations WHERE name = ?', [$name]);
            });

            $rolledBack[] = $name;
        }

        return $rolledBack;
    }

    /**
     * Create the migrations tracking table when it does not exist yet.
     */
    private function ensureTrackingTable(): void
    {
        $this->database->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id     INTEGER PRIMARY KEY AUTOINCREMENT,
                name   TEXT    NOT NULL UNIQUE,
                batch  INTEGER NOT NULL,
                ran_at TEXT    NOT NULL
            )'
        );
    }

    /**
     * Return the names of all migrations that already ran.
     *
     * @return array<int, string>
     */
    private function appliedNames(): array
    {
        $rows = $this->database->query('SELECT name FROM migrations');

        return array_column($rows, 'name');
    }

    /**
     * Return all migration files, sorted by name so they run in order.
     *
     * @return array<int, string>
     */
    private function migrationFiles(): array
    {
        $files = glob($this->directory . '/*.php') ?: [];
        sort($files);

        return $files;
    }

    /**
     * Return the batch number to use for the next run.
     */
    private function nextBatchNumber(): int
    {
        return (int) ($this->database->query('SELECT COALESCE(MAX(batch), 0) AS batch FROM migrations')[0]['batch'] ?? 0) + 1;
    }

    /**
     * Load a migration file and validate its structure.
     *
     * @return array{up: string, down: string}
     */
    private function loadMigration(string $file): array
    {
        $migration = require $file;

        if (!is_array($migration) || !isset($migration['up'], $migration['down'])) {
            throw new RuntimeException('Invalid migration file: ' . $file . ' (must return ["up" => SQL, "down" => SQL]).');
        }

        return ['up' => (string) $migration['up'], 'down' => (string) $migration['down']];
    }

    /**
     * Execute the schema SQL inside a transaction, then the follow-up.
     *
     * SQLite schema changes are transactional, so a failed migration
     * rolls back completely and can never leave a half-applied state.
     */
    private function runInTransaction(string $sql, callable $after): void
    {
        $pdo = $this->database->pdo();

        $pdo->beginTransaction();

        try {
            $pdo->exec($sql);
            $after();
            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }
}
