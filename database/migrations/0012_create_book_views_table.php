<?php

declare(strict_types=1);

/**
 * Migration: book_views
 *
 * Purpose: track the books each user has recently viewed, so the
 * personalized recommendations can include a "similar to books you
 * recently viewed" factor (Phase 6.3).
 *
 * Relationships:
 *     book_views n---1 users  (a user can view many books)
 *     book_views n---1 books  (a book can be viewed by many users)
 *
 * Why this table exists:
 *     - The brief's personalization factors list "Recently Viewed
 *       Books" as a signal, and the schema has no views tracking yet.
 *       This is an INCREMENTAL migration: no existing table changes.
 *     - One row per (user, book): re-viewing a book UPDATEs the
 *       timestamp instead of inserting a duplicate (the UNIQUE
 *       constraint enforces this at the database level).
 *     - Only the most recent N views per user are ever read, so the
 *       table stays small and fully indexed.
 *
 * Design notes:
 *     - ON DELETE CASCADE removes a user's view history when the
 *       user or the book disappears.
 *     - The index on (user_id, book_id) serves both the uniqueness
 *       check and the "recent views of this user" read.
 */

return [
    'up' => "
        CREATE TABLE book_views (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL,
            book_id    INTEGER NOT NULL,
            viewed_at  TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            FOREIGN KEY (book_id) REFERENCES books (id) ON DELETE CASCADE,
            UNIQUE (user_id, book_id)
        );

        CREATE INDEX idx_book_views_user ON book_views (user_id);
    ",
    'down' => 'DROP TABLE book_views',
];
