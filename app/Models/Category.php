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

    /**
     * Find a category by name, creating it when it does not exist yet.
     *
     * The importer's category staging uses this. The slug is derived
     * from the name ("Mystery & Thriller" -> "mystery-thriller", the
     * same rule the seed slugs follow), and both name and slug are
     * UNIQUE, so the insert-or-ignore + read-back pattern is race-safe.
     * When the derived slug collides with a DIFFERENT category name,
     * a short hash suffix makes it unique instead of losing the import.
     *
     * @throws \InvalidArgumentException when the name is empty
     */
    public function findOrCreate(string $name): int
    {
        $name = trim($name);

        if ($name === '') {
            throw new \InvalidArgumentException('Category name must not be empty.');
        }

        $rows = db()->query('SELECT id FROM categories WHERE name = ?', [$name]);

        if ($rows !== []) {
            return (int) $rows[0]['id'];
        }

        $slug = $this->slugify($name);
        db()->execute('INSERT OR IGNORE INTO categories (name, slug) VALUES (?, ?)', [$name, $slug]);

        $rows = db()->query('SELECT id FROM categories WHERE name = ?', [$name]);

        if ($rows !== []) {
            return (int) $rows[0]['id'];
        }

        // Slug collision with a different name: retry with a suffix.
        db()->execute(
            'INSERT OR IGNORE INTO categories (name, slug) VALUES (?, ?)',
            [$name, $slug . '-' . substr(md5($name), 0, 4)],
        );

        $rows = db()->query('SELECT id FROM categories WHERE name = ?', [$name]);

        return (int) $rows[0]['id'];
    }

    /**
     * The URL slug of a category name: lowercased, every run of
     * non-alphanumeric characters collapsed into one dash.
     */
    private function slugify(string $name): string
    {
        $slug = mb_strtolower(trim($name));
        $slug = (string) preg_replace('/[^a-z0-9]+/u', '-', $slug);

        return trim($slug, '-') !== '' ? trim($slug, '-') : 'category';
    }
}
