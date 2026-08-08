<?php

declare(strict_types=1);

/**
 * config/analytics.php
 *
 * The tunables of the USER ANALYTICS module (Phase 12.1) - the
 * per-user reading/activity statistics page. Everything an operator
 * may want to tune (top-list sizes, the activity window) lives here
 * so no limit is ever hard-coded in PHP:
 *
 *     - enabled            -> the module switch (with the page fallback)
 *     - limits.genres      -> how many top genres the statistics show
 *     - limits.authors     -> how many top authors they show
 *     - activity.months    -> how many trailing months the monthly
 *                             activity bars cover (older months are
 *                             still COUNTED - they collapse into the
 *                             "earlier" note, never fabricated)
 *     - activity.recent    -> how many recent events the activity
 *                             timeline lists
 *
 * The module reads ONLY existing data (user_library, reviews,
 * book_categories, book_authors, authors, categories) and never
 * writes; the cache seam lives in UserAnalyticsService (Phase 13).
 */

return [
    'enabled' => (bool) env('USER_ANALYTICS_ENABLED', true),

    'limits' => [
        'genres'  => (int) env('USER_ANALYTICS_TOP_GENRES', 5),
        'authors' => (int) env('USER_ANALYTICS_TOP_AUTHORS', 5),
    ],

    'activity' => [
        'months' => (int) env('USER_ANALYTICS_ACTIVITY_MONTHS', 12),
        'recent' => (int) env('USER_ANALYTICS_RECENT_EVENTS', 10),
    ],
];