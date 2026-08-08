<?php

declare(strict_types=1);

/**
 * search/partials/_results.php
 *
 * The RESULTS REGION of the global search page (Phase 11.2): the
 * state for every possible outcome, rendered the same way on the
 * server (the full page) and through the live endpoint (the JSON
 * partial) - one file, zero drift:
 *
 *     - no query yet   -> the "type to search" empty state
 *     - search failed  -> the friendly error alert (the service
 *                         already translated the failure; no
 *                         stack traces ever reach here)
 *     - zero matches   -> the "no results" empty state with the
 *                         Try again action
 *     - matches        -> the hit list (one card per hit, shaped
 *                         by SearchResultFormatter: title, subtitle,
 *                         url, and the entity's own row in $data)
 *                         + the shared pagination bar
 *
 * Available variables (from SearchController / the JSON envelope):
 *     $result - a SearchResult (or null when the module is
 *               disabled / rate limited - $error explains why)
 *     $scope  - the active scope key ('books', 'authors', ...)
 *     $error  - optional page-level error message
 */
?>

<?php if (isset($error) && $error !== ''): ?>
    <div class="card-base">
        <?php $alert = [
            'type'    => 'danger',
            'message' => $error,
        ]; ?>
        <?php require root_path('app/Views/components/alert.php'); ?>
    </div>
<?php elseif ($result === null): ?>
    <?php $empty = [
        'icon'    => 'fa-magnifying-glass',
        'title'   => 'Type to search',
        'message' => 'Search the whole catalogue - books, authors, categories, publishers and community reviews.',
        'class'   => 'empty-state--search',
    ]; ?>
    <div class="card-base">
        <?php require root_path('app/Views/components/empty-state.php'); ?>
    </div>
<?php elseif ($result->error !== ''): ?>
    <div class="card-base">
        <?php $alert = [
            'type'    => 'danger',
            'message' => $result->error,
        ]; ?>
        <?php require root_path('app/Views/components/alert.php'); ?>
    </div>
<?php elseif ($result->query === '' && ($filters ?? []) === []): ?>
    <?php $empty = [
        'icon'    => 'fa-magnifying-glass',
        'title'   => 'Type to search',
        'message' => 'Start typing above to search books, authors, categories, publishers and reviews.',
        'class'   => 'empty-state--search',
    ]; ?>
    <div class="card-base">
        <?php require root_path('app/Views/components/empty-state.php'); ?>
    </div>
<?php elseif ($result->total === 0): ?>
    <?php $empty = [
        'icon'    => 'fa-circle-question',
        'title'   => ($result->query !== '' ? 'No results for "' . $result->query . '"' : 'No results with these filters'),
        'message' => 'Nothing in the catalogue matches that ' . ($result->query !== '' ? 'term. Check the spelling, or try a different scope or a broader word.' : 'combination of filters. Widen or remove a filter to see more books.'),
        'action'  => ['label' => 'Clear search', 'href' => '/search'],
        'class'   => 'empty-state--search',
    ]; ?>
    <div class="card-base">
        <?php require root_path('app/Views/components/empty-state.php'); ?>
    </div>
<?php else: ?>

    <div class="search-results">
        <p class="search-results-summary" role="status">
            <?= $result->total ?> <?= $result->total === 1 ? 'result' : 'results' ?>
            <?php if ($result->query !== ''): ?>
                for &ldquo;<?= e($result->query) ?>&rdquo;
            <?php elseif (($filters ?? []) !== []): ?>
                with the applied filters
            <?php endif; ?>
            <?= $scope !== 'books' ? 'in ' . e(ucfirst($scope)) : '' ?>
        </p>

        <div class="search-hit-list">
            <?php foreach ($result->hits as $hit): ?>
                <?php require root_path('app/Views/search/partials/_hit.php'); ?>
            <?php endforeach; ?>
        </div>

        <?php if ($result->pages > 1): ?>
            <?php
            $searchPageUrl = fn (int $target): string => \BookSphere\App\Services\SearchService::queryString(
                ['q' => $result->query, 'scope' => $scope, 'per_page' => (string) $result->perPage] + ($filters ?? []),
                [],
                ['page' => $target],
            );
            ?>
            <?php $pagination = [
                'page'    => $result->page,
                'pages'   => $result->pages,
                'pageUrl' => $searchPageUrl,
                'summary' => 'Showing ' . $result->firstOnPage() . '&ndash;' . $result->lastOnPage()
                           . ' of ' . $result->total . ' &middot; Page ' . $result->page . ' of ' . $result->pages,
            ]; ?>
            <?php require root_path('app/Views/books/components/pagination.php'); ?>
        <?php endif; ?>
    </div>

<?php endif; ?>