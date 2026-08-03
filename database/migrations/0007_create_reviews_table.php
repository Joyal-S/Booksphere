<?php

declare(strict_types=1);

/**
 * Migration: reviews
 *
 * Purpose: ratings and written reviews.
 * One user can rate a book once and optionally leave a short
 * written review.
 *
 * Relationships:
 *     reviews n---1 users  (a user can write many reviews)
 *     reviews n---1 books  (a book can receive many reviews)
 *
 * Why this table exists:
 *     - Ratings are the main quality signal for recommendations.
 *     - Only ONE rating per user per book is allowed, which is
 *       why the pair (user_id, book_id) is UNIQUE - the database
 *       itself enforces this rule.
 *
 * Design notes:
 *     - rating is CHECKed to stay between 1 and 5.
 *     - The written review is optional (rating alone is valid).
 *     - ON DELETE CASCADE: deleting a user or book removes its
 *       reviews automatically.
 *     - The UNIQUE index covers lookups by user; the extra index
 *       on book_id speeds up "all reviews for this book".
 */

return [
    'up' => "
        CREATE TABLE reviews (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL,
            book_id    INTEGER NOT NULL,
            rating     INTEGER NOT NULL CHECK (rating BETWEEN 1 AND 5),
            review     TEXT,
            created_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            FOREIGN KEY (book_id) REFERENCES books (id) ON DELETE CASCADE,
            UNIQUE (user_id, book_id)
        );

        CREATE INDEX idx_reviews_book ON reviews (book_id);
    ",
    'down' => 'DROP TABLE reviews',
];
