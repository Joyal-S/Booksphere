<?php

declare(strict_types=1);

/**
 * library/partials/_collections.php
 *
 * The SMART COLLECTIONS rail of the library dashboard (Phase 8.4) -
 * the richer successor of the Phase 8.3 shelf tabs. One item per
 * collection ("All", the five status shelves, "Favourites"), each
 * showing the occupancy numbers the brief asks for:
 *
 *     - the book count
 *     - the average rating of the books in the collection
 *     - the last-updated stamp of the collection
 *
 * Every item is a plain link (the no-JS path filters the grid like
 * the old tabs did) and carries the Phase 8.3 tab data contract
 * ([data-library-tabs] / [data-library-tab] + .library-tab-count),
 * so the existing library.js highlight / counter refresh keeps
 * working on the rail unchanged.
 *
 * Included from a view that sets:
 *
 *     $collections  - collectionStatistics() payload: collection id
 *                     -> ['count', 'average_rating', 'last_updated']
 *     $activeShelf  - 'all' | one status | 'favorites' (the highlight)
 */

$collections = $collections ?? [];
$activeShelf = $activeShelf ?? 'all';

// The rail order, labels, icons and URLs (the "smart collection"
// order of the brief: All, the five shelves, Favourites).
$collectionMeta = [
    'all'               => ['label' => 'All Books',           'icon' => 'fa-layer-group',        'url' => '/library'],
    'want_to_read'      => ['label' => 'Want to Read',        'icon' => 'fa-bookmark',           'url' => '/library?status=want_to_read'],
    'currently_reading' => ['label' => 'Currently Reading',   'icon' => 'fa-book-open-reader',   'url' => '/library?status=currently_reading'],
    'finished'          => ['label' => 'Finished',            'icon' => 'fa-circle-check',       'url' => '/library?status=finished'],
    'on_hold'           => ['label' => 'On Hold',             'icon' => 'fa-pause',              'url' => '/library?status=on_hold'],
    'dropped'           => ['label' => 'Dropped',             'icon' => 'fa-ban',                'url' => '/library?status=dropped'],
    'favorites'         => ['label' => 'Favourites',          'icon' => 'fa-heart',              'url' => '/library/favorites'],
];

?>
<nav class="library-collections" aria-label="Library collections" data-library-tabs>
    <?php foreach ($collectionMeta as $key => $meta): ?>
        <?php $data = (array) ($collections[$key] ?? []); ?>
        <?php $count = (int) ($data['count'] ?? 0); ?>
        <?php $rating = (float) ($data['average_rating'] ?? 0); ?>
        <?php $updated = (string) ($data['last_updated'] ?? ''); ?>
        <?php $updatedOn = $updated !== '' ? gmdate('M j', max(0, (int) strtotime($updated))) : ''; ?>
        <a class="library-collection library-collection--<?= e($key) ?><?= $activeShelf === $key ? ' is-active' : '' ?>"
           href="<?= e($meta['url']) ?>" data-library-tab="<?= e($key) ?>">
            <span class="library-collection-icon" aria-hidden="true"><i class="fa-solid <?= e($meta['icon']) ?>"></i></span>
            <span class="library-collection-body">
                <strong class="library-collection-name"><?= e($meta['label']) ?></strong>
                <span class="library-collection-count">
                    <span data-collection-count><?= $count ?></span>
                    <?= $count === 1 ? 'book' : 'books' ?>
                </span>
                <span class="library-collection-meta">
                    <?php if ($count > 0): ?>
                        <span data-collection-rating-text><i class="fa-solid fa-star" aria-hidden="true"></i><?= e(format_rating($rating)) ?></span>
                        <?php if ($updatedOn !== ''): ?>
                            <span class="library-collection-dot" aria-hidden="true">·</span>
                            <span data-collection-updated-text><i class="fa-regular fa-clock" aria-hidden="true"></i><?= e($updatedOn) ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span data-collection-empty>no books yet</span>
                    <?php endif; ?>
                </span>
            </span>
        </a>
    <?php endforeach; ?>
</nav>
