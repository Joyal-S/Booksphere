<?php

declare(strict_types=1);

/**
 * categories/show.php
 *
 * The CATEGORY PAGE (Phase 7.6): the full rating profile of one
 * category, aggregated by the Reviews module (ReviewService::
 * categoryStatistics()):
 *
 *     1. Page intro with the category's name
 *     2. The average category rating (review-summary-card)
 *     3. Top rated books (top-rated-book-cards)
 *     4. Most reviewed books (top-rated-book-cards)
 *     5. The community favourite (the current top-rated book)
 *     6. Recently reviewed (recent-review-cards)
 *
 * Every figure counts approved reviews of the category's books
 * only; soft-deleted books never appear.
 */

$category = $category ?? [];
$stats    = $statistics ?? [];

$average   = (float) ($stats['average'] ?? 0);
$reviews   = (int) ($stats['reviews'] ?? 0);
$reviewed  = (int) ($stats['booksReviewed'] ?? 0);
$topRated  = $stats['topRated'] ?? [];
$most      = $stats['mostReviewed'] ?? [];
$favourite = $stats['communityFavourite'] ?? null;
$recent    = $stats['recentReviews'] ?? [];

?>
<div class="page-intro">
    <p class="eyebrow">Category page</p>
    <h1><?= e($category['name']) ?></h1>
    <p class="lead">How the community rated the books of this category.</p>
</div>

<?php if ($reviews === 0): ?>
    <div class="card-base p-4 text-center text-muted">
        No one has reviewed a book in this category yet - be the first to review one.
    </div>
<?php else: ?>
    <div class="row g-3 g-xl-4 mb-4">
        <div class="col-12 col-md-6 col-xl-4">
            <?php $summary = ['title' => 'Average category rating', 'average' => $average, 'count' => $reviews, 'subtitle' => $reviewed . ' book' . ($reviewed === 1 ? '' : 's') . ' reviewed', 'class' => 'h-100']; ?>
            <?php require root_path('app/Views/components/review-summary-card.php'); ?>
        </div>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card-base h-100 p-4">
                <h3 class="section-title">Top rated books</h3>
                <?php if ($topRated === []): ?>
                    <p class="text-muted mb-0">No rated books yet.</p>
                <?php else: ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($topRated as $index => $book): ?>
                            <?php $rank = $index + 1; ?>
                            <?php require root_path('app/Views/components/top-rated-book-card.php'); ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card-base h-100 p-4">
                <h3 class="section-title">Most reviewed books</h3>
                <?php if ($most === []): ?>
                    <p class="text-muted mb-0">No reviewed books yet.</p>
                <?php else: ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($most as $index => $book): ?>
                            <?php $rank = $index + 1; ?>
                            <?php require root_path('app/Views/components/top-rated-book-card.php'); ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row g-3 g-xl-4 mb-4">
        <div class="col-12 col-md-6">
            <div class="card-base h-100 p-4">
                <h3 class="section-title">Community favourite</h3>
                <?php if ($favourite === null): ?>
                    <p class="text-muted mb-0">The community has not picked a favourite yet.</p>
                <?php else: ?>
                    <?php $book = $favourite; $rank = 1; ?>
                    <?php require root_path('app/Views/components/top-rated-book-card.php'); ?>
                    <p class="text-muted small mt-2 mb-0">
                        The highest-rated book in this category right now.
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-12 col-md-6">
            <div class="card-base h-100 p-4">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="section-icon" aria-hidden="true"><i class="fa-solid fa-clock-rotate-left"></i></span>
                    <h3 class="section-title mb-0">Recently reviewed</h3>
                </div>
                <?php if ($recent === []): ?>
                    <p class="text-muted mb-0">No community reviews yet.</p>
                <?php else: ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($recent as $recentReview): ?>
                            <?php $review = $recentReview; ?>
                            <?php require root_path('app/Views/components/recent-review-card.php'); ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>
