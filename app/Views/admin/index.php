<?php

declare(strict_types=1);

/**
 * admin/index.php
 *
 * The administration landing page. Reaching this page at all is the
 * proof of role-based authorization: the AdminMiddleware only lets
 * users with the "admin" role through.
 *
 * Phase 7.3: the page now carries the live RATING ANALYTICS, computed
 * by the Reviews module (ReviewService::adminAnalytics()) - the
 * catalogue average, the rating distribution over all approved
 * reviews, the highest and lowest rated books and the books that
 * have no reviews yet. Every figure aggregates the real reviews
 * table; the sample rating columns on the seeded books are never
 * shown to the administrator.
 *
 * The recommendation engine monitoring page lives at
 * /admin/recommendations (see admin/recommendations.php).
 */

$analytics = $ratingAnalytics ?? [];

$overall    = (float) ($analytics['overallAverage']['average'] ?? 0.0);
$reviewCount = (int) ($analytics['overallAverage']['count'] ?? 0);
$distribution = $analytics['distribution'] ?? [];
$highest     = $analytics['highestRated'] ?? [];
$lowest      = $analytics['lowestRated'] ?? [];
$unrated     = $analytics['booksWithoutRatings'] ?? [];
$categories  = $analytics['categoryAverage'] ?? [];

// Phase 7.6: the extended platform picture.
$totalReviews      = (int) ($analytics['totalReviews'] ?? 0);
$activeReviewers   = (int) ($analytics['activeReviewers'] ?? 0);
$withoutReviews    = (int) ($analytics['booksWithoutReviews'] ?? 0);
$topReviewers      = $analytics['mostActiveReviewers'] ?? [];
$reviewedCategories = $analytics['mostReviewedCategories'] ?? [];
$authors           = $analytics['authorAverage'] ?? [];
?>
<div class="page-intro">
    <p class="eyebrow">Restricted area</p>
    <h1>Administration</h1>
    <p class="lead">You are signed in as an administrator. Here is the live state of the library's ratings.</p>
</div>

<!-- Phase 7.3: Rating analytics (aggregated from the reviews table) -->
<section class="dash-section" data-animate>
    <?php $section = ['eyebrow' => 'Community signal', 'title' => 'Rating Analytics', 'icon' => 'fa-chart-simple']; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>

    <!-- Phase 7.6: the platform headline numbers -->
    <div class="row g-3 g-xl-4 mb-4">
        <div class="col-6 col-md-3">
            <?php $stat = ['icon' => 'fa-comments', 'label' => 'Total reviews', 'value' => $totalReviews, 'tone' => 'info']; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
        <div class="col-6 col-md-3">
            <?php $stat = ['icon' => 'fa-users', 'label' => 'Active reviewers', 'value' => $activeReviewers, 'tone' => 'success']; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
        <div class="col-6 col-md-3">
            <?php $stat = ['icon' => 'fa-book', 'label' => 'Books without reviews', 'value' => $withoutReviews, 'tone' => 'warning']; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
        <div class="col-6 col-md-3">
            <?php $stat = ['icon' => 'fa-star', 'label' => 'Average platform rating', 'value' => number_format($overall, 2), 'tone' => 'primary']; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
    </div>

    <div class="row g-3 g-xl-4 mb-4">
        <div class="col-12 col-md-4">
            <div class="card-base h-100 p-4 text-center">
                <p class="eyebrow">Catalogue average</p>
                <div class="analytics-average-value"><?= e(number_format($overall, 2)) ?>/5</div>
                <p class="text-muted mb-0">from <?= $reviewCount ?> approved review<?= $reviewCount === 1 ? '' : 's' ?></p>
                <?php $starRating = [
                    'rating' => $overall,
                    'count'  => null,
                    'size'   => 'md',
                    'tooltip'=> false,
                ]; ?>
                <div class="mt-2 d-flex justify-content-center"><?php require root_path('app/Views/components/star-rating.php'); ?></div>
            </div>
        </div>
        <div class="col-12 col-md-8">
            <div class="card-base h-100 p-4">
                <h3 class="section-title">Distribution</h3>
                <?php $breakdown = []; $total = 0; ?>
                <?php for ($star = 5; $star >= 1; $star--): ?>
                    <?php $count = (int) ($distribution[$star] ?? 0); $total += $count; ?>
                    <?php $breakdown[] = ['stars' => $star, 'count' => $count, 'total' => 0]; ?>
                <?php endfor; ?>
                <?php foreach ($breakdown as &$row): ?>
                    <?php $row['percent'] = $total > 0 ? (int) round(((int) $row['count'] / $total) * 100) : 0; ?>
                    <?php $row['total'] = $total; ?>
                <?php endforeach; unset($row); ?>
                <?php $title = ''; $empty = 'No ratings yet.'; ?>
                <?php require root_path('app/Views/reviews/partials/_rating-distribution.php'); ?>
            </div>
        </div>
    </div>

    <div class="row g-3 g-xl-4">
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card-base h-100 p-4">
                <h3 class="section-title">Highest rated</h3>
                <?php if ($highest === []): ?><p class="text-muted mb-0">No rated books yet.</p><?php endif; ?>
                <ol class="analytics-list mb-0">
                    <?php foreach ($highest as $book): ?>
                        <li>
                            <a href="/books/<?= (int) $book['id'] ?>"><?= e($book['title']) ?></a>
                            <span class="text-muted"><?= e(number_format((float) $book['average'], 1)) ?> &middot; <?= (int) $book['count'] ?> review<?= (int) $book['count'] === 1 ? '' : 's' ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card-base h-100 p-4">
                <h3 class="section-title">Lowest rated</h3>
                <?php if ($lowest === []): ?><p class="text-muted mb-0">No rated books yet.</p><?php endif; ?>
                <ol class="analytics-list mb-0">
                    <?php foreach ($lowest as $book): ?>
                        <li>
                            <a href="/books/<?= (int) $book['id'] ?>"><?= e($book['title']) ?></a>
                            <span class="text-muted"><?= e(number_format((float) $book['average'], 1)) ?> &middot; <?= (int) $book['count'] ?> review<?= (int) $book['count'] === 1 ? '' : 's' ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card-base h-100 p-4">
                <h3 class="section-title">Without ratings</h3>
                <?php if ($unrated === []): ?><p class="text-muted mb-0">Every book has a review. Nice work!</p><?php endif; ?>
                <ul class="analytics-list mb-0">
                    <?php foreach ($unrated as $book): ?>
                        <li>
                            <a href="/books/<?= (int) $book['id'] ?>"><?= e($book['title']) ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card-base h-100 p-4">
                <h3 class="section-title">Average by category</h3>
                <?php if ($categories === []): ?><p class="text-muted mb-0">No categories with reviews yet.</p><?php endif; ?>
                <ul class="analytics-list mb-0">
                    <?php foreach ($categories as $category): ?>
                        <li>
                            <span><?= e($category['name']) ?></span>
                            <span class="text-muted"><?= e(number_format((float) $category['average'], 2)) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <!-- Phase 7.6: the extended analytics blocks -->
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card-base h-100 p-4">
                <h3 class="section-title">Most active reviewers</h3>
                <?php if ($topReviewers === []): ?><p class="text-muted mb-0">No reviews yet.</p><?php endif; ?>
                <ol class="analytics-list mb-0">
                    <?php foreach ($topReviewers as $reviewer): ?>
                        <li>
                            <a href="/reviews/user/<?= (int) $reviewer['id'] ?>" class="text-decoration-none"><?= e($reviewer['user_name']) ?></a>
                            <span class="text-muted">
                                <?= (int) $reviewer['count'] ?> review<?= (int) $reviewer['count'] === 1 ? '' : 's' ?>
                                &middot; <?= (int) $reviewer['helpful'] ?> helpful
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card-base h-100 p-4">
                <h3 class="section-title">Most reviewed categories</h3>
                <?php if ($reviewedCategories === []): ?><p class="text-muted mb-0">No reviews yet.</p><?php endif; ?>
                <ol class="analytics-list mb-0">
                    <?php foreach ($reviewedCategories as $category): ?>
                        <li>
                            <a href="/categories/<?= (int) $category['id'] ?>" class="text-decoration-none"><?= e($category['name']) ?></a>
                            <span class="text-muted">
                                <?= (int) $category['count'] ?> review<?= (int) $category['count'] === 1 ? '' : 's' ?>
                                &middot; <?= e(number_format((float) $category['average'], 2)) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card-base h-100 p-4">
                <h3 class="section-title">Average by author</h3>
                <?php if ($authors === []): ?><p class="text-muted mb-0">No authors with reviews yet.</p><?php endif; ?>
                <ul class="analytics-list mb-0">
                    <?php foreach ($authors as $author): ?>
                        <li>
                            <a href="/authors/<?= (int) $author['id'] ?>" class="text-decoration-none"><?= e($author['name']) ?></a>
                            <span class="text-muted"><?= e(number_format((float) $author['average'], 2)) ?> &middot; <?= (int) $author['count'] ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</section>

<?php
// Phase 12.4: the coordinated analytics dashboard. The payloads were
// assembled by AdminAnalyticsService - this template only reads them.
$dashboard = $dashboard ?? [];
$books = $dashboard['books'] ?? [];
$bookOverview = $books['overview'] ?? [];
$bookShelves  = $books['shelves'] ?? [];
$bookRankings = $books['rankings'] ?? [];
$recommendation = $dashboard['recommendation'] ?? [];
$recTotals = $recommendation['totals'] ?? [];
$engine   = $dashboard['engine'] ?? [];
$engineScores = $engine['scores'] ?? [];
?>

<!-- Phase 12.2: catalogue analytics (BookAnalyticsService) -->
<section class="dash-section" data-animate>
    <?php $section = ['eyebrow' => 'Catalogue', 'title' => 'Book Analytics', 'icon' => 'fa-book']; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>

    <!-- The catalogue tiles (12.2 overview) -->
    <div class="row g-3 g-xl-4 mb-4">
        <div class="col-6 col-md-3">
            <?php $stat = ['icon' => 'fa-book', 'label' => 'Books in catalogue', 'value' => (int) ($bookOverview['books'] ?? 0), 'tone' => 'primary']; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
        <div class="col-6 col-md-3">
            <?php $stat = ['icon' => 'fa-comments', 'label' => 'Approved reviews', 'value' => (int) ($bookOverview['reviews'] ?? 0), 'tone' => 'info']; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
        <div class="col-6 col-md-3">
            <?php $stat = ['icon' => 'fa-images', 'label' => 'Books with covers', 'value' => (int) ($bookOverview['with_covers'] ?? 0), 'tone' => 'success']; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
        <div class="col-6 col-md-3">
            <?php $stat = ['icon' => 'fa-list-check', 'label' => 'Books with pages', 'value' => (int) ($bookOverview['with_pages'] ?? 0), 'tone' => 'warning']; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
    </div>

    <div class="row g-3 g-xl-4">
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card-base h-100 p-4">
                <h3 class="section-title">Popular &middot; by community signal</h3>
                <?php if (($bookRankings['popular'] ?? []) === []): ?><p class="text-muted mb-0">No signal yet - the ranking fills as the community rates and saves.</p><?php endif; ?>
                <ol class="analytics-list mb-0">
                    <?php foreach (array_slice($bookRankings['popular'] ?? [], 0, 5) as $book): ?>
                        <li>
                            <a href="/books/<?= (int) $book['id'] ?>"><?= e($book['title']) ?></a>
                            <span class="text-muted"><?= e(number_format((float) $book['score'] * 100, 1)) ?>%</span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card-base h-100 p-4">
                <h3 class="section-title">Trending &middot; last window period</h3>
                <?php if (($bookRankings['trending'] ?? []) === []): ?><p class="text-muted mb-0">No activity in the trending window yet.</p><?php endif; ?>
                <ol class="analytics-list mb-0">
                    <?php foreach (array_slice($bookRankings['trending'] ?? [], 0, 5) as $book): ?>
                        <li>
                            <a href="/books/<?= (int) $book['id'] ?>"><?= e($book['title']) ?></a>
                            <span class="text-muted"><?= e(number_format((float) $book['score'] * 100, 1)) ?>%</span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card-base h-100 p-4">
                <h3 class="section-title">Community shelves</h3>
                <?php $shelfLabels = [
                    'finished'          => 'Finished',
                    'currently_reading' => 'Reading now',
                    'want_to_read'      => 'Wishlist',
                    'on_hold'           => 'On hold',
                    'dropped'           => 'Dropped',
                ]; ?>
                <?php if ($bookShelves === []): ?><p class="text-muted mb-0">No library records yet.</p><?php endif; ?>
                <ul class="analytics-list mb-0">
                    <?php foreach ($bookShelves as $status => $count): ?>
                        <li>
                            <span><?= e($shelfLabels[$status] ?? $status) ?></span>
                            <span class="text-muted"><?= (int) $count ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</section>

<!-- Phase 12.5: the visual layer - the dashboards' own numbers, re-shaped -->
<?php
$cp = '\BookSphere\App\Presenters\ChartPresenter';

$shelfOrder = ['finished', 'currently_reading', 'want_to_read', 'on_hold', 'dropped'];
$shelfLabels = [
    'finished'          => 'Finished',
    'currently_reading' => 'Reading now',
    'want_to_read'      => 'Wishlist',
    'on_hold'           => 'On hold',
    'dropped'           => 'Dropped',
];
$shelfChart = '';
$shelfValues = array_map(static fn (string $status): int => (int) ($bookShelves[$status] ?? 0), $shelfOrder);
$shelfSummary = array_sum($shelfValues) > 0
    ? 'Community shelves: ' . implode(', ', array_map(
        static fn (string $status, int $count): string => $count . ' ' . strtolower((string) $shelfLabels[$status]),
        $shelfOrder,
        $shelfValues,
    )) . '.'
    : '';
if ($shelfSummary !== '') {
    $shelfChart = (string) $cp::doughnut('shelves', array_values($shelfLabels), $shelfValues, $shelfSummary);
}

$categoryChart = '';
$categoryRows = array_slice(array_values($categories ?? []), 0, 8);
$categorySummary = $categoryRows !== []
    ? 'Approved-review rating by category: ' . implode(', ', array_map(
        static fn (array $r): string => ($r['category'] ?? '?') . ' ' . number_format((float) ($r['average'] ?? 0), 2),
        $categoryRows,
    )) . '.'
    : '';
if ($categorySummary !== '') {
    $categoryChart = (string) $cp::bar('categories',
        array_map(static fn (array $r): string => (string) ($r['category'] ?? 'Category'), $categoryRows),
        [['label' => 'Average rating', 'tone' => 'warning', 'values' => array_map(static fn (array $r): float => (float) ($r['average'] ?? 0), $categoryRows)]],
        $categorySummary);
}

$reviewerChart = '';
$reviewerRows = array_slice($topReviewers ?? [], 0, 6);
$reviewerSummary = $reviewerRows !== []
    ? 'Most active reviewers: ' . implode(', ', array_map(
        static fn (array $r): string => ($r['name'] ?? $r['user'] ?? '?') . ' ' . (int) ($r['count'] ?? ($r['reviews'] ?? 0)),
        $reviewerRows,
    )) . '.'
    : '';
if ($reviewerSummary !== '') {
    $reviewerChart = (string) $cp::hbar('reviewers',
        array_map(static fn (array $r): string => (string) ($r['name'] ?? $r['user'] ?? 'Reviewer'), $reviewerRows),
        array_map(static fn (array $r): int => (int) ($r['count'] ?? ($r['reviews'] ?? 0)), $reviewerRows),
        $reviewerSummary);
}

$signalChart = '';
$signalRows = array_slice($recommendation['signals'] ?? [], 0, 6);
$signalSummary = $signalRows !== []
    ? 'Where recommendations were served: ' . implode(', ', array_map(
        static fn (array $r): string => ($r['signal'] !== '' ? $r['signal'] : 'unnamed surface') . ' ' . (int) ($r['logs'] ?? 0),
        $signalRows,
    )) . '.'
    : '';
if ($signalSummary !== '') {
    $signalChart = (string) $cp::doughnut('signals',
        array_map(static fn (array $r): string => (string) ($r['signal'] !== '' ? $r['signal'] : 'unnamed surface'), $signalRows),
        array_map(static fn (array $r): int => (int) ($r['logs'] ?? 0), $signalRows),
        $signalSummary);
}
?>
<section class="dash-section" data-animate>
    <div class="d-flex justify-content-between align-items-end mb-3">
        <div>
            <p class="eyebrow mb-1">Visuals &middot; the dashboards as pictures</p>
            <h2 class="h3 mb-0">Platform at a glance</h2>
        </div>
        <a class="btn btn-outline-secondary btn-sm print-hidden" href="/admin/analytics/report">
            <i class="fa-solid fa-print me-1" aria-hidden="true"></i> Print-friendly report
        </a>
    </div>
    <div class="row g-3 g-xl-4">
        <div class="col-12 col-md-6 col-xl-3">
            <?php $chartEyebrow = 'Community shelves'; $chartTitle = 'Where books sit'; $chartTrend = ''; $chart = $shelfChart; $chartSummary = $shelfSummary; ?>
            <?php require root_path('app/Views/components/chart-card.php'); ?>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <?php $chartEyebrow = 'Approved reviews'; $chartTitle = 'Rating by category'; $chartTrend = ''; $chart = $categoryChart; $chartSummary = $categorySummary; ?>
            <?php require root_path('app/Views/components/chart-card.php'); ?>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <?php $chartEyebrow = 'Top reviewers'; $chartTitle = 'Who contributes'; $chartTrend = ''; $chart = $reviewerChart; $chartSummary = $reviewerSummary; ?>
            <?php require root_path('app/Views/components/chart-card.php'); ?>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <?php $chartEyebrow = 'Engine surfaces'; $chartTitle = 'Where served'; $chartTrend = ''; $chart = $signalChart; $chartSummary = $signalSummary; ?>
            <?php require root_path('app/Views/components/chart-card.php'); ?>
        </div>
    </div>
    <p class="muted small mt-3 mb-0 report-rules">
        Every chart re-uses the aggregates of this page &mdash; no analytics are recomputed. The engine currently has no
        click or conversion tracking, so CTR-style charts are deliberately absent rather than fabricated.
    </p>
</section>
<section class="dash-section" data-animate>
    <?php $section = ['eyebrow' => 'Engine', 'title' => 'Recommendation Analytics', 'icon' => 'fa-wand-magic-sparkles']; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>

    <!-- The recommendation-log totals -->
    <div class="row g-3 g-xl-4 mb-4">
        <div class="col-6 col-md-3">
            <?php $stat = ['icon' => 'fa-bullhorn', 'label' => 'Recommendations served', 'value' => (int) ($recTotals['logs'] ?? 0), 'tone' => 'primary']; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
        <div class="col-6 col-md-3">
            <?php $stat = ['icon' => 'fa-users', 'label' => 'Users served', 'value' => (int) ($recTotals['users'] ?? 0), 'tone' => 'info']; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
        <div class="col-6 col-md-3">
            <?php $stat = ['icon' => 'fa-book', 'label' => 'Books suggested', 'value' => (int) ($recTotals['books'] ?? 0), 'tone' => 'success']; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
        <div class="col-6 col-md-3">
            <?php $stat = [
                'icon'  => 'fa-gauge-high',
                'label' => 'Engine health',
                'value' => ($recTotals['latest'] ?? null) ? 'live' : 'no data',
                'tone'  => 'warning',
            ]; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
    </div>

    <div class="row g-3 g-xl-4">
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card-base h-100 p-4">
                <h3 class="section-title">Surfaces serving recommendations</h3>
                <?php if (($recommendation['signals'] ?? []) === []): ?><p class="text-muted mb-0">No recommendation has been served yet.</p><?php endif; ?>
                <ul class="analytics-list mb-0">
                    <?php foreach ($recommendation['signals'] ?? [] as $signalRow): ?>
                        <li>
                            <span><?= e($signalRow['signal'] !== '' ? $signalRow['signal'] : 'unnamed surface') ?></span>
                            <span class="text-muted"><?= (int) $signalRow['logs'] ?> served</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card-base h-100 p-4">
                <h3 class="section-title">Most recommended books</h3>
                <?php if (($recommendation['top'] ?? []) === []): ?><p class="text-muted mb-0">Nothing recommended yet.</p><?php endif; ?>
                <ol class="analytics-list mb-0">
                    <?php foreach ($recommendation['top'] ?? [] as $book): ?>
                        <li>
                            <a href="/books/<?= (int) $book['id'] ?>"><?= e($book['title']) ?></a>
                            <span class="text-muted"><?= (int) $book['logs'] ?> &times; suggested</span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card-base h-100 p-4">
                <h3 class="section-title">Sleeping suggestions</h3>
                <?php if (($recommendation['slept'] ?? []) === []): ?><p class="text-muted mb-0">Every repeatedly recommended book has community interaction. Good engine.</p><?php endif; ?>
                <ul class="analytics-list mb-0">
                    <?php foreach ($recommendation['slept'] ?? [] as $book): ?>
                        <li>
                            <a href="/books/<?= (int) $book['id'] ?>"><?= e($book['title']) ?></a>
                            <span class="text-muted">recommended <?= (int) $book['logs'] ?>x, never acted on</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</section>
