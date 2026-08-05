<?php

declare(strict_types=1);

/**
 * Migration: user_library
 *
 * Purpose: the Personal Reading Library of Phase 8.1 - one record
 * per user PER BOOK that tracks where a book sits in a user's
 * personal reading journey.
 *
 * This is the evolution of the simple "saved for later" wishlist into
 * a complete personal library. The legacy `wishlist` table (migration
 * 0008) STAYS: the recommendation engine reads it as a
 * personalization signal (RecommendationRepository::wishlistBookIds),
 * and wiring the two together is a Phase 8.5 concern. This table is
 * the new, richer home of every "which shelf is this book on"
 * decision.
 *
 * Columns:
 *     id                  -> primary key
 *     user_id             -> owner (FK users.id, ON DELETE CASCADE)
 *     book_id             -> the book (FK books.id, ON DELETE CASCADE)
 *     library_status      -> the reading shelf:
 *                            want_to_read | currently_reading |
 *                            finished | on_hold | dropped
 *                            (default want_to_read, CHECK constrained)
 *     is_favorite         -> boolean (0/1), independent of status - a
 *                            finished book may still be a favourite
 *     progress_percentage -> 0-100, CHECK constrained, default 0
 *     started_reading_at  -> set when the user begins reading the book
 *     finished_reading_at -> set when the user finishes it
 *     created_at / updated_at
 *
 * Design notes:
 *     - UNIQUE (user_id, book_id): a book can appear only ONCE in a
 *       user's library (the "one record per user per book" rule).
 *     - ON DELETE CASCADE removes a user's records when the user OR
 *       the book is deleted.
 *     - Four supporting indexes: user_id ("one user's library"),
 *       book_id ("who has this book"), library_status (the shelf
 *       buckets) and is_favorite (the favourites shelf).
 *     - The CHECK constraints enforce the allowed statuses and the
 *       0-100 progress range at the database level - the last line
 *       of defence behind the request validation and the service.
 */

return [
    'up' => "
        CREATE TABLE user_library (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id             INTEGER NOT NULL,
            book_id             INTEGER NOT NULL,
            library_status      TEXT    NOT NULL DEFAULT 'want_to_read'
                                        CHECK (library_status IN ('want_to_read', 'currently_reading', 'finished', 'on_hold', 'dropped')),
            is_favorite         INTEGER NOT NULL DEFAULT 0 CHECK (is_favorite IN (0, 1)),
            progress_percentage INTEGER NOT NULL DEFAULT 0 CHECK (progress_percentage BETWEEN 0 AND 100),
            started_reading_at  TEXT,
            finished_reading_at TEXT,
            created_at          TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            updated_at          TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            FOREIGN KEY (book_id) REFERENCES books (id) ON DELETE CASCADE,
            UNIQUE (user_id, book_id)
        );

        CREATE INDEX idx_user_library_user   ON user_library (user_id);
        CREATE INDEX idx_user_library_book   ON user_library (book_id);
        CREATE INDEX idx_user_library_status ON user_library (library_status);
        CREATE INDEX idx_user_library_favorite ON user_library (is_favorite);
    ",
    'down' => 'DROP TABLE user_library',
];