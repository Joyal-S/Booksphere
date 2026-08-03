<?php

declare(strict_types=1);

/**
 * Migration: wishlist
 *
 * Purpose: books a user has saved for later ("read this soon").
 *
 * Relationships:
 *     wishlist n---1 users  (a user can save many books)
 *     wishlist n---1 books  (a book can be saved by many users)
 *
 * Why this table exists:
 *     - Users need a personal list that is separate from reading
 *       status. The wishlist is the "saved" bucket.
 *
 * Design notes:
 *     - UNIQUE (user_id, book_id): the same book cannot be saved
 *       twice by the same user.
 *     - ON DELETE CASCADE removes a user's entries automatically
 *       when the user or the book is deleted.
 *     - The index on book_id supports "who saved this book"
 *       queries (useful for recommendations later).
 */

return [
    'up' => "
        CREATE TABLE wishlist (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL,
            book_id    INTEGER NOT NULL,
            created_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            FOREIGN KEY (book_id) REFERENCES books (id) ON DELETE CASCADE,
            UNIQUE (user_id, book_id)
        );

        CREATE INDEX idx_wishlist_book ON wishlist (book_id);
    ",
    'down' => 'DROP TABLE wishlist',
];
