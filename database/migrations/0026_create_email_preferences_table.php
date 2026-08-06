<?php

declare(strict_types=1);

/**
 * Migration: email_preferences — Phase 9.5 (Email Notifications)
 *
 * Purpose: the per-user, per-category opt-outs of the EMAIL channel -
 * a user can silence individual email subjects without touching their
 * in-app notification preferences (migration 0024). One row per user,
 * upserted with the standard INSERT ... ON CONFLICT (user_id) DO
 * UPDATE pattern, exactly like the notification preferences.
 *
 *     follow          -> a follow confirmation + new releases of a
 *                         followed author
 *     review          -> someone found the user's review helpful
 *     reply           -> someone replied to the user's review
 *     recommendations -> "your picks are ready" refresh pings
 *     newsletter      -> periodic digests (reserved, a later phase)
 *
 * Every toggle is 0/1, CHECK constrained, default 1 (opt-out model:
 * everything is on until the user silences a subject). Transactional
 * emails with no opt-out (password reset, verification, welcome)
 * never consult this table.
 */

return [
    'up' => "
        CREATE TABLE email_preferences (
            user_id        INTEGER PRIMARY KEY,
            follow         INTEGER NOT NULL DEFAULT 1 CHECK (follow IN (0, 1)),
            review         INTEGER NOT NULL DEFAULT 1 CHECK (review IN (0, 1)),
            reply          INTEGER NOT NULL DEFAULT 1 CHECK (reply IN (0, 1)),
            recommendations INTEGER NOT NULL DEFAULT 1 CHECK (recommendations IN (0, 1)),
            newsletter     INTEGER NOT NULL DEFAULT 1 CHECK (newsletter IN (0, 1)),
            updated_at     TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        );
    ",
    'down' => 'DROP TABLE IF EXISTS email_preferences',
];