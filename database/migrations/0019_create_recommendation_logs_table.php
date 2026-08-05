<?php

declare(strict_types=1);

/**
 * Migration: recommendation_logs
 *
 * Purpose: the audit trail of the Phase 8.5 Personal Library
 * recommendations. Whenever the engine serves a recommendation shelf
 * built from library signals, one row per recommended book is
 * appended here - the user, the book, the explainable reason, the
 * score, the signal (which section produced it) and the generation
 * timestamp.
 *
 * The table powers two things:
 *
 *     1. the profile's "Recommendation Accuracy" figure - of the
 *        books recommended to the user inside the accuracy window,
 *        how many did they actually act on (save to library, rate,
 *        review);
 *     2. an audit trail for the administrator (what the engine
 *        suggested, when, and why) without exposing internals to
 *        the pages themselves.
 *
 * Columns:
 *     id           -> primary key
 *     user_id      -> the user the shelf was built for (FK users.id,
 *                     ON DELETE CASCADE)
 *     book_id      -> the recommended book (FK books.id, ON DELETE
 *                     CASCADE)
 *     reason       -> the explainable reason the card showed
 *     score        -> the weighted library score, 0-100
 *     signal       -> the section key that produced the suggestion
 *                     ('because_you_read', 'readers_also_enjoyed', ...)
 *     generated_at -> when the recommendation was served (UTC)
 *
 * Design notes:
 *     - ON DELETE CASCADE: deleting a user or a book scrubs their
 *       logs automatically.
 *     - idx_recommendation_logs_user_generated serves the two hot
 *       reads: "the recent logs of one user" (profile accuracy) and
 *       the prune-on-write (keep the newest retention_per_user rows).
 *     - idx_recommendation_logs_book serves "which books have been
 *       recommended to everyone" (admin audit).
 *     - Retention is enforced by RecommendationService on write
 *       (config('recommendations.library.logs.retention_per_user')),
 *       so the table stays bounded per user without a background job.
 */

return [
    'up' => "
        CREATE TABLE recommendation_logs (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id      INTEGER NOT NULL,
            book_id      INTEGER NOT NULL,
            reason       TEXT    NOT NULL DEFAULT '',
            score        REAL    NOT NULL DEFAULT 0,
            signal       TEXT    NOT NULL DEFAULT '',
            generated_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            FOREIGN KEY (book_id) REFERENCES books (id) ON DELETE CASCADE
        );

        CREATE INDEX idx_recommendation_logs_user_generated ON recommendation_logs (user_id, generated_at DESC);
        CREATE INDEX idx_recommendation_logs_book ON recommendation_logs (book_id);
    ",
    'down' => 'DROP TABLE recommendation_logs',
];
