<?php

declare(strict_types=1);

/**
 * components/review-filters.php
 *
 * The reusable REVIEW FILTER chips (Phase 7.4): the star-rating
 * chips (All / 5★ / 4★ / 3★ / 2★ / 1★), the "Edited only" toggle,
 * the "My reviews only" toggle (community lists) and the Reset link.
 * Every chip is a GET link that preserves the rest of the toolbar
 * state (sort, search term, page size), so filters compose with the
 * search and the sorting without any JavaScript.
 *
 * Included from a view that sets the $filters array first:
 *
 *     $filters = [
 *         'base'     => '/reviews/search',      // the list endpoint
 *         'params'   => ['sort' => 'newest'],   // preserved params
 *         'rating'   => 0,                      // current chip (0=All)
 *         'edited'   => false,                  // Edited-only toggle
 *         'mine'     => false,                  // My-reviews toggle
 *         'showMine' => true,                   // render the chip?
 *     ];
 */

$filters = array_merge([
    'base'     => '/reviews/search',
    'params'   => [],
    'rating'   => 0,
    'edited'   => false,
    'mine'     => false,
    'showMine' => false,
], $filters ?? []);

$preserved = array_filter(
    (array) $filters['params'],
    static fn ($value): bool => $value !== null && $value !== '',
);

/**
 * Build a filter chip URL: the preserved params with the chip's own
 * value applied (null drops the key).
 */
$chipUrl = static function (array $changes) use ($filters, $preserved): string {
    $params = array_merge($preserved, $changes);
    $params = array_filter($params, static fn ($value): bool => $value !== null && $value !== '');

    return $filters['base'] . ($params === [] ? '' : '?' . http_build_query($params));
};

$chips = [
    ['label' => 'All', 'rating' => 0],
    ['label' => '5★', 'rating' => 5],
    ['label' => '4★', 'rating' => 4],
    ['label' => '3★', 'rating' => 3],
    ['label' => '2★', 'rating' => 2],
    ['label' => '1★', 'rating' => 1],
];
?>
<div class="review-filters" aria-label="Filter reviews">
    <?php foreach ($chips as $chip): ?>
        <?php $active = (int) $filters['rating'] === (int) $chip['rating']; ?>
        <a class="review-chip<?= $active ? ' is-active' : '' ?>" aria-current="<?= $active ? 'true' : 'false' ?>"
           href="<?= e($chipUrl(['rating' => (int) $chip['rating'] > 0 ? (string) $chip['rating'] : null])) ?>">
            <?= e($chip['label']) ?>
        </a>
    <?php endforeach; ?>

    <span class="review-filters-sep" aria-hidden="true"></span>

    <a class="review-chip review-chip--toggle<?= $filters['edited'] ? ' is-active' : '' ?>"
       href="<?= e($chipUrl(['edited' => $filters['edited'] ? null : '1'])) ?>">
        <i class="fa-solid fa-pen me-1" aria-hidden="true"></i>Edited only
    </a>

    <?php if ($filters['showMine']): ?>
        <a class="review-chip review-chip--toggle<?= $filters['mine'] ? ' is-active' : '' ?>"
           href="<?= e($chipUrl(['mine' => $filters['mine'] ? null : '1'])) ?>">
            <i class="fa-solid fa-user me-1" aria-hidden="true"></i>My reviews only
        </a>
    <?php endif; ?>

    <?php
    $filtered = (int) $filters['rating'] > 0 || $filters['edited'] || $filters['mine'];
    ?>
    <?php if ($filtered): ?>
        <a class="review-chip review-chip--reset" href="<?= e($filters['base']) ?>">
            <i class="fa-solid fa-rotate-left me-1" aria-hidden="true"></i>Reset filters
        </a>
    <?php endif; ?>
</div>
