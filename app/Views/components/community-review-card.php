<?php

declare(strict_types=1);

/**
 * components/community-review-card.php
 *
 * The COMMUNITY REVIEW CARD (Phase 7.6): a horizontal review row
 * for community lists - the reviewer's initial avatar, the review
 * title, the stars, a short excerpt, the book, the helpful-vote
 * count and the time - used by the author page's "Recent community
 * reviews" and the community activity widget.
 *
 * Included from a view that sets $review first (a row of the shared
 * review SELECT, so user_name, book_title and helpful_count travel
 * with it):
 *
 *     $review = [
 *         'id'            => 12,
 *         'title'         => 'A great read',
 *         'review'        => 'The body text...',
 *         'rating'        => 5,
 *         'user_name'     => 'Riya Sharma',
 *         'book_title'    => 'The Martian',
 *         'book_id'       => 3,       // optional
 *         'helpful_count' => 7,
 *         'created_at'    => '2025-...',
 *     ];
 */

$review = array_merge([
    'id'            => 0,
    'title'         => '',
    'review'        => '',
    'rating'        => 0,
    'user_name'     => '',
    'book_title'    => '',
    'book_id'       => 0,
    'helpful_count' => 0,
    'created_at'    => null,
], $review ?? []);

$initial = strtoupper(substr((string) ($review['user_name'] ?? ''), 0, 1) ?: '?');
$excerpt = mb_strlen((string) $review['review']) > 160
    ? mb_substr((string) $review['review'], 0, 157) . '…'
    : (string) $review['review'];

?>
<div class="community-review-card d-flex gap-3 align-items-start">
    <span class="avatar-initial" aria-hidden="true"><?= e($initial) ?></span>
    <div class="flex-grow-1 min-w-0">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <?php if ((int) $review['rating'] > 0): ?>
                <?php $starRating = [
                    'rating'  => (int) $review['rating'],
                    'count'   => null,
                    'size'    => 'sm',
                    'tooltip' => false,
                ]; ?>
                <?php require root_path('app/Views/components/star-rating.php'); ?>
            <?php endif; ?>
            <a class="fw-semibold text-decoration-none" href="/reviews/<?= (int) $review['id'] ?>">
                <?= e((string) ($review['title'] !== '' ? $review['title'] : 'Review #' . (int) $review['id'])) ?>
            </a>
        </div>
        <?php if ($excerpt !== ''): ?>
            <p class="mb-1 small text-muted"><?= e($excerpt) ?></p>
        <?php endif; ?>
        <p class="mb-0 small text-muted">
            by <span class="fw-semibold text-body"><?= e((string) $review['user_name']) ?></span>
            on
            <a class="text-decoration-none" href="/books/<?= (int) $review['book_id'] ?>"><?= e((string) $review['book_title']) ?></a>
            &middot; <?= e(format_review_date((string) $review['created_at'])) ?>
        </p>
    </div>
    <?php if ((int) $review['helpful_count'] > 0): ?>
        <span class="badge text-bg-light border" title="Helpful votes">
            <i class="fa-regular fa-thumbs-up me-1" aria-hidden="true"></i><?= (int) $review['helpful_count'] ?>
        </span>
    <?php endif; ?>
</div>
