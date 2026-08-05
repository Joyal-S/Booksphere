<?php

declare(strict_types=1);

/**
 * library/partials/_filters.php
 *
 * The SEARCH + FILTER + SORT + VIEW bar of the library dashboard
 * (Phase 8.3) - the control row above the grid. One GET form to
 * /library (the no-JS path): the search box (title / description /
 * publisher / language / author / category), the status / category /
 * author / rating selects, the favourite / recently-added /
 * recently-updated toggles, the sort dropdown (incl. Most Reviewed /
 * Most Recommended) and the grid / list view switch. The
 * form is upgraded by library.js: typing in the box and changing any
 * control fetches /library/filter (or /library/sort for the sort,
 * /library/view-mode for the view) and swaps the [data-library-results]
 * region in place - the two paths render the identical fragment.
 *
 * Included from a view that sets:
 *
 *     $filters - the ACTIVE filter values (q, status, category,
 *                author, rating, favorite, recently_added,
 *                recently_updated) - the selects render these selected
 *     $options - the dropdown vocabulary (categories / authors)
 *     $sorts   - sort key -> label
 *     $prefs   - the persisted preferences (sort / view)
 *     $total   - the current grid total (shown next to the reset)
 */

$filters = $filters ?? [];
$options = $options ?? [];
$sorts   = $sorts ?? [];
$prefs   = $prefs ?? [];
$total   = $total ?? 0;
$statusLabels = $statusLabels ?? [];

$selected = static fn (string $key): string => (string) ($filters[$key] ?? '');
$checked  = static fn (string $key): string => !empty($filters[$key]) ? ' checked' : '';

// The view-switch links: the current query with the view swapped (the
// no-JS path; library.js turns them into persisted fetch toggles).
$viewQuery = static function (string $view, array $filters, array $prefs): array {
    $query = $filters;
    $query['sort'] = $prefs['sort'] ?? 'newest_added';
    $query['view'] = $view;

    return $query;
};

?>
<form class="card-base library-filter-bar" method="get" action="/library" role="search"
      data-library-filter-form data-filter-endpoint="/library/filter" data-sort-endpoint="/library/sort"
      data-view-endpoint="/library/view-mode" data-view-mode="<?= e($prefs['view'] ?? 'grid') ?>">

    <div class="library-filter-search">
        <label class="visually-hidden" for="library-filter-q">Search your library</label>
        <input class="form-control" type="search" id="library-filter-q" name="q"
               data-library-filter="q" data-library-search-input
               placeholder="Search your library by title, description, author, category..."
               value="<?= e((string) $selected('q')) ?>" autocomplete="off">
        <button class="btn btn-primary" type="submit">
            <i class="fa-solid fa-magnifying-glass me-1" aria-hidden="true"></i>Search
        </button>
    </div>

    <div class="library-filter-controls">
        <div class="library-filter-select">
            <label class="visually-hidden" for="library-filter-status">Shelf</label>
            <select class="form-select form-select-sm" id="library-filter-status" name="status" data-library-filter="status">
                <option value="">Any shelf</option>
                <?php foreach ($statusLabels as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= $selected('status') === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="library-filter-select">
            <label class="visually-hidden" for="library-filter-category">Category</label>
            <select class="form-select form-select-sm" id="library-filter-category" name="category" data-library-filter="category">
                <option value="">Any category</option>
                <?php foreach (($options['categories'] ?? []) as $option): ?>
                    <option value="<?= (int) $option['id'] ?>"<?= $selected('category') === (string) (int) $option['id'] ? ' selected' : '' ?>><?= e((string) $option['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="library-filter-select">
            <label class="visually-hidden" for="library-filter-author">Author</label>
            <select class="form-select form-select-sm" id="library-filter-author" name="author" data-library-filter="author">
                <option value="">Any author</option>
                <?php foreach (($options['authors'] ?? []) as $option): ?>
                    <option value="<?= (int) $option['id'] ?>"<?= $selected('author') === (string) (int) $option['id'] ? ' selected' : '' ?>><?= e((string) $option['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="library-filter-select">
            <label class="visually-hidden" for="library-filter-rating">Minimum rating</label>
            <select class="form-select form-select-sm" id="library-filter-rating" name="rating" data-library-filter="rating">
                <option value="">Any rating</option>
                <?php for ($star = 5; $star >= 1; $star--): ?>
                    <option value="<?= $star ?>"<?= $selected('rating') === (string) $star ? ' selected' : '' ?>>Rated <?= $star ?>+</option>
                <?php endfor; ?>
            </select>
        </div>

        <label class="library-filter-toggle">
            <input type="checkbox" name="favorite" value="1" data-library-filter="favorite"<?= $checked('favorite') ?>>
            <i class="fa-solid fa-heart" aria-hidden="true"></i> Favourites
        </label>

        <label class="library-filter-toggle">
            <input type="checkbox" name="recently_added" value="1" data-library-filter="recently_added"<?= $checked('recently_added') ?>>
            <i class="fa-solid fa-calendar-plus" aria-hidden="true"></i> Added this month
        </label>

        <label class="library-filter-toggle">
            <input type="checkbox" name="recently_updated" value="1" data-library-filter="recently_updated"<?= $checked('recently_updated') ?>>
            <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Updated recently
        </label>

        <div class="library-filter-spacer"></div>

        <div class="library-filter-select">
            <label class="visually-hidden" for="library-filter-sort">Sort</label>
            <select class="form-select form-select-sm" id="library-filter-sort" name="sort" data-library-sort>
                <?php foreach ($sorts as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= ($prefs['sort'] ?? '') === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="library-view-toggle" role="group" aria-label="View mode">
            <?php foreach (['grid' => 'fa-table-cells-large', 'list' => 'fa-list'] as $viewKey => $icon): ?>
                <a class="library-view-btn<?= ($prefs['view'] ?? 'grid') === $viewKey ? ' is-active' : '' ?>"
                   href="/library?<?= e(http_build_query($viewQuery($viewKey, $filters, $prefs))) ?>"
                   data-library-view="<?= e($viewKey) ?>"
                   aria-pressed="<?= ($prefs['view'] ?? 'grid') === $viewKey ? 'true' : 'false' ?>"
                   title="<?= ucfirst($viewKey) ?> view">
                    <i class="fa-solid <?= e($icon) ?>" aria-hidden="true"></i>
                    <span class="visually-hidden"><?= ucfirst($viewKey) ?> view</span>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($filters !== []): ?>
            <a class="btn btn-sm btn-outline-secondary library-filter-reset" href="/library" title="Clear all filters">
                <i class="fa-solid fa-rotate-left me-1" aria-hidden="true"></i>Reset
            </a>
        <?php endif; ?>
    </div>
</form>