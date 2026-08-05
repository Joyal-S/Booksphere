<?php

declare(strict_types=1);

/**
 * components/review-pagination.php
 *
 * The reusable REVIEW PAGINATION (Phase 7.4): the result line
 * ("Showing 1-10 of 47 reviews"), the per-page select (10 / 20 / 50)
 * and the pager links (previous / page numbers / next). Every link
 * preserves the toolbar state (sort, search term, filters) - only
 * the page number changes - and the per-page select auto-submits
 * through reviews.js (with the loading skeletons, no reload flash).
 *
 * Included from a view that sets the $pagination array first:
 *
 *     $pagination = [
 *         'base'     => '/reviews/search',   // the list endpoint
 *         'params'   => ['sort' => 'newest'],// preserved params
 *         'page'     => 1,                   // current page
 *         'pages'    => 5,                   // total pages
 *         'total'    => 47,                  // total results
 *         'perPage'  => 10,                  // current page size
 *         'perPages' => [10, 20, 50],        // allowed page sizes
 *     ];
 */

$pagination = array_merge([
    'base'     => '/reviews',
    'params'   => [],
    'page'     => 1,
    'pages'    => 1,
    'total'    => 0,
    'perPage'  => 10,
    'perPages' => [10, 20, 50],
], $pagination ?? []);

$page     = max(1, (int) $pagination['page']);
$pages    = max(1, (int) $pagination['pages']);
$total    = (int) $pagination['total'];
$perPage  = (int) $pagination['perPage'];
$from     = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
$to       = min($total, $page * $perPage);
$preserved = array_filter(
    (array) $pagination['params'],
    static fn ($value): bool => $value !== null && $value !== '',
);

/** The URL of a page link: preserved params + the new page number. */
$pageUrl = static function (int $target) use ($pagination, $preserved): string {
    $params = $preserved;

    if ($target > 1) {
        $params['page'] = $target;
    }

    return $pagination['base'] . ($params === [] ? '' : '?' . http_build_query($params));
};

// The pager window: up to five page numbers around the current page.
$first = max(1, $page - 2);
$last  = min($pages, $first + 4);
$first = max(1, $last - 4);

$numbers = range($first, $last);
?>
<?php if ($total > 0): ?>
    <div class="review-pagination">
        <p class="review-pagination-result">
            Showing <?= $from ?>&ndash;<?= $to ?> of <?= $total ?> review<?= $total === 1 ? '' : 's' ?>
        </p>

        <?php if (count($pagination['perPages']) > 1): ?>
            <form class="review-pagination-per-page" method="get" action="<?= e($pagination['base']) ?>" data-review-toolbar>
                <?php foreach ($preserved as $name => $value): ?>
                    <input type="hidden" name="<?= e((string) $name) ?>" value="<?= e((string) $value) ?>">
                <?php endforeach; ?>
                <label for="review-pager-per-page">Per page
                    <select class="form-select form-select-sm" id="review-pager-per-page" name="per_page" data-review-select>
                        <?php foreach ($pagination['perPages'] as $option): ?>
                            <option value="<?= (int) $option ?>"<?= (int) $option === $perPage ? ' selected' : '' ?>>
                                <?= (int) $option ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>
        <?php endif; ?>

        <?php if ($pages > 1): ?>
            <nav class="review-pagination-pager" aria-label="Review pages">
                <?php if ($page > 1): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e($pageUrl($page - 1)) ?>">
                        <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                        <span class="visually-hidden">Previous page</span>
                    </a>
                <?php endif; ?>

                <?php foreach ($numbers as $number): ?>
                    <?php if ($number === $page): ?>
                        <span class="btn btn-sm btn-primary review-pager-current" aria-current="page"><?= $number ?></span>
                    <?php else: ?>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e($pageUrl($number)) ?>"><?= $number ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>

                <?php if ($page < $pages): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e($pageUrl($page + 1)) ?>">
                        <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                        <span class="visually-hidden">Next page</span>
                    </a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </div>
<?php endif; ?>
