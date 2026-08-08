<?php

declare(strict_types=1);

/**
 * search/partials/_filters.php
 *
 * The Phase 11.3 ADVANCED FILTERS bar of the books scope: a compact,
 * responsive grid of <select> / number inputs that narrow the same
 * catalogue page as the search box - status, category, author,
 * publisher, language, publication-year range and minimum rating.
 *
 * Design rules:
 *     - The vocabulary ($statuses / $languages / $ratings from
 *       config/search.php, categories / authors / publishers from the
 *       provider) is ALWAYS a whitelist - an option can never produce
 *       a value the request gate would drop.
 *     - Every select is data-auto-submit so search.js refetches on
 *       change; with no JS the form's GET behaves identically.
 *     - Values are re-selected from the ALREADY-normalized $filters
 *       map (never the raw $_GET), so a tampered value that the gate
 *       dropped cannot "stick" to the UI.
 *
 * Available variables (from search/index.php):
 *     $current  - the active scope key ('books')
 *     $filters  - the active filter map (status, language, min_rating,
 *                 year_from, year_to, category_id, author_id, publisher)
 *     $options  - the filter vocabulary (categories, authors,
 *                 publishers, statuses, languages, ratings)
 */
$selected  = fn (string $key, string $value): string => isset($filters[$key]) && (string) $filters[$key] === $value ? ' selected' : '';
$yearFrom  = isset($filters['year_from']) ? (string) $filters['year_from'] : '';
$yearTo    = isset($filters['year_to']) ? (string) $filters['year_to'] : '';
$publisher = isset($filters['publisher']) ? (string) $filters['publisher'] : '';
?>

<fieldset class="book-browse-filters">
    <legend class="visually-hidden">Narrow the results</legend>

    <div class="book-browse-filter-grid">

        <div class="browse-field">
            <label class="form-label" for="search-filter-status">Status</label>
            <select class="form-select" id="search-filter-status" name="status" data-auto-submit data-filter-key="status">
                <option value="">All statuses</option>
                <?php foreach ($options['statuses'] ?? [] as $key => $label): ?>
                    <option value="<?= e((string) $key) ?>"<?= $selected('status', (string) $key) ?>><?= e((string) $label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="browse-field">
            <label class="form-label" for="search-filter-language">Language</label>
            <select class="form-select" id="search-filter-language" name="language" data-auto-submit data-filter-key="language">
                <option value="">All languages</option>
                <?php foreach ($options['languages'] ?? [] as $key => $label): ?>
                    <option value="<?= e((string) $key) ?>"<?= $selected('language', (string) $key) ?>><?= e((string) $label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="browse-field">
            <label class="form-label" for="search-filter-min-rating">Minimum rating</label>
            <select class="form-select" id="search-filter-min-rating" name="min_rating" data-auto-submit data-filter-key="min_rating">
                <option value="">Any rating</option>
                <?php foreach ($options['ratings'] ?? [] as $key => $label): ?>
                    <option value="<?= e((string) $key) ?>"<?= $selected('min_rating', (string) $key) ?>><?= e((string) $label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="browse-field">
            <label class="form-label" for="search-filter-category">Category</label>
            <select class="form-select" id="search-filter-category" name="category_id" data-auto-submit data-filter-key="category_id">
                <option value="">All categories</option>
                <?php foreach ($options['categories'] ?? [] as $category): ?>
                    <option value="<?= (int) ($category['id'] ?? 0) ?>"<?= isset($filters['category_id']) && (int) $filters['category_id'] === (int) ($category['id'] ?? 0) ? ' selected' : '' ?>>
                        <?= e((string) ($category['name'] ?? '')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="browse-field">
            <label class="form-label" for="search-filter-author">Author</label>
            <select class="form-select" id="search-filter-author" name="author_id" data-auto-submit data-filter-key="author_id">
                <option value="">All authors</option>
                <?php foreach ($options['authors'] ?? [] as $author): ?>
                    <option value="<?= (int) ($author['id'] ?? 0) ?>"<?= isset($filters['author_id']) && (int) $filters['author_id'] === (int) ($author['id'] ?? 0) ? ' selected' : '' ?>>
                        <?= e((string) ($author['name'] ?? '')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="browse-field">
            <label class="form-label" for="search-filter-publisher">Publisher</label>
            <input class="form-control" type="text" id="search-filter-publisher" name="publisher"
                   data-live-search list="search-filter-publishers" autocomplete="off" placeholder="Any publisher"
                   value="<?= e($publisher) ?>">
            <datalist id="search-filter-publishers">
                <?php foreach ($options['publishers'] ?? [] as $value): ?>
                    <option value="<?= e((string) $value) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </div>

        <div class="browse-field browse-field-years">
            <span class="form-label browse-field-label" id="search-filter-year-label">Publication year</span>
            <div class="browse-year-range">
                <label class="visually-hidden" for="search-filter-year-from">Published in or after</label>
                <input class="form-control" type="number" id="search-filter-year-from" name="year_from"
                       min="1000" max="2100" inputmode="numeric" placeholder="From" data-live-search value="<?= e($yearFrom) ?>">
                <span aria-hidden="true">&ndash;</span>
                <label class="visually-hidden" for="search-filter-year-to">Published in or before</label>
                <input class="form-control" type="number" id="search-filter-year-to" name="year_to"
                       min="1000" max="2100" inputmode="numeric" placeholder="To" data-live-search value="<?= e($yearTo) ?>">
            </div>
        </div>

    </div>
</fieldset>