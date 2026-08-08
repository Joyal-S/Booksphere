<?php

declare(strict_types=1);

/**
 * Migration: add synchronization columns to books
 *
 * Purpose: Phase 10.6 - the Google Books synchronizer records WHEN a
 * book's provider metadata was last synchronized and WHAT the outcome
 * of that run was, so the admin can see the freshness of an imported
 * book at a glance:
 *
 *     - synced_at     UTC timestamp of the LAST sync attempt (the
 *                     run time, whatever the outcome - it always means
 *                     "checked against the provider at this moment")
 *     - sync_status   'pending' | 'in_sync' | 'updated' | 'failed'
 *                     - pending: never synchronized yet (the state of
 *                       every pre-10.6 imported book, and the column
 *                       default)
 *                     - in_sync: the last run compared local vs remote
 *                       and found nothing to change
 *                     - updated: the last run wrote at least one
 *                       metadata field
 *                     - failed:  the last run could not reach/apply
 *                       the provider record (the error text is kept
 *                       in sync_message)
 *     - sync_message  short human note of the last run ("2 metadata
 *                     fields updated", "the provider was unreachable",
 *                     ...)
 *
 * Design note: these columns only ever change through the sync
 * path (GoogleBooksSyncService). The admin book form never exposes
 * them - the generic BookService::update() path stays with the
 * catalogue form columns, exactly like the Phase 10.4 cover-cache
 * columns before it.
 */

return [
    'up' => "
        ALTER TABLE books ADD COLUMN synced_at TEXT;
        ALTER TABLE books ADD COLUMN sync_status TEXT NOT NULL DEFAULT 'pending';
        ALTER TABLE books ADD COLUMN sync_message TEXT;
    ",
    'down' => "
        ALTER TABLE books DROP COLUMN sync_message;
        ALTER TABLE books DROP COLUMN sync_status;
        ALTER TABLE books DROP COLUMN synced_at;
    ",
];