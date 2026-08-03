<?php

declare(strict_types=1);

/**
 * config/recommendations.php
 *
 * The tunables of the recommendation engine. EVERY weight, limit and
 * threshold of the Phase 6.3 hybrid personalization lives here - no
 * scoring value is hardcoded inside a service or a query - so a
 * future developer can retune the engine from this one file.
 *
 * Read with the config() helper: config('recommendations.hybrid_weights').
 *
 * The hybrid score (0-100) is a weighted sum of six factors:
 *
 *     category    40  "you enjoy this category"
 *     author      25  "you follow this author"
 *     wishlist    15  "similar to books in your wishlist"
 *     rating      10  "similar to books you rated highly"
 *     trending     5  "gaining momentum right now"
 *     popularity   5  "a community favourite" (never dominates)
 *                100
 *
 * "Popularity should never dominate personalization" is enforced by
 * design here: it is the smallest weight in the formula, exactly as
 * the brief's example prescribes.
 */

return [
    // The hybrid scoring weights. They must add up to 100.
    'hybrid_weights' => [
        'category'  => 40,
        'author'    => 25,
        'wishlist'  => 15,
        'rating'    => 10,
        'trending'  => 5,
        'popularity' => 5,
    ],

    // --- Profile building -------------------------------------------
    // How the per-user "favourite categories / authors" profile is
    // derived from the three signal sources. Every weight is relative:
    // a wishlist save counts more than a rating, which counts more
    // than a written review.
    'profile' => [
        'wishlist_weight'        => 3, // a book in the wishlist
        'high_rating_weight'     => 2, // a book rated >= min_favourite_rating
        'review_weight'          => 1, // a reviewed book (rating <= ignore_rating counts 0)
        'min_favourite_rating'   => 4, // ratings below this never build a favourite
        'ignore_rating'          => 2, // books rated this low are ignored entirely
        'favourite_categories'   => 5, // top-N categories kept in the profile
        'favourite_authors'      => 5, // top-N authors kept in the profile
    ],

    // --- Candidate pool ---------------------------------------------
    // The engine never scores the whole catalogue: candidates are the
    // books matching ANY personal factor plus a small "popularity
    // fallback" (so a brand-new user still gets a sensible shelf).
    // The pool is ordered by popularity only to bound its size; the
    // final shelf order is always the hybrid score.
    'candidates' => [
        'pool_limit' => 50, // max books the formula ever scores per user
        'signal_book_cap' => 20, // wishlist / rated / viewed ids per set
        'popularity_fallback' => 10, // top popularity ids always considered
    ],

    // --- Confidence thresholds --------------------------------------
    // Derived from the final score (0-100): high >= threshold_high AND
    // at least two distinct factors matched; medium >= threshold_medium;
    // otherwise low.
    'confidence' => [
        'high'   => 60,
        'medium' => 30,
    ],

    // --- Per-user result cache --------------------------------------
    // Results are cached per user for cache.ttl_seconds. The cache is
    // invalidated explicitly when wishlist / rating / review signals
    // change (RecommendationService::invalidatePersonalization()) and
    // flushed for everyone when the catalogue changes (a create /
    // update / soft delete in the Book module calls
    // RecommendationService::flushPersonalization()).
    'cache' => [
        'enabled'      => true,
        'ttl_seconds'  => 1800,      // 30 minutes
        'directory'    => root_path('database/cache/recommendations'),
    ],

    // --- Write-endpoint security (Phase 6.5) -------------------------
    // The two write actions of the dashboard (wishlist toggle,
    // refresh) are login- and CSRF-protected already; these limits
    // additionally cap how often ONE session may call them within a
    // window, so a single user can never flood the endpoints. The
    // RateLimiter (app/Core/RateLimiter.php) enforces the buckets;
    // a request past the limit answers HTTP 429.
    'security' => [
        'rate_limit' => [
            'wishlist_toggle' => ['limit' => 60, 'window_seconds' => 60],
            'refresh'         => ['limit' => 30, 'window_seconds' => 60],
        ],
    ],
];
