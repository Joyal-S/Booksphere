<?php

declare(strict_types=1);

/**
 * components/review-header.php
 *
 * The reusable REVIEW HEADER of the professional review sections
 * (Phase 7.4): the section title with the rating summary beside it -
 * the average in large type, the interactive stars and the honest
 * "Based on N reviews" line. Used by the book detail review section,
 * the /books/{id}/reviews page, "My Reviews" and the search page.
 *
 * Included from a view that sets the $header array first:
 *
 *     $header = [
 *         'title'   => 'Reviews &amp; Ratings',   // required
 *         'average' => 4.3,                      // float average
 *         'count'   => 12,                       // number of reviews
 *         'eyebrow' => 'Community voices',       // optional
 *         'icon'    => 'fa-comments',            // optional
 *     ];
 *
 * The average and count are ALWAYS the truthful aggregations the
 * controllers pass (they come from the reviews table, never from
 * the seeded sample columns).
 */

$header = array_merge([
    'title'   => 'Reviews',
    'average' => null,
    'count'   => 0,
    'eyebrow' => '',
    'icon'    => '',
], $header ?? []);

$average = $header['average'] === null ? null : (float) $header['average'];
$count   = (int) $header['count'];
?>
<div class="review-header">
    <div class="review-header-titles">
        <?php if ($header['eyebrow'] !== ''): ?>
            <p class="eyebrow"><?= e($header['eyebrow']) ?></p>
        <?php endif; ?>
        <h2 class="section-title mb-0"><?= e($header['title']) ?></h2>
    </div>

    <?php if ($average !== null || $count > 0): ?>
        <div class="review-header-summary">
            <?php if ($average !== null): ?>
                <span class="review-header-average"><?= e(format_rating($average)) ?></span>
            <?php endif; ?>
            <?php $starRating = [
                'rating' => $average ?? 0.0,
                'count'  => $count > 0 ? $count : null,
                'size'   => 'sm',
            ]; ?>
            <?php require root_path('app/Views/components/star-rating.php'); ?>
            <span class="review-header-based-on">
                Based on <?= $count ?> review<?= $count === 1 ? '' : 's' ?>
            </span>
        </div>
    <?php endif; ?>
</div>
