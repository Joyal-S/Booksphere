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

<div class="search-hero" data-animate>
    <div class="search-hero-main">
        <div class="search-hero-content">
            <p class="eyebrow">Search</p>
            <h1>Search everything</h1>
            <p class="lead">Search across books, authors, categories, publishers and reviews.</p>
        </div>
        <div class="search-hero-art" aria-hidden="true">
            <svg viewBox="0 0 72 58" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="14" y="44" width="44" height="8" rx="2" fill="url(#searchArtGrad1)" stroke="var(--primary)" stroke-width="1.5"/>
                <rect x="10" y="34" width="48" height="8" rx="2" fill="url(#searchArtGrad2)" stroke="var(--primary)" stroke-width="1.5"/>
                <path d="M18 16C26 13 34 14 36 17C38 14 46 13 54 16V31C46 28 38 29 36 32C34 29 26 28 18 31V16Z" fill="url(#searchArtGrad3)" stroke="var(--primary)" stroke-width="1.5" stroke-linejoin="round"/>
                <path d="M36 17V32" stroke="var(--primary)" stroke-width="1.5" stroke-linecap="round"/>
                <circle cx="50" cy="18" r="8" fill="rgba(255,255,255,0.92)" stroke="#8b5cf6" stroke-width="2"/>
                <path d="M56 24L64 32" stroke="#8b5cf6" stroke-width="2.5" stroke-linecap="round"/>
                <circle cx="20" cy="10" r="2.5" fill="#f59e0b"/>
                <path d="M58 8L60 6M61 11L63 12" stroke="#f59e0b" stroke-width="1.5" stroke-linecap="round"/>
                <defs>
                    <linearGradient id="searchArtGrad1" x1="14" y1="44" x2="58" y2="52" gradientUnits="userSpaceOnUse">
                        <stop stop-color="#ffffff"/>
                        <stop offset="1" stop-color="#ede9fe"/>
                    </linearGradient>
                    <linearGradient id="searchArtGrad2" x1="10" y1="34" x2="58" y2="42" gradientUnits="userSpaceOnUse">
                        <stop stop-color="#ede9fe"/>
                        <stop offset="1" stop-color="#ddd6fe"/>
                    </linearGradient>
                    <linearGradient id="searchArtGrad3" x1="18" y1="14" x2="54" y2="32" gradientUnits="userSpaceOnUse">
                        <stop stop-color="#ffffff"/>
                        <stop offset="1" stop-color="#f5f3ff"/>
                    </linearGradient>
                </defs>
            </svg>
        </div>
    </div>
    <div class="search-shortcuts" aria-label="Search areas">
        <button type="button" class="search-shortcut" data-shortcut-scope="books">
            <span class="search-shortcut-icon"><i class="fa-solid fa-book" aria-hidden="true"></i></span>
            <div class="search-shortcut-info">
                <strong>Books</strong>
                <span>Find your next read</span>
            </div>
        </button>
        <button type="button" class="search-shortcut" data-shortcut-scope="authors">
            <span class="search-shortcut-icon"><i class="fa-solid fa-user-pen" aria-hidden="true"></i></span>
            <div class="search-shortcut-info">
                <strong>Authors</strong>
                <span>Explore writers</span>
            </div>
        </button>
        <button type="button" class="search-shortcut" data-shortcut-scope="categories">
            <span class="search-shortcut-icon"><i class="fa-solid fa-tags" aria-hidden="true"></i></span>
            <div class="search-shortcut-info">
                <strong>Categories</strong>
                <span>Discover genres</span>
            </div>
        </button>
        <button type="button" class="search-shortcut" data-shortcut-scope="publishers">
            <span class="search-shortcut-icon"><i class="fa-solid fa-building" aria-hidden="true"></i></span>
            <div class="search-shortcut-info">
                <strong>Publishers</strong>
                <span>Browse imprints</span>
            </div>
        </button>
        <button type="button" class="search-shortcut" data-shortcut-scope="reviews">
            <span class="search-shortcut-icon"><i class="fa-solid fa-star" aria-hidden="true"></i></span>
            <div class="search-shortcut-info">
                <strong>Reviews</strong>
                <span>See what readers think</span>
            </div>
        </button>
    </div>
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
        <button class="btn btn-primary btn-search-submit" type="submit">
            <i class="fa-solid fa-magnifying-glass me-1" aria-hidden="true"></i>Search
        </button>
        <?php if ($term !== ''): ?>
            <a class="btn btn-outline-secondary btn-search-reset" href="/search" title="Clear search">
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