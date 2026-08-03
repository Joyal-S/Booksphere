<?php

declare(strict_types=1);

/**
 * Migration: authors
 *
 * Purpose: people who wrote the books.
 *
 * Relationships (defined in the other tables' migrations):
 *     authors 1---n book_authors   (authors <-> books, many-to-many)
 *
 * Why this table exists:
 *     - An author can write many books, so the author's data lives
 *       in its own table instead of being repeated on every book
 *       (this avoids duplicate data).
 *     - "Books by the same author" is a strong recommendation
 *       signal in later phases.
 *
 * Design notes:
 *     - name is UNIQUE so the same person is never stored twice.
 *     - photo is optional: it will be populated by cover/photo
 *       uploads or the Google Books import in a later phase.
 */

return [
    'up' => "
        CREATE TABLE authors (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT    NOT NULL UNIQUE,
            biography  TEXT,
            photo      TEXT,
            created_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
        )
    ",
    'down' => 'DROP TABLE authors',
];
