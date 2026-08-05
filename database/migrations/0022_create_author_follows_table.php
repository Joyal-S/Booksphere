<?php

declare(strict_types=1);

/**
 * Migration: author_follows — Phase 9.2 (Follow Authors)
 *
 * Purpose: the follow relationship - one row per "user X follows
 * author Y" - the core table of the Follow Authors module (the
 * Phase 9.1 architecture blueprint, Task 1).
 *
 *     id         -> primary key
 *     user_id    -> the follower (FK users.id, ON DELETE CASCADE)
 *     author_id  -> the followed author (FK authors.id, ON DELETE
 *                   CASCADE - authors have no soft-delete column, so
 *                   a removed author cleans its follow rows here)
 *     created_at -> UTC ISO-8601 stamp (strftime pattern the whole
 *                   project uses)
 *
 * Design notes:
 *     - UNIQUE (user_id, author_id): a user can follow an author
 *       ONCE - the duplicate-prevention rule at the database level
 *       (order-independent in SQLite). Two simultaneous POSTs race
 *       past the service guard only to be stopped here; the service
 *       translates the constraint violation into
 *       FollowException::duplicateFollow().
 *     - ON DELETE CASCADE on both foreign keys: no orphan rows,
 *       ever (users are hard-deleted by the auth module too).
 *     - "You cannot follow yourself" cannot be a CHECK across two
 *       tables, so it is a service rule (FollowService::follow()).
 *     - Two supporting indexes: user_id ("who does this user
 *       follow") and author_id ("who follows this author" - the
 *       follower list and the COUNT(*) follower statistic). The
 *       UNIQUE index doubles as a covering index for the
 *       user-prefixed pair lookups.
 */

return [
    'up' => "
        CREATE TABLE author_follows (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL,
            author_id  INTEGER NOT NULL,
            created_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            FOREIGN KEY (user_id)   REFERENCES users   (id) ON DELETE CASCADE,
            FOREIGN KEY (author_id) REFERENCES authors (id) ON DELETE CASCADE,
            UNIQUE (user_id, author_id)
        );

        CREATE INDEX idx_author_follows_user   ON author_follows (user_id);
        CREATE INDEX idx_author_follows_author ON author_follows (author_id);
    ",
    'down' => "
        DROP INDEX IF EXISTS idx_author_follows_user;
        DROP INDEX IF EXISTS idx_author_follows_author;
        DROP TABLE IF EXISTS author_follows;
    ",
];
