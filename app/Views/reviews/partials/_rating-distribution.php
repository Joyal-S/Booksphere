<?php

declare(strict_types=1);

/**
 * reviews/partials/_rating-distribution.php
 *
 * The REUSABLE RATING DISTRIBUTION panel (Phase 7.3): the animated
 * percentage bars of a book's rating breakdown, one row per star
 * (★★★★★ down to ★☆☆☆☆) with the count and the share of reviews.
 * Included by the book detail page and the /books/{id}/reviews
 * page, so the two pages can never drift apart.
 *
 * Available variables (set by the including page):
 *     $breakdown - rows from ReviewService::ratingBreakdown():
 *                  ['stars' => 5..1, 'count' => int,
 *                   'percent' => int, 'total' => int]
 *     $title     - optional: the panel heading ('Rating breakdown'
 *                  by default; null or '' renders no heading for
 *                  pages that carry their own section title)
 *     $empty     - optional: the empty-state message ('No ratings
 *                  yet - be the first to rate this book.' by
 *                  default)
 *
 * Animation: rating.js paints the bars (GSAP, or a plain CSS
 * transition when motion is reduced) the first time the panel
 * scrolls into view - no page reload, no scroll-listener chatter.
 *
 * Accessibility: each bar is a real role="progressbar" with the
 * percent in visible text next to it.
 */

$breakdown = $breakdown ?? [];
$total     = (int) ($breakdown[0]['total'] ?? 0);
$title     = $title ?? 'Rating breakdown';
$empty     = $empty ?? 'No ratings yet - be the first to rate this book.';
?>
<div class="rating-distribution" data-rating-distribution aria-label="Rating distribution">
    <?php if ($title !== null && $title !== ''): ?>
        <h3 class="rating-distribution-title"><?= e($title) ?></h3>
    <?php endif; ?>
    <?php if ($total <= 0): ?>
        <p class="rating-dist-empty"><?= e($empty) ?></p>
    <?php else: ?>
        <?php foreach ($breakdown as $row): ?>
            <div class="rating-dist-row">
                <span class="rating-dist-stars" aria-hidden="true">
                    <?= str_repeat('★', (int) $row['stars']) . str_repeat('☆', 5 - (int) $row['stars']) ?>
                </span>
                <div class="progress rating-dist-bar" role="progressbar"
                     aria-valuenow="<?= (int) $row['percent'] ?>" aria-valuemin="0" aria-valuemax="100"
                     aria-label="<?= (int) $row['stars'] ?> star rating share">
                    <div class="progress-bar" data-bar-percent="<?= (int) $row['percent'] ?>" style="width: 0%"></div>
                </div>
                <span class="rating-dist-count">
                    <?= (int) $row['percent'] ?>% &middot; <?= (int) $row['count'] ?> review<?= (int) $row['count'] === 1 ? '' : 's' ?>
                </span>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
