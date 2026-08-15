<?php

declare(strict_types=1);

/**
 * components/top-rated-book-card.php
 *
 * The TOP RATED BOOK CARD (Phase 7.6): a ranked book row for the
 * "Top Rated" / "Most Reviewed" / "Community Favourite" shelves of
 * the author and category pages - a rank number, the cover, the
 * title, the average rating and the review count.
 *
 * Included from a view that sets $book and $rank first:
 *
 *     $book = [
 *         'id'          => 3,
 *         'title'       => 'The Martian',
 *         'cover_image' => 'https://...',
 *         'average'     => 4.6,    // the aggregated average
 *         'count'       => 12,     // the aggregated review count
 *     ];
 *     $rank = 1;
 *
 * The aggregated rows (average/count keys) are the ones the Reviews
 * module returns, so this card only ever reads those keys.
 */

$book = array_merge([
    'id'          => 0,
    'title'       => '',
    'cover_image' => null,
    'average'     => 0.0,
    'count'       => 0,
], $book ?? []);

$rank = max(1, (int) ($rank ?? 1));

?>
<div class="top-rated-book-card d-flex align-items-center gap-3">
    <span class="top-rated-rank" aria-hidden="true"><?= $rank ?></span>
    <a href="/books/<?= (int) $book['id'] ?>" class="text-decoration-none flex-shrink-0">
        <?php if (!empty($book['cover_image'])): ?>
            <img src="<?= e($book['cover_image']) ?>" alt="<?= e($book['title']) ?> cover"
                 class="top-rated-cover" loading="lazy"
                 onerror="this.onerror=null;this.src='/assets/images/cover-placeholder.svg';">
        <?php else: ?>
            <img src="/assets/images/cover-placeholder.svg" alt="<?= e($book['title']) ?> cover"
                 class="top-rated-cover" loading="lazy">
        <?php endif; ?>
    </a>
    <div class="flex-grow-1 min-w-0">
        <h4 class="mb-1">
            <a href="/books/<?= (int) $book['id'] ?>" class="text-decoration-none stretched-link">
                <?= e($book['title']) ?>
            </a>
        </h4>
        <?php $starRating = [
            'rating' => (float) $book['average'],
            'count'  => (int) $book['count'] > 0 ? (int) $book['count'] : null,
            'size'   => 'sm',
        ]; ?>
        <?php require root_path('app/Views/components/star-rating.php'); ?>
    </div>
</div>
