<?php

declare(strict_types=1);

/**
 * Migration: books - status and soft delete
 *
 * Purpose: extend the catalogue with the two columns that the
 * book management phase needs:
 *
 *     - status     -> the publication state of a book. An admin
 *                     creates a book as "draft", publishes it when
 *                     it is ready, and archives it to take it out
 *                     of the active catalogue without deleting it.
 *     - deleted_at -> soft delete timestamp. NULL means the book
 *                     is active; a timestamp means the book was
 *                     "deleted". Soft delete keeps the row (and its
 *                     reviews, wishlist entries, recommendations)
 *                     recoverable and avoids destroying the history.
 *
 * Design notes:
 *     - ALTER TABLE ... ADD COLUMN with a NOT NULL DEFAULT fills
 *       the existing 20 seeded books with 'published' so the whole
 *       catalogue stays visible without a data migration.
 *     - Indexes are added for the two filters the management list
 *       uses most: filtering by status and hiding deleted rows.
 *
 * Why this is an incremental migration instead of editing 0002:
 *     - 0002 has already run against every developer's database.
 *       Rewriting history would leave those databases inconsistent
 *       with the migration table; a new numbered migration is the
 *       safe, forward-only way to evolve the schema.
 */

return [
    'up' => "
        ALTER TABLE books ADD COLUMN status     TEXT NOT NULL DEFAULT 'published';
        ALTER TABLE books ADD COLUMN deleted_at TEXT;

        CREATE INDEX idx_books_status     ON books (status);
        CREATE INDEX idx_books_deleted_at ON books (deleted_at);
    ",
    'down' => "
        DROP INDEX IF EXISTS idx_books_status;
        DROP INDEX IF EXISTS idx_books_deleted_at;

        ALTER TABLE books DROP COLUMN status;
        ALTER TABLE books DROP COLUMN deleted_at;
    ",
];
