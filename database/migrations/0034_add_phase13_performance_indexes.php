<?php

declare(strict_types=1);

/**
 * Migration: 0034_add_phase13_performance_indexes
 *
 * Purpose: Add composite index for published book rating sorting to
 * eliminate temporary B-tree sorting in SQLite query execution.
 */

return [
    'up' => "
        CREATE INDEX IF NOT EXISTS idx_books_status_rating
            ON books (status, deleted_at, average_rating DESC, id DESC);
    ",
    'down' => "
        DROP INDEX IF EXISTS idx_books_status_rating;
    ",
];
