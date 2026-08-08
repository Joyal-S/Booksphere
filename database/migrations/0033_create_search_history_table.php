<?php

declare(strict_types=1);

/**
 * Migration: create the search_history table
 *
 * Purpose: Phase 11.5 - one authenticated user's private search
 * history. Each row is ONE (user, query, scope, filters) key:
 *
 *     - id            primary key
 *     - user_id       owner (FK -> users, cascade on delete)
 *     - query         the normalized search term
 *     - scope         the search scope ('books', 'authors', ...)
 *     - filters       the active books-scope filters as JSON ({} when
 *                     none) - the whitelisted map of SearchQueryRequest,
 *                     stored so a past search can be restored exactly
 *     - created_at    when the search was FIRST run
 *     - last_used_at  when it was MOST RECENTLY run (re-running the
 *                     same search updates this and bumps count)
 *     - count         how many times this exact search has run
 *
 * Deduplication: the UNIQUE index on (user_id, query, scope, filters)
 * turns "duplicate consecutive searches" (and any repeated search)
 * into an UPSERT by design - a re-run updates last_used_at and bumps
 * count instead of inserting a duplicate row. The second index makes
 * "list the newest N rows of one user" (ORDER BY last_used_at DESC)
 * an index-only read.
 */

return [
    'up' => "
        CREATE TABLE IF NOT EXISTS search_history (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id      INTEGER NOT NULL,
            query        TEXT NOT NULL COLLATE NOCASE,
            scope        TEXT NOT NULL DEFAULT 'books',
            filters      TEXT NOT NULL DEFAULT '{}',
            created_at   TEXT NOT NULL,
            last_used_at TEXT NOT NULL,
            count        INTEGER NOT NULL DEFAULT 1,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE UNIQUE INDEX IF NOT EXISTS idx_search_history_user_key
            ON search_history (user_id, query, scope, filters);

        CREATE INDEX IF NOT EXISTS idx_search_history_user_last
            ON search_history (user_id, last_used_at DESC);
    ",
    'down' => "
        DROP INDEX IF EXISTS idx_search_history_user_last;
        DROP INDEX IF EXISTS idx_search_history_user_key;
        DROP TABLE IF EXISTS search_history;
    ",
];