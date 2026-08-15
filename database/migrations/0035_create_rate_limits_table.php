<?php

declare(strict_types=1);

/**
 * Migration: 0035_create_rate_limits_table
 *
 * Purpose: Provide persistent, database-backed rate limiting for Phase 13.4.
 * Protects critical endpoints (login, password reset, search, reviews) against
 * session-clearing and session-rotation bypass attacks.
 */

return [
    'up' => "
        CREATE TABLE IF NOT EXISTS rate_limits (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            key          TEXT NOT NULL,
            action       TEXT NOT NULL,
            attempts     INTEGER NOT NULL DEFAULT 1,
            starts_at    INTEGER NOT NULL,
            expires_at   INTEGER NOT NULL,
            created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
        );

        CREATE UNIQUE INDEX IF NOT EXISTS idx_rate_limits_key_action
            ON rate_limits (key, action);

        CREATE INDEX IF NOT EXISTS idx_rate_limits_expires
            ON rate_limits (expires_at);
    ",
    'down' => "
        DROP INDEX IF EXISTS idx_rate_limits_expires;
        DROP INDEX IF EXISTS idx_rate_limits_key_action;
        DROP TABLE IF EXISTS rate_limits;
    ",
];
