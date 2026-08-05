<?php

declare(strict_types=1);

/**
 * library/components/skeleton-card.php
 *
 * One LOADING SKELETON of the library page: a shimmering placeholder
 * that mirrors the library card layout (cover, title lines, a bar), so
 * the live search shows exactly where the results will land while the
 * request is in flight - no layout shift. Marked aria-hidden because
 * it is decorative and replaced by the real cards when they arrive.
 */

?>
<div class="library-skeleton-card skeleton" aria-hidden="true">
    <div class="library-skeleton-cover skeleton"></div>
    <div class="library-skeleton-body">
        <div class="library-skeleton-line skeleton library-skeleton-line--title"></div>
        <div class="library-skeleton-line skeleton library-skeleton-line--meta"></div>
        <div class="library-skeleton-line skeleton library-skeleton-line--bar"></div>
        <div class="library-skeleton-line skeleton"></div>
    </div>
</div>