<?php

declare(strict_types=1);

/**
 * Migration: recommendations
 *
 * Purpose: personalised book suggestions.
 * Stores one row per suggested book: a score (how strongly the
 * book is recommended) and a human-readable reason ("Because you
 * liked The Martian").
 *
 * Relationships:
 *     recommendations n---1 users  (a user receives many suggestions)
 *     recommendations n---1 books  (a book can be suggested to many users)
 *
 * Why this table exists:
 *     - The recommendation engine (a later phase) writes its
 *       results here, and the recommendation page simply reads
 *       this table - computation and display stay separated.
 *
 * Design notes:
 *     - score is a number between 0 and 100 used for sorting.
 *     - reason is the EXPLAINABLE part: a short text shown to the
 *       user so the suggestion is transparent (great for a viva).
 *     - UNIQUE (user_id, book_id): a book is suggested at most
 *       once per user; regenerating replaces the row.
 *     - ON DELETE CASCADE keeps the table clean when a user or
 *       book disappears.
 */

return [
    'up' => "
        CREATE TABLE recommendations (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL,
            book_id    INTEGER NOT NULL,
            score      REAL    NOT NULL CHECK (score >= 0 AND score <= 100),
            reason     TEXT,
            created_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            FOREIGN KEY (book_id) REFERENCES books (id) ON DELETE CASCADE,
            UNIQUE (user_id, book_id)
        );

        CREATE INDEX idx_recommendations_user ON recommendations (user_id);
        CREATE INDEX idx_recommendations_book ON recommendations (book_id);
    ",
    'down' => 'DROP TABLE recommendations',
];
