<?php

declare(strict_types=1);

/**
 * components/chart-card.php
 *
 * The reusable CHART CARD of the Charts & Reports layer (Phase
 * 12.5). Included from a view that sets these variables first:
 *
 *     $chartEyebrow  - the small label above the title
 *     $chartTitle    - the card title
 *     $chartTrend    - an optional trend badge label ('' = none)
 *     $chart         - a ChartPresenter JSON config (a string;
 *                      '' = not enough data)
 *     $chartSummary  - the accessible summary SENTENCE that
 *                      restates the chart's numbers in words
 *
 * The card renders:
 *
 *     1. the header (the same rhythm as every analytics card),
 *     2. the <canvas> charts.js mounts (role="img" + aria-label so
 *        the chart is announced even when painting fails),
 *     3. the JSON config as an inline <script type="application/json">
 *        (no extra network request, no duplicated data in the
 *        markup),
 *     4. the summary sentence under the chart - the accessible text
 *        layer: the information never depends on the picture alone
 *        (a screen reader user, a no-JS visitor and a colour-blind
 *        reader all get the same numbers in words).
 *
 * When $chart is '' the card shows the honest "insufficient data"
 * state instead of a fabricated picture.
 */

$chart        = $chart ?? '';
$chartTitle   = $chartTitle ?? 'Chart';
$chartEyebrow = $chartEyebrow ?? 'Visualisation';
$chartTrend   = $chartTrend ?? '';
$chartSummary = $chartSummary ?? '';
?>

<div class="card-base chart-card h-100 p-4">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <p class="eyebrow mb-1"><?= e($chartEyebrow) ?></p>
            <h2 class="h5 mb-0"><?= e($chartTitle) ?></h2>
        </div>
        <?php if ($chartTrend !== ''): ?>
            <span class="badge rounded-pill text-bg-light border chart-trend"><?= $chartTrend ?></span>
        <?php endif; ?>
    </div>

    <?php if ($chart === ''): ?>
        <div class="chart-empty py-5 text-center">
            <span class="stat-icon tone-secondary me-2" aria-hidden="true"><i class="fa-solid fa-chart-column"></i></span>
            <p class="muted small mb-0">Not enough data to draw this chart yet &mdash; real activity fills it.</p>
        </div>
    <?php else: ?>
        <div class="chart-frame position-relative">
            <canvas class="chart-canvas"
                    role="img"
                    aria-label="<?= e($chartEyebrow) ?>: <?= e($chartTitle) ?>. <?= e($chartSummary) ?>"></canvas>
            <script type="application/json" data-chart-config><?= $chart ?></script>
        </div>
    <?php endif; ?>

    <?php if ($chartSummary !== ''): ?>
        <p class="muted small mt-3 mb-0 chart-summary"><?= e($chartSummary) ?></p>
    <?php endif; ?>
</div>