<?php

declare(strict_types=1);

/**
 * components/community-activity-widget.php
 *
 * The COMMUNITY ACTIVITY WIDGET (Phase 7.6): a panel that lists
 * several community review rows (via the shared community-review
 * card) under one header, with the total review count in the
 * header - the author page's "Recent community reviews" block and
 * the category page's community strip.
 *
 * Included from a view that sets $widget first:
 *
 *     $widget = [
 *         'icon'    => 'fa-comments',
 *         'title'   => 'Recent community reviews',
 *         'total'   => 12,          // the total review count
 *         'reviews' => [...],       // the review rows to list
 *         'empty'   => 'No reviews for this author yet.', // optional
 *     ];
 */

$widget = array_merge([
    'icon'    => 'fa-comments',
    'title'   => 'Community reviews',
    'total'   => 0,
    'reviews' => [],
    'empty'   => 'No community reviews yet.',
], $widget ?? []);

?>
<div class="card-base h-100 p-4 community-activity-widget">
    <div class="d-flex align-items-center gap-2 mb-3">
        <span class="section-icon" aria-hidden="true"><i class="fa-solid <?= e($widget['icon']) ?>"></i></span>
        <h3 class="section-title mb-0"><?= e($widget['title']) ?></h3>
        <?php if ((int) $widget['total'] > 0): ?>
            <span class="badge text-bg-light border ms-auto"><?= (int) $widget['total'] ?> total</span>
        <?php endif; ?>
    </div>
    <?php if ($widget['reviews'] === []): ?>
        <p class="text-muted mb-0"><?= e($widget['empty']) ?></p>
    <?php else: ?>
        <div class="d-flex flex-column gap-3">
            <?php foreach ($widget['reviews'] as $widgetReview): ?>
                <?php $review = $widgetReview; ?>
                <?php require root_path('app/Views/components/community-review-card.php'); ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
