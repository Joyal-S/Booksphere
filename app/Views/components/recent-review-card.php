<?php

declare(strict_types=1);

/**
 * components/recent-review-card.php
 *
 * The RECENT REVIEW CARD (Phase 7.6): a compact, book-focused
 * review row for the "Recently Reviewed" shelf of the category page
 * and the dashboard - the cover thumbnail, the book title, the
 * stars, who reviewed it and when.
 *
 * This is the book-facing cousin of community-review-card (which is
 * reviewer-facing and carries the helpful-vote badge): recent-review
 * cards lead with the BOOK, so a reader scanning "what did people
 * review lately?" can see the title first.
 *
 * Included from a view that sets $review first (a row of the shared
 * review SELECT):
 *
 *     $review = [
 *         'id'         => 12,
 *         'rating'     => 5,
 *         'user_name'  => 'Riya Sharma',
 *         'book_title' => 'The Martian',
 *         'book_id'    => 3,
 *         'cover_image'=> 'https://...', // optional
 *         'created_at' => '2025-...',
 *     ];
 */

$review = array_merge([
    'id'          => 0,
    'rating'      => 0,
    'user_name'   => '',
    'book_title'  => '',
    'book_id'     => 0,
    'cover_image' => null,
    'created_at'  => null,
], $review ?? []);

?>
<div class="recent-review-card d-flex align-items-center gap-3">
    <a href="/books/<?= (int) $review['book_id'] ?>" class="text-decoration-none flex-shrink-0">
        <?php if (!empty($review['cover_image'])): ?>
            <img src="<?= e($review['cover_image']) ?>" alt="<?= e($review['book_title']) ?> cover"
                 class="recent-review-cover" loading="lazy"
                 onerror="this.onerror=null;this.src='/assets/images/cover-placeholder.svg';">
        <?php else: ?>
            <img src="/assets/images/cover-placeholder.svg" alt="<?= e($review['book_title']) ?> cover"
                 class="recent-review-cover" loading="lazy">
        <?php endif; ?>
    </a>
    <div class="flex-grow-1 min-w-0">
        <h4 class="mb-1">
            <a href="/books/<?= (int) $review['book_id'] ?>" class="text-decoration-none stretched-link">
                <?= e($review['book_title']) ?>
            </a>
        </h4>
        <?php if ((int) $review['rating'] > 0): ?>
            <?php $starRating = [
                'rating'  => (int) $review['rating'],
                'count'   => null,
                'size'    => 'sm',
                'tooltip' => false,
            ]; ?>
            <?php require root_path('app/Views/components/star-rating.php'); ?>
        <?php endif; ?>
        <p class="mb-0 small text-muted">
            <a class="text-decoration-none" href="/reviews/<?= (int) $review['id'] ?>">Read the review</a>
            by <?= e($review['user_name']) ?>
            &middot; <?= e(format_review_date((string) $review['created_at'])) ?>
        </p>
    </div>
</div>
