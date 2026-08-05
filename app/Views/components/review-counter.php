<?php

declare(strict_types=1);

/**
 * components/review-counter.php
 *
 * The REVIEW COUNTER (Phase 7.6): a small, text-only chip that
 * states how many reviews an entity has ("12 reviews", "No reviews
 * yet"). Used next to rating badges on author and category pages,
 * in directories and in statistics rows - it renders the count
 * without the star component, so a zero count stays friendly.
 *
 * Included from a view that sets $counter first:
 *
 *     $counter = [
 *         'count' => 12,          // the review count (int)
 *         'label' => 'reviews',   // optional: the unit word
 *     ];
 */

$counter = array_merge([
    'count' => 0,
    'label' => 'reviews',
], $counter ?? []);

$count = max(0, (int) $counter['count']);

?>
<span class="review-counter">
    <i class="fa-regular fa-comment" aria-hidden="true"></i>
    <?php if ($count === 0): ?>
        No reviews yet
    <?php else: ?>
        <?= $count ?> <?= e((string) $counter['label']) ?>
    <?php endif; ?>
</span>
