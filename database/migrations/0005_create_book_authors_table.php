<?php

declare(strict_types=1);

/**
 * Migration: book_authors
 *
 * Purpose: junction (link) table between books and authors.
 * A book can have several authors and an author can write several
 * books, so the relationship is MANY-TO-MANY and needs its own
 * table with one row per (book, author) pair.
 *
 * Relationships:
 *     book_authors n---1 books   (a book can appear many times)
 *     book_authors n---1 authors (an author can appear many times)
 *
 * Why this table exists:
 *     - Storing author ids directly on the books table would only
 *       allow ONE author per book. A junction table allows any
 *       number on both sides without duplicating author data.
 *
 * Design notes:
 *     - Foreign keys use ON DELETE CASCADE: when a book or author
 *       is deleted, its link rows are removed automatically.
 *     - UNIQUE (book_id, author_id) prevents the same link twice.
 *     - The composite unique index already covers lookups by
 *       book_id; a separate index on author_id speeds up the
 *       reverse lookup ("books by this author").
 */

return [
    'up' => "
        CREATE TABLE book_authors (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            book_id   INTEGER NOT NULL,
            author_id INTEGER NOT NULL,
            FOREIGN KEY (book_id)   REFERENCES books (id)   ON DELETE CASCADE,
            FOREIGN KEY (author_id) REFERENCES authors (id) ON DELETE CASCADE,
            UNIQUE (book_id, author_id)
        );

        CREATE INDEX idx_book_authors_author ON book_authors (author_id);
    ",
    'down' => 'DROP TABLE book_authors',
];
