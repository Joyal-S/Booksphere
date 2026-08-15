<?php

declare(strict_types=1);

/**
 * authors/show.php
 *
 * The AUTHOR PAGE (Phase 7.6): the full rating profile of one
 * author, aggregated by the Reviews module (ReviewService::
 * authorStatistics()):
 *
 *     1. Page intro with the author's name
 *     2. Statistics row (statistics-cards): average author rating,
 *        books reviewed, total reviews
 *     3. The average rating summary (review-summary-card)
 *     4. Highest rated book and most reviewed book
 *        (top-rated-book-cards)
 *     5. Recent community reviews (community-activity-widget)
 *     6. Top reviewers of this author's books
 *
 * Every figure counts approved reviews of the author's books only;
 * soft-deleted books never appear.
 */

$author = $author ?? [];
$stats  = $statistics ?? [];

$average   = (float) ($stats['average'] ?? 0);
$reviews   = (int) ($stats['reviews'] ?? 0);
$reviewed  = (int) ($stats['booksReviewed'] ?? 0);
$highest   = $stats['highestRated'] ?? null;
$most      = $stats['mostReviewed'] ?? null;
$recent    = $stats['recentReviews'] ?? [];
$reviewers = $stats['topReviewers'] ?? [];

?>
<div class="page-intro d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between gap-3 mb-4">
    <div>
        <p class="eyebrow">Author page</p>
        <h1 class="mb-1"><?= e($author['name']) ?></h1>
        <p class="lead mb-0">How the community rated the books of this author.</p>
    </div>

    <div class="author-header-actions flex-shrink-0">
        <?php
        // Phase 9.2: the Follow / Following control - the button state
        // comes from the controller (the session user's row) and the
        // follower count from the shared FollowService, so this control
        // and the /authors/{id}/followers page always agree. The lead
        // above stays short; the count lives inside the control itself.
        $follow = [
            'author_id' => (int) $author['id'],
            'author'    => (string) $author['name'],
            'followed'  => (bool) ($followed ?? false),
            'followers' => (int) ($followers ?? 0),
        ];
        ?>
        <?php require root_path('app/Views/components/follow-button.php'); ?>
    </div>
</div>

<?php if ($reviews === 0): ?>
    <div class="card-base p-4 text-center text-muted">
        No one has reviewed this author's books yet - be the first to review one.
    </div>
<?php else: ?>
    <div class="row g-3 g-xl-4 mb-4">
        <div class="col-12 col-md-4">
            <?php $stat = ['icon' => 'fa-star', 'label' => 'Average author rating', 'value' => format_rating($average), 'hint' => 'from ' . $reviews . ' approved review' . ($reviews === 1 ? '' : 's')]; ?>
            <?php require root_path('app/Views/components/statistics-card.php'); ?>
        </div>
        <div class="col-6 col-md-4">
            <?php $stat = ['icon' => 'fa-book', 'label' => 'Books with reviews', 'value' => $reviewed]; ?>
            <?php require root_path('app/Views/components/statistics-card.php'); ?>
        </div>
        <div class="col-6 col-md-4">
            <?php $stat = ['icon' => 'fa-comments', 'label' => 'Total reviews', 'value' => $reviews]; ?>
            <?php require root_path('app/Views/components/statistics-card.php'); ?>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3 g-xl-4 mb-4">
    <div class="col-12 col-md-6 col-xl-4">
        <?php $summary = ['title' => 'Average author rating', 'average' => $average, 'count' => $reviews]; ?>
        <?php require root_path('app/Views/components/review-summary-card.php'); ?>
    </div>
    <div class="col-12 col-md-6 col-xl-4">
        <div class="card-base p-4">
            <div class="d-flex align-items-center gap-2 mb-3">
                <span class="section-icon" aria-hidden="true"><i class="fa-solid fa-trophy"></i></span>
                <h3 class="section-title mb-0">Top reviewers</h3>
            </div>
            <?php if ($reviewers === []): ?>
                <p class="text-muted mb-0">No reviewers yet.</p>
            <?php else: ?>
                <ol class="analytics-list mb-0">
                    <?php foreach (array_slice($reviewers, 0, 5) as $reviewer): ?>
                        <li>
                            <a href="/reviews/user/<?= (int) $reviewer['id'] ?>" class="text-decoration-none">
                                <?= e($reviewer['user_name']) ?>
                            </a>
                            <span class="text-muted">
                                <?= (int) $reviewer['count'] ?> review<?= (int) $reviewer['count'] === 1 ? '' : 's' ?>
                                &middot; avg <?= e(format_rating($reviewer['average'])) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-12 col-xl-4">
        <div class="d-flex flex-column gap-3">
            <div class="card-base p-4">
                <h3 class="section-title">Highest rated book</h3>
                <?php if ($highest === null): ?>
                    <p class="text-muted mb-0">No rated books yet.</p>
                <?php else: ?>
                    <?php $book = $highest; $rank = 1; ?>
                    <?php require root_path('app/Views/components/top-rated-book-card.php'); ?>
                <?php endif; ?>
            </div>
            <div class="card-base p-4">
                <h3 class="section-title">Most reviewed book</h3>
                <?php if ($most === null): ?>
                    <p class="text-muted mb-0">No reviewed books yet.</p>
                <?php else: ?>
                    <?php $book = $most; $rank = 1; ?>
                    <?php require root_path('app/Views/components/top-rated-book-card.php'); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 g-xl-4">
    <div class="col-12">
        <?php $widget = [
            'icon'    => 'fa-comments',
            'title'   => 'Recent community reviews',
            'total'   => $reviews,
            'reviews' => $recent,
            'empty'   => 'No community reviews for this author yet.',
        ]; ?>
        <?php require root_path('app/Views/components/community-activity-widget.php'); ?>
    </div>
</div>
