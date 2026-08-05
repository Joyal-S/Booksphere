<?php

declare(strict_types=1);

/**
 * reviews/index.php
 *
 * The "My Reviews" page (Phase 7.2 + Phase 7.4): every review of
 * the signed-in user, presented as a professional review list - the
 * user's own review statistics on top (total, average, highest,
 * lowest, latest + the rating distribution), the sort / search /
 * filter toolbar, the review cards with the owner-only Edit and
 * Delete actions (ReviewPolicy gates inside the controller) and the
 * pagination.
 *
 * Available variables (from ReviewController::index):
 *     $reviews    - the review rows of the current page
 *     $stats      - reviewStatistics(['user_id' => me])
 *     $breakdown  - distributionBreakdown() rows
 *     $toolbar    - the Phase 7.4 toolbar payload
 *     $pagination - the Phase 7.4 pagination payload
 */

$reviews    = $reviews ?? [];
$stats      = $stats ?? [];
$breakdown  = $breakdown ?? [];
$toolbar    = $toolbar ?? null;
$pagination = $pagination ?? null;
?>
<div class="page-intro">
    <p class="eyebrow">Reviews &middot; <?= (int) ($stats['total'] ?? 0) ?> written</p>
    <h1>My Reviews</h1>
    <p class="lead">The books you rated and reviewed, sorted, filtered and searched your way.</p>
</div>

<?php if ((int) ($stats['total'] ?? 0) > 0): ?>
    <?php $stats['breakdown'] = $breakdown; ?>
    <div class="mb-4">
        <?php require root_path('app/Views/components/review-stats.php'); ?>
    </div>
<?php endif; ?>

<?php if ($toolbar !== null): ?>
    <div class="review-toolbar-wrap mb-3">
        <?php require root_path('app/Views/reviews/partials/_toolbar.php'); ?>
    </div>
<?php endif; ?>

<div data-review-list>
    <?php $skeletons = ['count' => 3]; ?>
    <?php require root_path('app/Views/components/loading-skeleton.php'); ?>

    <?php if ($reviews === []): ?>
        <?php $emptyBase = [
            'title'   => 'No reviews yet',
            'message' => 'Visit any book in the catalogue and write your first review.',
            'action'  => ['label' => 'Browse books', 'href' => '/books'],
        ]; ?>
        <?php require root_path('app/Views/reviews/partials/_empty.php'); ?>
    <?php else: ?>
        <?php $canManage = true; ?>
        <?php require root_path('app/Views/reviews/partials/_list.php'); ?>

        <?php if ($pagination !== null): ?>
            <div class="mt-4">
                <?php require root_path('app/Views/components/review-pagination.php'); ?>
            </div>
        <?php endif; ?>

        <?php require root_path('app/Views/reviews/partials/_delete-modal.php'); ?>
        <?php require root_path('app/Views/reviews/partials/_report-modal.php'); ?>
    <?php endif; ?>
</div>
