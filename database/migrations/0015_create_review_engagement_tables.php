<?php

declare(strict_types=1);

/**
 * Migration: review engagement — Phase 7.5 (Helpful Votes & Reports)
 *
 * Purpose: create the two tables the community-engagement features
 * of the Reviews module run on:
 *
 *     review_helpful_votes   - one row per "this review helped me"
 *                              vote. The UNIQUE (review_id, user_id)
 *                              index enforces the "one vote per
 *                              user per review" rule at the database
 *                              level (order-independent in SQLite,
 *                              matching the brief).
 *     review_reports         - one row per user report. status is a
 *                              moderation lifecycle enum (pending |
 *                              reviewed | dismissed | resolved,
 *                              default pending) and reason a fixed
 *                              enum (Spam, Harassment, Offensive
 *                              Content, False Information, Duplicate,
 *                              Other) - both validated at the
 *                              database level with CHECK
 *                              constraints so corrupt rows are
 *                              impossible, exactly like the rating
 *                              1-5 check in migration 0007.
 *
 * Why it is incremental:
 *     - Fresh tables, no ALTERs on existing data.
 *     - The reviews.status enum (approved | pending | hidden)
 *       already exists from 0014; the admin "hide review" action of
 *       Phase 7.5 only sets that column, so nothing else migrates.
 *     - Indexes: every read path is covered - listing reports by
 *       status (idx_review_reports_status), the moderation queue
 *       join on review_id (idx_review_reports_review), per-user
 *       report lookup (idx_review_reports_reported_by), and the
 *       helpful-count join (idx_review_helpful_votes_review).
 *
 * Deletion note: the brief does not ask for a report-count cutoff
 * or auto-hide, so there is no partial-index trigger. Phase 7.6
 * moderation can add whatever policy it needs as a migration.
 */

return [
    'up' => "
        CREATE TABLE review_helpful_votes (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            review_id  INTEGER NOT NULL,
            user_id    INTEGER NOT NULL,
            created_at TEXT    NOT NULL DEFAULT '',
            FOREIGN KEY (review_id) REFERENCES reviews (id) ON DELETE CASCADE,
            FOREIGN KEY (user_id)   REFERENCES users   (id) ON DELETE CASCADE
        );

        CREATE UNIQUE INDEX idx_review_helpful_votes_unique
            ON review_helpful_votes (review_id, user_id);

        CREATE INDEX idx_review_helpful_votes_review
            ON review_helpful_votes (review_id);

        CREATE TABLE review_reports (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            review_id   INTEGER NOT NULL,
            reported_by INTEGER NOT NULL,
            reason      TEXT    NOT NULL DEFAULT 'Other',
            description TEXT    NOT NULL DEFAULT '',
            status      TEXT    NOT NULL DEFAULT 'pending',
            created_at  TEXT    NOT NULL DEFAULT '',
            updated_at  TEXT    NOT NULL DEFAULT '',
            FOREIGN KEY (review_id)   REFERENCES reviews (id) ON DELETE CASCADE,
            FOREIGN KEY (reported_by) REFERENCES users   (id) ON DELETE CASCADE,
            CHECK (reason IN ('Spam', 'Harassment', 'Offensive Content', 'False Information', 'Duplicate', 'Other')),
            CHECK (status IN ('pending', 'reviewed', 'dismissed', 'resolved'))
        );

        CREATE INDEX idx_review_reports_status      ON review_reports (status);
        CREATE INDEX idx_review_reports_review      ON review_reports (review_id);
        CREATE INDEX idx_review_reports_reported_by ON review_reports (reported_by);
    ",
    'down' => "
        DROP INDEX IF EXISTS idx_review_helpful_votes_unique;
        DROP INDEX IF EXISTS idx_review_helpful_votes_review;
        DROP TABLE IF EXISTS review_helpful_votes;

        DROP INDEX IF EXISTS idx_review_reports_status;
        DROP INDEX IF EXISTS idx_review_reports_review;
        DROP INDEX IF EXISTS idx_review_reports_reported_by;
        DROP TABLE IF EXISTS review_reports;
    ",
];
