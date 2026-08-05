<?php

declare(strict_types=1);

/**
 * components/loading-skeleton.php
 *
 * The reusable LOADING SKELETON of the review lists (Phase 7.4):
 * shimmering placeholder cards that match the review card layout
 * (avatar, lines, actions), so the page shows exactly where the
 * list will appear while the next search / sort / filter / page
 * navigation is in flight.
 *
 * reviews.js toggles a .is-loading class on the review list
 * container ([data-review-list]): the skeletons fade in and the
 * list fades out, then the browser navigates - a smooth, layout-
 * stable loading state with NO layout shift (the skeleton keeps
 * the same height as a review card).
 *
 * Included from a view that sets $skeletons:
 *
 *     $skeletons = ['count' => 3];   // number of skeleton cards
 */

$skeletons = array_merge(['count' => 3], $skeletons ?? []);
?>
<div class="review-skeleton" aria-hidden="true">
    <?php for ($i = 0; $i < max(1, (int) $skeletons['count']); $i++): ?>
            <div class="review-skeleton-card">
                <div class="review-skeleton-avatar skeleton"></div>
                <div class="review-skeleton-body">
                    <div class="review-skeleton-line skeleton review-skeleton-line--short"></div>
                    <div class="review-skeleton-line skeleton review-skeleton-line--medium"></div>
                    <div class="review-skeleton-line skeleton"></div>
                    <div class="review-skeleton-line skeleton review-skeleton-line--medium"></div>
                </div>
            </div>
    <?php endfor; ?>
</div>
