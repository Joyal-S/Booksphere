<?php

declare(strict_types=1);

/**
 * reviews/user.php
 *
 * The public reviews page of ONE reviewer (Phase 7.4): their review
 * statistics (total, average, highest, lowest, latest + their rating
 * distribution) and their reviews as a professional review list -
 * sortable, searchable within their own reviews, filterable and
 * paginated. Reached from the reviewer name / avatar link on every
 * review card.
 *
 * Available variables (from ReviewController::userReviews):
 *     $profile    - the reviewer's user row
 *     $reviews    - the review rows of the current page
 *     $stats      - reviewStatistics(['user_id' => reviewer])
 *     $breakdown  - distributionBreakdown() rows
 *     $toolbar    - the Phase 7.4 toolbar payload
 *     $pagination - the Phase 7.4 pagination payload
 */

$reviews    = $reviews ?? [];
$stats      = $stats ?? [];
$breakdown  = $breakdown ?? [];
$toolbar    = $toolbar ?? null;
$pagination = $pagination ?? null;
$actorId    = auth()?->id();
$isOwner    = $actorId !== null && (int) $actorId === (int) ($profile['id'] ?? 0);
?>
<div class="page-intro">
    <p class="eyebrow">Reviews &middot; By <?= e($profile['full_name'] ?? 'Reader') ?></p>
    <h1><?= e($profile['full_name'] ?? 'Reader') ?>&rsquo;s Reviews</h1>
    <p class="lead">
        <?= (int) ($stats['total'] ?? 0) ?> review<?= (int) ($stats['total'] ?? 0) === 1 ? '' : 's' ?> written
        <?php if ($stats['average'] !== null): ?>
            &middot; <?= e(format_rating($stats['average'])) ?> average
        <?php endif; ?>
    </p>
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
            'message' => $isOwner
                ? 'Visit any book in the catalogue and write your first review.'
                : 'This reader has not reviewed any books yet.',
            'action'  => $isOwner ? ['label' => 'Browse books', 'href' => '/books'] : null,
        ]; ?>
        <?php require root_path('app/Views/reviews/partials/_empty.php'); ?>
    <?php else: ?>
        <?php
        // The owner (or an admin) sees the Edit / Delete actions on
        // their own rows; everyone else reads the same page.
        $canManage = $isOwner || auth_is_admin();
        ?>
        <?php require root_path('app/Views/reviews/partials/_list.php'); ?>

        <?php if ($pagination !== null): ?>
            <div class="mt-4">
                <?php require root_path('app/Views/components/review-pagination.php'); ?>
            </div>
        <?php endif; ?>

        <?php if ($canManage): ?>
            <?php require root_path('app/Views/reviews/partials/_delete-modal.php'); ?>
        <?php endif; ?>

        <?php require root_path('app/Views/reviews/partials/_report-modal.php'); ?>
    <?php endif; ?>
</div>
