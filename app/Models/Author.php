<?php

declare(strict_types=1);

namespace BookSphere\App\Models;

/**
 * Author
 *
 * Data access for the authors table. Only the two queries the book
 * management forms and pages need: the full list (for the author
 * checkboxes) and a lookup by id.
 *
 * Like every model in this application it returns plain associative
 * arrays and always uses prepared statements.
 */
final class Author
{
    /**
     * Return every author, ordered by name.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return db()->query(
            'SELECT a.id, a.name
             FROM authors a
             JOIN book_authors ba ON ba.author_id = a.id
             JOIN books b ON b.id = ba.book_id
             WHERE b.status = ? AND b.deleted_at IS NULL
             GROUP BY a.id, a.name
             ORDER BY a.name ASC',
            ['published'],
        );
    }

    /**
     * Find an author by primary key.
     *
     * @return array<string, mixed>|null The author row, or null
     */
    public function findById(int $id): ?array
    {
        $rows = db()->query(
            'SELECT id, name FROM authors WHERE id = ?',
            [$id],
        );

        return $rows[0] ?? null;
    }

    /**
     * Find an author by name, creating it when it does not exist yet.
     *
     * The importer's author staging uses this. authors.name is UNIQUE,
     * so the insert-or-ignore + read-back pattern is race-safe: two
     * imports of the same new author can never create a second row.
     *
     * @throws \InvalidArgumentException when the name is empty
     */
    public function findOrCreate(string $name): int
    {
        $name = trim($name);

        if ($name === '') {
            throw new \InvalidArgumentException('Author name must not be empty.');
        }

        $rows = db()->query('SELECT id FROM authors WHERE name = ?', [$name]);

        if ($rows !== []) {
            return (int) $rows[0]['id'];
        }

        db()->execute('INSERT OR IGNORE INTO authors (name, biography, photo) VALUES (?, NULL, NULL)', [$name]);
        $rows = db()->query('SELECT id FROM authors WHERE name = ?', [$name]);

        return (int) $rows[0]['id'];
    }
}
