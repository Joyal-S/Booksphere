<?php

declare(strict_types=1);

/**
 * Migration: books - browse indexes
 *
 * Purpose: make the Phase 5.5 browse screens (search, filters,
 * sorting, pagination) scale when the catalogue grows to thousands
 * of rows.
 *
 * Why these indexes:
 *     - idx_books_language     -> the language filter dropdown
 *     - idx_books_publisher    -> the publisher filter
 *     - idx_books_created_at   -> the "newest / oldest" sorts
 *     - idx_books_updated_at   -> the "recently updated" sort
 *     - idx_books_average_rating -> the rating sort + min-rating filter
 *     - idx_book_authors_author, idx_book_categories_category
 *                              -> the author/category filters and the
 *                                 EXISTS subqueries of free-text search;
 *                                 the two-column form (filter column
 *                                 first, book_id second) lets SQLite
 *                                 serve "which books are in this
 *                                 category/author?" straight from the
 *                                 index without touching the tables.
 *                                 Migrations 0005/0006 created these
 *                                 names as single-column indexes; this
 *                                 migration upgrades them in place.
 *
 * Design note (what does NOT get an index):
 *     Free-text search uses LIKE '%term%' (title, isbn, description,
 *     ...). A B-tree index cannot accelerate a wildcard that starts
 *     with "%", so those columns are deliberately not indexed for
 *     search. For a catalogue in the low thousands, the full scan is
 *     instant; SQLite's FTS5 full-text engine is the documented
 *     upgrade path if the catalogue ever reaches tens of thousands.
 *
 * Why a new migration instead of editing 0002:
 *     Existing databases have already run 0002; schema evolution is
 *     forward-only, exactly like migration 0010 did for status.
 */

return [
    'up' => "
        CREATE INDEX idx_books_language     ON books (language);
        CREATE INDEX idx_books_publisher    ON books (publisher);
        CREATE INDEX idx_books_created_at   ON books (created_at);
        CREATE INDEX idx_books_updated_at   ON books (updated_at);
        CREATE INDEX idx_books_average_rating ON books (average_rating);

        DROP INDEX IF EXISTS idx_book_authors_author;
        CREATE INDEX idx_book_authors_author ON book_authors (author_id, book_id);

        DROP INDEX IF EXISTS idx_book_categories_category;
        CREATE INDEX idx_book_categories_category ON book_categories (category_id, book_id);
    ",
    'down' => "
        DROP INDEX IF EXISTS idx_books_language;
        DROP INDEX IF EXISTS idx_books_publisher;
        DROP INDEX IF EXISTS idx_books_created_at;
        DROP INDEX IF EXISTS idx_books_updated_at;
        DROP INDEX IF EXISTS idx_books_average_rating;

        DROP INDEX IF EXISTS idx_book_authors_author;
        CREATE INDEX idx_book_authors_author ON book_authors (author_id);

        DROP INDEX IF EXISTS idx_book_categories_category;
        CREATE INDEX idx_book_categories_category ON book_categories (category_id);
    ",
];
