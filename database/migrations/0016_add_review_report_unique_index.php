<?php

declare(strict_types=1);

/**
 * Migration: one report per user per review — Phase 7.7
 * (production readiness)
 *
 * Purpose: close the last-line-of-defence gap of the "one report
 * per user per review" rule. The service checks the rule before
 * every insert (ReviewService::reportReview ->
 * ReviewRepository::userReportedReview), but the database had no
 * UNIQUE constraint behind it - two racing requests could both
 * pass the check and file two reports. The reviews table has
 * UNIQUE (user_id, book_id) and the votes table has UNIQUE
 * (review_id, user_id) since their creation; this migration gives
 * review_reports the same guarantee:
 *
 *     UNIQUE (reported_by, review_id)
 *
 * Why it is incremental:
 *     - The check-then-insert in the service stays the friendly
 *       path (it answers ReviewException::alreadyReported with a
 *       meaningful message); the index is the safety net exactly
 *       like the other two UNIQUE indexes.
 *     - Before the index can be created, any duplicate rows a race
 *       already produced are collapsed (the oldest report of each
 *       reporter/review pair wins), because SQLite refuses to
 *       create a UNIQUE index over data that violates it.
 *     - The existing read indexes (status / review_id /
 *       reported_by) are untouched; the new index also serves the
 *       per-user report lookup (idx_review_reports_reported_by
 *       becomes redundant for it, the unique index covers it).
 */

return [
    'up' => "
        DELETE FROM review_reports
        WHERE id NOT IN (
            SELECT MIN(id)
            FROM review_reports
            GROUP BY reported_by, review_id
        );

        CREATE UNIQUE INDEX idx_review_reports_unique
            ON review_reports (reported_by, review_id);
    ",
    'down' => "
        DROP INDEX IF EXISTS idx_review_reports_unique;
    ",
];
