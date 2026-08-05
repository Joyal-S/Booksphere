<?php

declare(strict_types=1);

/**
 * Migration: notification_preferences — Phase 9.2 (Notification System)
 *
 * Purpose: the per-user, per-category opt-outs of the notification
 * module (the Phase 9.1 architecture blueprint, Task 1). One row per
 * user, upserted with the standard INSERT ... ON CONFLICT (user_id)
 * DO UPDATE pattern - exactly like the library preferences
 * (migration 0018).
 *
 *     author_followed      -> the user's own confirmation ping
 *                             ("You started following X")
 *     author_activity      -> new book / new review bumps of a
 *                             followed author
 *     community            -> "helpful" / reply on your review
 *     recommendations      -> "your shelf refreshed" ping
 *     wishlist_reminders   -> wishlist nudges (a later phase)
 *     system_announcements -> admin broadcasts
 *
 * Every toggle is 0/1, CHECK constrained, default 1 (opt-out model:
 * everything is on until the user silences a category).
 *
 * Rule (blueprint Task 1): a preference only gates AUTO-GENERATED
 * notifications (the bulk fan-out). Explicit transactional rows
 * (system_announcement) still deliver.
 */

return [
    'up' => "
        CREATE TABLE notification_preferences (
            user_id              INTEGER PRIMARY KEY,
            author_followed      INTEGER NOT NULL DEFAULT 1 CHECK (author_followed IN (0, 1)),
            author_activity      INTEGER NOT NULL DEFAULT 1 CHECK (author_activity IN (0, 1)),
            community            INTEGER NOT NULL DEFAULT 1 CHECK (community IN (0, 1)),
            recommendations      INTEGER NOT NULL DEFAULT 1 CHECK (recommendations IN (0, 1)),
            wishlist_reminders   INTEGER NOT NULL DEFAULT 1 CHECK (wishlist_reminders IN (0, 1)),
            system_announcements INTEGER NOT NULL DEFAULT 1 CHECK (system_announcements IN (0, 1)),
            updated_at           TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        );
    ",
    'down' => 'DROP TABLE IF EXISTS notification_preferences',
];
