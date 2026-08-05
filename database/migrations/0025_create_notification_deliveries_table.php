<?php

declare(strict_types=1);

/**
 * Migration: notification_deliveries — Phase 9.2 (Notification System)
 *
 * Purpose: the reserved CHANNEL OUTBOX of the notification module
 * (the Phase 9.1 architecture blueprint, Task 1). One row per
 * notification per delivery channel; shipping with ZERO rows so the
 * email and push phases of a later sprint are purely additive - the
 * module's own tables never get ALTERed.
 *
 *     id               -> primary key
 *     notification_id  -> the notification being delivered (FK
 *                         notifications.id, ON DELETE CASCADE)
 *     user_id          -> the recipient (FK users.id, ON DELETE CASCADE)
 *     channel          -> email | push | in_app
 *     status           -> pending | sent | failed
 *     sent_at          -> NULL until the channel actually sent it
 *     error            -> retry / failure note
 *
 * The dispatcher's outbox hook is a no-op in 9.2 (the table stays
 * empty); the indexes are created now so the queue reads of the
 * later phase are already covered.
 */

return [
    'up' => "
        CREATE TABLE notification_deliveries (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            notification_id INTEGER NOT NULL,
            user_id         INTEGER NOT NULL,
            channel         TEXT    NOT NULL,
            status          TEXT    NOT NULL DEFAULT 'pending',
            sent_at         TEXT,
            error           TEXT,
            FOREIGN KEY (notification_id) REFERENCES notifications (id) ON DELETE CASCADE,
            FOREIGN KEY (user_id)         REFERENCES users         (id) ON DELETE CASCADE,
            CHECK (channel IN ('email', 'push', 'in_app')),
            CHECK (status IN ('pending', 'sent', 'failed'))
        );

        CREATE INDEX idx_notification_deliveries_notification ON notification_deliveries (notification_id);
        CREATE INDEX idx_notification_deliveries_user_status  ON notification_deliveries (user_id, status);
    ",
    'down' => "
        DROP INDEX IF EXISTS idx_notification_deliveries_notification;
        DROP INDEX IF EXISTS idx_notification_deliveries_user_status;
        DROP TABLE IF EXISTS notification_deliveries;
    ",
];
