<?php

declare(strict_types=1);

use BookSphere\App\Services\SearchService;

/**
 * search/index.php
 *
 * The GLOBAL SEARCH page (Phase 11.2 + 11.3): one box searches the
 * whole catalogue - books, authors, categories, publishers and
 * community reviews - with a scope tab to narrow the search to ONE
 * entity and (for the books scope) a Phase 11.3 filter bar to narrow
 * further by status, category, author, publisher, language,
 * publication year and minimum rating.
 *
 * How it works:
 *     - The form GETs /search. With JavaScript enabled, search.js
 *       watches the box (debounced, abortable), fetches /search with
 *       X-Requested-With: fetch, and swaps in the fresh
 *       search/partials/_results.php partial (progressive
 *       enhancement - the no-JS form gets the same server-rendered
 *       page). The filter selects auto-submit the same fetch on every
 *       change, exactly like the browse toolbar's [data-auto-submit].
 *     - If NO JavaScript: the form is a plain GET; every control's
 *       serialized name reaches the same /search query the page
 *       uses, so the no-JS path is identical.
 *     - $result / $error / $scopes / $scope / $filters / $options
 *       come from SearchController::index.
 *
 * Available variables:
 *     $result  - a SearchResult (or null when disabled/rate-limited)
 *     $scope   - the active scope key ('books', ..., 'reviews')
 *     $query   - the current term
 *     $filters - the active filter map (status, language, min_rating,
 *                year_from, year_to, category_id, author_id,
 *                publisher) - already normalized by the request gate
 *     $options - the filter bar vocabulary: categories/authors/
 *                publishers (from the provider) + statuses/languages/
 *                ratings (from config/search.php)
 *     $scopes  - the enabled scope catalog (key -> display label)
 *     $errors  - optional per-field validation errors
 */
$current = $scope ?? 'books';
$term    = $query ?? '';
$filters = $filters ?? [];
$options = $options ?? [];
$history = $history ?? [];
$historyEnabled = $historyEnabled ?? false;

// Whether the books filter bar should render (only the books scope
// has filters - the other entities have no book columns to narrow).
$showFilters = $current === 'books';

// The selected-attribute helper for <select> filter controls.
$selected = fn (string $key): string => isset($filters[$key]) ? ' selected' : '';

// The active-filter chips: one per applied filter, each a link back
// to /search with exactly that filter removed. The URL builder drops
// the removed key and keeps everything else (q, scope, other filters)
// so chips, the filter bar and the pagination bar always agree.
$chipUrl = fn (array $remove): string => SearchService::queryString(
    ['q' => $term, 'scope' => $current] + $filters,
    $remove,
);

$chips = [];

foreach ($filters as $key => $value) {
    if ($key === 'year_from' || $key === 'year_to') {
        continue; // grouped into a single "published X–Y" chip below
    }

    $label = match ($key) {
        'status'      => ($options['statuses'][$value] ?? $value) . ' status',
        'language'    => ($options['languages'][$value] ?? $value) . ' language',
        'min_rating'  => ($options['ratings'][$value] ?? $value) . ' rating',
        'publisher'   => $value,
        'category_id' => null,
        'author_id'   => null,
        default       => null,
    };

    if ($key === 'category_id') {
        foreach ($options['categories'] ?? [] as $category) {
            if ((int) ($category['id'] ?? 0) === (int) $value) {
                $label = $category['name'];
                break;
            }
        }
        $label ??= 'Category';
    }

    if ($key === 'author_id') {
        foreach ($options['authors'] ?? [] as $author) {
            if ((int) ($author['id'] ?? 0) === (int) $value) {
                $label = $author['name'];
                break;
            }
        }
        $label ??= 'Author';
    }

    if ($label !== null && $label !== '') {
        $chips[] = ['label' => $label, 'url' => $chipUrl([$key])];
    }
}

// Publication-year is ONE removable chip covering both bounds.
if (isset($filters['year_from']) || isset($filters['year_to'])) {
    $chips[] = [
        'label' => 'Published ' . ($filters['year_from'] ?? '…') . '–' . ($filters['year_to'] ?? '…'),
        'url'   => $chipUrl(['year_from', 'year_to']),
    ];
}

?>

<div class="page-intro">
    <p class="eyebrow">Search</p>
    <h1>Search everything</h1>
    <p class="lead">One search across the whole catalogue &mdash; books, authors, categories, publishers and reviews.</p>
</div>

<form class="card-base search-toolbar mb-3" method="get" action="/search" role="search"
      data-search-form data-search-endpoint="/search">

    <div class="search-row">
        <div class="search-field">
            <i class="fa-solid fa-magnifying-glass search-field-icon" aria-hidden="true"></i>
            <label class="visually-hidden" for="search-q">Search the catalogue</label>
            <input class="form-control search-field-input" type="search" id="search-q" name="q"
                   data-live-search data-autocomplete data-autocomplete-endpoint="/search/suggest"
                   data-autocomplete-min="<?= (int) (config('search.suggestions.min_length') ?? 2) ?>"
                   placeholder="Search by title, author, ISBN, publisher, category, review text..."
                   value="<?= e($term) ?>" autocomplete="off" aria-describedby="search-errors">
            <span class="search-field-kbd" aria-hidden="true">Ctrl&nbsp;K</span>
        </div>
        <button class="btn btn-primary" type="submit">
            <i class="fa-solid fa-magnifying-glass me-1" aria-hidden="true"></i>Search
        </button>
        <a class="btn btn-outline-secondary" href="/search" title="Clear the search and every filter">
            <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
            <span class="visually-hidden">Clear the search and every filter</span>
        </a>
    </div>
    <?php $field = 'q'; require root_path('app/Views/partials/form-errors.php'); ?>

    <div class="search-scopes" role="radiogroup" aria-labelledby="search-scope-label">
        <span class="search-scopes-label" id="search-scope-label">Search in</span>
        <?php foreach (($scopes ?? []) as $key => $label): ?>
            <?php $activeScope = $current === $key; ?>
            <label class="search-scope<?= $activeScope ? ' is-active' : '' ?>">
                <input type="radio" class="visually-hidden" name="scope" value="<?= e($key) ?>"
                       <?= $activeScope ? 'checked' : '' ?> data-scope-radio>
                <span class="search-scope-btn"><?= e($label) ?></span>
            </label>
        <?php endforeach; ?>
    </div>

    <?php if ($showFilters): ?>
        <?php require __DIR__ . '/partials/_filters.php'; ?>
    <?php endif; ?>

    <div class="search-chips" aria-label="Active filters">
        <?php if ($chips === []): ?>
            <span class="search-chips-empty">No filters &mdash; use the Filters box to narrow the catalogue.</span>
        <?php else: ?>
            <span class="search-chips-label"><?= count($chips) ?> active:</span>
            <?php foreach ($chips as $chip): ?>
                <a class="filter-chip" href="<?= e($chip['url']) ?>" title="Remove this filter" data-filter-chip>
                    <?= e($chip['label']) ?>
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    <span class="visually-hidden">Remove filter <?= e($chip['label']) ?></span>
                </a>
            <?php endforeach; ?>
            <a class="filter-chip filter-chip-clear" href="/search?<?= e(http_build_query(['q' => $term, 'scope' => $current])) ?>" title="Clear all filters" data-filter-chip>
                <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>Clear all
            </a>
        <?php endif; ?>
    </div>
</form>

<div class="visually-hidden" role="status" data-search-status aria-live="polite"></div>

<div data-search-results aria-busy="false">
    <?php require __DIR__ . '/partials/_results.php'; ?>
</div>

<?php if ($historyEnabled): ?>
    <?php require __DIR__ . '/partials/_history.php'; ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/_history-modal.php'; ?>