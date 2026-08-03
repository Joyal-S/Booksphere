<?php

declare(strict_types=1);

/**
 * Migration: books
 *
 * Purpose: the catalogue.
 * Holds every book the application knows about, with the metadata
 * needed for browsing, searching and recommending.
 *
 * Relationships (defined in the other tables' migrations):
 *     books 1---n book_authors      (book <-> authors, many-to-many)
 *     books 1---n book_categories   (book <-> categories, many-to-many)
 *     books 1---n reviews
 *     books 1---n wishlist
 *     books 1---n recommendations
 *
 * Why this table exists:
 *     - It is the heart of the catalogue: discovery and
 *       recommendations both start from the books table.
 *
 * Design notes:
 *     - google_book_id and isbn are UNIQUE (but nullable) so the
 *       Google Books import phase can merge records without dupes.
 *     - average_rating is a denormalized value updated from the
 *       reviews table - read fast, kept in sync by the service
 *       layer in a later phase.
 *     - title and published_year are indexed because search and
 *       filtering use them most often.
 */

return [
    'up' => "
        CREATE TABLE books (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            google_book_id  TEXT    UNIQUE,
            isbn            TEXT    UNIQUE,
            title           TEXT    NOT NULL,
            subtitle        TEXT,
            description     TEXT,
            publisher       TEXT,
            published_year  INTEGER,
            language        TEXT    NOT NULL DEFAULT 'en',
            page_count      INTEGER,
            cover_image     TEXT,
            average_rating  REAL    NOT NULL DEFAULT 0,
            ratings_count   INTEGER NOT NULL DEFAULT 0,
            created_at      TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            updated_at      TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
        );

        CREATE INDEX idx_books_title          ON books (title);
        CREATE INDEX idx_books_published_year ON books (published_year);
    ",
    'down' => 'DROP TABLE books',
];
