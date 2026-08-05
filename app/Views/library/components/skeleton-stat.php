<?php

declare(strict_types=1);

/**
 * library/components/skeleton-stat.php
 *
 * The STATISTIC CARD loading skeleton (Phase 8.3): mirrors the shared
 * stat-card component (icon tile + value + label lines) so the stats
 * row never collapses while a fetch repaints it. Clicked into the
 * shared shimmer (app.css .skeleton) by its own class list.
 *
 * Included from a view (or injected by library.js) as one block:
 *
 *     <div class="library-skeleton-stat skeleton">
 *         <span class="library-skeleton-tile skeleton"></span>
 *         <span class="library-skeleton-line skeleton"></span>
 *         <span class="library-skeleton-line library-skeleton-line--sm skeleton"></span>
 *     </div>
 */
?>
<div class="library-skeleton-stat skeleton">
    <span class="library-skeleton-tile skeleton"></span>
    <span class="library-skeleton-line skeleton"></span>
    <span class="library-skeleton-line library-skeleton-line--sm skeleton"></span>
</div>