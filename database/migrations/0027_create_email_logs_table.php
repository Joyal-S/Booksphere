<?php

declare(strict_types=1);

/**
 * Migration: email_logs — Phase 9.5 (Email Notifications)
 *
 * Purpose: the audit trail of every email the module attempted to
 * send (the Phase 9.5 REQUIREMENTS: success, failure, skip and queued
 * messages are all recorded, and failures are logged - never exposed
 * to end-users). One row per attempt.
 *
 *     id         -> primary key
 *     user_id    -> the recipient (FK users.id, ON DELETE CASCADE)
 *     type       -> the email type key (EmailType::* constants)
 *     dedupe_key -> the hash of the exact event, see below
 *     to_address -> the validated recipient address (snapshot - the
 *                   user may change it later)
 *     subject    -> the subject line that was built (snapshot)
 *     status     -> sent | failed | skipped | queued
 *     error      -> the transport error for failures (never displayed)
 *     created_at -> when the attempt happened
 *
 * The UNIQUE(user_id, type, dedupe_key) index is the "prevent
 * duplicate sends" rule: dispatch() computes dedupe_key from the
 * notification type + its context (+ user), so the SAME event can only
 * ever produce one emailed attempt - whatever path re-fires it (a
 * retry, a double dispatch, a re-run of a queue worker) is dropped by
 * the ON CONFLICT DO NOTHING insert.
 */

return [
    'up' => "
        CREATE TABLE email_logs (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL,
            type       TEXT    NOT NULL,
            dedupe_key TEXT,
            to_address TEXT    NOT NULL,
            subject    TEXT    NOT NULL,
            status     TEXT    NOT NULL DEFAULT 'sent',
            error      TEXT,
            created_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CHECK (status IN ('sent', 'failed', 'skipped', 'queued'))
        );

        CREATE UNIQUE INDEX idx_email_logs_dedupe ON email_logs (user_id, type, dedupe_key);
        CREATE INDEX idx_email_logs_user_created ON email_logs (user_id, created_at);
    ",
    'down' => "
        DROP INDEX IF EXISTS idx_email_logs_user_created;
        DROP INDEX IF EXISTS idx_email_logs_dedupe;
        DROP TABLE IF EXISTS email_logs;
    ",
];