<?php

declare(strict_types=1);

/**
 * analytics/show.php
 *
 * The USER ANALYTICS page (Phase 12.1): the personal reading
 * statistics of the signed-in user, computed by UserAnalyticsService
 * from the real user_library + reviews rows (nothing is estimated).
 *
 * Available variables (from UserAnalyticsController::show):
 *     $analytics - the UserAnalytics::toArray() payload:
 *         empty       - true only when the user has no shelves AND
 *                       no reviews (the guidance empty state)
 *         summary     - shelved, reading, wishlist, completed,
 *                       completionRate, activeDays, reviews,
 *                       averageRating (null = never rated)
 *         shelf       - the five statuses -> counts
 *         genres      - unique + rows (name, books, percent)
 *         authors     - unique + rows (name, books, percent)
 *         reviews     - total, average (null), favourite,
 *                       distribution (1..5 -> counts)
 *         activity    - months (key, label, completed, rated),
 *                       older (completed, rated),
 *                       recent events (type, label, book_title, at)
 *         generatedAt - UTC ISO timestamp of the snapshot
 *
 * Design: a thin first analytics surface - Phase 14 owns the final
 * dashboard polish. It reuses shared components (stat-card,
 * empty-state, card-base, analytics-tile) and Bootstrap bars; every
 * zero or dash carries the context of what is missing.
 */

$analytics = $analytics ?? [];
$summary   = $analytics['summary'] ?? [];
$shelf     = $analytics['shelf'] ?? [];
$genres    = $analytics['genres'] ?? [];
$authors   = $analytics['authors'] ?? [];
$reviews   = $analytics['reviews'] ?? [];
$activity  = $analytics['activity'] ?? [];

$shelfLabels = [
    'want_to_read'      => 'Want to read',
    'currently_reading' => 'Currently reading',
    'finished'          => 'Finished',
    'on_hold'           => 'On hold',
    'dropped'           => 'Dropped',
];

$shelfTones = [
    'want_to_read'      => 'info',
    'currently_reading' => 'warning',
    'finished'          => 'success',
    'on_hold'           => 'secondary',
    'dropped'           => 'danger',
];

$shelfTotal = max(1, (int) array_sum($shelf));

$months       = $activity['months'] ?? [];
$completedAll = array_column($months, 'completed');
$ratedAll     = array_column($months, 'rated');
$maxCompleted = max(1, ...($completedAll === [] ? [1] : $completedAll));
$maxRated     = max(1, ...($ratedAll === [] ? [1] : $ratedAll));

$older = $activity['older'] ?? [];
$dash  = '&mdash;';

?>

<div class="page-intro">
    <p class="eyebrow">Personal Analytics &middot; no guesswork</p>
    <h1>My Analytics</h1>
    <p class="lead">How your reading and rating activity looks today.</p>
</div>

<?php if (!empty($analytics['empty'])): ?>
    <section data-animate>
        <?php $empty = [
            'icon'    => 'fa-chart-line',
            'title'   => 'Your analytics are waiting for you',
            'message' => 'Once you shelf a book, finish one or review a read, this page fills with your real numbers: your shelves, the genres and authors you read, your rating style and your reading activity over time. Every number comes from something you actually did - nothing here is ever estimated or guessed.',
            'action'  => ['label' => 'Browse the catalogue', 'href' => '/books'],
        ]; ?>
        <?php require root_path('app/Views/components/empty-state.php'); ?>
    </section>
<?php else: ?>
    <section data-animate>
        <div class="row g-3 g-xl-4 row-cols-2 row-cols-md-3 row-cols-xl-4">
            <?php
            $cards = [
                ['icon' => 'fa-book',               'label' => 'Books Shelved',       'value' => (int) ($summary['shelved'] ?? 0),   'tone' => 'primary'],
                ['icon' => 'fa-circle-check',       'label' => 'Books Finished',      'value' => (int) ($summary['completed'] ?? 0), 'tone' => 'success'],
                ['icon' => 'fa-book-open-reader',   'label' => 'Currently Reading',   'value' => (int) ($summary['reading'] ?? 0),   'tone' => 'warning'],
                ['icon' => 'fa-heart',              'label' => 'Wishlist',            'value' => (int) ($summary['wishlist'] ?? 0),  'tone' => 'info'],
                ['icon' => 'fa-flag-checkered',     'label' => 'Completion Rate',     'value' => number_format((float) ($summary['completionRate'] ?? 0), 1) . '%', 'tone' => 'primary'],
                ['icon' => 'fa-calendar-check',     'label' => 'Active Reading Days', 'value' => (int) ($summary['activeDays'] ?? 0), 'tone' => 'info'],
                ['icon' => 'fa-star',               'label' => 'Reviews Written',     'value' => (int) ($summary['reviews'] ?? 0),   'tone' => 'warning'],
                ['icon' => 'fa-star-half-stroke',   'label' => 'Average Rating',      'value' => $summary['averageRating'] === null ? $dash : number_format((float) $summary['averageRating'], 1), 'tone' => 'danger'],
            ];
            foreach ($cards as $stat):
                require root_path('app/Views/components/stat-card.php');
            endforeach;
            ?>
        </div>
    </section>

    <?php
    // Phase 12.5: the visual layer. The charts are shaped from the SAME
    // payload (ChartPresenter only re-formats; the numbers are the
    // service's own). Every canvas is paired with a summary sentence
    // and the sections below stay as the tabular alternative.
    $chartConfig = static function (string $method, ...$args): string {
        try {
            return (string) \BookSphere\App\Presenters\ChartPresenter::$method(...$args);
        } catch (JsonException) {
            return '';
        }
    };

    $shelfChart = '';
    $shelfNumbers = array_values(array_map(static fn (string $status): int => (int) ($shelf[$status] ?? 0), array_keys($shelfLabels)));
    $shelfSummary = array_sum($shelfNumbers) > 0
        ? 'Your shelf split: ' . implode(', ', array_map(
            static fn (string $label, int $count): string => $count . ' ' . strtolower(e($label)),
            array_keys($shelfLabels),
            $shelfNumbers,
        ))
        : '';
    if ($shelfSummary !== '') {
        $shelfChart = $chartConfig('doughnut', 'shelf', array_values($shelfLabels), $shelfNumbers, $shelfSummary);
    }

    $chrono = array_reverse($months);
    $monthLabels = array_map(static fn (array $m): string => (string) ($m['label'] ?? ''), $chrono);
    $monthCompleted = array_map(static fn (array $m): int => (int) ($m['completed'] ?? 0), $chrono);
    $monthRated = array_map(static fn (array $m): int => (int) ($m['rated'] ?? 0), $chrono);
    $monthlySummary = array_sum($monthCompleted) + array_sum($monthRated) > 0
        ? 'In the last ' . count($months) . ' months you finished ' . array_sum($monthCompleted) . ' books and wrote ' . array_sum($monthRated) . ' reviews.'
        : '';
    $monthlyChart = $monthlySummary !== ''
        ? $chartConfig('line', 'monthly', $monthLabels, [
            ['label' => 'Books finished', 'tone' => 'success', 'values' => $monthCompleted],
            ['label' => 'Reviews written', 'tone' => 'warning', 'values' => $monthRated],
        ], $monthlySummary)
        : '';

    $genreRows = $genres['rows'] ?? [];
    $genreSummary = $genreRows !== []
        ? 'Genre membership of your finished books: ' . implode(', ', array_map(
            static fn (array $r): string => $r['name'] . ' ' . round((float) $r['percent'], 1) . '%',
            $genreRows,
        ))
        : '';
    $genreChart = $genreSummary !== ''
        ? $chartConfig('hbar', 'genres', array_map(static fn (array $r): string => (string) $r['name'], $genreRows),
            array_map(static fn (array $r): float => (float) $r['percent'], $genreRows), $genreSummary)
        : '';

    $distribution = $reviews['distribution'] ?? [];
    $distributionSummary = array_sum(array_values($distribution)) > 0
        ? 'You gave ' . array_sum(array_values($distribution)) . ' ratings in total: ' . implode(', ', array_map(
            static fn (int $star, int $count): string => $count . ' at ' . $star . ' stars',
            array_keys($distribution),
            array_values($distribution),
        ))
        : '';
    $distributionChart = $distributionSummary !== ''
        ? $chartConfig('bar', 'ratings', array_map(static fn (int $star): string => $star . ' star' . ($star === 1 ? '' : 's'), array_keys($distribution)),
            [['label' => 'Reviews', 'tone' => 'warning', 'values' => array_map('intval', array_values($distribution))]],
            $distributionSummary)
        : '';
    ?>
    <section class="dash-section" data-animate>
        <div class="d-flex justify-content-between align-items-end mb-3">
            <div>
                <p class="eyebrow mb-1">Visuals &middot; the picture of the same numbers</p>
                <h2 class="h3 mb-0">At a glance</h2>
            </div>
            <a class="btn btn-outline-secondary btn-sm print-hidden" href="/analytics/report">
                <i class="fa-solid fa-print me-1" aria-hidden="true"></i> Print-friendly report
            </a>
        </div>

        <div class="row g-3 g-xl-4">
            <div class="col-12 col-md-6 col-xl-3">
                <?php $chartEyebrow = 'Shelf split'; $chartTitle = 'Where your books sit'; $chartTrend = ''; $chart = $shelfChart; $chartSummary = $shelfSummary; ?>
                <?php require root_path('app/Views/components/chart-card.php'); ?>
            </div>
            <div class="col-12 col-md-6 col-xl-4">
                <?php $chartEyebrow = 'Monthly activity'; $chartTitle = 'Finished &amp; rated'; $chartTrend = ''; $chart = $monthlyChart; $chartSummary = $monthlySummary; ?>
                <?php require root_path('app/Views/components/chart-card.php'); ?>
            </div>
            <div class="col-12 col-md-6 col-xl-2">
                <?php $chartEyebrow = 'My genres'; $chartTitle = 'Share of reads'; $chartTrend = ''; $chart = $genreChart; $chartSummary = $genreSummary; ?>
                <?php require root_path('app/Views/components/chart-card.php'); ?>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <?php $chartEyebrow = 'Rating history'; $chartTitle = 'How I rate'; $chartTrend = ''; $chart = $distributionChart; $chartSummary = $distributionSummary; ?>
                <?php require root_path('app/Views/components/chart-card.php'); ?>
            </div>
        </div>
    </section>

    <section class="dash-section" data-animate>
        <div class="row g-3 g-xl-4">
            <div class="col-lg-6">
                <div class="card-base h-100">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <p class="eyebrow mb-1">Shelf breakdown</p>
                            <h2 class="h4 mb-0">Where your books sit</h2>
                        </div>
                        <span class="badge rounded-pill text-bg-light border"><?= (int) array_sum($shelf) ?> total</span>
                    </div>
                    <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                        <?php foreach ($shelfLabels as $status => $label): ?>
                            <?php $count = (int) ($shelf[$status] ?? 0); ?>
                            <li>
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-semibold"><?= e($label) ?></span>
                                    <span class="small muted"><?= $count ?> (<?= round($count / $shelfTotal * 100) ?>%)</span>
                                </div>
                                <div class="progress" role="progressbar"
                                     aria-valuenow="<?= $count ?>" aria-valuemin="0" aria-valuemax="<?= $shelfTotal ?>"
                                     aria-label="<?= e($label) ?>, <?= $count ?> of <?= (int) $shelfTotal ?> books">
                                    <div class="progress-bar bg-<?= e($shelfTones[$status]) ?>" style="width: <?= (int) round($count / $shelfTotal * 100) ?>%"></div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="muted small mt-3 mb-0">Every shelved book sits on exactly one shelf &mdash; the statuses the library module records. "Dropped" counts the books you abandoned.</p>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card-base h-100">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <p class="eyebrow mb-1">Reading activity</p>
                            <h2 class="h4 mb-0">Last <?= count($months) ?> months</h2>
                        </div>
                        <?php $olderTotal = (int) ($older['completed'] ?? 0) + (int) ($older['rated'] ?? 0); ?>
                        <?php if ($olderTotal > 0): ?>
                            <span class="badge rounded-pill text-bg-light border">+<?= $olderTotal ?> earlier</span>
                        <?php endif; ?>
                    </div>

                    <p class="small fw-semibold text-uppercase tracking-wide mt-1 mb-1">Books finished per month</p>
                    <ul class="list-unstyled d-flex flex-column gap-2 mb-3">
                        <?php foreach (array_reverse($months) as $month): ?>
                            <li class="d-flex align-items-center gap-2">
                                <span class="small text-nowrap" style="min-width: 3.4rem; flex-shrink: 0"><?= e($month['label']) ?></span>
                                <div class="progress flex-grow-1" role="progressbar"
                                     aria-valuenow="<?= (int) $month['completed'] ?>" aria-valuemin="0" aria-valuemax="<?= $maxCompleted ?>"
                                     aria-label="<?= (int) $month['completed'] ?> books finished in <?= e($month['label']) ?>">
                                    <div class="progress-bar bg-success" style="width: <?= (int) round((int) $month['completed'] / $maxCompleted * 100) ?>%"></div>
                                </div>
                                <span class="small muted text-nowrap" style="width: 1.6rem; text-align: right"><?= (int) $month['completed'] ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <p class="small fw-semibold text-uppercase tracking-wide mb-1">Reviews written per month</p>
                    <ul class="list-unstyled d-flex flex-column gap-2 mb-0">
                        <?php foreach (array_reverse($months) as $month): ?>
                            <li class="d-flex align-items-center gap-2">
                                <span class="small text-nowrap" style="min-width: 3.4rem; flex-shrink: 0"><?= e($month['label']) ?></span>
                                <div class="progress flex-grow-1" role="progressbar"
                                     aria-valuenow="<?= (int) $month['rated'] ?>" aria-valuemin="0" aria-valuemax="<?= $maxRated ?>"
                                     aria-label="<?= (int) $month['rated'] ?> reviews in <?= e($month['label']) ?>">
                                    <div class="progress-bar bg-warning" style="width: <?= (int) round((int) $month['rated'] / $maxRated * 100) ?>%"></div>
                                </div>
                                <span class="small muted text-nowrap" style="width: 1.6rem; text-align: right"><?= (int) $month['rated'] ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="muted small mt-3 mb-0">Timestamps are the ones the library and review services recorded at the moment of action &mdash; never backdated, never guessed.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="dash-section" data-animate>
        <div class="row g-3 g-xl-4">
            <div class="col-lg-6">
                <div class="card-base h-100">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <p class="eyebrow mb-1">Genres I read</p>
                            <h2 class="h4 mb-0">Top <?= count($genres['rows'] ?? []) ?> of <?= (int) ($genres['unique'] ?? 0) ?></h2>
                        </div>
                    </div>
                    <?php if (empty($genres['rows'] ?? [])): ?>
                        <p class="muted">Finish a book and its genres appear here.</p>
                    <?php else: ?>
                        <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                            <?php foreach ($genres['rows'] as $row): ?>
                                <li>
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="fw-semibold"><?= e($row['name']) ?></span>
                                        <span class="small muted"><?= (int) $row['books'] ?> book<?= (int) $row['books'] === 1 ? '' : 's' ?> &middot; <?= number_format((float) $row['percent'], 1) ?>%</span>
                                    </div>
                                    <div class="progress" role="progressbar"
                                         aria-valuenow="<?= (int) round((float) $row['percent']) ?>" aria-valuemin="0" aria-valuemax="100"
                                         aria-label="<?= e($row['name']) ?>, <?= number_format((float) $row['percent'], 1) ?> percent of your reads">
                                        <div class="progress-bar bg-info" style="width: <?= (int) min(100, round((float) $row['percent'])) ?>%"></div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <p class="muted small mt-3 mb-0">Percentages are genre-membership shares of the books you finished. A book in two genres counts once in each &mdash; no book or genre is ever double-counted.</p>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card-base h-100">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <p class="eyebrow mb-1">Authors I read</p>
                            <h2 class="h4 mb-0">Top <?= count($authors['rows'] ?? []) ?> of <?= (int) ($authors['unique'] ?? 0) ?></h2>
                        </div>
                    </div>
                    <?php if (empty($authors['rows'] ?? [])): ?>
                        <p class="muted">Finish a book and its authors appear here.</p>
                    <?php else: ?>
                        <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                            <?php foreach ($authors['rows'] as $row): ?>
                                <li>
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="fw-semibold"><?= e($row['name']) ?></span>
                                        <span class="small muted"><?= (int) $row['books'] ?> book<?= (int) $row['books'] === 1 ? '' : 's' ?> &middot; <?= number_format((float) $row['percent'], 1) ?>%</span>
                                    </div>
                                    <div class="progress" role="progressbar"
                                         aria-valuenow="<?= (int) round((float) $row['percent']) ?>" aria-valuemin="0" aria-valuemax="100"
                                         aria-label="<?= e($row['name']) ?>, <?= number_format((float) $row['percent'], 1) ?> percent of your books">
                                        <div class="progress-bar bg-warning" style="width: <?= (int) min(100, round((float) $row['percent'])) ?>%"></div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <p class="muted small mt-3 mb-0">Co-authored books count once per author. The unique number is DISTINCT authors in SQL &mdash; the sync join can never double one.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="dash-section" data-animate>
        <div class="row g-3 g-xl-4">
            <div class="col-lg-6">
                <div class="card-base h-100">
                    <p class="eyebrow mb-1">Rating style</p>
                    <h2 class="h4 mb-3">How I rate</h2>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <div class="analytics-tile h-100">
                                <span class="analytics-tile-value"><?= (int) ($reviews['total'] ?? 0) ?></span>
                                <span class="analytics-tile-label">Reviews written</span>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="analytics-tile h-100">
                                <span class="analytics-tile-value"><?= $reviews['average'] === null ? $dash : number_format((float) $reviews['average'], 1) ?></span>
                                <span class="analytics-tile-label">Average rating given <?= $reviews['average'] === null ? '&mdash; no ratings yet' : '&middot; of 5' ?></span>
                            </div>
                        </div>
                    </div>

                    <?php $distribution = $reviews['distribution'] ?? []; ?>
                    <?php if ((int) ($reviews['total'] ?? 0) === 0): ?>
                        <p class="muted">No approved ratings yet &mdash; rate a book and the distribution fills below.</p>
                    <?php else: ?>
                        <?php $distributionMax = max(1, ...array_values($distribution)); ?>
                        <ul class="list-unstyled d-flex flex-column gap-2 mb-0">
                            <?php foreach (array_reverse($distribution, true) as $stars => $count): ?>
                                <li class="d-flex align-items-center gap-2">
                                    <span class="small text-nowrap" style="width: 2.1rem" aria-hidden="true"><i class="fa-solid fa-star fa-xs text-warning me-1"></i><?= $stars ?></span>
                                    <div class="progress flex-grow-1" role="progressbar"
                                         aria-valuenow="<?= (int) $count ?>" aria-valuemin="0" aria-valuemax="<?= $distributionMax ?>"
                                         aria-label="<?= $stars ?>-star rating: <?= (int) $count ?> review<?= (int) $count === 1 ? '' : 's' ?>">
                                        <div class="progress-bar bg-warning" style="width: <?= (int) round((int) $count / $distributionMax * 100) ?>%"></div>
                                    </div>
                                    <span class="small muted text-nowrap" style="width: 1.6rem; text-align: right"><?= (int) $count ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if ($reviews['favourite'] !== null): ?>
                            <p class="muted small mt-3 mb-0">Your most frequent rating is <strong><?= (int) $reviews['favourite'] ?>&nbsp;star<?= (int) $reviews['favourite'] === 1 ? '' : 's' ?></strong>.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card-base h-100">
                    <p class="eyebrow mb-1">Recent activity</p>
                    <h2 class="h4 mb-3">Latest moments</h2>

                    <?php $recent = $activity['recent'] ?? []; ?>
                    <?php if ($recent === []): ?>
                        <p class="muted">Finish a book, start one, rate a read or shelf a wish &mdash; the timeline fills with the real moments.</p>
                    <?php else: ?>
                        <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                            <?php foreach ($recent as $event): ?>
                                <?php $eventIcon = [
                                    'finished' => 'fa-circle-check',
                                    'started'  => 'fa-book-open',
                                    'rated'    => 'fa-star',
                                    'shelved'  => 'fa-heart',
                                ][$event['type']] ?? 'fa-circle'; ?>
                                <li class="d-flex align-items-start gap-3">
                                    <span class="stat-icon tone-<?= $event['type'] === 'finished' ? 'success' : ($event['type'] === 'rated' ? 'warning' : 'info') ?>" aria-hidden="true">
                                        <i class="fa-solid <?= $eventIcon ?>"></i>
                                    </span>
                                    <div class="flex-grow-1">
                                        <span class="d-block fw-semibold"><?= e($event['label']) ?></span>
                                        <span class="d-block small muted"><?= e($event['book_title']) ?></span>
                                    </div>
                                    <span class="small muted text-nowrap"><?= e(gmdate('M j, Y', strtotime((string) $event['at']))) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php $olderCompleted = (int) ($older['completed'] ?? 0); ?>
                        <?php $olderRated     = (int) ($older['rated'] ?? 0); ?>
                        <?php if ($olderCompleted > 0 || $olderRated > 0): ?>
                            <p class="muted small mt-3 mb-0">Plus <?= $olderCompleted ?> completion<?= $olderCompleted === 1 ? '' : 's' ?> and <?= $olderRated ?> rating<?= $olderRated === 1 ? '' : 's' ?> before the window above.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <p class="muted small text-center mt-4 mb-0">
        Snapshot generated <?= e(gmdate('M j, Y H:i', strtotime((string) ($analytics['generatedAt'] ?? 'now')))) ?> UTC. Every number derives from your libraries and your approved reviews.
    </p>
<?php endif; ?>