<?php

declare(strict_types=1);

/**
 * book_analytics/index.php
 *
 * The BOOK ANALYTICS page (Phase 12.2): the catalogue-wide numbers -
 * shelves, ratings, rankings, metadata and monthly activity - all
 * computed by BookAnalyticsService from the real rows (approved
 * reviews, user_library shelves, the genre/author joins). Nothing is
 * estimated.
 *
 * Available variables (from BookAnalyticsController::index):
 *     $analytics - the BookAnalytics::toArray() payload:
 *         empty       - true only when the catalogue has no visible
 *                       books (the guidance empty state)
 *         overview    - books, reviews, averageRating (null),
 *                       distribution (1..5), with_covers,
 *                       without_covers, with_year, with_publisher,
 *                       with_pages, imported
 *         shelves     - the five library statuses -> (book, user) pairs
 *         rankings    - highestRated (average, count), mostReviewed,
 *                       mostWishlisted, mostRead, mostEngaged,
 *                       popular and trending (score in [0, 1])
 *         metadata    - genres (unique/size/reading), authors
 *                       (unique/size/reading), publishers, languages,
 *                       years, pageRanges
 *         activity    - recent (the trailing window' snapshot),
 *                       window (the $months calendar rows), older
 *         generatedAt - UTC ISO timestamp of the snapshot
 *
 * Design: mirrors the Phase 12.1 personal page (same components,
 * same bar language); every empty list carries the reason it is
 * empty - a ranking with no qualifying book is "waiting for data",
 * never a fabricated list.
 */

$analytics = $analytics ?? [];
$overview  = $analytics['overview'] ?? [];
$shelves   = $analytics['shelves'] ?? [];
$rankings  = $analytics['rankings'] ?? [];
$metadata  = $analytics['metadata'] ?? [];
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

$shelfTotal = max(1, (int) array_sum($shelves));

$dash = '&mdash;';

$distribution = $overview['distribution'] ?? [];
$distributionMax = max(1, ...($distribution === [] ? [1] : array_values($distribution)));

$rankList = static function (array $rows): string {
    ob_start();
    ?>
    <ul class="analytics-rank-list">
        <?php foreach ($rows as $i => $row): ?>
            <li class="analytics-rank-row">
                <span class="analytics-rank-number"><?= sprintf('%02d', (int) $i + 1) ?></span>
                <div class="analytics-rank-cover">
                    <?php $cover = [
                        'src'   => (string) ($row['cover'] ?? ''),
                        'alt'   => (string) $row['title'],
                        'class' => 'table-cover',
                    ]; ?>
                    <?php require root_path('app/Views/books/components/book-cover.php'); ?>
                </div>
                <div class="analytics-rank-info">
                    <a class="analytics-rank-title" href="/books/<?= (int) $row['id'] ?>"><?= e((string) $row['title']) ?></a>
                    <?php if (!empty($row['author_name'])): ?>
                        <span class="analytics-rank-author"><?= e((string) $row['author_name']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="analytics-rank-metric">
                    <?php if (isset($row['average'])): ?>
                        <span class="badge rounded-pill text-bg-warning mb-1"><i class="fa-solid fa-star fa-xs" aria-hidden="true"></i> <?= e(format_rating($row['average'])) ?></span>
                        <span class="small text-muted text-nowrap"><?= (int) $row['count'] ?> review<?= (int) $row['count'] === 1 ? '' : 's' ?></span>
                    <?php elseif (isset($row['score'])): ?>
                        <span class="analytics-metric-value"><?= number_format((float) $row['score'] * 100, 1) ?>%</span>
                    <?php else: ?>
                        <span class="badge rounded-pill text-bg-light border"><?= (int) $row['count'] ?></span>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php
    return (string) ob_get_clean();
};

$emptyNote = static fn (string $why): string =>
    '<p class="muted small mb-0">' . $why . ' &mdash; the ranking fills once real activity arrives.</p>';
?>

<div class="page-intro">
    <p class="eyebrow">Catalogue Analytics &middot; real numbers</p>
    <h1>Book Analytics</h1>
    <p class="lead">What the whole catalogue says today: shelves, ratings, rankings, metadata and activity over time.</p>
</div>

<?php if (!empty($analytics['empty'])): ?>
    <section data-animate>
        <?php $empty = [
            'icon'    => 'fa-chart-pie',
            'title'   => 'The catalogue is waiting for books',
            'message' => 'Once the catalogue holds published books, this page fills with real aggregates: the shelves users pick, the ratings approved reviews produce, the genres, authors and metadata spread, and the monthly activity of the whole community. Nothing here is ever estimated or guessed.',
            'action'  => ['label' => 'Browse Books', 'href' => '/books'],
        ]; ?>
        <?php require root_path('app/Views/components/empty-state.php'); ?>
    </section>
<?php else: ?>
    <section data-animate>
        <div class="row g-3 g-xl-4 row-cols-2 row-cols-md-3 row-cols-xl-4">
            <?php
            $cards = [
                ['icon' => 'fa-book',               'label' => 'Books in Catalogue', 'value' => (int) ($overview['books'] ?? 0),          'tone' => 'primary'],
                ['icon' => 'fa-star',               'label' => 'Approved Reviews',    'value' => (int) ($overview['reviews'] ?? 0),        'tone' => 'warning'],
                ['icon' => 'fa-star-half-stroke',   'label' => 'Average Rating',      'value' => $overview['averageRating'] === null ? $dash : format_rating($overview['averageRating']), 'tone' => 'danger'],
                ['icon' => 'fa-book-open-cover',    'label' => 'Books with Covers',   'value' => (int) ($overview['with_covers'] ?? 0),    'tone' => 'info'],
            ];
            foreach ($cards as $stat):
                require root_path('app/Views/components/stat-card.php');
            endforeach;
            ?>
        </div>
    </section>

    <?php
    $chartP = '\BookSphere\App\Presenters\ChartPresenter';

    $shelfChart = '';
    $shelfTotals = array_map(static fn (string $status): int => (int) ($shelves[$status] ?? 0), array_keys($shelfLabels));
    $shelfSummary = array_sum($shelfTotals) > 0
        ? 'Community shelves across all users: ' . implode(', ', array_map(
            static fn (string $label, int $count): string => $count . ' ' . strtolower($label),
            array_values($shelfLabels),
            $shelfTotals,
        ))
        : '';
    if ($shelfSummary !== '') {
        $shelfChart = (string) $chartP::doughnut('shelves', array_values($shelfLabels), $shelfTotals, $shelfSummary);
    }

    $windowRows = array_reverse($activity['window'] ?? []);
    $monthlySummary = $windowRows !== []
        ? 'Monthly community activity: ' . implode(', ', array_map(
            static fn (array $m): string => $m['label'] . ' (' . (int) $m['reviews'] . ' reviews / ' . (int) $m['finishes'] . ' finishes)',
            $windowRows,
        )) . '.'
        : '';
    $monthlyChart = $monthlySummary !== ''
        ? (string) $chartP::bar('monthly', array_map(static fn (array $m): string => (string) $m['label'], $windowRows), [
            ['label' => 'Approved reviews', 'tone' => 'warning', 'values' => array_map(static fn (array $m): int => (int) $m['reviews'], $windowRows)],
            ['label' => 'Books finished',   'tone' => 'success', 'values' => array_map(static fn (array $m): int => (int) $m['finishes'], $windowRows)],
        ], $monthlySummary)
        : '';

    $chartLanguages = array_slice($metadata['languages'] ?? [], 0, 8);
    $languageSummary = $chartLanguages !== []
        ? 'Catalogue by language: ' . implode(', ', array_map(
            static fn (array $r): string => $r['language'] . ' ' . (int) $r['books'],
            $chartLanguages,
        )) . '.'
        : '';
    $languageChart = $languageSummary !== ''
        ? (string) $chartP::hbar('languages', array_map(static fn (array $r): string => (string) $r['language'], $chartLanguages),
            array_map(static fn (array $r): int => (int) $r['books'], $chartLanguages), $languageSummary)
        : '';

    $chartPages = $metadata['pageRanges'] ?? [];
    $pagesSummary = $chartPages !== []
        ? 'Catalogue length spread: ' . implode(', ', array_map(
            static fn (array $r): string => $r['label'] . ' ' . (int) $r['books'],
            $chartPages,
        )) . '.'
        : '';
    $pagesChart = $pagesSummary !== ''
        ? (string) $chartP::bar('pages', array_map(static fn (array $r): string => (string) $r['label'], $chartPages),
            [['label' => 'Books', 'tone' => 'primary', 'values' => array_map(static fn (array $r): int => (int) $r['books'], $chartPages)]],
            $pagesSummary)
        : '';

    $popular  = $rankings['popular'] ?? [];
    $trending = $rankings['trending'] ?? [];
    $topBook  = $popular[0] ?? ($rankings['highestRated'][0] ?? null);
    ?>

    <!-- Main Analytics Section (Ranked Books + Featured Book Panel) -->
    <section class="dash-section" data-animate>
        <div class="row g-3 g-xl-4 align-items-start">
            <!-- Left Column: Performance Lists -->
            <div class="col-lg-7 col-xl-8 d-flex flex-column gap-3 gap-xl-4">
                <div class="card-base">
                    <p class="eyebrow mb-1">Popularity</p>
                    <h2 class="h4 mb-3">Most popular right now</h2>
                    <?php if ($popular === []): ?>
                        <?= $emptyNote('Popularity needs at least one review or wishlist entry') ?>
                    <?php else: ?>
                        <?= $rankList($popular) ?>
                        <p class="muted small mt-3 mb-0">Score = 40% rating + 30% review volume + 30% wishlist volume &middot; weights are tunable in config/book_analytics.php.</p>
                    <?php endif; ?>
                </div>

                <div class="card-base">
                    <p class="eyebrow mb-1">Trending</p>
                    <h2 class="h4 mb-3">Last <?= (int) ($activity['windowDays'] ?? 30) ?> days</h2>
                    <?php if ($trending === []): ?>
                        <?= $emptyNote('Trending needs recent activity inside the trailing window') ?>
                    <?php else: ?>
                        <?= $rankList($trending) ?>
                        <p class="muted small mt-3 mb-0">Score = 40% recent reviews + 30% recent wishlist adds + 30% recent finishes &middot; the window lives in config/book_analytics.php.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Column: Featured Book Panel + Summary Visuals -->
            <div class="col-lg-5 col-xl-4 d-flex flex-column gap-3 gap-xl-4">
                <?php if ($topBook !== null): ?>
                    <div class="analytics-featured-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <p class="eyebrow mb-0">Top Performer</p>
                            <span class="badge text-bg-primary rounded-pill"><i class="fa-solid fa-crown fa-xs me-1"></i> #1 Ranked</span>
                        </div>
                        <h2 class="h4 mb-3">Featured Book</h2>
                        <div class="analytics-featured-cover-wrap">
                            <?php $cover = [
                                'src'   => (string) ($topBook['cover'] ?? ''),
                                'alt'   => (string) $topBook['title'],
                                'class' => 'analytics-featured-cover',
                            ]; ?>
                            <?php require root_path('app/Views/books/components/book-cover.php'); ?>
                        </div>
                        <div class="analytics-featured-info">
                            <a class="analytics-featured-title" href="/books/<?= (int) $topBook['id'] ?>"><?= e((string) $topBook['title']) ?></a>
                            <?php if (!empty($topBook['author_name'])): ?>
                                <div class="analytics-featured-author"><?= e((string) $topBook['author_name']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="analytics-featured-stats">
                            <?php if (isset($topBook['score'])): ?>
                                <div class="analytics-featured-stat-item">
                                    <div class="analytics-featured-stat-val text-primary"><?= number_format((float) $topBook['score'] * 100, 1) ?>%</div>
                                    <div class="analytics-featured-stat-lbl">Popularity Score</div>
                                </div>
                            <?php elseif (isset($topBook['average'])): ?>
                                <div class="analytics-featured-stat-item">
                                    <div class="analytics-featured-stat-val text-warning"><i class="fa-solid fa-star fa-xs me-1"></i><?= e(format_rating($topBook['average'])) ?></div>
                                    <div class="analytics-featured-stat-lbl">Rating</div>
                                </div>
                            <?php endif; ?>
                            <?php if (isset($topBook['count'])): ?>
                                <div class="analytics-featured-stat-item">
                                    <div class="analytics-featured-stat-val"><?= (int) $topBook['count'] ?></div>
                                    <div class="analytics-featured-stat-lbl">Volume</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($shelfChart !== ''): ?>
                    <div>
                        <?php $chartEyebrow = 'Community shelves'; $chartTitle = 'All five statuses'; $chartTrend = ''; $chart = $shelfChart; $chartSummary = $shelfSummary; ?>
                        <?php require root_path('app/Views/components/chart-card.php'); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Additional Visuals Section -->
    <section class="dash-section" data-animate>
        <div class="d-flex justify-content-between align-items-end mb-3">
            <div>
                <p class="eyebrow mb-1">Visuals &middot; the same numbers as pictures</p>
                <h2 class="h3 mb-0">Catalogue at a glance</h2>
            </div>
            <?php if ((auth_user()['role'] ?? '') === 'admin'): ?>
                <a class="btn btn-outline-secondary btn-sm print-hidden" href="/admin/analytics/report">
                    <i class="fa-solid fa-print me-1" aria-hidden="true"></i> Catalogue report
                </a>
            <?php endif; ?>
        </div>
        <div class="row g-3 g-xl-4">
            <div class="col-12 col-md-4">
                <?php $chartEyebrow = 'Per calendar month'; $chartTitle = 'Reviews &amp; finishes'; $chartTrend = ''; $chart = $monthlyChart; $chartSummary = $monthlySummary; ?>
                <?php require root_path('app/Views/components/chart-card.php'); ?>
            </div>
            <div class="col-12 col-md-4">
                <?php $chartEyebrow = 'Top languages'; $chartTitle = 'Catalogue mix'; $chartTrend = ''; $chart = $languageChart; $chartSummary = $languageSummary; ?>
                <?php require root_path('app/Views/components/chart-card.php'); ?>
            </div>
            <div class="col-12 col-md-4">
                <?php $chartEyebrow = 'Page count'; $chartTitle = 'Length spread'; $chartTrend = ''; $chart = $pagesChart; $chartSummary = $pagesSummary; ?>
                <?php require root_path('app/Views/components/chart-card.php'); ?>
            </div>
        </div>
    </section>

    <section class="dash-section" data-animate>
        <div class="row row-cols-1 row-cols-lg-2 g-3 g-xl-4">
            <?php
            $boards = [
                ['key' => 'highestRated', 'title' => 'Highest Rated', 'eyebrow' => 'Average &middot; real reviews only',
                 'tone' => 'warning', 'note' => 'Ranked by the real average of APPROVED reviews &middot; a book needs at least the configured minimum count to qualify.'],
                ['key' => 'mostReviewed', 'title' => 'Most Reviewed', 'eyebrow' => 'Approved reviews',
                 'tone' => 'primary', 'note' => 'Approved reviews per book &middot; pending and hidden reviews never count.'],
                ['key' => 'mostWishlisted', 'title' => 'Most Wishlisted', 'eyebrow' => 'The want&nbsp;to&nbsp;read shelf',
                 'tone' => 'info', 'note' => 'The modern wishlist shelf &middot; one user per book per shelf, so no user is ever counted twice.'],
                ['key' => 'mostRead', 'title' => 'Most Read', 'eyebrow' => 'Finished records',
                 'tone' => 'success', 'note' => 'Finished library records &middot; a user can finish a book only once.'],
                ['key' => 'mostEngaged', 'title' => 'Most Engaged', 'eyebrow' => 'Distinct participants',
                 'tone' => 'danger', 'note' => 'Distinct users across shelves AND reviews &middot; a user who both shelves and reviews still counts once.'],
            ];
            foreach ($boards as $board):
                $rows = $rankings[$board['key']] ?? [];
                ?>
                <div class="col">
                    <div class="card-base h-100">
                        <p class="eyebrow mb-1"><?= $board['eyebrow'] ?></p>
                        <h2 class="h4 mb-3"><?= $board['title'] ?></h2>
                        <?php if ($rows === []): ?>
                            <?= $emptyNote('No book qualifies yet') ?>
                        <?php else: ?>
                            <?= $rankList($rows) ?>
                            <p class="muted small mt-3 mb-0"><?= $board['note'] ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="dash-section" data-animate>
        <div class="row g-3 g-xl-4">
            <div class="col-lg-6">
                <div class="card-base h-100">
                    <p class="eyebrow mb-1">Rating distribution</p>
                    <h2 class="h4 mb-3">How the community rates</h2>
                    <?php if ((int) ($overview['reviews'] ?? 0) === 0): ?>
                        <p class="muted">No approved ratings yet &mdash; the distribution fills with the first ones.</p>
                    <?php else: ?>
                        <ul class="list-unstyled d-flex flex-column gap-2 mb-3">
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
                        <p class="muted small mb-0">Only approved reviews count &mdash; the same rule the profile and the review pages use.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card-base h-100">
                    <p class="eyebrow mb-1">Recent activity</p>
                    <h2 class="h4 mb-3">Last <?= (int) ($activity['windowDays'] ?? 30) ?> days</h2>
                    <?php $recent = $activity['recent'] ?? []; ?>
                    <div class="row g-3 mb-3">
                        <div class="col-4">
                            <div class="analytics-tile h-100">
                                <span class="analytics-tile-value"><?= (int) ($recent['recent_reviews'] ?? 0) ?></span>
                                <span class="analytics-tile-label">Reviews</span>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="analytics-tile h-100">
                                <span class="analytics-tile-value"><?= (int) ($recent['recent_interests'] ?? 0) ?></span>
                                <span class="analytics-tile-label">Wishlisted</span>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="analytics-tile h-100">
                                <span class="analytics-tile-value"><?= (int) ($recent['recent_finishes'] ?? 0) ?></span>
                                <span class="analytics-tile-label">Finished</span>
                            </div>
                        </div>
                    </div>
                    <p class="muted small mb-0">The three signals that also feed the trending score &mdash; counted from real timestamps inside the window, nothing older leaks in.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="dash-section" data-animate>
        <p class="eyebrow mb-1">Genres &amp; authors</p>
        <h2 class="h4 mb-3">What the catalogue is made of</h2>
        <div class="row row-cols-1 row-cols-lg-2 g-3 g-xl-4">
            <div class="col">
                <div class="card-base h-100">
                    <p class="eyebrow mb-1">Genres &middot; catalogue size</p>
                    <h3 class="h5 mb-3"><?= (int) ($metadata['genres']['unique'] ?? 0) ?> distinct genres</h3>
                    <?php $genresSize = $metadata['genres']['size'] ?? []; ?>
                    <?php if ($genresSize === []): ?>
                        <p class="muted small mb-0">No genre links yet &mdash; books without a category appear here as missing, never as a bucket.</p>
                    <?php else: ?>
                        <ul class="list-unstyled d-flex flex-column gap-2 mb-0">
                            <?php foreach ($genresSize as $row): ?>
                                <li class="d-flex justify-content-between align-items-center gap-2">
                                    <span class="fw-semibold text-truncate"><?= e($row['name']) ?></span>
                                    <span class="small muted text-nowrap"><?= (int) $row['books'] ?> book<?= (int) $row['books'] === 1 ? '' : 's' ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col">
                <div class="card-base h-100">
                    <p class="eyebrow mb-1">Genres &middot; read the most</p>
                    <h3 class="h5 mb-3">Genre popularities</h3>
                    <?php $genresReading = $metadata['genres']['reading'] ?? []; ?>
                    <?php if ($genresReading === []): ?>
                        <p class="muted small mb-0">A finished book marks its genres &mdash; the first finish fills this list.</p>
                    <?php else: ?>
                        <ul class="list-unstyled d-flex flex-column gap-2 mb-0">
                            <?php foreach ($genresReading as $row): ?>
                                <li class="d-flex justify-content-between align-items-center gap-2">
                                    <span class="fw-semibold text-truncate"><?= e($row['name']) ?></span>
                                    <span class="small muted text-nowrap"><?= (int) $row['count'] ?> finish<?= (int) $row['count'] === 1 ? '' : 'es' ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <p class="muted small mt-3 mb-0">Catalogue size (top-left) and popularity (this list) are separate questions &mdash; a genre with many books is not necessarily the most read.</p>
                </div>
            </div>
            <div class="col">
                <div class="card-base h-100">
                    <p class="eyebrow mb-1">Authors &middot; catalogue size</p>
                    <h3 class="h5 mb-3"><?= (int) ($metadata['authors']['unique'] ?? 0) ?> distinct authors</h3>
                    <?php $authorsSize = $metadata['authors']['size'] ?? []; ?>
                    <?php if ($authorsSize === []): ?>
                        <p class="muted small mb-0">No author links yet.</p>
                    <?php else: ?>
                        <ul class="list-unstyled d-flex flex-column gap-2 mb-0">
                            <?php foreach ($authorsSize as $row): ?>
                                <li class="d-flex justify-content-between align-items-center gap-2">
                                    <span class="fw-semibold text-truncate"><?= e($row['name']) ?></span>
                                    <span class="small muted text-nowrap"><?= (int) $row['books'] ?> book<?= (int) $row['books'] === 1 ? '' : 's' ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <p class="muted small mt-3 mb-0">Co-authored books count once per author; the DISTINCT counts live in SQL, so no junction join can ever double one.</p>
                </div>
            </div>
            <div class="col">
                <div class="card-base h-100">
                    <p class="eyebrow mb-1">Authors &middot; read the most</p>
                    <h3 class="h5 mb-3">Finished by author</h3>
                    <?php $authorsReading = $metadata['authors']['reading'] ?? []; ?>
                    <?php if ($authorsReading === []): ?>
                        <p class="muted small mb-0">Finish a book and its author appear here.</p>
                    <?php else: ?>
                        <ul class="list-unstyled d-flex flex-column gap-2 mb-0">
                            <?php foreach ($authorsReading as $row): ?>
                                <li class="d-flex justify-content-between align-items-center gap-2">
                                    <span class="fw-semibold text-truncate"><?= e($row['name']) ?></span>
                                    <span class="small muted text-nowrap"><?= (int) $row['count'] ?> finished<?= (int) $row['count'] === 1 ? '' : 'es' ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <section class="dash-section" data-animate>
        <div class="row g-3 g-xl-4">
            <div class="col-lg-4">
                <div class="card-base h-100">
                    <p class="eyebrow mb-1">Publishers</p>
                    <h2 class="h4 mb-3">By catalogue size</h2>
                    <?php $publishers = $metadata['publishers'] ?? []; ?>
                    <?php if ($publishers === []): ?>
                        <p class="muted small mb-0">No book carries a publisher yet.</p>
                    <?php else: ?>
                        <ul class="list-unstyled d-flex flex-column gap-2 mb-0">
                            <?php foreach ($publishers as $row): ?>
                                <li class="d-flex justify-content-between align-items-center gap-2">
                                    <span class="fw-semibold text-truncate"><?= e($row['name']) ?></span>
                                    <span class="small muted text-nowrap"><?= (int) $row['books'] ?> book<?= (int) $row['books'] === 1 ? '' : 's' ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card-base h-100">
                    <p class="eyebrow mb-1">Languages</p>
                    <h2 class="h4 mb-3">Of the catalogue</h2>
                    <?php $languages = $metadata['languages'] ?? []; ?>
                    <?php if ($languages === []): ?>
                        <p class="muted small mb-0">No book carries a language tag yet.</p>
                    <?php else: ?>
                        <ul class="list-unstyled d-flex flex-column gap-2 mb-0">
                            <?php foreach ($languages as $row): ?>
                                <li class="d-flex justify-content-between align-items-center gap-2">
                                    <span class="fw-semibold text-truncate"><?= e($row['language']) ?></span>
                                    <span class="small muted text-nowrap"><?= (int) $row['books'] ?> book<?= (int) $row['books'] === 1 ? '' : 's' ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card-base h-100">
                    <p class="eyebrow mb-1">Publication year</p>
                    <h2 class="h4 mb-3">Newest first</h2>
                    <?php $years = $metadata['years'] ?? []; ?>
                    <?php if ($years === []): ?>
                        <p class="muted small mb-0">No book carries a publication year yet.</p>
                    <?php else: ?>
                        <ul class="list-unstyled d-flex flex-column gap-2 mb-0">
                            <?php foreach ($years as $row): ?>
                                <li class="d-flex justify-content-between align-items-center gap-2">
                                    <span class="fw-semibold"><?= (int) $row['year'] ?></span>
                                    <span class="small muted text-nowrap"><?= (int) $row['books'] ?> book<?= (int) $row['books'] === 1 ? '' : 's' ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="row g-3 g-xl-4 mt-0">
            <div class="col-lg-6">
                <div class="card-base h-100">
                    <p class="eyebrow mb-1">Length of the catalogue</p>
                    <h2 class="h4 mb-3">Books by page count</h2>
                    <?php $pageRanges = $metadata['pageRanges'] ?? []; ?>
                    <?php if ($pageRanges === []): ?>
                        <p class="muted small mb-0">No book carries a page count yet.</p>
                    <?php else: ?>
                        <ul class="list-unstyled d-flex flex-column gap-2 mb-0">
                            <?php foreach ($pageRanges as $row): ?>
                                <li class="d-flex justify-content-between align-items-center gap-2">
                                    <span class="fw-semibold"><?= e($row['label']) ?></span>
                                    <span class="small muted text-nowrap"><?= (int) $row['books'] ?> book<?= (int) $row['books'] === 1 ? '' : 's' ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <section class="dash-section" data-animate>
        <p class="eyebrow mb-1">Activity over time</p>
        <h2 class="h4 mb-3">Per calendar month</h2>
        <div class="row row-cols-1 row-cols-lg-2 g-3 g-xl-4">
            <?php
            $window  = $activity['window'] ?? [];
            $older   = $activity['older'] ?? [];
            $olderTotal = (int) ($older['reviews'] ?? 0) + (int) ($older['finishes'] ?? 0);
            $maxReviews  = max(1, ...($window === [] ? [1] : array_map(static fn (array $m): int => (int) $m['reviews'], $window)));
            $maxFinishes = max(1, ...($window === [] ? [1] : array_map(static fn (array $m): int => (int) $m['finishes'], $window)));
            ?>
            <div class="col">
                <div class="card-base h-100">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <p class="eyebrow mb-1">Approved reviews</p>
                        <?php if ($olderTotal > 0): ?><span class="badge rounded-pill text-bg-light border">+<?= $olderTotal ?> earlier</span><?php endif; ?>
                    </div>
                    <ul class="list-unstyled d-flex flex-column gap-2 mb-0">
                        <?php foreach (array_reverse($window) as $month): ?>
                            <li class="d-flex align-items-center gap-2">
                                <span class="small text-nowrap" style="min-width: 3.4rem; flex-shrink: 0"><?= e($month['label']) ?></span>
                                <div class="progress flex-grow-1" role="progressbar"
                                     aria-valuenow="<?= (int) $month['reviews'] ?>" aria-valuemin="0" aria-valuemax="<?= $maxReviews ?>"
                                     aria-label="<?= (int) $month['reviews'] ?> reviews in <?= e($month['label']) ?>">
                                    <div class="progress-bar bg-warning" style="width: <?= (int) round((int) $month['reviews'] / $maxReviews * 100) ?>%"></div>
                                </div>
                                <span class="small muted text-nowrap" style="width: 1.6rem; text-align: right"><?= (int) $month['reviews'] ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <div class="col">
                <div class="card-base h-100">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <p class="eyebrow mb-1">Books finished</p>
                        <?php if ($olderTotal > 0): ?><span class="badge rounded-pill text-bg-light border">+<?= $olderTotal ?> earlier</span><?php endif; ?>
                    </div>
                    <ul class="list-unstyled d-flex flex-column gap-2 mb-0">
                        <?php foreach (array_reverse($window) as $month): ?>
                            <li class="d-flex align-items-center gap-2">
                                <span class="small text-nowrap" style="min-width: 3.4rem; flex-shrink: 0"><?= e($month['label']) ?></span>
                                <div class="progress flex-grow-1" role="progressbar"
                                     aria-valuenow="<?= (int) $month['finishes'] ?>" aria-valuemin="0" aria-valuemax="<?= $maxFinishes ?>"
                                     aria-label="<?= (int) $month['finishes'] ?> books finished in <?= e($month['label']) ?>">
                                    <div class="progress-bar bg-success" style="width: <?= (int) round((int) $month['finishes'] / $maxFinishes * 100) ?>%"></div>
                                </div>
                                <span class="small muted text-nowrap" style="width: 1.6rem; text-align: right"><?= (int) $month['finishes'] ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <p class="muted small mt-3 mb-0 text-center">Every month bucket is the count of the real stamps the library and review services recorded &mdash; never guessed, never backdated. Months with nothing are zeros.</p>
    </section>

    <p class="muted small text-center mt-4 mb-0">
        Snapshot generated <?= e(gmdate('M j, Y H:i', strtotime((string) ($analytics['generatedAt'] ?? 'now')))) ?> UTC. Every number derives from the published books, their approved reviews and the community's library shelves.
    </p>
<?php endif; ?>