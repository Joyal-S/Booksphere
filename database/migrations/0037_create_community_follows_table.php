<?php

declare(strict_types=1);

/**
 * Migration: 0037_create_community_follows_table.php
 *
 * Purpose: Represents the user-to-user follow relationship in the
 * Community module (Phase C7-B: User Following).
 *
 * Schema:
 *   id           -> Primary key
 *   follower_id  -> User ID of the follower (FK users.id ON DELETE CASCADE)
 *   following_id -> User ID of the followed user (FK users.id ON DELETE CASCADE)
 *   created_at   -> UTC ISO-8601 timestamp
 *
 * Constraints & Indexes:
 *   UNIQUE (follower_id, following_id): Prevents duplicate follows at DB level.
 *   idx_community_follows_follower: Fast lookup for "who does user X follow".
 *   idx_community_follows_following: Fast lookup for "who follows user Y".
 */

return [
    'up' => "
        CREATE TABLE community_follows (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            follower_id  INTEGER NOT NULL,
            following_id INTEGER NOT NULL,
            created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            FOREIGN KEY (follower_id)  REFERENCES users (id) ON DELETE CASCADE,
            FOREIGN KEY (following_id) REFERENCES users (id) ON DELETE CASCADE,
            UNIQUE (follower_id, following_id)
        );

        CREATE INDEX idx_community_follows_follower  ON community_follows (follower_id);
        CREATE INDEX idx_community_follows_following ON community_follows (following_id);
    ",
    'down' => "
        DROP INDEX IF EXISTS idx_community_follows_follower;
        DROP INDEX IF EXISTS idx_community_follows_following;
        DROP TABLE IF EXISTS community_follows;
    ",
];
