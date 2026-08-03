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
        return db()->query('SELECT id, name FROM authors ORDER BY name ASC');
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
}
