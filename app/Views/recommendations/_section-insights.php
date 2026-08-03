<?php

declare(strict_types=1);

/**
 * recommendations/_section-insights.php
 *
 * Section 7 of the Phase 6.4 dashboard: "Recommendation insights".
 *
 * Purpose:
 *     The trust layer, rendered as six small statistics via the
 *     shared stat-card component:
 *         - How many recommendations the engine generated in total
 *         - The average confidence across the personalised shelf
 *         - How many personalised picks scored 80 or above
 *         - How many genres the current batch covers
 *         - How many authors the current batch draws from
 *         - When the cache was last rebuilt (the "freshness" beat)
 *     Numeric values count up on load through the global data-count
 *     animation in app.js (skipped for reduced-motion users).
 *
 * Data ($shelf, set by index.php):
 *     'shelf' => ['stats' => [
 *         ['icon' => 'fa-book', 'label' => '...', 'value' => n, 'tone' => '...', 'trend' => '...'],
 *         ...
 *     ]]
 *
 * Responsive: 2 columns / 3 / 6 across breakpoints.
 */

$shelf = array_merge(['stats' => []], $shelf ?? []);

?>
<section class="rec-section" data-reveal>
    <?php $section = [
        'eyebrow' => 'Under the hood',
        'title'   => 'Recommendation insights',
        'icon'    => 'fa-chart-simple',
    ]; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>

    <?php if ($shelf['stats'] === []): ?>
        <div class="card-base">
            <?php $empty = [
                'icon'    => 'fa-chart-simple',
                'title'   => 'Nothing to report yet',
                'message' => 'The insights panel fills with numbers once the engine has run a personalised batch.',
            ]; ?>
            <?php require root_path('app/Views/components/empty-state.php'); ?>
        </div>
    <?php else: ?>
        <div class="row g-3 g-xl-4 row-cols-2 row-cols-md-3 row-cols-xl-6" aria-label="Recommendation insights">
            <?php foreach ($shelf['stats'] as $stat): ?>
                <?php require root_path('app/Views/components/stat-card.php'); ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
