<?php

declare(strict_types=1);

/**
 * Migration: recommendation engine - composite indexes
 *
 * Purpose: make the Phase 6.2/6.3 recommendation queries scale when
 * the catalogue and the signal tables grow.
 *
 * What each index serves (and why composite):
 *
 *     - idx_reviews_book_created ON reviews (book_id, created_at)
 *       The engine counts "reviews of this book" twice per book row:
 *       the all-time count (REVIEW_COUNT_SQL) and the 30-day windowed
 *       count (RECENT_REVIEW_COUNT_SQL, "created_at >= ?"). The old
 *       idx_reviews_book (book_id) serves the equality half of both;
 *       adding created_at lets SQLite answer the windowed count from
 *       the index alone.
 *
 *     - idx_wishlist_book_created ON wishlist (book_id, created_at)
 *       The same argument for the wishlist counts (WISHLIST_COUNT_SQL
 *       and RECENT_WISHLIST_COUNT_SQL).
 *
 *     - idx_book_views_user_viewed ON book_views (user_id, viewed_at)
 *       "Recently viewed" reads the last N views of ONE user
 *       (WHERE user_id = ? ORDER BY viewed_at DESC LIMIT ?). The old
 *       idx_book_views_user (user_id) filters the user but still sorts
 *       every row of that user; the composite index serves both the
 *       filter and the sort.
 *
 *     - idx_books_status_deleted ON books (status, deleted_at)
 *       Every recommendation query narrows the catalogue with the
 *       ACTIVE_WHERE rule (deleted_at IS NULL AND status = ?). The
 *       composite index lets SQLite locate the active slice without
 *       scanning the whole books table.
 *
 * Design note (what deliberately does NOT get an index):
 *     The candidate-pool OR chain of hybridCandidates() matches via
 *     EXISTS against book_categories / book_authors, which are
 *     already served by the two-column indexes of migration 0011
 *     (idx_book_categories_category, idx_book_authors_author). The
 *     ORDER BY of the pool uses the popularity expression, which is
 *     computed - a B-tree cannot index it, but the pool is bounded
 *     by LIMIT after a single pass, so that is fine.
 *
 * Why a new migration instead of editing 0007/0008/0012:
 *     Existing databases have already run those migrations; schema
 *     evolution is forward-only (the same rule as 0011/0010).
 */

return [
    'up' => "
        CREATE INDEX idx_reviews_book_created   ON reviews (book_id, created_at);
        CREATE INDEX idx_wishlist_book_created  ON wishlist (book_id, created_at);
        CREATE INDEX idx_book_views_user_viewed ON book_views (user_id, viewed_at);
        CREATE INDEX idx_books_status_deleted   ON books (status, deleted_at);
    ",
    'down' => "
        DROP INDEX IF EXISTS idx_reviews_book_created;
        DROP INDEX IF EXISTS idx_wishlist_book_created;
        DROP INDEX IF EXISTS idx_book_views_user_viewed;
        DROP INDEX IF EXISTS idx_books_status_deleted;
    ",
];
