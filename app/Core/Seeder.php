<?php

declare(strict_types=1);

namespace BookSphere\App\Core;

use Throwable;

/**
 * Seeder
 *
 * Runs seed files: PHP files in database/seeds/ that insert
 * sample or default data.
 *
 * Each seed file returns a closure that receives the Database
 * and can run any INSERT it needs:
 *
 *     return function (Database $database): void {
 *         $database->execute('INSERT INTO books (title) VALUES (?)', ['Sample Book']);
 *     };
 *
 * All seed files run inside ONE transaction, so a failing seed
 * rolls back everything and never leaves the database half-filled.
 * Seeds are not tracked - they are intended to be run explicitly,
 * e.g. once after migrations, for development data.
 */
final class Seeder
{
    public function __construct(
        private readonly Database $database,
        private readonly string $directory,
    ) {}

    /**
     * Run every seed file, in file name order.
     *
     * @return array<int, string> Names of the seed files that ran
     */
    public function run(): array
    {
        $files = glob($this->directory . '/*.php') ?: [];
        sort($files);

        $pdo = $this->database->pdo();
        $pdo->beginTransaction();

        $seeded = [];

        try {
            foreach ($files as $file) {
                $seed = require $file;

                if (!is_callable($seed)) {
                    throw new \RuntimeException('Invalid seed file: ' . $file . ' (must return a callable).');
                }

                $seed($this->database);
                $seeded[] = basename($file, '.php');
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        return $seeded;
    }
}
