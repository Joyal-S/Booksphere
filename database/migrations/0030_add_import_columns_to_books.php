<?php

declare(strict_types=1);

/**
 * Migration: add import columns to books
 *
 * Purpose: Phase 10.3 - the Google Books importer stores the PROVIDER's
 * own metadata next to the app-managed columns, without ever touching
 * the review-driven rating fields:
 *
 *     - preview_link          the Google Books preview/detail link
 *     - provider_rating       the PROVIDER's average rating (0-5),
 *                             separate from books.average_rating, which
 *                             the ReviewService derives from the app's
 *                             own reviews (writing Google's number into
 *                             average_rating would corrupt the
 *                             recommendation scoring)
 *     - provider_ratings_count the PROVIDER's rating count (same logic)
 *
 * google_book_id and isbn already exist (migration 0002). The remote
 * cover is stored in the existing cover_image column ("local path or
 * remote URL"), so no cover column is needed here.
 *
 * Design note: provider_rating / provider_ratings_count stay NULL until
 * a book has been imported - locally created books simply have no
 * provider record, which is the truthful state.
 */

return [
    'up' => "
        ALTER TABLE books ADD COLUMN preview_link TEXT;
        ALTER TABLE books ADD COLUMN provider_rating REAL;
        ALTER TABLE books ADD COLUMN provider_ratings_count INTEGER;
    ",
    'down' => "
        ALTER TABLE books DROP COLUMN provider_ratings_count;
        ALTER TABLE books DROP COLUMN provider_rating;
        ALTER TABLE books DROP COLUMN preview_link;
    ",
];
