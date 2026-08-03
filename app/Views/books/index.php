<?php

declare(strict_types=1);

use BookSphere\App\Services\BookService;

/**
 * books/index.php
 *
 * The BROWSE page (Phase 5.5): a professional search + filter
 * toolbar above the catalogue, with sorting, server-side
 * pagination and a grid/table view toggle.
 *
 * Layout, top to bottom:
 *
 *     1. Search row      - large live-search box (debounced fetch
 *                          to /books/search), Search + Reset buttons
 *     2. Filter grid     - category, author, publisher, language,
 *                          publication-year range, status, rating
 *     3. Toolbar row     - active-filter chips, sort select,
 *                          grid/table toggle, page-size select
 *     4. Results region  - books/partials/_results.php (shared with
 *                          the live-search JSON endpoint)
 *
 * How the live search works:
 *     app.js watches the search box (and the free-text publisher
 *     box), debounces for 300 ms, then fetches /books/search with
 *     the form's query string. The endpoint returns the freshly
 *     rendered _results.php partial; JS swaps it in and keeps the
 *     URL shareable via history.replaceState. With no JavaScript,
 *     the Search button submits the same form and gets the same
 *     page - the live search is pure progressive enhancement.
 *
 * Available variables (from BookController::index / ::catalogue()):
 *     $result    - ['items', 'total', 'page', 'perPage', 'pages']
 *     $filters   - normalized filters (already whitelisted)
 *     $options   - filter dropdown sources
 *     $sorts     - BookService::SORTS (sort key -> label/spec)
 *     $pageSizes - BookService::PAGE_SIZES (10, 20, 50, 100)
 *     $ratings   - BookService::RATING_FILTERS
 *     $isAdmin   - whether the admin row actions are shown
 */

$total   = $result['total'];
$filters = $filters ?? [];

$options = $options ?? [];

// --- Active filter chips -------------------------------------------
// Each chip is a link that removes exactly ONE filter from the
// query string ("/books?q=harry&category_id=3" without category).
// No JavaScript needed: plain links back to the browse page. The
// URL builder is the service's single source of truth, so the
// chips, the pagination bar and the form always agree.
$chipUrl = fn (array $remove): string => BookService::queryString($filters, $remove);

$chips = [];

if (($filters['q'] ?? '') !== '') {
    $chips[] = ['label' => 'Search: "' . $filters['q'] . '"', 'url' => $chipUrl(['q'])];
}

if (($filters['category_id'] ?? null) !== null) {
    foreach ($options['categories'] ?? [] as $category) {
        if ((int) $category['id'] === $filters['category_id']) {
            $chips[] = ['label' => $category['name'], 'url' => $chipUrl(['category_id'])];
            break;
        }
    }
}

if (($filters['author_id'] ?? null) !== null) {
    foreach ($options['authors'] ?? [] as $author) {
        if ((int) $author['id'] === $filters['author_id']) {
            $chips[] = ['label' => $author['name'], 'url' => $chipUrl(['author_id'])];
            break;
        }
    }
}

if (($filters['publisher'] ?? '') !== '') {
    $chips[] = ['label' => $filters['publisher'], 'url' => $chipUrl(['publisher'])];
}

if (($filters['language'] ?? '') !== '') {
    $chips[] = ['label' => $options['languages'][$filters['language']] ?? $filters['language'], 'url' => $chipUrl(['language'])];
}

if (($filters['year_from'] ?? null) !== null || ($filters['year_to'] ?? null) !== null) {
    $chips[] = [
        'label' => 'Published ' . ($filters['year_from'] ?? '…') . '–' . ($filters['year_to'] ?? '…'),
        'url'   => $chipUrl(['year_from', 'year_to']),
    ];
}

if (($filters['min_rating'] ?? null) !== null) {
    $chips[] = ['label' => $filters['min_rating'] . ' stars & up', 'url' => $chipUrl(['min_rating'])];
}

if (($filters['status'] ?? '') !== '') {
    $chips[] = ['label' => $options['statuses'][$filters['status']] ?? $filters['status'], 'url' => $chipUrl(['status'])];
}

$selected = fn (string $name, string|int|float|null $value): string => (string) ($filters[$name] ?? '') === (string) $value ? ' selected' : '';

?>
<div class="page-intro">
    <p class="eyebrow">Library</p>
    <h1>Browse Books</h1>
    <p class="lead">Search, filter and sort through <?= $total ?> <?= $total === 1 ? 'book' : 'books' ?> in the catalogue.</p>
</div>

<form class="card-base book-browse-toolbar mb-3" method="get" action="/books" role="search"
      data-live-search-form data-search-endpoint="/books/search">

    <!-- 1. Search row ------------------------------------------------- -->
    <div class="book-browse-search-row">
        <div class="book-browse-search">
            <i class="fa-solid fa-magnifying-glass book-browse-search-icon" aria-hidden="true"></i>
            <label class="visually-hidden" for="browse-q">Search books</label>
            <input class="form-control book-browse-search-input" type="search" id="browse-q" name="q"
                   data-live-search placeholder="Search by title, author, ISBN, publisher, category, language..."
                   value="<?= e($filters['q'] ?? '') ?>" autocomplete="off">
            <span class="book-browse-search-kbd" aria-hidden="true">Ctrl&nbsp;K</span>
        </div>
        <button class="btn btn-primary" type="submit">
            <i class="fa-solid fa-magnifying-glass me-1" aria-hidden="true"></i>Search
        </button>
        <a class="btn btn-outline-secondary" href="/books" title="Reset search and filters">
            <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
            <span class="visually-hidden">Reset search and filters</span>
        </a>
    </div>

    <!-- 2. Filter grid ------------------------------------------------- -->
    <fieldset class="book-browse-filters">
        <legend class="visually-hidden">Catalogue filters</legend>
        <div class="book-browse-filter-grid">
            <div class="browse-field">
                <label class="form-label" for="browse-category">Category</label>
                <select class="form-select" id="browse-category" name="category_id" data-auto-submit>
                    <option value="">All categories</option>
                    <?php foreach ($options['categories'] ?? [] as $category): ?>
                        <option value="<?= (int) $category['id'] ?>"<?= $selected('category_id', (string) $category['id']) ?>>
                            <?= e($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="browse-field">
                <label class="form-label" for="browse-author">Author</label>
                <select class="form-select" id="browse-author" name="author_id" data-auto-submit>
                    <option value="">All authors</option>
                    <?php foreach ($options['authors'] ?? [] as $author): ?>
                        <option value="<?= (int) $author['id'] ?>"<?= $selected('author_id', (string) $author['id']) ?>>
                            <?= e($author['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="browse-field">
                <label class="form-label" for="browse-publisher">Publisher</label>
                <input class="form-control" type="text" id="browse-publisher" name="publisher"
                       data-live-search list="browse-publishers" autocomplete="off"
                       placeholder="Any publisher" value="<?= e($filters['publisher'] ?? '') ?>">
                <datalist id="browse-publishers">
                    <?php foreach ($options['publishers'] ?? [] as $publisher): ?>
                        <option value="<?= e((string) $publisher) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="browse-field">
                <label class="form-label" for="browse-language">Language</label>
                <select class="form-select" id="browse-language" name="language" data-auto-submit>
                    <option value="">All languages</option>
                    <?php foreach ($options['languages'] ?? [] as $code => $label): ?>
                        <option value="<?= e($code) ?>"<?= $selected('language', $code) ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="browse-field browse-field-years">
                <span class="form-label browse-field-label" id="browse-year-label">Publication year</span>
                <div class="browse-year-range">
                    <label class="visually-hidden" for="browse-year-from">Published in or after</label>
                    <input class="form-control" type="number" id="browse-year-from" name="year_from"
                           min="1000" max="2100" inputmode="numeric" placeholder="From"
                           data-live-search value="<?= e((string) ($filters['year_from'] ?? '')) ?>">
                    <span aria-hidden="true">&ndash;</span>
                    <label class="visually-hidden" for="browse-year-to">Published in or before</label>
                    <input class="form-control" type="number" id="browse-year-to" name="year_to"
                           min="1000" max="2100" inputmode="numeric" placeholder="To"
                           data-live-search value="<?= e((string) ($filters['year_to'] ?? '')) ?>">
                </div>
            </div>

            <div class="browse-field">
                <label class="form-label" for="browse-status">Status</label>
                <select class="form-select" id="browse-status" name="status" data-auto-submit>
                    <option value="">All statuses</option>
                    <?php foreach ($options['statuses'] ?? [] as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $selected('status', $key) ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="browse-field">
                <label class="form-label" for="browse-rating">Rating</label>
                <select class="form-select" id="browse-rating" name="min_rating" data-auto-submit>
                    <?php foreach ($ratings as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $selected('min_rating', $key) ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </fieldset>

    <!-- 3. Toolbar row: chips + sort + view + page size ----------------- -->
    <div class="book-browse-toolbar-row">
        <div class="book-browse-chips" aria-label="Active filters">
            <?php if ($chips === []): ?>
                <span class="book-browse-chips-empty">All books shown &mdash; use the filters above to narrow the list.</span>
            <?php else: ?>
                <span class="book-browse-chips-label"><?= count($chips) ?> active:</span>
                <?php foreach ($chips as $chip): ?>
                    <a class="filter-chip" href="<?= e($chip['url']) ?>" title="Remove this filter">
                        <?= e($chip['label']) ?>
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        <span class="visually-hidden">Remove filter <?= e($chip['label']) ?></span>
                    </a>
                <?php endforeach; ?>
                <a class="filter-chip filter-chip-clear" href="/books" title="Clear all filters">
                    <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>Clear all
                </a>
            <?php endif; ?>
        </div>

        <div class="book-browse-controls">
            <?php if ($isAdmin): ?>
                <a class="btn btn-primary" href="/books/create">
                    <i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add Book
                </a>
            <?php endif; ?>

            <div class="browse-field browse-field-sort">
                <label class="form-label" for="browse-sort">Sort by</label>
                <select class="form-select" id="browse-sort" name="sort" data-auto-submit>
                    <?php foreach ($sorts as $key => $spec): ?>
                        <option value="<?= e($key) ?>"<?= $selected('sort', $key) ?>>
                            <?= e($spec['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="browse-field browse-field-view">
                <span class="form-label browse-field-label" id="browse-view-label">View</span>
                <div class="view-toggle" role="group" aria-labelledby="browse-view-label">
                    <button type="button" class="view-toggle-btn" data-view-toggle="grid" aria-pressed="false">
                        <i class="fa-solid fa-table-cells-large" aria-hidden="true"></i>
                        <span>Grid</span>
                    </button>
                    <button type="button" class="view-toggle-btn" data-view-toggle="table" aria-pressed="true">
                        <i class="fa-solid fa-table-list" aria-hidden="true"></i>
                        <span>Table</span>
                    </button>
                </div>
            </div>

            <div class="browse-field browse-field-perpage">
                <label class="form-label" for="browse-per-page">Per page</label>
                <select class="form-select" id="browse-per-page" name="per_page" data-auto-submit>
                    <?php foreach ($pageSizes as $size): ?>
                        <option value="<?= (int) $size ?>"<?= $selected('perPage', (string) $size) ?>>
                            <?= (int) $size ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
</form>

<div class="visually-hidden" role="status" data-live-status aria-live="polite"></div>

<?php require root_path('app/Views/books/partials/_results.php'); ?>

<?php if ($isAdmin): ?>
    <?php require root_path('app/Views/books/partials/_delete-modal.php'); ?>
<?php endif; ?>
