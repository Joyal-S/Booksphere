<?php

declare(strict_types=1);

/**
 * Migration: Community Tables (Phase C2 -- Database Foundation)
 *
 * Creates four new tables for the Community module.
 * No existing tables are altered.
 *
 * FK ON DELETE rationale (C1 Audit, docs/PHASE_C1_COMMUNITY_AUDIT.md, Section 10):
 *   - user_id    ON DELETE CASCADE   users are hard-deleted in this project
 *   - book_id    ON DELETE SET NULL  preserves post as general discussion
 *   - post_id    ON DELETE CASCADE   comments/likes/reports follow their post
 *   - comment_id ON DELETE CASCADE   reports follow their comment
 *
 * Status CHECK enums mirror migration 0014 (reviews.status).
 * UNIQUE like constraint mirrors migration 0015 (review_helpful_votes).
 * Timestamp: strftime ISO-8601 UTC (project-wide since migration 0001).
 */

return [
    'up'   => "
        CREATE TABLE community_posts (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL,
            book_id    INTEGER DEFAULT NULL,
            title      TEXT    NOT NULL,
            body       TEXT    NOT NULL,
            status     TEXT    NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'hidden', 'deleted')),
            created_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            updated_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            FOREIGN KEY (book_id) REFERENCES books (id) ON DELETE SET NULL
        );
        CREATE INDEX idx_community_posts_user    ON community_posts (user_id);
        CREATE INDEX idx_community_posts_book    ON community_posts (book_id);
        CREATE INDEX idx_community_posts_status  ON community_posts (status);
        CREATE INDEX idx_community_posts_created ON community_posts (created_at);
        CREATE TABLE community_comments (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id    INTEGER NOT NULL,
            user_id    INTEGER NOT NULL,
            body       TEXT    NOT NULL,
            status     TEXT    NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'hidden', 'deleted')),
            created_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            updated_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            FOREIGN KEY (post_id) REFERENCES community_posts (id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users           (id) ON DELETE CASCADE
        );
        CREATE INDEX idx_community_comments_post    ON community_comments (post_id);
        CREATE INDEX idx_community_comments_user    ON community_comments (user_id);
        CREATE INDEX idx_community_comments_created ON community_comments (created_at);
        CREATE TABLE community_likes (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id    INTEGER NOT NULL,
            user_id    INTEGER NOT NULL,
            created_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            FOREIGN KEY (post_id) REFERENCES community_posts (id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users           (id) ON DELETE CASCADE
        );
        CREATE UNIQUE INDEX idx_community_likes_unique ON community_likes (post_id, user_id);
        CREATE INDEX        idx_community_likes_post   ON community_likes (post_id);
        CREATE TABLE community_reports (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id      INTEGER DEFAULT NULL,
            comment_id   INTEGER DEFAULT NULL,
            reported_by  INTEGER NOT NULL,
            reason       TEXT    NOT NULL DEFAULT 'Other' CHECK (reason IN ('Spam', 'Harassment', 'Offensive Content', 'False Information', 'Duplicate', 'Other')),
            description  TEXT    NOT NULL DEFAULT '',
            status       TEXT    NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'reviewed', 'dismissed', 'resolved')),
            created_at   TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            updated_at   TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            FOREIGN KEY (post_id)     REFERENCES community_posts    (id) ON DELETE CASCADE,
            FOREIGN KEY (comment_id)  REFERENCES community_comments (id) ON DELETE CASCADE,
            FOREIGN KEY (reported_by) REFERENCES users              (id) ON DELETE CASCADE
        );
        CREATE INDEX idx_community_reports_status      ON community_reports (status);
        CREATE INDEX idx_community_reports_post        ON community_reports (post_id);
        CREATE INDEX idx_community_reports_comment     ON community_reports (comment_id);
        CREATE INDEX idx_community_reports_reported_by ON community_reports (reported_by);
    ",

    'down' => "
        DROP INDEX IF EXISTS idx_community_reports_status;
        DROP INDEX IF EXISTS idx_community_reports_post;
        DROP INDEX IF EXISTS idx_community_reports_comment;
        DROP INDEX IF EXISTS idx_community_reports_reported_by;
        DROP TABLE IF EXISTS community_reports;
        DROP INDEX IF EXISTS idx_community_likes_unique;
        DROP INDEX IF EXISTS idx_community_likes_post;
        DROP TABLE IF EXISTS community_likes;
        DROP INDEX IF EXISTS idx_community_comments_post;
        DROP INDEX IF EXISTS idx_community_comments_user;
        DROP INDEX IF EXISTS idx_community_comments_created;
        DROP TABLE IF EXISTS community_comments;
        DROP INDEX IF EXISTS idx_community_posts_user;
        DROP INDEX IF EXISTS idx_community_posts_book;
        DROP INDEX IF EXISTS idx_community_posts_status;
        DROP INDEX IF EXISTS idx_community_posts_created;
        DROP TABLE IF EXISTS community_posts;
    ",
];
