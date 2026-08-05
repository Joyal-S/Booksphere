<?php

declare(strict_types=1);

/**
 * reviews/partials/_toolbar.php
 *
 * The shared REVIEW LIST TOOLBAR (Phase 7.4): the search box, the
 * sort and per-page selects and the filter chips of a professional
 * review list. ONE form carries the whole toolbar state (the search
 * box, the two selects and the hidden active filters), so a sort
 * change keeps the search term and the filters; the chips are plain
 * links that preserve the same state through their query strings.
 *
 * reviews.js auto-submits the form when a select changes and shows
 * the loading skeletons around the list while the page navigates -
 * no flicker, no reload.
 *
 * Available variables (set by the including page):
 *     $toolbar - from ReviewListPresenter::toolbar():
 *                base (string), sort (string), sorts (map),
 *                perPage (int), perPages (int[]), q (string),
 *                rating (int), edited (bool), mine (bool),
 *                showMine (bool)
 */

$toolbar = array_merge([
    'base'     => '/reviews',
    'sort'     => 'newest',
    'sorts'    => [],
    'perPage'  => 10,
    'perPages' => [],
    'q'        => '',
    'rating'   => 0,
    'edited'   => false,
    'mine'     => false,
    'showMine' => false,
], $toolbar ?? []);

// The state the filter chips preserve: only the values that are NOT
// part of the chips themselves (the chips add/remove rating, edited
// and mine) and NOT the page number (a filter change restarts the
// list at page 1).
$chipParams = array_filter([
    'sort'     => $toolbar['sort'],
    'q'        => $toolbar['q'] !== '' ? $toolbar['q'] : null,
    'per_page' => (int) $toolbar['perPage'] !== 10 ? (string) $toolbar['perPage'] : null,
], static fn ($value): bool => $value !== null && $value !== '');
?>
<form class="review-toolbar" method="get" action="<?= e($toolbar['base']) ?>" data-review-toolbar>
    <div class="review-toolbar-row">
        <?php $search = ['q' => $toolbar['q']]; ?>
        <?php require root_path('app/Views/components/review-search.php'); ?>

        <div class="review-toolbar-selects">
            <?php if ($toolbar['sorts'] !== []): ?>
                <label class="review-toolbar-label" for="review-toolbar-sort">
                    Sort
                    <select class="form-select form-select-sm" id="review-toolbar-sort" name="sort" data-review-select>
                        <?php foreach ($toolbar['sorts'] as $key => $label): ?>
                            <option value="<?= e($key) ?>"<?= $toolbar['sort'] === $key ? ' selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>

            <?php if ($toolbar['perPages'] !== []): ?>
                <label class="review-toolbar-label" for="review-toolbar-per-page">
                    Per page
                    <select class="form-select form-select-sm" id="review-toolbar-per-page" name="per_page" data-review-select>
                        <?php foreach ($toolbar['perPages'] as $option): ?>
                            <option value="<?= (int) $option ?>"<?= (int) $option === (int) $toolbar['perPage'] ? ' selected' : '' ?>>
                                <?= (int) $option ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
        </div>
    </div>

    <?php if ((int) $toolbar['rating'] > 0): ?>
        <input type="hidden" name="rating" value="<?= (int) $toolbar['rating'] ?>">
    <?php endif; ?>
    <?php if ($toolbar['edited']): ?>
        <input type="hidden" name="edited" value="1">
    <?php endif; ?>
    <?php if ($toolbar['mine']): ?>
        <input type="hidden" name="mine" value="1">
    <?php endif; ?>
</form>

<?php $filters = [
    'base'     => $toolbar['base'],
    'params'   => $chipParams,
    'rating'   => (int) $toolbar['rating'],
    'edited'   => (bool) $toolbar['edited'],
    'mine'     => (bool) $toolbar['mine'],
    'showMine' => (bool) $toolbar['showMine'],
]; ?>
<?php require root_path('app/Views/components/review-filters.php'); ?>
