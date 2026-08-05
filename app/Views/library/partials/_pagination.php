<?php

declare(strict_types=1);

/**
 * library/partials/_pagination.php
 *
 * The PAGINATION BAR of the library dashboard grid (Phase 8.3).
 * Renders a compact prev / page-numbers / next strip that PRESERVES
 * the entire active query (search, filters, sort, view) - page links
 * are plain <a> hrefs to /library with only the page number swapped,
 * so they are deep-linkable and work without JavaScript.
 *
 * Included from a view that sets $grid (the buildGrid() payload).
 */

$grid = $grid ?? [];

$page      = (int) ($grid['page'] ?? 1);
$pages     = (int) ($grid['pages'] ?? 1);
$hasPrev   = (bool) ($grid['has_prev'] ?? false);
$hasNext   = (bool) ($grid['has_next'] ?? false);

if ($pages <= 1) {
    return;
}

// The query every page link keeps: the active filters plus the sort
// and the view (the fragment renders inside the same request state).
$base = array_merge((array) ($grid['filters'] ?? []), [
    'sort' => $grid['sort'] ?? 'newest_added',
    'view' => $grid['view'] ?? 'grid',
]);

$link = fn (int $target): string => '/library?' . http_build_query(array_merge($base, ['page' => $target]));

// The page window: current page ±2, clamped to 1..pages.
$start = max(1, $page - 2);
$end   = min($pages, $page + 2);
$window = range($start, $end);

?>
<nav class="library-pagination" aria-label="Library pages">
    <?php if ($hasPrev): ?>
        <a class="library-page-btn" href="<?= e($link($page - 1)) ?>" rel="prev">
            <i class="fa-solid fa-angle-left" aria-hidden="true"></i><span class="visually-hidden">Previous page</span>
        </a>
    <?php else: ?>
        <span class="library-page-btn is-disabled" aria-hidden="true"><i class="fa-solid fa-angle-left"></i></span>
    <?php endif; ?>

    <?php foreach ($window as $target): ?>
        <?php if ($target === $page): ?>
            <span class="library-page-btn is-current" aria-current="page"><?= $target ?></span>
        <?php else: ?>
            <a class="library-page-btn" href="<?= e($link($target)) ?>"><?= $target ?></a>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($hasNext): ?>
        <a class="library-page-btn" href="<?= e($link($page + 1)) ?>" rel="next">
            <i class="fa-solid fa-angle-right" aria-hidden="true"></i><span class="visually-hidden">Next page</span>
        </a>
    <?php else: ?>
        <span class="library-page-btn is-disabled" aria-hidden="true"><i class="fa-solid fa-angle-right"></i></span>
    <?php endif; ?>
</nav>