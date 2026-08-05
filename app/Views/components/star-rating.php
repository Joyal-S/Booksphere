<?php

declare(strict_types=1);

/**
 * components/star-rating.php
 *
 * The REUSABLE STAR RATING component of the whole application
 * (Phase 7.3): five stars with half-star support in two modes:
 *
 *     DISPLAY MODE  - read-only stars + numeric value + optional
 *                     review count ("★★★★☆ 4.7 · Based on 325
 *                     reviews"). Replaces every hand-rolled star
 *                     markup (book cards, book detail, review
 *                     lists, recommendation cards, dashboard,
 *                     admin panel, profile).
 *
 *     INPUT MODE    - an interactive star selector for forms
 *                     (the review write/edit forms): hover
 *                     highlights, click to select, full keyboard
 *                     support (arrow keys, Home/End, Space/Enter)
 *                     and a live "You selected ★★★★☆ 4 Stars"
 *                     preview. Behaviour is powered by the
 *                     reusable public/assets/js/rating.js module
 *                     (no jQuery); the markup below is the
 *                     no-JavaScript fallback too - the hidden
 *                     input still submits, so a form works even
 *                     without the enhancement.
 *
 * Usage (a view sets $starRating first):
 *
 *     $starRating = [
 *         'rating'   => 4.6,        // float 0-5 (display) or the
 *                                   // selected value (input)
 *         'max'      => 5,          // optional: star count
 *         'readOnly' => true,       // optional: display (true) or
 *                                   // input (false) mode
 *         'size'     => 'md',       // optional: sm | md | lg
 *         'name'     => 'rating',   // input mode: the hidden input
 *                                   // name (default 'rating')
 *         'label'    => 'Your rating', // input mode: the
 *                                   // radiogroup label
 *         'count'    => 12,         // display mode: "(12 reviews)"
 *         'countLabel' => 'reviews',// optional: units for $count
 *         'tooltip'  => true,       // optional: value tooltip
 *         'compact'  => false,      // optional: dense list layout
 *                                   // (one star icon + value)
 *     ];
 *     <?php require root_path('app/Views/components/star-rating.php'); ?>
 *
 * Dependencies:
 *     - Font Awesome (fa-solid / fa-regular star icons)
 *     - rating.css (sizes, hover, animation, dark mode)
 *     - rating.js (input mode: hover, keyboard, preview)
 *
 * Accessibility (input mode):
 *     - the stars are a WAI-ARIA radio group (role="radiogroup"
 *       with role="radio" buttons) with a roving tabindex, so the
 *       whole group is one Tab stop and arrow keys move inside it
 *     - the live preview is an aria-live region, so screen
 *       readers announce every change
 *     - the label is announced through aria-labelledby
 */

$starRating = array_merge([
    'rating'     => 0.0,
    'max'        => 5,
    'readOnly'   => true,
    'size'       => 'md',
    'name'       => 'rating',
    'label'      => 'Your rating',
    'count'      => null,
    'countLabel' => 'reviews',
    'tooltip'    => true,
    'compact'    => false,
], $starRating ?? []);

$max        = max(1, (int) $starRating['max']);
$rating     = max(0.0, min((float) $max, (float) $starRating['rating']));
$size       = in_array($starRating['size'], ['sm', 'md', 'lg'], true) ? $starRating['size'] : 'md';
$isCompact  = (bool) $starRating['compact'];
$isReadOnly = (bool) $starRating['readOnly'];

if ($isReadOnly): ?>
<span class="star-rating star-rating-<?= e($size) ?><?= $isCompact ? ' star-rating-compact' : '' ?>"
      <?= $starRating['tooltip'] ? 'title="' . e(number_format($rating, 1)) . ' out of ' . $max . '"' : '' ?>>
    <span class="star-rating-visual" aria-hidden="true">
        <?php for ($i = 1; $i <= $max; $i++): ?>
            <?php if ($rating >= $i - 0.25): ?>
                <i class="fa-solid fa-star is-filled"></i>
            <?php elseif ($rating >= $i - 0.75): ?>
                <i class="fa-solid fa-star-half-stroke is-half"></i>
            <?php else: ?>
                <i class="fa-regular fa-star"></i>
            <?php endif; ?>
        <?php endfor; ?>
    </span>
    <span class="star-rating-value"><?= e(number_format($rating, 1)) ?></span>
    <?php if ($starRating['count'] !== null): ?>
        <span class="star-rating-count">
            <?= (int) $starRating['count'] === 1 ? 'Based on 1 review' : 'Based on ' . (int) $starRating['count'] . ' ' . e($starRating['countLabel']) ?>
        </span>
    <?php endif; ?>
    <span class="visually-hidden">Rated <?= e(number_format($rating, 1)) ?> out of <?= $max ?> stars</span>
</span>
<?php else: ?>
<?php
$selected = (int) $rating;
$tabStop  = $selected > 0 ? $selected : 1;
?>
<div class="star-input star-input-<?= e($size) ?>" data-star-input
     data-max="<?= $max ?>" data-value="<?= e(number_format($rating, 0)) ?>">
    <span class="star-input-label" id="star-input-<?= e((string) $starRating['name']) ?>-label">
        <?= e($starRating['label']) ?>
    </span>
    <div class="star-input-stars" role="radiogroup"
         aria-labelledby="star-input-<?= e((string) $starRating['name']) ?>-label">
        <?php for ($i = 1; $i <= $max; $i++): ?>
            <button type="button" class="star-input-star" role="radio"
                    data-star="<?= $i ?>"
                    aria-checked="<?= (int) $rating >= $i ? 'true' : 'false' ?>"
                    aria-label="<?= $i ?> out of <?= $max ?> stars"
                    tabindex="<?= $i === $tabStop ? '0' : '-1' ?>">
                <i class="fa-<?= (int) $rating >= $i ? 'solid' : 'regular' ?> fa-star" aria-hidden="true"></i>
            </button>
        <?php endfor; ?>
    </div>
    <p class="star-input-preview" aria-live="polite" data-star-preview>
        <?php if ($rating > 0): ?>
            You selected <?= str_repeat('★', (int) $rating) . str_repeat('☆', $max - (int) $rating) ?> <?= (int) $rating ?> Star<?= (int) $rating === 1 ? '' : 's' ?>
        <?php else: ?>
            Select a rating to continue
        <?php endif; ?>
    </p>
    <input type="hidden" name="<?= e($starRating['name']) ?>" value="<?= (int) $rating > 0 ? (int) $rating : '' ?>" data-star-value>
    <p class="star-input-error text-danger small mt-1 mb-0" data-star-error hidden></p>
</div>
<?php endif; ?>
