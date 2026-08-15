<?php

declare(strict_types=1);

/**
 * components/rating-badge.php
 *
 * The RATING BADGE (Phase 7.6): a compact, inline rating chip -
 * small stars plus the numeric value (and an optional label) - used
 * wherever a list or table shows one rating figure without room for
 * the full display component (author directories, category lists,
 * reviewer rows, search results).
 *
 * Included from a view that sets $badge first:
 *
 *     $badge = [
 *         'rating' => 4.5,        // the value to show (0-5)
 *         'size'   => 'sm',       // optional: sm | md
 *         'label'  => '4.5',      // optional: the text next to the
 *                                 // stars (defaults to the rating)
 *         'suffix' => 'from 12 reviews', // optional: trailing hint
 *     ];
 *
 * The stars themselves reuse the shared star-rating component in
 * compact mode - one rendering source for every star in the app.
 */

$badge = array_merge([
    'rating' => 0.0,
    'size'   => 'sm',
    'label'  => null,
    'suffix' => '',
], $badge ?? []);

$label = $badge['label'] !== null
    ? $badge['label']
    : format_rating($badge['rating']);

?>
<span class="rating-badge">
    <?php $starRating = [
        'rating'   => (float) $badge['rating'],
        'size'     => in_array($badge['size'], ['sm', 'md'], true) ? $badge['size'] : 'sm',
        'count'    => null,
        'tooltip'  => false,
        'compact'  => true,
    ]; ?>
    <?php require root_path('app/Views/components/star-rating.php'); ?>
    <span class="rating-badge-value"><?= e((string) $label) ?></span>
    <?php if ($badge['suffix'] !== ''): ?>
        <span class="rating-badge-suffix text-muted small"><?= e($badge['suffix']) ?></span>
    <?php endif; ?>
</span>
