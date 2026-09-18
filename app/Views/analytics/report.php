<?php

declare(strict_types=1);

/**
 * analytics/report.php
 *
 * Phase 12.5 - the USER READING REPORT, the print/PDF view of the
 * Phase 12.1 analytics. It renders the same UserAnalyticsService
 * payload as analytics/show.php, but as a standalone portrait sheet
 * for paper: a cover header, the chart shapes, and the real numbers
 * in tables. The layout master chrome (navbar, sidebar, footer) is
 * hidden by the body.report-print print rules in charts.css.
 *
 * Available variables (from UserAnalyticsController::report):
 *     $analytics  - the full 12.1 payload (summary, shelf, months,
 *                   genres, reviews)
 *     $generatedAt - the UTC stamp of this report instance
 *
 * Print contract: Ctrl-P / Cmd-P renders the dashboard + the four
 * charts + the underlying tables on A4 - no analytics recomputed.
 */

$analytics  = $analytics ?? [];
$summary    = $analytics['summary'] ?? [];
$shelf      = $analytics['shelf'] ?? [];
$months     = $analytics['months'] ?? [];
$genres     = $analytics['genres'] ?? [];
$reviews    = $analytics['reviews'] ?? [];
$generatedAt = (string) ($generatedAt ?? gmdate('Y-m-d H:i') . ' UTC');

$shelfLabels = [
    'want_to_read'      => 'Want to read',
    'currently_reading' => 'Currently reading',
    'finished'          => 'Finished',
    'on_hold'           => 'On hold',
    'dropped'           => 'Dropped',
];

$dash = '—';
?>

<div class="report-hero card-base report-sheet mb-4" data-animate>
    <div class="report-hero-copy">
        <p class="eyebrow report-hero-eyebrow">
            <i class="fa-solid fa-chart-line me-1" aria-hidden="true"></i>
            Reading Report &middot; personal
        </p>
        <h1 class="report-hero-title">Your Reading Journey</h1>
        <span class="visually-hidden">My Reading Report</span>
        <p class="report-meta mb-0">Prepared <?= e($generatedAt) ?> &middot; every number derives from your actual reading activity, shelves, finishes and approved reviews.</p>
    </div>
    <div class="report-hero-actions print-hidden">
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
            <i class="fa-solid fa-print me-1" aria-hidden="true"></i>Print Report
        </button>
        <a class="btn btn-primary btn-sm" href="/analytics">
            <i class="fa-solid fa-chart-pie me-1" aria-hidden="true"></i>Full Analytics
        </a>
    </div>
</div>

<section class="dash-section report-sheet mb-4">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <p class="eyebrow mb-1">Summary</p>
            <h2 class="h4 mb-0">Your Reading Milestones</h2>
        </div>
    </div>
    <div class="row row-cols-2 row-cols-md-4 g-3">
        <?php
        $rows = [
            ['label' => 'Books Shelved',     'value' => (int) ($summary['shelved'] ?? 0), 'icon' => 'fa-bookmark', 'tone' => 'primary'],
            ['label' => 'Books Finished',    'value' => (int) ($summary['completed'] ?? 0), 'icon' => 'fa-circle-check', 'tone' => 'success'],
            ['label' => 'Reviews Written',   'value' => (int) ($summary['reviews'] ?? 0), 'icon' => 'fa-feather-pointed', 'tone' => 'warning'],
            ['label' => 'Average Rating',    'value' => $summary['averageRating'] === null ? $dash : format_rating($summary['averageRating']), 'icon' => 'fa-star', 'tone' => 'danger'],
            ['label' => 'Completion Rate',   'value' => number_format((float) ($summary['completionRate'] ?? 0), 1) . '%', 'icon' => 'fa-chart-pie', 'tone' => 'info'],
            ['label' => 'Active Reading Days','value' => (int) ($summary['activeDays'] ?? 0), 'icon' => 'fa-calendar-check', 'tone' => 'success'],
            ['label' => 'Currently Reading', 'value' => (int) ($summary['reading'] ?? 0), 'icon' => 'fa-book-open-reader', 'tone' => 'primary'],
            ['label' => 'Wishlist',          'value' => (int) ($summary['wishlist'] ?? 0), 'icon' => 'fa-heart', 'tone' => 'danger'],
        ];
        foreach ($rows as $row): ?>
            <div class="col">
                <div class="report-kpi-card card-base h-100 p-3">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="report-kpi-label"><?= e($row['label']) ?></span>
                        <span class="report-kpi-icon report-kpi-icon--<?= e($row['tone']) ?>" aria-hidden="true">
                            <i class="fa-solid <?= e($row['icon']) ?>"></i>
                        </span>
                    </div>
                    <span class="report-kpi-value d-block"><?= $row['value'] ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php
$chartP = '\BookSphere\App\Presenters\ChartPresenter';
$json = static function (string $method, ...$args) use ($chartP): string {
    try {
        return (string) $chartP::$method(...$args);
    } catch (JsonException) {
        return '';
    }
};

$shelfNumbers = array_values(array_map(static fn (string $status): int => (int) ($shelf[$status] ?? 0), array_keys($shelfLabels)));
if (array_sum($shelfNumbers) > 0): ?>
<section class="dash-section report-sheet">
    <p class="eyebrow mb-1">My shelves</p>
    <h2 class="h4 mb-3">Where my books sit</h2>
    <div class="row g-3">
        <div class="col-md-5">
            <?php $chartEyebrow = 'Shelf split'; $chartTitle = 'All five statuses'; $chartTrend = ''; $chart = $json('doughnut', 'shelf', array_values($shelfLabels), $shelfNumbers, ''); $chartSummary = implode(' · ', array_map(
                static fn (string $label, int $count): string => $count . ' ' . strtolower($label),
                array_values($shelfLabels),
                $shelfNumbers,
            )); ?>
            <?php require root_path('app/Views/components/chart-card.php'); ?>
        </div>
        <div class="col-md-7">
            <div class="card-base p-4">
                <div class="table-responsive">
                    <table class="table align-middle table-striped mb-0">
                        <thead>
                            <tr><th scope="col">Status</th><th scope="col" class="text-end">Books</th><th scope="col" class="text-end">Share</th></tr>
                        </thead>
                        <tbody>
                            <?php $total = max(1, array_sum($shelfNumbers)); ?>
                            <?php foreach ($shelfLabels as $status => $label): ?>
                                <?php $count = (int) ($shelf[$status] ?? 0); ?>
                                <tr>
                                    <td><?= e($label) ?></td>
                                    <td class="text-end"><?= $count ?></td>
                                    <td class="text-end"><?= round($count / $total * 100) ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<?php
$chrono = array_reverse($months);
if ($chrono !== []):
    $finished = array_map(static fn (array $m): int => (int) ($m['completed'] ?? 0), $chrono);
    $rated    = array_map(static fn (array $m): int => (int) ($m['rated'] ?? 0), $chrono);
    ?>
    <section class="detail-section report-sheet">
        <p class="eyebrow mb-1">Activity over time</p>
        <h2 class="h4 mb-3">Monthly totals</h2>
        <div class="row g-4">
            <div class="col-md-7">
                <?php $chartEyebrow = 'Last ' . count($months) . ' months'; $chartTitle = 'Finishes & reviews'; $chartTrend = ''; $chart = $json('line', 'monthly', array_map(static fn (array $m): string => (string) $m['label'], $chrono), [
                    ['label' => 'Books finished', 'tone' => 'success', 'values' => $finished],
                    ['label' => 'Reviews written', 'tone' => 'warning', 'values' => $rated],
                ], ''); $chartSummary = ''; ?>
                <?php require root_path('app/Views/components/chart-card.php'); ?>
            </div>
            <div class="col-md-5">
                <div class="card-base p-4">
                    <div class="table-responsive">
                        <table class="table align-middle table-striped mb-0">
                            <thead>
                                <tr><th scope="col">Month</th><th class="text-end" scope="col">Finished</th><th class="text-end" scope="col">Reviews</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_reverse($months) as $m): ?>
                                    <tr>
                                        <td><?= e((string) $m['label']) ?></td>
                                        <td class="text-end"><?= (int) ($m['completed'] ?? 0) ?></td>
                                        <td class="text-end"><?= (int) ($m['rated'] ?? 0) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php $genreRows = $genres['rows'] ?? []; ?>
<?php if ($genreRows !== []): ?>
    <section class="detail-section report-sheet">
        <p class="eyebrow mb-1">Genre spread</p>
        <h2 class="h4 mb-1">Share of my finished books</h2>
        <p class="report-meta mb-3">The percentages are the service's own genre-to-ratio computation.</p>
        <div class="row g-4">
            <div class="col-md-7">
                <?php $chartEyebrow = 'My genres'; $chartTitle = 'By share'; $chartTrend = ''; $chart = $json('hbar', 'genres', array_map(static fn (array $r): string => (string) $r['name'], $genreRows), array_map(static fn (array $r): float => (float) $r['percent'], $genreRows), ''); $chartSummary = ''; ?>
                <?php require root_path('app/Views/components/chart-card.php'); ?>
            </div>
            <div class="col-md-5">
                <div class="card-base p-4">
                    <div class="table-responsive">
                        <table class="table align-middle table-striped mb-0">
                            <thead>
                                <tr><th scope="col">Genre</th><th class="text-end" scope="col">Share</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($genreRows as $r): ?>
                                    <tr>
                                        <td><?= e((string) $r['name']) ?></td>
                                        <td class="text-end"><?= number_format((float) $r['percent'], 1) ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
<?php endif; ?>

<p class="muted small text-center mt-4 mb-0 report-sheet">
    <?= e($generatedAt) ?> &middot; my reading report on BookSphere. Numbers update with the shelves, finishes and reviews &mdash; nothing is estimated.
</p>