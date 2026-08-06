<?php

declare(strict_types=1);

/**
 * Migration: email_queue — Phase 9.5 (Email Notifications)
 *
 * Purpose: the OUTBOX of the email module (the queue-ready
 * requirement: generation is separated from delivery - the request
 * that triggers a notification only WRITES a pending row; a worker
 * call (EmailNotificationService::processQueue) delivers it later).
 *
 *     id          -> primary key
 *     user_id     -> the recipient (FK users.id, ON DELETE CASCADE)
 *     type        -> the email type key (EmailType::* constants)
 *     to_address  -> the validated recipient address (snapshot)
 *     to_name     -> the recipient's display name (snapshot)
 *     subject     -> the pre-rendered subject line
 *     html        -> the pre-rendered HTML body (generation already
 *                    happened at write time)
 *     dedupe_key  -> the same event hash as email_logs (queued rows
 *                    carry it so the log insert stays unique)
 *     status      -> pending | sent | failed
 *     attempts    -> how many delivery attempts happened (retry bookkeeping)
 *     error       -> the last failure detail
 *     created_at  -> when the row was queued
 *     sent_at     -> when delivery succeeded
 *
 * The (status, created_at) index covers the worker's "oldest pending
 * first" read.
 */

return [
    'up' => "
        CREATE TABLE email_queue (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL,
            type       TEXT    NOT NULL,
            to_address TEXT    NOT NULL,
            to_name    TEXT    NOT NULL DEFAULT '',
            subject    TEXT    NOT NULL,
            html       TEXT    NOT NULL,
            dedupe_key TEXT,
            status     TEXT    NOT NULL DEFAULT 'pending',
            attempts   INTEGER NOT NULL DEFAULT 0,
            error      TEXT,
            created_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            sent_at    TEXT,
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CHECK (status IN ('pending', 'sent', 'failed'))
        );

        CREATE INDEX idx_email_queue_status_created ON email_queue (status, created_at);
    ",
    'down' => "
        DROP INDEX IF EXISTS idx_email_queue_status_created;
        DROP TABLE IF EXISTS email_queue;
    ",
];