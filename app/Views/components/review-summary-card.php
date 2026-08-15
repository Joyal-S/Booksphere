<?php

declare(strict_types=1);

/**
 * components/review-summary-card.php
 *
 * The REVIEW SUMMARY CARD (Phase 7.6): the "big number" rating
 * panel - a large average value, the compact stars, the review
 * count and the 5-star distribution bars - used as the headline
 * rating block of the author page ("Average author rating") and the
 * category page ("Average category rating").
 *
 * Included from a view that sets $summary first:
 *
 *     $summary = [
 *         'title'       => 'Average author rating',
 *         'average'     => 4.3,    // the average value (0-5)
 *         'count'       => 12,     // the review count
 *         'subtitle'    => 'across 3 books', // optional hint
 *         'distribution'=> [],     // optional: star (5..1) -> count
 *     ];
 *
 * The distribution bars reuse the shared
 * _rating-distribution.php partial (like the book page and the
 * admin analytics), so every distribution in the app looks alike.
 */

$summary = array_merge([
    'title'        => 'Average rating',
    'average'      => 0.0,
    'count'        => 0,
    'subtitle'     => '',
    'distribution' => null,
], $summary ?? []);

$average = (float) $summary['average'];
$count   = max(0, (int) $summary['count']);

$cardClass = trim('card-base p-4 review-summary-card ' . ($summary['class'] ?? ''));

?>
<div class="<?= e($cardClass) ?>">
    <h3 class="section-title"><?= e((string) $summary['title']) ?></h3>

    <div class="review-summary-average">
        <span class="analytics-average-value"><?= e(format_rating($average)) ?>/5</span>
        <div class="mt-1">
            <?php $starRating = [
                'rating'  => $average,
                'count'   => null,
                'size'    => 'md',
                'tooltip' => false,
            ]; ?>
            <?php require root_path('app/Views/components/star-rating.php'); ?>
        </div>
        <p class="text-muted mb-0">
            <?= $count === 0 ? 'No reviews yet' : 'from ' . $count . ' review' . ($count === 1 ? '' : 's') ?>
            <?= $summary['subtitle'] !== '' ? ' &middot; ' . e((string) $summary['subtitle']) : '' ?>
        </p>
    </div>

    <?php if (is_array($summary['distribution']) && $summary['distribution'] !== []): ?>
        <?php $total = (int) array_sum($summary['distribution']); ?>
        <?php $breakdown = []; ?>
        <div class="mt-3">
            <?php for ($star = 5; $star >= 1; $star--): ?>
                <?php $count = (int) ($summary['distribution'][$star] ?? 0); ?>
                <?php $breakdown[] = [
                    'stars'   => $star,
                    'count'   => $count,
                    'percent' => $total > 0 ? (int) round($count / $total * 100) : 0,
                    'total'   => $total,
                ]; ?>
            <?php endfor; ?>
            <?php $title = ''; ?>
            <?php require root_path('app/Views/reviews/partials/_rating-distribution.php'); ?>
        </div>
    <?php endif; ?>
</div>
