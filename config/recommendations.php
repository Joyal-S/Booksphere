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
 * The hybrid score (0-100) is a weighted sum of seven factors:
 *
 *     category     40  "you enjoy this category"
 *     author       25  "you follow this author"
 *     wishlist     10  "similar to books in your wishlist"
 *     rating       10  "reading history: similar to books you rated highly"
 *     review_score 10  "the book's community review quality" (Phase 7.6)
 *     trending      0  "gaining momentum right now" (reserved; the raw
 *                      score still breaks score ties)
 *     popularity    5  "a community favourite" (never dominates)
 *                 100
 *
 * Phase 7.6 re-balanced the weights to the brief's distribution
 * (category 40 / author 25 / wishlist 10 / reading history 10 /
 * review score 10 / popularity 5): the reading-history factor is the
 * existing "rating" similarity signal, the new review_score factor
 * lets a book's community review quality (its approved average
 * rating, normalized 0-1) earn up to 10 points, and trending was
 * reduced to 0 to make room - the trending SHELF and the trending
 * score-as-tiebreak both still work.
 *
 * "Popularity should never dominate personalization" is enforced by
 * design here: it is the smallest non-zero weight in the formula,
 * exactly as the brief's example prescribes.
 *
 * Phase 8.5 added the 'library' section: the weights of the Personal
 * Library signals (favourites, reading history, want-to-read
 * similarity), the section limits of every recommendation surface,
 * the recommendation_logs retention and the Hidden Gems filter.
 * Every one of them is read through RecommendationConfig - no Phase
 * 8.5 scoring value lives inside a service or a query.
 */

return [
    // The hybrid scoring weights. They must add up to 100.
    'hybrid_weights' => [
        'category'     => 40,
        'author'       => 25,
        'wishlist'     => 10,
        'rating'       => 10,
        'review_score' => 10,
        'community'    => 5,
        'trending'     => 0,
        'popularity'   => 0,
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

    // --- Phase 8.5: Personal Library signals -------------------------
    // The weights of the library-derived scoring (libraryScore in
    // RecommendationScoring). They must add up to 100:
    //
    //     favourite_category 35  "you favourite books in this category"
    //     favourite_author   25  "you favourite books by this author"
    //     reading_history    15  "similar to books you finished"
    //     want_to_read       10  "similar to books on your want-to-read shelf"
    //     rating             10  "the book's community review quality"
    //     popularity          5  "a community favourite" (never dominates)
    //                       100
    //
    // 'section_limits' cap how many books each surface renders per
    // shelf (dashboard / book page / library page / profile).
    // 'logs' keeps the recommendation_logs table bounded per user and
    // 'hidden_gems' defines the Hidden Gems filter (high rated, few
    // reviews). 'accuracy' is the window the profile's
    // "Recommendation Accuracy" figure measures the user's actions
    // against their recent logged recommendations; only actions
    // created at or after the recommendation count (strict
    // attribution - actions predating the served recommendation
    // never count).
    'library' => [
        'weights' => [
            'favourite_category' => 35,
            'favourite_author'   => 25,
            'reading_history'    => 15,
            'want_to_read'       => 10,
            'rating'             => 10,
            'popularity'         => 5,
        ],
        'section_limits' => [
            'dashboard' => 6,
            'book'      => 6,
            'library'   => 6,
            'profile'   => 5,
        ],
        'logs' => [
            'retention_per_user' => 200,
        ],
        'hidden_gems' => [
            'max_reviews' => 8,
            'min_rating'  => 4.0,
        ],
        'accuracy' => [
            'window_days' => 30,
        ],
        // The similarity bands of the book-detail sections: a book is
        // "similar by rating" when its average rating is within
        // rating_band of the anchor's; "similar by popularity" when
        // its ratings count is within popularity_factor x the anchor's
        // count. 'discovery_window_days' is the window the
        // "Recently Discovered" shelf measures community saves in.
        'similarity' => [
            'rating_band'            => 0.5,
            'popularity_factor'      => 0.5,
            'discovery_window_days'  => 30,
        ],
    ],

    // --- Write-endpoint security (Phase 6.5 + Phase 7.7 + Phase 8.1) -----
    // The write actions of the dashboard (wishlist toggle, refresh)
    // are login- and CSRF-protected already; these limits
    // additionally cap how often ONE session may call them within a
    // window, so a single user can never flood the endpoints. The
    // RateLimiter (app/Core/RateLimiter.php) enforces the buckets;
    // a request past the limit answers HTTP 429.
    //
    // Phase 7.7: the three review write endpoints joined the same
    // scheme - creating/editing reviews, the helpful-vote toggles
    // and the report modal. Reviews are rare (20/hour), votes are
    // rapid clicks (the wishlist budget), reports get the tightest
    // window because a spammer's first abuse target is a report
    // button.
    //
    // Phase 8.1: the personal library writes (add / update / delete)
    // share one budget - organizing a library is bursty (a user
    // importing their collection), so the window is the widest of
    // the write actions.
    //
    // Phase 9.2: the follow/unfollow writes share one budget - a
    // session may follow or unfollow at most 60 times per minute
    // (mass-following is exactly what a script would do, so the
    // limit matches the wishlist-toggling budget).
    'security' => [
        'rate_limit' => [
            'wishlist_toggle' => ['limit' => 60, 'window_seconds' => 60],
            'refresh'         => ['limit' => 30, 'window_seconds' => 60],
            'review_write'    => ['limit' => 20, 'window_seconds' => 3600],
            'review_vote'     => ['limit' => 60, 'window_seconds' => 60],
            'review_report'   => ['limit' => 10, 'window_seconds' => 3600],
            'library_write'   => ['limit' => 120, 'window_seconds' => 3600],
            'follow_write'    => ['limit' => 60, 'window_seconds' => 60],
        ],
    ],
];
