<?php

declare(strict_types=1);

/**
 * library/partials/_grid.php
 *
 * The LIBRARY GRID fragment of the library dashboard (Phase 8.3) - the
 * SHARED partial behind every grid rendering path:
 *
 *     - the no-JS page: library/index.php renders this block
 *       server-side inside the [data-library-results] region
 *     - the live requests: /library/search, /library/filter and
 *       /library/sort all ship this EXACT fragment (View::fragment),
 *       which library.js swaps into the same region
 *
 * Because every path renders the same file, the live grid and the
 * no-JS page can never drift. The fragment renders, top to bottom:
 *
 *     1. the active-filter chips (one per applied filter, each with a
 *        "remove" link that keeps the rest of the query)
 *     2. the book grid (cards) or book list (rows) - the view stored
 *        in $grid['view']
 *     3. the empty state: "no books match your filters" with a clear
 *        link when filters are applied, or the library-starter CTA
 *        when the library itself is empty
 *     4. the pagination bar (when there is more than one page)
 *
 * Included from a view that sets $grid (the buildGrid() payload).
 */

$grid = $grid ?? [];

$items        = $grid['items'] ?? [];
$total        = (int) ($grid['total'] ?? 0);
$view         = $grid['view'] ?? 'grid';
$filters      = $grid['filters'] ?? [];
$options      = $grid['options'] ?? [];
$sorts        = $grid['sorts'] ?? [];
$recommended  = $grid['recommended'] ?? [];
$statusLabels = $grid['statusLabels'] ?? [];

// The id -> name lookups of the filter dropdowns (for readable chips).
$categoryNames = [];
foreach (($options['categories'] ?? []) as $option) {
    $categoryNames[(string) ($option['id'] ?? '')] = (string) ($option['name'] ?? '');
}

$authorNames = [];
foreach (($options['authors'] ?? []) as $option) {
    $authorNames[(string) ($option['id'] ?? '')] = (string) ($option['name'] ?? '');
}

// The label of every applied filter (for the chips).
$filterLabels = [
    'status'           => static fn (string $value): string => 'Status: ' . ($statusLabels[$value] ?? $value),
    'category'         => static fn (string $value): string => 'Category: ' . ($categoryNames[(string) $value] ?? '#' . $value),
    'author'           => static fn (string $value): string => 'Author: ' . ($authorNames[(string) $value] ?? '#' . $value),
    'rating'           => static fn (string $value): string => 'Rated ' . $value . '+',
    'favorite'         => static fn (): string => 'Favourites only',
    'recently_added'   => static fn (): string => 'Added this month',
    'recently_updated' => static fn (): string => 'Updated recently',
];

// The base query a chip keeps when it removes itself: everything
// except the removed filter, plus the sort and the view.
$baseQuery = array_merge($filters, ['sort' => $grid['sort'] ?? 'newest_added', 'view' => $grid['view'] ?? 'grid']);

$chipLink = static function (string $removeKey, array $filters, string $sort, string $view): string {
    $rest = $filters;
    unset($rest[$removeKey]);

    $query = array_merge($rest, ['sort' => $sort, 'view' => $view]);

    return '/library' . ($query !== [] ? '?' . http_build_query($query) : '');
};

?>
<?php if ($filters !== []): ?>
    <div class="library-chip-row" aria-label="Active filters">
        <span class="library-chip-label">
            <i class="fa-solid fa-filter" aria-hidden="true"></i>Filters
        </span>
        <?php foreach ($filters as $key => $value): ?>
            <?php if (!isset($filterLabels[$key])) { continue; } ?>
            <span class="library-chip">
                <?= e((string) $filterLabels[$key]($value)) ?>
                <a href="<?= e($chipLink((string) $key, $filters, $grid['sort'] ?? 'newest_added', $grid['view'] ?? 'grid')) ?>"
                   aria-label="Remove the <?= e((string) $key) ?> filter" title="Remove this filter">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </a>
            </span>
        <?php endforeach; ?>
        <span class="library-chip-count"><?= (int) $total ?></span>
    </div>
<?php endif; ?>

<?php if ($total === 0): ?>
    <div class="card-base p-4 text-center text-muted" data-library-empty>
        <?php if ($filters !== []): ?>
            <i class="fa-solid fa-magnifying-glass fa-lg me-2" aria-hidden="true"></i>
            No books in your library match these filters.
            <a class="btn btn-sm btn-outline-secondary ms-2" href="/library">
                <i class="fa-solid fa-rotate-left me-1" aria-hidden="true"></i>Clear filters
            </a>
        <?php else: ?>
            <i class="fa-solid fa-book-open fa-lg me-2" aria-hidden="true"></i>
            Your library is empty - start your reading journey.
            <a class="btn btn-sm btn-primary ms-2" href="/books">Browse Books</a>
        <?php endif; ?>
    </div>
<?php elseif ($view === 'list'): ?>
    <div class="library-list" data-library-view="list">
        <?php foreach ($items as $record): ?>
            <?php require root_path('app/Views/library/partials/_library-row.php'); ?>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="row g-3 g-xl-4 row-cols-1 row-cols-sm-2 row-cols-xl-3 row-cols-xxl-4" data-library-view="grid">
        <?php foreach ($items as $record): ?>
            <div class="col"><?php require root_path('app/Views/library/partials/_library-card.php'); ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require root_path('app/Views/library/partials/_pagination.php'); ?>