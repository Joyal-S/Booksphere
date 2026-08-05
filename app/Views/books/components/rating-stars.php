<?php

declare(strict_types=1);

/**
 * books/components/rating-stars.php
 *
 * The ADAPTER of the reusable StarRatingComponent (Phase 7.3),
 * kept for the callers that already know the $ratingInfo contract:
 * the book detail page, the book table row, the review lists and
 * the book reviews page. It maps the legacy options onto the
 * component's props and delegates - one markup, one component,
 * every page can never drift.
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
 * The half-star rounding and the accessibility label now live in
 * the component itself (components/star-rating.php).
 */

$ratingInfo = array_merge([
    'rating'  => 0.0,
    'count'   => null,
    'compact' => false,
], $ratingInfo ?? []);

$starRating = [
    'rating'   => (float) $ratingInfo['rating'],
    'count'    => $ratingInfo['count'] === null ? null : (int) $ratingInfo['count'],
    'compact'  => (bool) $ratingInfo['compact'],
    'readOnly' => true,
];

require root_path('app/Views/components/star-rating.php');
