<?php

declare(strict_types=1);

/**
 * admin/google-books.php
 *
 * The Google Books search + import page (Phase 10.2 + 10.3, admin).
 *
 * Layout:
 *     1. Intro        - title + one-line purpose
 *     2. Status strip - provider on/off, base URL, cache TTL and the
 *                       circuit breaker state, so an admin can SEE
 *                       the module health at a glance (mirrors the
 *                       recommendation monitoring page's intent)
 *     3. Search form  - scope selector (any/title/author/isbn/
 *                       publisher/subject) + term input; submits as
 *                       a plain GET so it works WITHOUT JavaScript
 *                       (the page re-renders with the results)
 *     4. Results      - admin/google-books/partials/_results.php,
 *                       shared verbatim with the live JSON endpoint;
 *                       each result card carries an Import button
 *                       (Phase 10.3) that brings ONE record into the
 *                       local catalogue
 *
 * Available variables (GoogleBooksController::index()):
 *     $result   - ?ProviderSearchResult (null = nothing searched yet)
 *     $request  - the SearchBooksRequest (form state)
 *     $enabled  - whether the provider module is switched on
 *     $breaker  - circuit breaker stats array
 *     $cache    - response cache stats array
 *     $existing - [google_book_id => local book id] for the cards
 *     $config   - safe config values (base_url, display limit, TTLs)
 */

$req       = $request;
$result    = $result;
$breaker   = $breaker;
$cache     = $cache;
$config    = $config;
$searchTtl = (int) ($config['search_ttl_seconds'] ?? 900);

$typeLabels = [
    'any'       => 'Everything',
    'title'     => 'Title',
    'author'    => 'Author',
    'isbn'      => 'ISBN',
    'publisher' => 'Publisher',
    'subject'   => 'Subject',
];

$selected = fn (string $value): string => $req->type() === $value ? ' selected' : '';

$breakerState = (string) ($breaker['state'] ?? 'closed');
$breakerTone  = ['open' => 'danger', 'half-open' => 'warning', 'closed' => 'success'][$breakerState] ?? 'secondary';

$cacheStats = $cache['namespaces']['search'] ?? [];
$cacheFiles = (int) ($cacheStats['files'] ?? 0);
$cacheStale = (int) ($cacheStats['stale'] ?? 0);

?>
<div class="page-intro">
    <p class="eyebrow">Administration &middot; Google Books</p>
    <h1>Google Books Search</h1>
    <p class="lead">
        Search the Google Books catalogue directly - results come from the provider, not the
        local library. The <strong>Import</strong> button on a result brings that book into
        your catalogue instantly.
    </p>
</div>

<!-- 1. Status strip --------------------------------------------------- -->
<div class="d-flex flex-wrap align-items-center gap-2 mb-3" data-animate>
    <span class="badge <?= $enabled ? 'text-bg-success' : 'text-bg-secondary' ?>">
        <i class="fa-solid fa-plug me-1" aria-hidden="true"></i>
        Provider <?= $enabled ? 'enabled' : 'disabled' ?>
    </span>
    <span class="badge text-bg-light" title="Google Books API endpoint">
        <i class="fa-solid fa-globe me-1" aria-hidden="true"></i>
        <?= e((string) $config['base_url']) ?>
    </span>
    <span class="badge text-bg-light" title="How long a search result set stays cached">
        <i class="fa-solid fa-clock me-1" aria-hidden="true"></i>
        Cache <?= (int) ($searchTtl / 60) ?> min &middot; <?= $cacheFiles ?> entries<?= $cacheStale > 0 ? ' &middot; ' . $cacheStale . ' stale' : '' ?>
    </span>
    <span class="badge text-bg-<?= e($breakerTone) ?>" title="Circuit breaker state (cache-only mode when open)">
        <i class="fa-solid fa-heart-circle-bolt me-1" aria-hidden="true"></i>
        Breaker <?= e($breakerState) ?><?= $breakerState !== 'closed' ? ' &middot; ' . (int) ($breaker['failures'] ?? 0) . '/' . (int) ($breaker['max_failures'] ?? 3) . ' failures' : '' ?>
    </span>
</div>

<?php if (!$enabled): ?>
    <?php $alert = ['type' => 'info', 'message' => 'The Google Books provider is disabled. Set GOOGLE_BOOKS_ENABLED=true in .env to turn search on (an API key in GOOGLE_BOOKS_API_KEY is optional but recommended).']; ?>
    <?php require root_path('app/Views/components/alert.php'); ?>
<?php endif; ?>

<!-- 2. Search form ----------------------------------------------------- -->
<form class="card-base google-books-toolbar mb-3" method="get" action="/admin/google-books" role="search"
      data-gb-form data-search-endpoint="/admin/google-books/search">
    <div class="row g-2 align-items-end">
        <div class="col-sm-4 col-lg-3">
            <label class="form-label" for="gb-type">Search by</label>
            <select class="form-select" id="gb-type" name="type" data-gb-type>
                <?php foreach ($typeLabels as $typeKey => $label): ?>
                    <option value="<?= e($typeKey) ?>"<?= $selected($typeKey) ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-8 col-lg-7">
            <label class="form-label" for="gb-q">Search term</label>
            <div class="gb-search-wrap">
                <i class="fa-solid fa-magnifying-glass gb-search-icon" aria-hidden="true"></i>
                <input class="form-control gb-search-input" type="search" id="gb-q" name="q"
                       data-gb-search-input placeholder="e.g. Harry Potter, J.K. Rowling, 9780439064873..."
                       value="<?= e($req->query()) ?>" autocomplete="off" maxlength="100">
            </div>
            <p class="form-text mb-0" id="gb-q-hint" data-gb-hint hidden></p>
        </div>
        <div class="col-auto">
            <button class="btn btn-primary" type="submit">
                <i class="fa-solid fa-magnifying-glass me-1" aria-hidden="true"></i>Search
            </button>
        </div>
        <div class="col-auto">
            <a class="btn btn-outline-secondary" href="/admin/google-books" title="Clear the search">
                <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                <span class="visually-hidden">Clear the search</span>
            </a>
        </div>
    </div>
</form>

<div class="visually-hidden" role="status" data-gb-status aria-live="polite"></div>

<!-- 3. Results --------------------------------------------------------- -->
<?php require root_path('app/Views/admin/google-books/partials/_results.php'); ?>
