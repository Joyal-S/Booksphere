<?php

declare(strict_types=1);

/**
 * Migration: categories
 *
 * Purpose: the controlled list of genres used to classify books
 * (Fiction, Fantasy, Technology, ...).
 *
 * Relationships (defined in the other tables' migrations):
 *     categories 1---n book_categories  (categories <-> books, many-to-many)
 *
 * Why this table exists:
 *     - A small, fixed set of categories keeps browsing and
 *       filtering predictable, and category similarity drives the
 *       recommendation engine in a later phase.
 *
 * Design notes:
 *     - name and slug are UNIQUE: no duplicate category ever.
 *     - The slug is the URL-friendly version of the name
 *       ("Science Fiction" -> "science-fiction") used in routes
 *       like /categories/science-fiction.
 */

return [
    'up' => "
        CREATE TABLE categories (
            id   INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            slug TEXT NOT NULL UNIQUE
        )
    ",
    'down' => 'DROP TABLE categories',
];
