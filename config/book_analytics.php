<?php

declare(strict_types=1);

/**
 * Book Analytics (Phase 12.2) configuration.
 *
 * Every tunable of the catalog-analytics module lives here and every
 * value is overridable through the environment - a future developer
 * retunes the module in this ONE file and nothing else changes.
 *
 *     limits        - the top-N caps of every ranking list
 *     ratings       - minimum rating count a book needs to enter the
 *                     "highest rated" ranking (task: do not mislead
 *                     with single-review averages) - NEVER hardcoded
 *                     inside the service
 *     popularity    - the documented formula weights + normalizers
 *                     (see BookAnalyticsService for the formula)
 *     trending      - the window and weights of the recent-activity
 *                     score (also documented in the service)
 *     activity      - how many trailing calendar months the two
 *                     monthly charts (reviews written, books read)
 *                     cover
 *     page_ranges   - the page-count buckets of the distribution;
 *                     each {label, min, max} with a null bound being open
 */
return [
    'enabled' => (bool) env('BOOK_ANALYTICS_ENABLED', true),

    'limits' => [
        'highest_rated'   => (int) env('BOOK_ANALYTICS_TOP_HIGHEST', 10),
        'most_reviewed'   => (int) env('BOOK_ANALYTICS_TOP_REVIEWED', 10),
        'most_wishlisted' => (int) env('BOOK_ANALYTICS_TOP_WISHLISTED', 10),
        'most_read'       => (int) env('BOOK_ANALYTICS_TOP_READ', 10),
        'most_engaged'    => (int) env('BOOK_ANALYTICS_TOP_ENGAGED', 10),
        'popular'         => (int) env('BOOK_ANALYTICS_TOP_POPULAR', 10),
        'trending'        => (int) env('BOOK_ANALYTICS_TOP_TRENDING', 10),
        'genres'          => (int) env('BOOK_ANALYTICS_TOP_GENRES', 12),
        'authors'         => (int) env('BOOK_ANALYTICS_TOP_AUTHORS', 12),
        'publishers'      => (int) env('BOOK_ANALYTICS_TOP_PUBLISHERS', 10),
        'languages'       => (int) env('BOOK_ANALYTICS_TOP_LANGUAGES', 10),
        'years'           => (int) env('BOOK_ANALYTICS_TOP_YEARS', 12),
    ],

    'ratings' => [
        'minimum_count' => (int) env('BOOK_ANALYTICS_MINIMUM_RATING_COUNT', 5),
    ],

    'popularity' => [
        'rating_weight'      => 0.40,
        'review_weight'      => 0.30,
        'interest_weight'    => 0.30,
        'rating_divisor'     => 5.0,
        'review_normalizer'  => (int) env('BOOK_ANALYTICS_POPULARITY_REVIEWS', 10),
        'interest_normalizer'=> (int) env('BOOK_ANALYTICS_POPULARITY_INTEREST', 10),
    ],

    'trending' => [
        'window_days'         => (int) env('BOOK_ANALYTICS_TRENDING_WINDOW_DAYS', 30),
        'review_weight'       => 0.40,
        'interest_weight'     => 0.30,
        'reading_weight'      => 0.30,
        'review_normalizer'   => (int) env('BOOK_ANALYTICS_TRENDING_REVIEWS', 5),
        'interest_normalizer' => (int) env('BOOK_ANALYTICS_TRENDING_INTEREST', 5),
        'reading_normalizer'  => (int) env('BOOK_ANALYTICS_TRENDING_READS', 5),
    ],

    'activity' => [
        'months' => (int) env('BOOK_ANALYTICS_ACTIVITY_MONTHS', 12),
    ],

    'page_ranges' => [
        ['label' => 'Up to 100',   'min' => 0,   'max' => 100],
        ['label' => '101 - 200',   'min' => 101, 'max' => 200],
        ['label' => '201 - 300',   'min' => 201, 'max' => 300],
        ['label' => '301 - 400',   'min' => 301, 'max' => 400],
        ['label' => '401 - 500',   'min' => 401, 'max' => 500],
        ['label' => 'Over 500',    'min' => 501, 'max' => null],
    ],
];