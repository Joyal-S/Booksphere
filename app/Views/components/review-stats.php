<?php

declare(strict_types=1);

/**
 * components/review-stats.php
 *
 * The reusable REVIEW STATISTICS panel (Phase 7.4): the headline
 * numbers of a review collection - total reviews, average rating,
 * highest and lowest rating given, the most recent review date -
 * rendered as the existing stat-card tiles, with the optional rating
 * distribution bars below (the shared _rating-distribution partial,
 * so the bars can never drift from the book page).
 *
 * The values are ALWAYS the truthful aggregations from the reviews
 * table (the controllers pass reviewStatistics() results directly).
 *
 * Included from a view that sets the $stats array first:
 *
 *     $stats = [
 *         'total'    => 47,
 *         'average'  => 4.2,   // float|null
 *         'highest'  => 5,     // int|null
 *         'lowest'   => 1,     // int|null
 *         'latest'   => '2026-08-01T10:00:00Z', // string|null
 *         'breakdown' => [...], // optional: distribution rows for
 *                              // the shared _rating-distribution.php
 *     ];
 */

$stats = array_merge([
    'total'    => 0,
    'average'  => null,
    'highest'  => null,
    'lowest'   => null,
    'latest'   => null,
    'breakdown'=> null,
], $stats ?? []);

$tiles = [
    [
        'icon'  => 'fa-comments',
        'label' => 'Total reviews',
        'value' => (int) $stats['total'],
        'tone'  => 'primary',
    ],
    [
        'icon'  => 'fa-star',
        'label' => 'Average rating',
        'value' => $stats['average'] === null ? 'No ratings yet' : format_rating($stats['average']),
        'tone'  => 'success',
    ],
    [
        'icon'  => 'fa-arrow-up',
        'label' => 'Highest rating',
        'value' => $stats['highest'] === null ? '—' : (int) $stats['highest'] . ' ★',
        'tone'  => 'warning',
    ],
    [
        'icon'  => 'fa-arrow-down',
        'label' => 'Lowest rating',
        'value' => $stats['lowest'] === null ? '—' : (int) $stats['lowest'] . ' ★',
        'tone'  => 'danger',
    ],
    [
        'icon'  => 'fa-clock',
        'label' => 'Latest review',
        'value' => $stats['latest'] === null ? '—' : format_review_date((string) $stats['latest']),
        'tone'  => 'info',
    ],
];
?>
<div class="review-stats">
    <div class="row g-3 g-xl-4 row-cols-2 row-cols-md-3 row-cols-xl-5">
        <?php foreach ($tiles as $stat): ?>
            <div class="col"><?php require root_path('app/Views/components/stat-card.php'); ?></div>
        <?php endforeach; ?>
    </div>

    <?php if (is_array($stats['breakdown']) && $stats['breakdown'] !== []): ?>
        <div class="review-stats-distribution">
            <?php $breakdown = $stats['breakdown']; ?>
            <?php require root_path('app/Views/reviews/partials/_rating-distribution.php'); ?>
        </div>
    <?php endif; ?>
</div>
