<?php

declare(strict_types=1);

/**
 * Migration: reviews — Phase 7.1 (Reviews & Ratings)
 *
 * Purpose: extend the reviews table (created in migration 0007 by
 * the Recommendation Engine phase) with the columns the Reviews
 * module needs, WITHOUT recreating the table or touching its data:
 *
 *     title      TEXT NOT NULL DEFAULT ''  - the review headline
 *                                            (max 120, validated in
 *                                            the request layer)
 *     status     TEXT NOT NULL DEFAULT 'approved'  - moderation
 *                                            enum: approved |
 *                                            pending | hidden
 *     is_edited  INTEGER NOT NULL DEFAULT 0 - 1 once the author
 *                                            edits their review
 *     updated_at TEXT NOT NULL DEFAULT ''  - last-change timestamp
 *                                            (backfilled from
 *                                            created_at)
 *
 * Why it is incremental:
 *     - SQLite cannot ALTER a table and ADD a CHECK constraint to
 *       an existing column, so the rating 1-5 check stays where it
 *       already lives (the CREATE TABLE CHECK in migration 0007)
 *       and the review length rules (20-2000 chars) are enforced
 *       by the request validation (StoreReviewRequest /
 *       UpdateReviewRequest) - the same place all other length
 *       rules of the project live.
 *     - The "one review per user per book" rule already exists as
 *       the UNIQUE (user_id, book_id) index from migration 0007
 *       (uniqueness is order-independent in SQLite, so the brief's
 *       (book_id, user_id) composite is the same constraint).
 *     - The book_id lookup index also already exists
 *       (idx_reviews_book from 0007, idx_reviews_book_created from
 *       0013). This migration only adds the missing lookup indexes:
 *       user_id (every "reviews by user" read), rating (scopes
 *       highestRated / lowestRated) and created_at (latest /
 *       oldest scopes and the "Recent Reviews" dashboard block).
 *
 * Column-order note: SQLite appends new columns at the end of the
 * row, so SELECT * from the Phase 6 era returns them after
 * created_at. Every read in ReviewRepository lists columns
 * explicitly, so the order never matters.
 */

return [
    'up' => "
        ALTER TABLE reviews ADD COLUMN title      TEXT NOT NULL DEFAULT '';
        ALTER TABLE reviews ADD COLUMN status     TEXT NOT NULL DEFAULT 'approved';
        ALTER TABLE reviews ADD COLUMN is_edited  INTEGER NOT NULL DEFAULT 0;
        ALTER TABLE reviews ADD COLUMN updated_at TEXT NOT NULL DEFAULT '';

        UPDATE reviews SET updated_at = created_at WHERE updated_at = '';

        CREATE INDEX idx_reviews_user    ON reviews (user_id);
        CREATE INDEX idx_reviews_rating  ON reviews (rating);
        CREATE INDEX idx_reviews_created ON reviews (created_at);
    ",
    'down' => "
        DROP INDEX IF EXISTS idx_reviews_user;
        DROP INDEX IF EXISTS idx_reviews_rating;
        DROP INDEX IF EXISTS idx_reviews_created;
    ",
];
