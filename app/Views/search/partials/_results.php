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
<?php elseif ($result === null || !$result->hasQuery()): ?>
    <div class="card-base search-empty-state-card p-4 p-md-5 text-center">
        <div class="search-empty-icon-wrap mb-3 mx-auto">
            <i class="fa-solid fa-magnifying-glass search-empty-icon" aria-hidden="true"></i>
        </div>
        <h2 class="h4 fw-bold mb-2">Search BookSphere</h2>
        <p class="text-muted mx-auto mb-4" style="max-width: 520px;">
            Find books, authors, categories, publishers and community reviews from one place.
        </p>
        <div class="search-empty-features">
            <div class="search-feature-card">
                <span class="search-feature-icon"><i class="fa-solid fa-book" aria-hidden="true"></i></span>
                <div class="search-feature-text">
                    <strong>Books</strong>
                    <span>Titles, ISBNs, descriptions, &amp; genres</span>
                </div>
            </div>
            <div class="search-feature-card">
                <span class="search-feature-icon"><i class="fa-solid fa-user-pen" aria-hidden="true"></i></span>
                <div class="search-feature-text">
                    <strong>Authors &amp; Publishers</strong>
                    <span>Literary creators and publishing imprints</span>
                </div>
            </div>
            <div class="search-feature-card">
                <span class="search-feature-icon"><i class="fa-solid fa-star" aria-hidden="true"></i></span>
                <div class="search-feature-text">
                    <strong>Real Reviews</strong>
                    <span>Community ratings, thoughts, &amp; feedback</span>
                </div>
            </div>
        </div>
    </div>
<?php elseif ($result->error !== ''): ?>
    <div class="card-base p-4">
        <?php $alert = ['type' => 'danger', 'message' => $result->error]; ?>
        <?php require root_path('app/Views/components/alert.php'); ?>
    </div>
<?php elseif ($result->total === 0): ?>
    <div class="card-base search-empty-state-card p-4 p-md-5 text-center">
        <div class="search-empty-icon-wrap search-empty-icon-wrap--muted mb-3 mx-auto">
            <i class="fa-solid fa-circle-question search-empty-icon" aria-hidden="true"></i>
        </div>
        <h2 class="h4 fw-bold mb-2">No results found</h2>
        <p class="text-muted mx-auto mb-3" style="max-width: 480px;">
            We couldn't find anything matching your search. Try checking your spelling or searching with different keywords.
        </p>
        <a class="btn btn-outline-secondary btn-sm" href="/search">
            <i class="fa-solid fa-rotate-left me-1" aria-hidden="true"></i>Clear search
        </a>
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