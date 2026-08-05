<?php

declare(strict_types=1);

/**
 * Migration: notifications — Phase 9.2 (Notification System)
 *
 * Purpose: the persistent in-app notification history (the Phase 9.1
 * architecture blueprint, Task 1). One row per notification a user
 * receives; the content is stored FORMATTED at write time (title,
 * message, icon, color, action_url) so rendering never joins the
 * author or book tables and cannot break when a source row is later
 * edited or deleted - history stays truthful.
 *
 *     id          -> primary key
 *     user_id     -> the recipient (FK users.id, ON DELETE CASCADE)
 *     type        -> the catalog key (Task 5 of the blueprint),
 *                    CHECK-constrained to the full list so corrupt
 *                    rows are impossible - the catalog is the single
 *                    source of truth in NotificationService::types()
 *     title       -> the short line produced by the formatter
 *     message     -> the expanded copy (may embed another user's
 *                    full_name - always e() at render time)
 *     icon        -> a Font Awesome 6.5.2 class, e.g. fa-solid fa-user-plus
 *     color       -> the app's accent token (primary | info |
 *                    success | warning | danger) mapped to a CSS
 *                    class by the view
 *     action_url  -> the relative path the row opens (/books/7);
 *                    NULL = no jump
 *     is_read     -> boolean (0/1, CHECK constrained)
 *     read_at     -> NULL until marked read
 *     created_at  -> UTC ISO-8601 stamp
 *
 * Rows are immutable after insert except the read flag (read_at
 * covers that), so there is deliberately NO updated_at column.
 *
 * Indexes:
 *     (user_id)              -> every per-user read
 *     (user_id, is_read)     -> the unread tab, the unread count and
 *                               the badge - one covering index
 *     (created_at)           -> history pagination and the reserved
 *                               prune sweep
 */

return [
    'up' => "
        CREATE TABLE notifications (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL,
            type       TEXT    NOT NULL,
            title      TEXT    NOT NULL,
            message    TEXT    NOT NULL DEFAULT '',
            icon       TEXT    NOT NULL,
            color      TEXT    NOT NULL,
            action_url TEXT,
            is_read    INTEGER NOT NULL DEFAULT 0 CHECK (is_read IN (0, 1)),
            read_at    TEXT,
            created_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CHECK (type IN (
                'author_followed',
                'author_new_release',
                'review_reacted',
                'review_replied',
                'recommendation_ready',
                'wishlist_reminder',
                'library_milestone',
                'system_announcement',
                'admin_alert',
                'account_notice'
            ))
        );

        CREATE INDEX idx_notifications_user      ON notifications (user_id);
        CREATE INDEX idx_notifications_user_read ON notifications (user_id, is_read);
        CREATE INDEX idx_notifications_created   ON notifications (created_at);
    ",
    'down' => "
        DROP INDEX IF EXISTS idx_notifications_user;
        DROP INDEX IF EXISTS idx_notifications_user_read;
        DROP INDEX IF EXISTS idx_notifications_created;
        DROP TABLE IF EXISTS notifications;
    ",
];
