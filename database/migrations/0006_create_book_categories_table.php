<?php

declare(strict_types=1);

/**
 * Migration: book_categories
 *
 * Purpose: junction (link) table between books and categories.
 * A book can belong to several categories and a category contains
 * many books, so the relationship is MANY-TO-MANY.
 *
 * Relationships:
 *     book_categories n---1 books      (a book can appear many times)
 *     book_categories n---1 categories (a category can appear many times)
 *
 * Why this table exists:
 *     - Same reason as book_authors: one book, many categories,
 *       without repeating the category name on every book row.
 *
 * Design notes:
 *     - ON DELETE CASCADE removes links automatically when either
 *       side is deleted.
 *     - UNIQUE (book_id, category_id) prevents the same link twice.
 *     - The index on category_id speeds up "books in this category".
 */

return [
    'up' => "
        CREATE TABLE book_categories (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            book_id     INTEGER NOT NULL,
            category_id INTEGER NOT NULL,
            FOREIGN KEY (book_id)     REFERENCES books (id)     ON DELETE CASCADE,
            FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE,
            UNIQUE (book_id, category_id)
        );

        CREATE INDEX idx_book_categories_category ON book_categories (category_id);
    ",
    'down' => 'DROP TABLE book_categories',
];
