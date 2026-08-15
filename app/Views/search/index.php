<?php

declare(strict_types=1);

/**
 * search/index.php
 *
 * The GLOBAL SEARCH page: one box searches the whole BookSphere system &mdash;
 * books, authors, categories, publishers and community reviews &mdash;
 * with scope tabs (All, Books, Authors, Categories, Publishers, Reviews) to narrow
 * search results to a single entity type.
 *
 * Available variables:
 *     $result  - a SearchResult (or null when disabled/rate-limited)
 *     $scope   - the active scope key ('all', 'books', ..., 'reviews')
 *     $query   - the current term
 *     $scopes  - the enabled scope catalog (key -> display label)
 *     $errors  - optional per-field validation errors
 */
$current = $scope ?? 'all';
$term    = $query ?? '';
$history = $history ?? [];
$historyEnabled = $historyEnabled ?? false;

?>

<div class="page-intro">
    <p class="eyebrow">Search</p>
    <h1>Search everything</h1>
    <p class="lead">Search across books, authors, categories, publishers and reviews.</p>
</div>

<form class="card-base search-toolbar mb-3" method="get" action="/search" role="search"
      data-search-form data-search-endpoint="/search">

    <div class="search-row">
        <div class="search-field">
            <i class="fa-solid fa-magnifying-glass search-field-icon" aria-hidden="true"></i>
            <label class="visually-hidden" for="search-q">Search BookSphere</label>
            <input class="form-control search-field-input" type="search" id="search-q" name="q"
                   data-live-search data-autocomplete data-autocomplete-endpoint="/search/suggest"
                   data-autocomplete-min="<?= (int) (config('search.suggestions.min_length') ?? 2) ?>"
                   placeholder="Search books, authors, categories, publishers, reviews..."
                   value="<?= e($term) ?>" autocomplete="off" aria-describedby="search-errors">
            <span class="search-field-kbd" aria-hidden="true">Ctrl&nbsp;K</span>
        </div>
        <button class="btn btn-primary" type="submit">
            <i class="fa-solid fa-magnifying-glass me-1" aria-hidden="true"></i>Search
        </button>
        <?php if ($term !== ''): ?>
            <a class="btn btn-outline-secondary" href="/search" title="Clear search">
                <i class="fa-solid fa-rotate-left me-1" aria-hidden="true"></i>Clear
            </a>
        <?php endif; ?>
    </div>
    <?php $field = 'q'; require root_path('app/Views/partials/form-errors.php'); ?>

    <div class="search-scopes" role="radiogroup" aria-labelledby="search-scope-label">
        <span class="search-scopes-label" id="search-scope-label">Scope</span>
        <?php foreach (($scopes ?? []) as $key => $label): ?>
            <?php $activeScope = $current === $key; ?>
            <label class="search-scope<?= $activeScope ? ' is-active' : '' ?>">
                <input type="radio" class="visually-hidden" name="scope" value="<?= e($key) ?>"
                       <?= $activeScope ? 'checked' : '' ?> data-scope-radio>
                <span class="search-scope-btn"><?= e($label) ?></span>
            </label>
        <?php endforeach; ?>
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