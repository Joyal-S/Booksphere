<?php

declare(strict_types=1);

use BookSphere\App\Services\BookService;

/**
 * books/partials/_results.php
 *
 * The RESULTS REGION of the browse page: either an empty state or
 * the book grid + table with the pagination bar underneath.
 *
 * This partial is included by TWO routes:
 *
 *     - /books (index)        -> rendered inline in books/index.php
 *     - /books/search (JSON)  -> rendered to a string, sent to the
 *                                live search, swapped in by JS
 *
 * Because both render paths use this one file, the page and the
 * real-time results can never disagree about markup.
 *
 * Available variables (from BookController::catalogue()):
 *     $result   - ['items', 'total', 'page', 'perPage', 'pages']
 *     $filters  - normalized filters (['q', 'status', 'category_id',
 *                 'author_id', 'publisher', 'language', 'year_from',
 *                 'year_to', 'min_rating', 'sort', 'perPage', 'page'])
 *     $options  - filter dropdown sources ('categories', 'authors',
 *                 'publishers', 'languages', 'statuses')
 *     $sorts    - BookService::SORTS (sort key -> spec)
 *     $isAdmin  - whether to render the admin row actions
 */

$items   = $result['items'];
$total   = $result['total'];
$page    = $result['page'];
$perPage = $result['perPage'];
$pages   = $result['pages'];

$statuses = $options['statuses'];

// The query string is preserved when navigating between pages, so
// search + every filter survive pagination. The URL builder is
// BookService::queryString() - the same single source of truth the
// filter chips use.
$pageUrl = fn (int $target): string => BookService::queryString($filters, [], ['page' => $target]);

$firstOnPage = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
$lastOnPage  = min($total, $page * $perPage);

// Which empty state to show? Three situations have three stories:
// an empty catalogue, a search with no hits, or filters that match
// nothing. Each gets its own icon, message and action.
$hasSearch  = ($filters['q'] ?? '') !== '';
$hasFilters = ($filters['status'] ?? '') !== ''
    || ($filters['category_id'] ?? null) !== null
    || ($filters['author_id'] ?? null) !== null
    || ($filters['publisher'] ?? '') !== ''
    || ($filters['language'] ?? '') !== ''
    || ($filters['year_from'] ?? null) !== null
    || ($filters['year_to'] ?? null) !== null
    || ($filters['min_rating'] ?? null) !== null;

if ($total === 0) {
    if ($hasSearch) {
        $empty = [
            'icon'    => 'fa-magnifying-glass',
            'title'   => 'No results for "' . $filters['q'] . '"',
            'message' => 'Nothing in the catalogue matches that search. Check the spelling or try a broader term.',
            'class'   => 'empty-state--search',
        ];
    } elseif ($hasFilters) {
        $empty = [
            'icon'    => 'fa-filter',
            'title'   => 'No books match these filters',
            'message' => 'Try removing one or two filters to see more of the catalogue.',
            'action'  => ['label' => 'Clear all filters', 'href' => '/books'],
            'class'   => 'empty-state--filter',
        ];
    } else {
        $empty = [
            'icon'    => 'fa-book-open',
            'title'   => 'The catalogue is empty',
            'message' => 'No books have been added yet.',
            'action'  => $isAdmin ? ['label' => 'Add a book', 'href' => '/books/create'] : null,
            'class'   => 'empty-state--empty',
        ];
    }
}

?>
<div class="book-browse-results" data-live-results aria-busy="false">

    <?php if ($total === 0): ?>
        <div class="card-base">
            <?php require root_path('app/Views/components/empty-state.php'); ?>
        </div>
    <?php else: ?>

        <div class="book-browse-view" data-book-view="table">

            <!-- GRID VIEW: cover-first cards, one per book.
                 Both views are rendered; the CSS data-book-view
                 switch (and the toolbar toggle) shows one at a
                 time, so no-JS users still get the table. -->
            <section class="book-browse-grid" aria-label="Book grid">
                <?php foreach ($items as $item): ?>
                    <?php $book = $item; ?>
                    <?php require root_path('app/Views/books/components/book-card.php'); ?>
                <?php endforeach; ?>
            </section>

            <!-- TABLE VIEW: the dense management list -->
            <section class="book-browse-table" aria-label="Book table">
                <div class="card-base p-0 overflow-hidden">
                    <div class="table-responsive">
                        <table class="table book-table align-middle mb-0">
                            <caption class="visually-hidden">Books in the catalogue</caption>
                            <thead>
                                <tr>
                                    <th class="ps-4" style="width: 64px;" scope="col">Cover</th>
                                    <th scope="col">Title</th>
                                    <th scope="col">Authors</th>
                                    <th scope="col">Categories</th>
                                    <th class="text-center" style="width: 76px;" scope="col">Year</th>
                                    <th style="width: 120px;" scope="col">Rating</th>
                                    <?php if ($isAdmin): ?>
                                        <th style="width: 110px;" scope="col">Status</th>
                                        <th class="pe-4 text-end" style="width: 130px;" scope="col">Actions</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <?php $rowBook = $item; ?>
                                    <?php require root_path('app/Views/books/components/book-table-row.php'); ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($pages > 1): ?>
                        <?php $pagination = [
                            'page'    => $page,
                            'pages'   => $pages,
                            'pageUrl' => $pageUrl,
                            'summary' => 'Showing ' . $firstOnPage . '&ndash;' . $lastOnPage . ' of ' . $total
                                       . ' books &middot; Page ' . $page . ' of ' . $pages,
                        ]; ?>
                        <?php require root_path('app/Views/books/components/pagination.php'); ?>
                    <?php endif; ?>
                </div>
            </section>
        </div>

    <?php endif; ?>
</div>
