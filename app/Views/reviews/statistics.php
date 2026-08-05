<?php

declare(strict_types=1);

/**
 * reviews/statistics.php
 *
 * The REVIEW STATISTICS page (Phase 7.4): the platform-wide review
 * numbers - total reviews, average / highest / lowest rating, the
 * latest review date and the rating distribution bars (all
 * aggregated LIVE from the approved reviews) - beside the signed-in
 * user's own activity, the newest community voices and the highest
 * rated community reviews. Every number is the truth from the
 * reviews table; the sample columns are never shown.
 *
 * Available variables (from ReviewController::statistics):
 *     $stats     - reviewStatistics() across the whole catalogue
 *     $breakdown - distributionBreakdown() rows
 *     $mine      - the signed-in user's reviewStatistics(), or null
 *     $recent    - the 5 latest approved reviews
 *     $highest   - the 5 highest-rated approved reviews
 */

$stats     = $stats ?? [];
$breakdown = $breakdown ?? [];
$mine      = $mine ?? null;
$recent    = $recent ?? [];
$highest   = $highest ?? [];
?>
<div class="page-intro">
    <p class="eyebrow">Reviews &middot; Across the whole catalogue</p>
    <h1>Review Statistics</h1>
    <p class="lead">
        Total reviews, the rating spread and the freshest community voices &mdash;
        aggregated live from the approved reviews.
    </p>
</div>

<?php if ((int) ($stats['total'] ?? 0) > 0): ?>
    <?php $stats['breakdown'] = $breakdown; ?>
    <div class="mb-4">
        <?php require root_path('app/Views/components/review-stats.php'); ?>
    </div>
<?php else: ?>
    <?php $empty = [
        'icon'    => 'fa-chart-column',
        'title'   => 'No statistics yet',
        'message' => 'Statistics appear here as soon as the first reader reviews a book.',
        'action'  => ['label' => 'Browse books', 'href' => '/books'],
    ]; ?>
    <?php require root_path('app/Views/components/empty-state.php'); ?>
<?php endif; ?>

<?php if ($mine !== null && (int) ($mine['total'] ?? 0) > 0): ?>
    <section class="dash-section" data-animate>
        <?php $section = ['eyebrow' => 'Your voice', 'title' => 'My Review Activity', 'icon' => 'fa-user-pen', 'link' => ['label' => 'View my reviews', 'href' => '/reviews']]; ?>
        <?php require root_path('app/Views/components/section-header.php'); ?>
        <div class="row g-3 g-xl-4 row-cols-2 row-cols-md-3 row-cols-xl-5">
            <?php
            $myTiles = [
                ['icon' => 'fa-comments', 'label' => 'Reviews written', 'value' => (int) $mine['total'], 'tone' => 'primary'],
                ['icon' => 'fa-star', 'label' => 'My average rating', 'value' => $mine['average'] === null ? '—' : number_format((float) $mine['average'], 1), 'tone' => 'success'],
                ['icon' => 'fa-arrow-up', 'label' => 'My highest rating', 'value' => $mine['highest'] === null ? '—' : (int) $mine['highest'] . ' ★', 'tone' => 'warning'],
                ['icon' => 'fa-arrow-down', 'label' => 'My lowest rating', 'value' => $mine['lowest'] === null ? '—' : (int) $mine['lowest'] . ' ★', 'tone' => 'danger'],
                ['icon' => 'fa-clock', 'label' => 'My latest review', 'value' => $mine['latest'] === null ? '—' : format_review_date((string) $mine['latest']), 'tone' => 'info'],
            ];
            ?>
            <?php foreach ($myTiles as $stat): ?>
                <div class="col"><?php require root_path('app/Views/components/stat-card.php'); ?></div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<div class="row g-4 mt-0">
    <div class="col-12 col-xl-6">
        <section class="dash-section" data-animate>
            <?php $section = ['eyebrow' => 'Community voices', 'title' => 'Latest Reviews', 'icon' => 'fa-comments', 'link' => ['label' => 'Browse all reviews', 'href' => '/reviews/search']]; ?>
            <?php require root_path('app/Views/components/section-header.php'); ?>
            <?php if ($recent === []): ?>
                <div class="card-base p-4 text-center text-muted">No community reviews yet.</div>
            <?php else: ?>
                <div class="row g-3 g-xl-4 row-cols-1 row-cols-md-2">
                    <?php foreach ($recent as $review): ?>
                        <div class="col">
                            <?php $compact = true; ?>
                            <?php require root_path('app/Views/components/review-card.php'); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="col-12 col-xl-6">
        <section class="dash-section" data-animate>
            <?php $section = ['eyebrow' => 'Reader favourites', 'title' => 'Highest Rated Reviews', 'icon' => 'fa-star', 'link' => ['label' => 'Most relevant first', 'href' => '/reviews/search?sort=relevant']]; ?>
            <?php require root_path('app/Views/components/section-header.php'); ?>
            <?php if ($highest === []): ?>
                <div class="card-base p-4 text-center text-muted">No community reviews yet.</div>
            <?php else: ?>
                <div class="row g-3 g-xl-4 row-cols-1 row-cols-md-2">
                    <?php foreach ($highest as $review): ?>
                        <div class="col">
                            <?php $compact = true; ?>
                            <?php require root_path('app/Views/components/review-card.php'); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>
