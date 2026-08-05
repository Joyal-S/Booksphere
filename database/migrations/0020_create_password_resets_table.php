<?php

declare(strict_types=1);

/**
 * Migration: password_resets
 *
 * Purpose: single-use password reset tokens issued by the
 * Forgot Password flow. The raw token is shown to the user on the
 * success screen in demo mode (there is no mailer yet); whatever the
 * delivery channel, only the SHA-256 hash is ever stored, so a
 * leaked database can never be replayed into an account.
 *
 * Columns:
 *     id         -> primary key
 *     user_id    -> the account the token belongs to (FK users.id,
 *                   ON DELETE CASCADE)
 *     token_hash -> sha256() of the raw token, UNIQUE so the same
 *                   token can never be redeemed twice
 *     expires_at -> UTC timestamp; tokens older than this are dead
 *     used_at    -> UTC timestamp set when the token is redeemed;
 *                   NULL until then (single-use enforcement)
 *     created_at -> when the token was issued (UTC)
 *
 * Design notes:
 *     - A user may only hold one outstanding token: the controller
 *       deletes the previous rows before issuing a new one.
 *     - idx_password_resets_token_hash serves the lookup of a
 *       presented token (the only hot query against this table).
 */

return [
    'up' => "
        CREATE TABLE password_resets (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL,
            token_hash TEXT    NOT NULL UNIQUE,
            expires_at TEXT    NOT NULL,
            used_at    TEXT    NULL,
            created_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        );

        CREATE INDEX idx_password_resets_token_hash ON password_resets (token_hash);
    ",
    'down' => 'DROP TABLE password_resets',
];
