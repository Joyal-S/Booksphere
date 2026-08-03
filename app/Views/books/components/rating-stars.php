<?php

declare(strict_types=1);

/**
 * books/components/rating-stars.php
 *
 * The reusable RATING STARS display: five stars that visualise
 * an average rating (1-5 scale) with half-star support, followed
 * by the numeric value and optional vote count.
 *
 * Why it exists:
 *     - The rating appears in the list table, on the detail page
 *       and on book cards; one component keeps the markup
 *       identical and accessible everywhere.
 *     - Star fills are computed once here (full / half / empty)
 *       so views never repeat rounding logic.
 *
 * Usage (a view sets $ratingInfo first):
 *
 *     $ratingInfo = [
 *         'rating' => 4.6,       // 0-5 average
 *         'count'  => 12,        // optional: number of votes
 *         'compact' => true,     // optional: smaller, list-friendly
 *     ];
 *     <?php require root_path('app/Views/books/components/rating-stars.php'); ?>
 *
 * Accessibility: the visual stars are aria-hidden; the actual
 * value is announced through a visually-hidden text label.
 */

$ratingInfo = array_merge([
    'rating'  => 0.0,
    'count'   => null,
    'compact' => false,
], $ratingInfo ?? []);

$rating = max(0.0, min(5.0, (float) $ratingInfo['rating']));

?>
<span class="rating-stars<?= $ratingInfo['compact'] ? ' rating-stars-compact' : '' ?>"
      title="<?= e(number_format($rating, 1)) ?> out of 5">
    <span class="rating-stars-visual" aria-hidden="true">
        <?php for ($i = 1; $i <= 5; $i++): ?>
            <?php if ($rating >= $i - 0.25): ?>
                <i class="fa-solid fa-star is-filled"></i>
            <?php elseif ($rating >= $i - 0.75): ?>
                <i class="fa-solid fa-star-half-stroke is-half"></i>
            <?php else: ?>
                <i class="fa-regular fa-star"></i>
            <?php endif; ?>
        <?php endfor; ?>
    </span>
    <span class="rating-stars-value"><?= e(number_format($rating, 1)) ?></span>
    <?php if ($ratingInfo['count'] !== null): ?>
        <span class="rating-stars-count">(<?= (int) $ratingInfo['count'] ?> ratings)</span>
    <?php endif; ?>
    <span class="visually-hidden">Rated <?= e(number_format($rating, 1)) ?> out of 5 stars</span>
</span>
