<?php

declare(strict_types=1);

/**
 * Migration: add cover cache columns to books
 *
 * Purpose: Phase 10.4 - the cover downloader stores WHERE a book's
 * cover came from and WHEN it was cached, so the app never has to
 * ask Google again for a book that already has a local cover:
 *
 *     - cover_source_url     the EXACT provider URL the cover was
 *                            downloaded from (the pre-optimization
 *                            source; cover_image holds the final
 *                            local path once the download succeeded)
 *     - cover_downloaded_at  UTC timestamp of the successful cache
 *                            (drives the TTL/expiration checks)
 *     - cover_status         'downloaded' | 'failed' | 'none'
 *                            - downloaded: a valid optimized copy is
 *                              stored locally and cover_image points
 *                              at it
 *                            - failed:     a download was attempted
 *                              and did not produce a usable image -
 *                              cover_image is cleared so every view
 *                              shows the BookSphere placeholder (a
 *                              broken remote URL is never served)
 *                            - none:       the provider record had
 *                              no cover to begin with
 *
 * Design note: the local path itself lives in the existing
 * cover_image column ("local path or remote URL" - migration 0002's
 * contract), so no duplicate "local path" column is added. The three
 * new columns stay NULL for books that predate Phase 10.4 (seeded
 * OpenLibrary covers, pre-10.4 imports) - the truthful state is
 * "this book was never processed by the cover pipeline".
 */

return [
    'up' => "
        ALTER TABLE books ADD COLUMN cover_source_url TEXT;
        ALTER TABLE books ADD COLUMN cover_downloaded_at TEXT;
        ALTER TABLE books ADD COLUMN cover_status TEXT;
    ",
    'down' => "
        ALTER TABLE books DROP COLUMN cover_status;
        ALTER TABLE books DROP COLUMN cover_downloaded_at;
        ALTER TABLE books DROP COLUMN cover_source_url;
    ",
];
