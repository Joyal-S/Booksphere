<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

/**
 * RecommendationConfig
 *
 * The single accessor for the tunables of the Phase 8.5 Personal
 * Library integration. EVERY weight, limit and threshold of the
 * library-derived scoring lives in config/recommendations.php under
 * the 'library' key - never hardcoded inside a service or a query -
 * and every consumer asks THIS class for them, so a future developer
 * can retune the whole feature from the config file and one accessor
 * without touching the engine.
 *
 * What it exposes:
 *
 *     - libraryWeights()       the six weighted factors of the
 *                              library score (sum 100: favourites,
 *                              reading history, want-to-read,
 *                              rating, popularity)
 *     - sectionLimit()         how many books a surface renders per
 *                              shelf (dashboard / book / library /
 *                              profile)
 *     - logRetention()         how many recommendation_logs rows are
 *                              kept per user (the prune-on-write cap)
 *     - hiddenGems()           the Hidden Gems filter: a book needs
 *                              at most max_reviews reviews AND at
 *                              least min_rating to be a gem
 *     - accuracyWindowDays()   the window the profile's
 *                              "Recommendation Accuracy" figure
 *                              measures user actions against logged
 *                              recommendations
 *
 * Every accessor falls back to a documented default when the config
 * file is missing a key (the same convention RecommendationScoring
 * uses for the hybrid weights), so a stripped config never crashes
 * the engine - it just uses the defaults.
 *
 * Why not constants: the Phase 6.3 hybrid weights stay constants
 * inside RecommendationScoring because they predate this class; the
 * Phase 8.5 values are deliberately read here so the config file
 * remains the one source of truth for the new feature.
 */
final class RecommendationConfig
{
    /** The default library weights (the documented Phase 8.5 distribution). */
    public const LIBRARY_WEIGHTS_DEFAULT = [
        'favourite_category' => 35,
        'favourite_author'   => 25,
        'reading_history'    => 15,
        'want_to_read'       => 10,
        'rating'             => 10,
        'popularity'         => 5,
    ];

    /** The default per-surface shelf size. */
    public const SECTION_LIMIT_DEFAULT = 6;

    /** How many recommendation_logs rows are kept per user by default. */
    public const LOG_RETENTION_DEFAULT = 200;

    /** The default Hidden Gems filter. */
    public const HIDDEN_GEMS_DEFAULT = [
        'max_reviews' => 8,
        'min_rating'  => 4.0,
    ];

    /** The default accuracy window (days). */
    public const ACCURACY_WINDOW_DEFAULT = 30;

    /**
     * The weighted factors of the library score, keyed by factor.
     *
     * @return array<string, float>
     */
    public static function libraryWeights(): array
    {
        $configured = (array) config('recommendations.library.weights', []);

        return array_replace(
            self::LIBRARY_WEIGHTS_DEFAULT,
            array_filter($configured, 'is_numeric'),
        );
    }

    /**
     * How many books one recommendation shelf of a surface may render.
     *
     * Input:  the surface key ('dashboard' | 'book' | 'library' |
     *         'profile')
     * Output: the configured limit, bounded 1-50 (a tampered config
     *         can never ask the engine for an unbounded shelf)
     */
    public static function sectionLimit(string $surface): int
    {
        $configured = (array) config('recommendations.library.section_limits', []);
        $limit      = (int) ($configured[$surface] ?? self::SECTION_LIMIT_DEFAULT);

        return max(1, min(50, $limit));
    }

    /**
     * How many recommendation_logs rows are kept per user.
     */
    public static function logRetention(): int
    {
        $configured = (array) config('recommendations.library.logs', []);
        $keep       = (int) ($configured['retention_per_user'] ?? self::LOG_RETENTION_DEFAULT);

        return max(1, min(10000, $keep));
    }

    /**
     * The Hidden Gems filter: a book is a gem when its approved
     * review count is at most max_reviews AND its average rating is
     * at least min_rating.
     *
     * @return array{max_reviews: int, min_rating: float}
     */
    public static function hiddenGems(): array
    {
        $configured = (array) config('recommendations.library.hidden_gems', []);
        $defaults   = self::HIDDEN_GEMS_DEFAULT;

        return [
            'max_reviews' => max(1, (int) ($configured['max_reviews'] ?? $defaults['max_reviews'])),
            'min_rating'  => max(0.0, min(5.0, (float) ($configured['min_rating'] ?? $defaults['min_rating']))),
        ];
    }

    /**
     * The window (days) the profile's Recommendation Accuracy figure
     * measures: a logged recommendation counts as "acted upon" when
     * the user saved / rated / reviewed the book within this window.
     */
    public static function accuracyWindowDays(): int
    {
        $configured = (array) config('recommendations.library.accuracy', []);
        $days       = (int) ($configured['window_days'] ?? self::ACCURACY_WINDOW_DEFAULT);

        return max(1, min(365, $days));
    }

    /**
     * The similarity settings of the book-detail sections: the rating
     * band, the popularity factor (a fraction of the anchor's count)
     * and the discovery window of the "Recently Discovered" shelf.
     *
     * @return array{rating_band: float, popularity_factor: float, discovery_window_days: int}
     */
    public static function similarity(): array
    {
        $configured = (array) config('recommendations.library.similarity', []);

        return [
            'rating_band'           => max(0.0, min(2.5, (float) ($configured['rating_band'] ?? 0.5))),
            'popularity_factor'     => max(0.0, min(2.0, (float) ($configured['popularity_factor'] ?? 0.5))),
            'discovery_window_days' => max(1, min(365, (int) ($configured['discovery_window_days'] ?? 30))),
        ];
    }
}
