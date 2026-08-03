<?php

declare(strict_types=1);

/**
 * recommendations/components/skeleton-card.php
 *
 * The LOADING SKELETON of the Phase 6.4 dashboard.
 *
 * Purpose:
 *     When the user hits "Refresh recommendations" the dashboard
 *     re-renders its shelves while the engine rebuilds the profile
 *     (the cache was just invalidated). To keep the page feeling
 *     instant - and to avoid a confusing layout jump - every card
 *     position is replaced by this shimmering placeholder, which
 *     mirrors the exact proportions of the real recommendation
 *     card (cover block + title + meta lines).
 *
 * Usage (a view sets $skeletonCount first, default 5):
 *
 *     <?php $skeletonCount = 8; ?>
 *     <?php require root_path('app/Views/recommendations/components/skeleton-card.php'); ?>
 *
 * Accessibility:
 *     The skeletons are decorative placeholders, so the wrapper is
 *     aria-hidden and the component never announces itself to
 *     assistive technology.
 */

$skeletonCount = max(1, min(20, (int) ($skeletonCount ?? 5)));

?>
<?php for ($i = 0; $i < $skeletonCount; $i++): ?>
    <div class="rec-card skeleton-card" aria-hidden="true">
        <div class="skeleton skeleton-cover"></div>
        <div class="skeleton-body">
            <div class="skeleton skeleton-line w-80"></div>
            <div class="skeleton skeleton-line w-55"></div>
            <div class="skeleton skeleton-line w-65"></div>
            <div class="skeleton skeleton-line w-40"></div>
        </div>
    </div>
<?php endfor; ?>
