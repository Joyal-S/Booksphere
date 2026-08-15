<?php

declare(strict_types=1);

/**
 * reviews/partials/_community-stats.php
 *
 * The COMMUNITY STATISTICS panel of the book detail page (Phase
 * 7.5): the engagement story of one book told truthfully from the
 * reviews / review_helpful_votes tables via
 * ReviewService::communityStats() -
 *
 *     - Total Reviews      - approved reviews on the book
 *     - Helpful Votes      - helpful votes those reviews earned
 *     - Average Rating     - the same average the header shows
 *     - Most Helpful       - the review with the most votes
 *     - Newest             - the most recent review
 *     - Highest Rated      - the top-starred review
 *
 * Every number is real: nothing is seeded or sampled. The three
 * spotlight rows link to the review detail pages; when a spotlight
 * review is missing (no reviews at all), the row renders a muted
 * dash instead of a dead link.
 *
 * Available variables:
 *     $communityStats - communityStats() result, or null (renders
 *                       nothing when the Reviews module is absent)
 */

$communityStats = $communityStats ?? null;

if ($communityStats === null || (int) $communityStats['totalReviews'] === 0) {
    return;
}

// The spotlight rows, precomputed so the templates never mix
// array access with operator precedence.
$spots = [];
foreach (['mostHelpful' => 'Most helpful review', 'newest' => 'Newest review', 'highestRated' => 'Highest rated review'] as $key => $label) {
    $review = $communityStats[$key] ?? null;
    $spots[] = [
        'label'  => $label,
        'exists' => $review !== null && (int) ($review['id'] ?? 0) > 0,
        'title'  => (string) ($review['title'] ?? ''),
        'rating' => (int) ($review['rating'] ?? 0),
        'id'     => (int) ($review['id'] ?? 0),
    ];
}
?>
<section class="mt-4" aria-label="Community statistics">
    <div class="card-base p-4">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <h2 class="section-title mb-0">Community Statistics</h2>
            <span class="badge text-bg-light">Phase 7.5</span>
        </div>
        <div class="row g-3">
            <div class="col-6 col-md-4 col-lg-2">
                <?php $stat = ['icon' => 'fa-comment-dots', 'label' => 'Total Reviews', 'value' => (int) $communityStats['totalReviews'], 'tone' => 'primary']; ?>
                <?php require root_path('app/Views/components/stat-card.php'); ?>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <?php $stat = ['icon' => 'fa-thumbs-up', 'label' => 'Helpful Votes', 'value' => (int) $communityStats['helpfulVotes'], 'tone' => 'success']; ?>
                <?php require root_path('app/Views/components/stat-card.php'); ?>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <?php $stat = ['icon' => 'fa-star', 'label' => 'Average Rating', 'value' => $communityStats['averageRating'] === null ? '—' : format_rating($communityStats['averageRating']), 'tone' => 'warning']; ?>
                <?php require root_path('app/Views/components/stat-card.php'); ?>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <?php $stat = ['icon' => 'fa-fire', 'label' => 'Most Helpful', 'value' => $spots[0]['exists'] ? (string) $spots[0]['rating'] . '★' : '—', 'tone' => 'danger']; ?>
                <?php require root_path('app/Views/components/stat-card.php'); ?>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <?php $stat = ['icon' => 'fa-clock', 'label' => 'Newest Review', 'value' => $spots[1]['exists'] ? 'View' : '—', 'tone' => 'info']; ?>
                <?php require root_path('app/Views/components/stat-card.php'); ?>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <?php $stat = ['icon' => 'fa-crown', 'label' => 'Highest Rated', 'value' => $spots[2]['exists'] ? (string) $spots[2]['rating'] . '★' : '—', 'tone' => 'primary']; ?>
                <?php require root_path('app/Views/components/stat-card.php'); ?>
            </div>
        </div>
        <div class="row g-3 mt-1">
            <?php foreach ($spots as $spot): ?>
                <div class="col-12 col-md-4">
                    <div class="community-spot">
                        <span class="community-spot-label"><?= e($spot['label']) ?></span>
                        <?php if ($spot['exists']): ?>
                            <a class="community-spot-title" href="/reviews/<?= $spot['id'] ?>">
                                <?= e($spot['title'] !== '' ? $spot['title'] : 'Review #' . $spot['id']) ?>
                            </a>
                            <span class="community-spot-meta">
                                <i class="fa-solid fa-star" aria-hidden="true"></i>
                                <?= $spot['rating'] ?>/5
                            </span>
                        <?php else: ?>
                            <span class="community-spot-title text-muted">&mdash;</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
