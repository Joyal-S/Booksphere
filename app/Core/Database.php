<?php

declare(strict_types=1);

namespace BookSphere\App\Core;

use PDO;
use RuntimeException;

/**
 * Database
 *
 * Opens and configures the SQLite connection through PDO.
 * PDO is PHP's built-in database layer: it handles the connection
 * and (through prepared statements) protects against SQL injection.
 *
 * The class is a SINGLETON: the whole application shares one
 * connection, created lazily on first use. It also provides small
 * wrappers around PDO so models and services never have to write
 * the same prepare/execute boilerplate twice.
 *
 * It exists so every model shares the same connection settings:
 * exceptions on failure, associative result rows, and SQLite
 * pragmas tuned for reliability on concurrent requests.
 */
final class Database
{
    /** The single shared connection of the application (lazy). */
    private static ?Database $instance = null;

    private PDO $pdo;

    public function __construct(string $path)
    {
        $directory = dirname($path);

        // Create the database directory on first run (e.g. "storage/").
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new RuntimeException('Database directory is not writable.');
        }

        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        $this->configurePragmas();
    }

    /**
     * Return the shared database connection.
     *
     * The connection is created on the first call and reused for
     * every later call. When no path is given, it is resolved from
     * the "database.path" configuration value, so the config file
     * remains the single source of truth.
     */
    public static function instance(?string $path = null): self
    {
        if (self::$instance === null) {
            $path ??= (string) config('database.path', root_path('database/booksphere.db'));

            self::$instance = new self($path);
        }

        return self::$instance;
    }

    /**
     * Return the underlying PDO connection.
     *
     * Used for advanced features (transactions, lastInsertId,
     * exec for DDL) that the small wrappers do not cover.
     */
    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Prepare a parameterized SQL statement.
     *
     * Placeholders in the SQL are written as "?" and filled in
     * later by execute(). This is the safe way to run queries
     * that contain user input.
     */
    public function prepare(string $sql): \PDOStatement
    {
        return $this->pdo->prepare($sql);
    }

    /**
     * Run a SELECT query and return every result row.
     *
     * Each row is an associative array: ['id' => 1, 'title' => '...'].
     *
     * @return array<int, array<string, mixed>>
     */
    public function query(string $sql, array $params = []): array
    {
        $statement = $this->prepare($sql);
        $this->bindValues($statement, $params);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * Run an INSERT, UPDATE or DELETE query.
     *
     * @return int The number of affected rows (0 when nothing changed)
     */
    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->prepare($sql);
        $this->bindValues($statement, $params);
        $statement->execute();

        return $statement->rowCount();
    }

    /**
     * Bind positional parameters with their native type.
     *
     * PDO's default execute() binds EVERY value as a string. With
     * native (non-emulated) prepares that is safe for column
     * comparisons (column affinity coerces the value), but it is
     * WRONG in expressions with no affinity context: SQLite treats
     * `5 >= '5'` as TEXT-vs-INTEGER and returns FALSE. The
     * recommendation queries rely on such expression comparisons
     * (counts and scores vs. bound thresholds), so integers MUST
     * reach SQLite as integers.
     *
     * @param array<int, mixed> $params
     */
    private function bindValues(\PDOStatement $statement, array $params): void
    {
        foreach ($params as $index => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $statement->bindValue($index + 1, $value, $type);
        }
    }

    /**
     * Return the id of the last inserted row.
     */
    public function lastInsertId(): string|false
    {
        return $this->pdo->lastInsertId();
    }

    /**
     * Apply SQLite connection settings:
     *
     *     - foreign_keys = ON   -> enforce relationships between tables
     *     - journal_mode = WAL  -> allow concurrent readers and writers
     *     - synchronous = NORMAL-> faster writes with safe durability
     *     - busy_timeout = 5000 -> wait instead of failing when the
     *                              database is briefly locked
     */
    private function configurePragmas(): void
    {
        $this->pdo->exec(
            'PRAGMA foreign_keys = ON;
             PRAGMA journal_mode = WAL;
             PRAGMA synchronous = NORMAL;
             PRAGMA busy_timeout = 5000;'
        );
    }
}
