<?php

declare(strict_types=1);

/**
 * Migration: user_preferences
 *
 * Purpose: the per-user presentation preferences of the library
 * dashboard (Phase 8.3) - one row per user holding the two settings
 * the dashboard remembers between visits:
 *
 *     library_sort  -> the active sort of the book grid
 *                      (allowlist owned by LibraryService::SORTS,
 *                      default 'newest_added')
 *     library_view  -> the grid / list arrangement of the book grid
 *                      (CHECK constrained here to the two values the
 *                      UI offers, default 'grid')
 *
 * Design notes:
 *     - user_id is the PRIMARY KEY - a user has exactly one
 *       preferences row (the UPSERT in the repository keeps it so).
 *     - ON DELETE CASCADE removes the row when the user is deleted.
 *     - library_sort deliberately has NO CHECK constraint: sorting
 *       is an open-ended list owned by the service, and the SQL
 *       layer cannot know which keys are valid. library_view is
 *       closed (two values) so it is CHECK constrained - the last
 *       line of defence behind the service's viewPreference().
 */

return [
    'up' => "
        CREATE TABLE user_preferences (
            user_id      INTEGER PRIMARY KEY,
            library_sort TEXT    NOT NULL DEFAULT 'newest_added',
            library_view TEXT    NOT NULL DEFAULT 'grid'
                             CHECK (library_view IN ('grid', 'list')),
            updated_at   TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        );
    ",
    'down' => 'DROP TABLE user_preferences',
];