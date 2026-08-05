<?php

declare(strict_types=1);

/**
 * components/statistics-card.php
 *
 * The STATISTICS CARD (Phase 7.6): a "big number" tile for detail
 * pages - an icon, a large value, a label and an optional hint line.
 * This is the detail-page counterpart of the dashboard's stat-card:
 * stat-card lives on the dashboard grid, statistics-card is the
 * inline tile used inside the author and category pages' stat rows
 * (it renders with the shared analytics-tile design).
 *
 * Included from a view that sets $stat first:
 *
 *     $stat = [
 *         'icon'  => 'fa-star',     // Font Awesome class
 *         'label' => 'Average author rating',
 *         'value' => 4.3,           // the big number (or a string)
 *         'hint'  => 'from 12 reviews', // optional
 *     ];
 */

$stat = array_merge([
    'icon'  => 'fa-circle-info',
    'label' => '',
    'value' => 0,
    'hint'  => '',
], $stat ?? []);

?>
<div class="statistics-card">
    <div class="analytics-tile h-100">
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="section-icon" aria-hidden="true"><i class="fa-solid <?= e($stat['icon']) ?>"></i></span>
            <span class="analytics-tile-label mb-0"><?= e($stat['label']) ?></span>
        </div>
        <span class="analytics-tile-value"><?= e((string) $stat['value']) ?></span>
        <?php if ($stat['hint'] !== ''): ?>
            <span class="analytics-tile-label d-block"><?= e($stat['hint']) ?></span>
        <?php endif; ?>
    </div>
</div>
