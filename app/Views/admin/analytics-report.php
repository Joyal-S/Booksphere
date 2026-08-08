<?php

declare(strict_types=1);

/**
 * admin/analytics-report.php
 *
 * Phase 12.5 - the ADMINISTRATION ANALYTICS REPORT, the print/PDF
 * view of the coordinated dashboard (Phase 12.4). It re-renders the
 * AdminAnalyticsService::dashboard() payload - the 12.2 catalogue
 * numbers, the 12.3 recommendation activity and the engine health -
 * as a portrait sheet, with a date-range selector that scopes the
 * recommendation numbers (log totals and per-surface counts) to the
 * chosen window. A ?format=csv link streams the raw log rows of the
 * same range.
 *
 * Available variables (from AdminController::analyticsReport):
 *     $dashboard  - AdminAnalyticsService::dashboard($since):
 *                   books (12.2), recommendation (totals/signals/
 *                   top/slept), engine, generatedAt
 *     $range      - the active range key ('all' | '7d' | '30d' |
 *                   '90d' | 'year' | 'custom')
 *     $rangeSince / $rangeUntil - ISO dates (null when all-time)
 *     $rangeLabel - the human label of the active range
 *     $generatedAt - the UTC stamp of this report instance
 *
 * Print contract: the controller passes bodyClass = 'report-print'
 * on the <body> (charts.css hides the app chrome in @media print);
 * Ctrl-P renders the sheet on A4. The class is server-rendered, so
 * a no-JS print works too (Phase 12.6 audit).
 */

$dashboard = $dashboard ?? [];
$books     = $dashboard['books'] ?? [];
$rec       = $dashboard['recommendation'] ?? [];
$engine    = $dashboard['engine'] ?? [];
$totals    = $rec['totals'] ?? [];
$signals   = $rec['signals'] ?? [];
$top       = $rec['top'] ?? [];
$slept     = $rec['slept'] ?? [];

$overview  = $books['overview'] ?? [];
$shelves   = $books['shelves'] ?? [];
$window    = $books['activity']['window'] ?? [];
$distribution = $overview['distribution'] ?? [];

$rangeLabel  = (string) ($rangeLabel ?? 'Last 30 days');
$generatedAt = (string) ($generatedAt ?? gmdate('Y-m-d H:i') . ' UTC');
$rangeSince  = $rangeSince ?? null;
$rangeUntil  = $rangeUntil ?? null;
$range       = (string) ($range ?? '30d');

$shelfLabels = [
    'finished'          => 'Finished',
    'currently_reading' => 'Reading now',
    'want_to_read'      => 'Wishlist',
    'on_hold'           => 'On hold',
    'dropped'           => 'Dropped',
];

$dash = '&mdash;';
$csvUrl = '/admin/analytics/report?format=csv' . ($range === 'custom'
    ? '&since=' . urlencode((string) $rangeSince) . '&until=' . urlencode((string) $rangeUntil)
    : '&range=' . urlencode($range));
?>

<div class="page-intro report-sheet">
    <p class="eyebrow">Administration Report &middot; restricted</p>
    <h1>Analytics Report</h1>
    <p class="report-meta mb-2">Range: <strong><?= e($rangeLabel) ?></strong> &middot; prepared <?= e($generatedAt) ?> &middot; every number derives from the real books, reviews, shelves and recommendation logs.</p>
    <div class="print-hidden report-controls d-flex flex-wrap gap-2 align-items-center">
        <form class="d-inline-flex align-items-center gap-2" method="get" action="/admin/analytics/report">
            <label class="small mb-0" for="report-range">Range</label>
            <select class="form-select form-select-sm w-auto" id="report-range" name="range">
                <?php
                $rangeOptions = [
                    'all'    => 'All time',
                    '7d'     => 'Last 7 days',
                    '30d'    => 'Last 30 days',
                    '90d'    => 'Last 90 days',
                    'year'   => 'Last 12 months',
                    'custom' => 'Custom dates',
                ];
                foreach ($rangeOptions as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $range === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="small muted">from</span>
            <input class="form-control form-control-sm" type="date" name="since" value="<?= e((string) ($rangeSince ?? '')) ?>" aria-label="From date">
            <span class="small muted">to</span>
            <input class="form-control form-control-sm" type="date" name="until" value="<?= e((string) ($rangeUntil ?? '')) ?>" aria-label="To date">
            <button class="btn btn-sm btn-outline-secondary" type="submit">Apply</button>
        </form>
        <a class="btn btn-sm btn-outline-primary" href="<?= e($csvUrl) ?>">
            <i class="fa-solid fa-file-csv me-1" aria-hidden="true"></i> Export CSV
        </a>
        <a class="btn btn-sm btn-outline-secondary" href="javascript:window.print()">
            <i class="fa-solid fa-print me-1" aria-hidden="true"></i> Print / PDF
        </a>
    </div>
</div>

<section class="dash-section report-sheet">
    <p class="eyebrow mb-1">Platform numbers</p>
    <h2 class="h4 mb-3">The catalogue and the engine</h2>
    <div class="row row-cols-2 row-cols-md-3 row-cols-xl-6 g-3">
        <?php
        $kpis = [
            ['label' => 'Books in catalogue', 'value' => (int) ($overview['books'] ?? 0), 'note' => 'all time'],
            ['label' => 'Approved reviews',   'value' => (int) ($overview['reviews'] ?? 0), 'note' => 'all time'],
            ['label' => 'Average rating',     'value' => $overview['averageRating'] === null ? $dash : number_format((float) $overview['averageRating'], 2), 'note' => 'all time'],
            ['label' => 'Recommendations',    'value' => (int) ($totals['logs'] ?? 0), 'note' => 'in range'],
            ['label' => 'Users served',       'value' => (int) ($totals['users'] ?? 0), 'note' => 'in range'],
            ['label' => 'Books suggested',    'value' => (int) ($totals['books'] ?? 0), 'note' => 'in range'],
        ];
        foreach ($kpis as $kpi): ?>
            <div class="col">
                <div class="card-base h-100 p-3">
                    <span class="analytics-tile-value d-block"><?= $kpi['value'] ?></span>
                    <span class="analytics-tile-label"><?= e($kpi['label']) ?></span>
                    <span class="small muted"><?= e($kpi['note']) ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php
$cp = '\BookSphere\App\Presenters\ChartPresenter';
$json = static function (string $method, ...$args) use ($cp): string {
    try {
        return (string) $cp::$method(...$args);
    } catch (JsonException) {
        return '';
    }
};

$shelfOrder = ['finished', 'currently_reading', 'want_to_read', 'on_hold', 'dropped'];
$shelfValues = array_map(static fn (string $status): int => (int) ($shelves[$status] ?? 0), $shelfOrder);

$windowRows = array_reverse($window);
$ratingValues = $distribution === []
    ? [0, 0, 0, 0, 0]
    : array_map(static fn (int $star): int => (int) ($distribution[$star] ?? 0), range(1, 5));
?>

<?php if (array_sum($shelfValues) > 0 || $windowRows !== [] || array_sum($ratingValues) > 0 || $signals !== []): ?>
<section class="dash-section report-sheet">
    <p class="eyebrow mb-1">Visuals</p>
    <h2 class="h4 mb-3">The same numbers, pictured</h2>
    <div class="row g-3">
        <div class="col-md-6 col-xl-3">
            <?php $chartEyebrow = 'Community shelves'; $chartTitle = 'All statuses'; $chartTrend = '';
            $chart = array_sum($shelfValues) > 0 ? $json('doughnut', 'shelves', array_values($shelfLabels), $shelfValues, '') : '';
            $chartSummary = array_sum($shelfValues) > 0 ? implode(' &middot; ', array_map(
                static fn (string $status, int $count): string => $count . ' ' . strtolower((string) $shelfLabels[$status]),
                $shelfOrder,
                $shelfValues,
            )) : ''; ?>
            <?php require root_path('app/Views/components/chart-card.php'); ?>
        </div>
        <div class="col-md-6 col-xl-3">
            <?php $chartEyebrow = 'Approved reviews'; $chartTitle = 'Rating distribution'; $chartTrend = '';
            $chart = array_sum($ratingValues) > 0 ? $json('bar', 'ratings', ['1 star', '2 stars', '3 stars', '4 stars', '5 stars'], [['label' => 'Reviews', 'tone' => 'warning', 'values' => $ratingValues]], '') : '';
            $chartSummary = array_sum($ratingValues) > 0 ? implode(' &middot; ', array_map(
                static fn (int $star, int $count): string => $count . ' at ' . $star . ' star' . ($star === 1 ? '' : 's'),
                range(1, 5),
                $ratingValues,
            )) : ''; ?>
            <?php require root_path('app/Views/components/chart-card.php'); ?>
        </div>
        <div class="col-md-6 col-xl-3">
            <?php $chartEyebrow = 'Monthly activity'; $chartTitle = 'Reviews &amp; finishes'; $chartTrend = '';
            $chart = $windowRows !== [] ? $json('bar', 'monthly',
                array_map(static fn (array $m): string => (string) $m['label'], $windowRows),
                [
                    ['label' => 'Approved reviews', 'tone' => 'warning', 'values' => array_map(static fn (array $m): int => (int) $m['reviews'], $windowRows)],
                    ['label' => 'Books finished',   'tone' => 'success', 'values' => array_map(static fn (array $m): int => (int) $m['finishes'], $windowRows)],
                ], '') : '';
            $chartSummary = $windowRows !== [] ? implode(' &middot; ', array_map(
                static fn (array $m): string => $m['label'] . ': ' . (int) $m['reviews'] . ' reviews, ' . (int) $m['finishes'] . ' finishes',
                $windowRows,
            )) : ''; ?>
            <?php require root_path('app/Views/components/chart-card.php'); ?>
        </div>
        <div class="col-md-6 col-xl-3">
            <?php $chartEyebrow = 'Surfaces &middot; in range'; $chartTitle = 'Where served'; $chartTrend = '';
            $chart = $signals !== [] ? $json('doughnut', 'signals',
                array_map(static fn (array $r): string => (string) ($r['signal'] !== '' ? $r['signal'] : 'unnamed surface'), $signals),
                array_map(static fn (array $r): int => (int) ($r['logs'] ?? 0), $signals), '') : '';
            $chartSummary = $signals !== [] ? implode(' &middot; ', array_map(
                static fn (array $r): string => ($r['signal'] !== '' ? $r['signal'] : 'unnamed surface') . ': ' . (int) ($r['logs'] ?? 0),
                $signals,
            )) : ''; ?>
            <?php require root_path('app/Views/components/chart-card.php'); ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="dash-section report-sheet">
    <p class="eyebrow mb-1">Recommendation activity &middot; <?= e($rangeLabel) ?></p>
    <h2 class="h4 mb-3">What the engine served</h2>
    <div class="row g-4">
        <div class="col-md-6">
            <div class="card-base p-4 h-100">
                <h3 class="h6 mb-3">Most recommended books &middot; all time</h3>
                <?php if ($top === []): ?>
                    <p class="muted small mb-0">Nothing recommended yet.</p>
                <?php else: ?>
                    <table class="table align-middle">
                        <thead><tr><th scope="col">Book</th><th scope="col" class="text-end">Times</th></tr></thead>
                        <tbody>
                            <?php foreach ($top as $book): ?>
                                <tr>
                                    <td><?= e($book['title']) ?></td>
                                    <td class="text-end"><?= (int) $book['logs'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <p class="muted small mt-3 mb-0">"Repeatedly suggested" has no meaning inside a short range, so these lists stay all-time - the note is on every copy.</p>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card-base p-4 h-100">
                <h3 class="h6 mb-3">Sleeping suggestions &middot; all time</h3>
                <?php if ($slept === []): ?>
                    <p class="muted small mb-0">Every repeatedly recommended book has community interaction.</p>
                <?php else: ?>
                    <table class="table align-middle">
                        <thead><tr><th scope="col">Book</th><th scope="col" class="text-end">Recommended</th></tr></thead>
                        <tbody>
                            <?php foreach ($slept as $book): ?>
                                <tr>
                                    <td><?= e($book['title']) ?></td>
                                    <td class="text-end"><?= (int) $book['logs'] ?>x</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card-base p-4 h-100">
                <h3 class="h6 mb-3">Surfaces &middot; in range</h3>
                <?php if ($signals === []): ?>
                    <p class="muted small mb-0">No recommendation was served inside this range.</p>
                <?php else: ?>
                    <table class="table align-middle">
                        <thead><tr><th scope="col">Surface</th><th scope="col" class="text-end">Served</th></tr></thead>
                        <tbody>
                            <?php foreach ($signals as $row): ?>
                                <tr>
                                    <td><?= e($row['signal'] !== '' ? $row['signal'] : 'unnamed surface') ?></td>
                                    <td class="text-end"><?= (int) $row['logs'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card-base p-4 h-100">
                <h3 class="h6 mb-3">Engine &amp; cache &middot; all time</h3>
                <?php $latest = $totals['latest'] ?? null; ?>
                <p class="mb-1"><strong><?= $latest === null ? 'no data' : e((string) $latest) ?></strong> <span class="muted">= most recent generation stamp in range (UTC)</span></p>
                <?php $cache = $engine['cache'] ?? []; ?>
                <?php if (is_array($cache) && $cache !== []): ?>
                    <p class="muted small mb-0">Cache <?= ($cache['enabled'] ?? false) ? 'enabled' : 'disabled' ?> &middot; <?= (int) ($cache['files'] ?? 0) ?> files &middot; <?= ($cache['writable'] ?? false) ? 'writable' : 'read-only' ?>.</p>
                <?php else: ?>
                    <p class="muted small mb-0">Engine health details live on the /admin/recommendations page.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<section class="detail-section report-sheet">
    <p class="eyebrow mb-1">Limitations, honestly</p>
    <h2 class="h4 mb-2">What this report does not contain</h2>
    <p class="report-rules mb-0">
        The recommendation engine records no click or conversion tracking, so CTR- or conversion-style charts are
        absent rather than fabricated. The 12.1 personal numbers (shelves, finishes, ratings) belong to the signed-in
        user's own report at /analytics/report and are never aggregated here. Recommendation totals are scoped to the
        selected range; catalogue numbers and the top/slept lists are all-time, as labelled.
    </p>
</section>

<p class="muted small text-center mt-4 mb-0 report-sheet">
    <?= e($generatedAt) ?> &middot; analytics report on BookSphere. Every number derives from the published books, their approved reviews, the library shelves and the recommendation logs.
</p>