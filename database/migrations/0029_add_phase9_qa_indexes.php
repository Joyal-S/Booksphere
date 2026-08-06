<?php

declare(strict_types=1);

/**
 * Migration: Phase 9.6 QA schema hardening
 *
 * The Phase 9.6 verification audit found three database-level issues
 * that this migration closes:
 *
 * 1. NOTIFICATIONS PAGINATION COVERAGE. The center's hot reads are
 *    per-user history queries:
 *
 *        SELECT ... FROM notifications
 *        WHERE user_id = ? [AND is_read = ?]
 *        ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?
 *
 *    Of the three indexes 0023 created, (user_id) and (created_at)
 *    do NOT cover those (they filter by user and sort by created_at),
 *    so SQLite would fall back to a temp B-tree sort on every page.
 *    This migration adds the two COVERING indexes the reads actually
 *    need and drops the now-redundant (user_id) index (a leftmost
 *    prefix of (user_id, is_read) and of the new (user_id,
 *    created_at)); the bare (created_at) index stays for the global
 *    prune() sweep.
 *
 * 2. EMAIL QUEUE DEDUPE. email_queue had no uniqueness, so in queue
 *    mode (EMAIL_QUEUE_ENABLED=true) a re-fired event inserted a
 *    SECOND pending row and the worker sent the email twice - the
 *    UNIQUE(user_id, type, dedupe_key) rule only protected
 *    email_logs. The new unique index mirrors the email_logs one and
 *    EmailQueueRepository::enqueue() now inserts ON CONFLICT DO
 *    NOTHING as the last line of defence behind the service gate.
 *    (The queue rows always carry a dedupe_key, so the NULL-distinct
 *    caveat of SQLite UNIQUE indexes does not apply here.)
 *
 * 3. email_queue.user_id INDEX. Every other Phase 9 FK column is
 *    indexed; this one was bare, making the users CASCADE a full
 *    table scan on user deletion.
 */
return [
    'up' => "CREATE INDEX idx_notifications_user_created
            ON notifications (user_id, created_at);

            CREATE INDEX idx_notifications_user_read_created
            ON notifications (user_id, is_read, created_at);

            DROP INDEX idx_notifications_user;

            CREATE UNIQUE INDEX idx_email_queue_dedupe
            ON email_queue (user_id, type, dedupe_key);

            CREATE INDEX idx_email_queue_user
            ON email_queue (user_id);",

    'down' => "DROP INDEX idx_email_queue_user;

            DROP INDEX idx_email_queue_dedupe;

            CREATE INDEX idx_notifications_user
            ON notifications (user_id);

            DROP INDEX idx_notifications_user_read_created;

            DROP INDEX idx_notifications_user_created;",
];