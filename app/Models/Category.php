<?php

declare(strict_types=1);

namespace BookSphere\App\Models;

/**
 * Category
 *
 * Data access for the categories table. Mirrors Author: the full
 * list (for the category checkboxes in the book form) and a lookup
 * by id. The slug is included in the list because category pages
 * (a later phase) will use it for URLs.
 */
final class Category
{
    /**
     * Return every category, ordered by name.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return db()->query('SELECT id, name, slug FROM categories ORDER BY name ASC');
    }

    /**
     * Find a category by primary key.
     *
     * @return array<string, mixed>|null The category row, or null
     */
    public function findById(int $id): ?array
    {
        $rows = db()->query(
            'SELECT id, name, slug FROM categories WHERE id = ?',
            [$id],
        );

        return $rows[0] ?? null;
    }
}
