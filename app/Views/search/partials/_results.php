<?php

declare(strict_types=1);

/**
 * search/partials/_results.php
 *
 * The RESULTS REGION of the global search page:
 * - pre-search empty state
 * - error state
 * - no results state
 * - grouped search results by entity type (Books, Authors, Categories, Publishers, Reviews)
 */
?>

<?php if (isset($error) && $error !== ''): ?>
    <div class="card-base p-4">
        <?php $alert = ['type' => 'danger', 'message' => $error]; ?>
        <?php require root_path('app/Views/components/alert.php'); ?>
    </div>
<?php elseif ($result === null): ?>
    <div class="card-base p-4">
        <?php $empty = [
            'icon'    => 'fa-magnifying-glass',
            'title'   => 'Search BookSphere',
            'message' => 'Find books, authors, categories, publishers and community reviews from one place.',
            'class'   => 'empty-state--search',
        ]; ?>
        <?php require root_path('app/Views/components/empty-state.php'); ?>
    </div>
<?php elseif ($result->error !== ''): ?>
    <div class="card-base p-4">
        <?php $alert = ['type' => 'danger', 'message' => $result->error]; ?>
        <?php require root_path('app/Views/components/alert.php'); ?>
    </div>
<?php elseif (!$result->hasQuery()): ?>
    <div class="card-base p-4">
        <?php $empty = [
            'icon'    => 'fa-magnifying-glass',
            'title'   => 'Search BookSphere',
            'message' => 'Find books, authors, categories, publishers and community reviews from one place.',
            'class'   => 'empty-state--search',
        ]; ?>
        <?php require root_path('app/Views/components/empty-state.php'); ?>
    </div>
<?php elseif ($result->total === 0): ?>
    <div class="card-base p-4">
        <?php $empty = [
            'icon'    => 'fa-circle-question',
            'title'   => 'No results found',
            'message' => 'We couldn\'t find anything matching your search.',
            'action'  => ['label' => 'Clear search', 'href' => '/search'],
            'class'   => 'empty-state--search',
        ]; ?>
        <?php require root_path('app/Views/components/empty-state.php'); ?>
    </div>
<?php else: ?>

    <div class="search-results">
        <p class="search-results-summary text-muted mb-4" role="status">
            Found <strong><?= (int) $result->total ?></strong> <?= $result->total === 1 ? 'match' : 'matches' ?>
            for &ldquo;<strong><?= e($result->query) ?></strong>&rdquo;
            <?= $scope !== 'all' ? 'in <strong>' . e(ucfirst($scope)) . '</strong>' : '' ?>
        </p>

        <?php
        // Group hits by entity scope
        $grouped = [
            'books'      => [],
            'authors'    => [],
            'categories' => [],
            'publishers' => [],
            'reviews'    => [],
        ];

        foreach ($result->hits as $hit) {
            $key = strtolower((string) $hit->entity);
            if (!isset($grouped[$key])) {
                $grouped[$key] = [];
            }
            $grouped[$key][] = $hit;
        }

        $groupMeta = [
            'books'      => ['label' => 'Books',      'icon' => 'fa-book'],
            'authors'    => ['label' => 'Authors',    'icon' => 'fa-user-pen'],
            'categories' => ['label' => 'Categories', 'icon' => 'fa-tags'],
            'publishers' => ['label' => 'Publishers', 'icon' => 'fa-building'],
            'reviews'    => ['label' => 'Reviews',    'icon' => 'fa-star'],
        ];
        ?>

        <?php foreach ($groupMeta as $entityKey => $meta): ?>
            <?php if (!empty($grouped[$entityKey])): ?>
                <section class="search-group mb-4" aria-labelledby="search-group-title-<?= e($entityKey) ?>">
                    <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                        <div class="d-flex align-items-center gap-2">
                            <span class="search-group-icon text-primary"><i class="fa-solid <?= e($meta['icon']) ?>"></i></span>
                            <h2 class="h6 text-uppercase tracking-wider fw-bold mb-0 text-secondary" id="search-group-title-<?= e($entityKey) ?>">
                                <?= e($meta['label']) ?>
                            </h2>
                        </div>
                        <span class="badge rounded-pill text-bg-light border"><?= count($grouped[$entityKey]) ?></span>
                    </div>

                    <div class="search-hit-list d-flex flex-column gap-2">
                        <?php foreach ($grouped[$entityKey] as $hit): ?>
                            <?php require root_path('app/Views/search/partials/_hit.php'); ?>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($result->pages > 1): ?>
            <?php
            $searchPageUrl = fn (int $target): string => \BookSphere\App\Services\SearchService::queryString(
                ['q' => $result->query, 'scope' => $scope, 'per_page' => (string) $result->perPage],
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