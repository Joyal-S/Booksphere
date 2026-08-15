<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

/**
 * RecommendationScoring
 *
 * The single home of every scoring decision in the recommendations
 * module. Weights, thresholds and windows are CONSTANTS here - never
 * hardcoded inside a strategy or a query - so a future developer can
 * tune the behaviour of all algorithms in one place.
 *
 * Two responsibilities:
 *
 *     1. PARAMETERS for SQL: each *Sql()/*Params() pair returns the
 *        weighted expression text and the weight values to bind.
 *        The values are always bound as prepared-statement
 *        parameters - never interpolated into the SQL string - so
 *        the weights stay tamper-proof while remaining constants.
 *
 *     2. MIRRORS in PHP: each *Score() method computes the same
 *        formula on plain numbers. The tests use these mirrors to
 *        prove the SQL ordering matches the documented formula, and
 *        Phase 6.3 can reuse them for in-memory scoring.
 *
 * The popularity formula (the blueprint's transparent, deterministic
 * signal):
 *
 *     popularity = (average_rating / 5) x 0.50
 *                + review_count        x 0.20
 *                + wishlist_count      x 0.30
 *
 * The rating term is normalized to 0-1 so a perfect rating scores
 * 0.50 at most; the count terms are used raw (with the seeded data
 * the counts are small and comparable). See the Limitations section
 * of PopularBooksStrategy for the trade-off.
 */
final class RecommendationScoring
{
    /**
     * Popularity weights: rating / wishlist saves / review count.
     *
     * The blueprint example (Rating x 0.5, Wishlist x 0.3, Review
     * count x 0.2). The sum of the three weights is 1.0, so the
     * score is a bounded, comparable figure.
     */
    public const POPULARITY_WEIGHTS = [
        'rating'  => 0.50,
        'wishlist' => 0.30,
        'review'  => 0.20,
    ];

    /**
     * Trending weights: recent reviews / recent wishlist saves.
     *
     * Equal 0.5/0.5 because both signals express "people are paying
     * attention right now". Views are NOT part of the formula yet:
     * the books table has no views tracking (the "Views (if
     * available)" clause of the phase brief) - when a views column
     * arrives, it becomes a third weight here and nothing else
     * changes.
     */
    public const TRENDING_WEIGHTS = [
        'review'   => 0.50,
        'wishlist' => 0.50,
    ];

    /** The window (in days) a signal must fall into to count as "recent". */
    public const TRENDING_WINDOW_DAYS = 30;

    /** A book needs at least this many reviews to be "top rated". */
    public const MIN_REVIEWS_FOR_RATING = 5;

    /** The maximum possible average rating (normalization divisor). */
    public const RATING_MAX = 5.0;

    /**
     * The weighted popularity expression, for SQL ORDER BY.
     *
     * The expression references the columns the repository names in
     * its inner query: average_rating, review_count, wishlist_count
     * (bare names - the expression is embedded in the OUTER query,
     * whose alias is the inner query's "t"). The "?" placeholders
     * are filled by popularityParams() and stay bound parameters -
     * the weights never reach the SQL text.
     */
    public static function popularitySql(): string
    {
        return '((average_rating / ?) * ? + review_count * ? + wishlist_count * ?)';
    }

    /**
     * The parameter values for popularitySql(), in placeholder order:
     * rating divisor, rating weight, review weight, wishlist weight.
     *
     * @return array<int, float>
     */
    public static function popularityParams(): array
    {
        return [
            self::RATING_MAX,
            self::POPULARITY_WEIGHTS['rating'],
            self::POPULARITY_WEIGHTS['review'],
            self::POPULARITY_WEIGHTS['wishlist'],
        ];
    }

    /**
     * The weighted trending expression, for SQL ORDER BY.
     *
     * References recent_review_count and recent_wishlist_count (the
     * columns the repository names in its inner query).
     */
    public static function trendingSql(): string
    {
        return '(recent_review_count * ? + recent_wishlist_count * ?)';
    }

    /**
     * The parameter values for trendingSql(): review weight, wishlist
     * weight.
     *
     * @return array<int, float>
     */
    public static function trendingParams(): array
    {
        return [
            self::TRENDING_WEIGHTS['review'],
            self::TRENDING_WEIGHTS['wishlist'],
        ];
    }

    /**
     * The popularity formula as pure PHP (mirror of popularitySql).
     *
     * Input:  the three raw signals of one book
     * Output: the weighted score (higher = more popular)
     *
     * Business responsibility: lets the tests assert the SQL ordering
     * against the documented formula, and lets later phases score
     * in-memory without touching the database.
     */
    public static function popularityScore(float $averageRating, int $reviewCount, int $wishlistCount): float
    {
        return ($averageRating / self::RATING_MAX) * self::POPULARITY_WEIGHTS['rating']
            + $reviewCount * self::POPULARITY_WEIGHTS['review']
            + $wishlistCount * self::POPULARITY_WEIGHTS['wishlist'];
    }

    /**
     * The trending formula as pure PHP (mirror of trendingSql).
     *
     * Input:  recent review and wishlist activity of one book
     * Output: the weighted trend score (higher = more trending)
     */
    public static function trendingScore(int $recentReviewCount, int $recentWishlistCount): float
    {
        return $recentReviewCount * self::TRENDING_WEIGHTS['review']
            + $recentWishlistCount * self::TRENDING_WEIGHTS['wishlist'];
    }

    // -----------------------------------------------------------------
    // Phase 6.3: the hybrid personalization formula
    // -----------------------------------------------------------------

    /**
     * The default hybrid weights (used when config/recommendations.php
     * is missing a key). Phase 7.6 distribution - the brief's
     * example (category 40 / author 25 / wishlist 10 / reading
     * history 10 / review score 10 / popularity 5) with the
     * reserved trending slot:
     *
     *     category 40 / author 25 / wishlist 10 / rating 10 /
     *     review_score 10 / trending 0 / popularity 5  = 100
     */
    public const HYBRID_WEIGHTS_DEFAULT = [
        'category'     => 40,
        'author'       => 25,
        'wishlist'     => 10,
        'rating'       => 10,
        'review_score' => 10,
        'community'    => 5,
        'trending'     => 0,
        'popularity'   => 0,
    ];

    /** How many shared favourite categories earn the full category weight. */
    public const CATEGORY_FACTOR_CAP = 2;

    /** How many shared favourite authors earn the full author weight.
     *  1 = binary: an author either wrote the book or not, so a
     *  single-author book matched through its author gets the full
     *  weight (the same rule the brief's "Author Match 25 Points"
     *  example implies). */
    public const AUTHOR_FACTOR_CAP = 1;

    /** How many shared wishlist categories earn the full wishlist weight. */
    public const WISHLIST_FACTOR_CAP = 3;

    /** How many shared rating categories earn the full rating weight. */
    public const RATING_FACTOR_CAP = 3;

    /**
     * The review-score factor cap. The signal is the book's approved
     * average rating normalized to 0-1 (average / RATING_MAX), so a
     * cap of 1 means the full weight is earned at a perfect 5.0.
     */
    public const REVIEW_SCORE_FACTOR_CAP = 1.0;

    /** How many Community signal points earn the full community weight. */
    public const COMMUNITY_FACTOR_CAP = 5.0;

    /** The popularity score that earns the full (small) popularity weight. */
    public const POPULARITY_NORMALIZER = 3.0;

    /** The trending score that maps to 100 in the 0-100 normalization. */
    public const TRENDING_MAX_RAW = 5.0;

    /**
     * The hybrid weights, from config/recommendations.php.
     *
     * Input:  nothing (reads the application configuration)
     * Output: the six weights as an array, keyed by factor
     *
     * Business responsibility: the weights are CONFIGURATION, never
     * hardcoded - a future developer retunes the engine in
     * config/recommendations.php and nothing else changes. Missing
     * keys fall back to the documented defaults.
     *
     * @return array<string, float>
     */
    public static function hybridWeights(): array
    {
        $configured = (array) config('recommendations.hybrid_weights', []);

        return array_replace(
            self::HYBRID_WEIGHTS_DEFAULT,
            array_filter($configured, 'is_numeric'),
        );
    }

    /**
     * The hybrid score of one candidate book, 0-100.
     *
     * Input:  the factor signals of one book
     *
     *     [
     *         'category'     => int  shared favourite categories (0+)
     *         'author'       => int  shared favourite authors (0+)
     *         'wishlist'     => int  categories shared with wishlist books
     *         'rating'       => int  categories shared with highly rated books
     *         'review_score' => float  the book's community review quality,
     *                                  approved average rating / 5 (0-1);
     *                                  0 when the book has no reviews
     *         'community'    => float  the user's community interactions on the book (0-5)
     *         'trending'     => float  the book's trending score (0+)
     *         'popularity'   => float  the book's popularity score (0+)
     *     ]
     *
     * Output: the weighted score, 0-100 (weight cap inclusive)
     *
     * Formula (weights from config/recommendations.php):
     *
     *     category     = w.category      x min(shared favourite categories, 2) / 2
     *     author       = w.author        x min(shared favourite authors, 1)   (binary)
     *     wishlist     = w.wishlist      x min(shared wishlist categories, 3) / 3
     *     rating       = w.rating        x min(shared rating categories, 3) / 3
     *     review_score = w.review_score  x min(review quality 0-1, 1)  (Phase 7.6)
     *     community    = w.community     x min(community signal 0-5, 5) / 5 (Phase C6-E)
     *     trending     = w.trending      x 1   (when the book is trending)
     *     popularity   = w.popularity    x min(popularity / 3, 1)  (small bonus)
     *
     * @param array<string, int|float> $signals
     */
    public static function hybridScore(array $signals): float
    {
        $weights = self::hybridWeights();

        $category = $weights['category']
            * min((int) ($signals['category'] ?? 0), self::CATEGORY_FACTOR_CAP)
            / self::CATEGORY_FACTOR_CAP;

        $author = $weights['author']
            * min((int) ($signals['author'] ?? 0), self::AUTHOR_FACTOR_CAP)
            / self::AUTHOR_FACTOR_CAP;

        $wishlist = $weights['wishlist']
            * min((int) ($signals['wishlist'] ?? 0), self::WISHLIST_FACTOR_CAP)
            / self::WISHLIST_FACTOR_CAP;

        $rating = $weights['rating']
            * min((int) ($signals['rating'] ?? 0), self::RATING_FACTOR_CAP)
            / self::RATING_FACTOR_CAP;

        $reviewScore = $weights['review_score']
            * min((float) ($signals['review_score'] ?? 0), self::REVIEW_SCORE_FACTOR_CAP)
            / self::REVIEW_SCORE_FACTOR_CAP;

        $community = ($weights['community'] ?? 0)
            * min(max(0.0, (float) ($signals['community'] ?? 0)), self::COMMUNITY_FACTOR_CAP)
            / self::COMMUNITY_FACTOR_CAP;

        $trending = ($weights['trending'] ?? 0) * ((float) ($signals['trending'] ?? 0) > 0 ? 1.0 : 0.0);

        $popularity = ($weights['popularity'] ?? 0)
            * min((float) ($signals['popularity'] ?? 0) / self::POPULARITY_NORMALIZER, 1.0);

        return $category + $author + $wishlist + $rating + $reviewScore + $community + $trending + $popularity;
    }

    // -----------------------------------------------------------------
    // Phase 8.5: the Personal Library score
    // -----------------------------------------------------------------

    /**
     * The factor caps of the library score (how many shared links earn
     * the full weight): two shared favourite categories, one shared
     * favourite author (binary), three reading-history categories and
     * three want-to-read categories - the same partial-credit shape as
     * the Phase 6.3 hybrid caps.
     */
    public const LIBRARY_FACTOR_CAPS = [
        'favourite_category' => 2,
        'favourite_author'   => 1,
        'reading_history'    => 3,
        'want_to_read'       => 3,
    ];

    /** The popularity score that earns the full (small) popularity weight. */
    public const LIBRARY_POPULARITY_NORMALIZER = 3.0;

    /**
     * The library weights, from RecommendationConfig
     * (config/recommendations.php -> 'library' -> 'weights').
     *
     * @return array<string, float>
     */
    public static function libraryWeights(): array
    {
        return RecommendationConfig::libraryWeights();
    }

    /**
     * The weighted library score of one candidate book, 0-100.
     *
     * Input:  the factor signals of one book
     *
     *     [
     *         'favourite_category' => int  categories shared with the
     *                                      user's favourite books
     *         'favourite_author'   => int  authors shared with the
     *                                      user's favourite books
     *         'reading_history'    => int  categories shared with the
     *                                      user's finished books
     *         'want_to_read'       => int  categories shared with the
     *                                      user's want-to-read books
     *         'rating'             => float  the book's community review
     *                                      quality (approved average / 5)
     *         'popularity'         => float  the book's popularity score
     *     ]
     *
     * Output: the weighted score, 0-100 (weight cap inclusive)
     *
     * Formula (weights from RecommendationConfig):
     *
     *     favourite_category = w x min(shared favourite categories, 2) / 2
     *     favourite_author   = w x min(shared favourite authors, 1)  (binary)
     *     reading_history    = w x min(shared finished categories, 3) / 3
     *     want_to_read       = w x min(shared want-to-read categories, 3) / 3
     *     rating             = w x min(review quality 0-1, 1)
     *     popularity         = w x min(popularity / 3, 1)  (small bonus)
     *
     * Same shape as hybridScore(): partial credit up to a cap, a
     * binary author match, and the popularity bonus capped by its own
     * small weight so it can never dominate the library signal.
     *
     * @param array<string, int|float> $signals
     */
    public static function libraryScore(array $signals): float
    {
        $weights = self::libraryWeights();
        $caps    = self::LIBRARY_FACTOR_CAPS;

        $favouriteCategory = $weights['favourite_category']
            * min((int) ($signals['favourite_category'] ?? 0), $caps['favourite_category'])
            / $caps['favourite_category'];

        $favouriteAuthor = $weights['favourite_author']
            * min((int) ($signals['favourite_author'] ?? 0), $caps['favourite_author'])
            / $caps['favourite_author'];

        $readingHistory = $weights['reading_history']
            * min((int) ($signals['reading_history'] ?? 0), $caps['reading_history'])
            / $caps['reading_history'];

        $wantToRead = $weights['want_to_read']
            * min((int) ($signals['want_to_read'] ?? 0), $caps['want_to_read'])
            / $caps['want_to_read'];

        $rating = $weights['rating']
            * min(max(0.0, (float) ($signals['rating'] ?? 0)), 1.0);

        $popularity = $weights['popularity']
            * min(max(0.0, (float) ($signals['popularity'] ?? 0)) / self::LIBRARY_POPULARITY_NORMALIZER, 1.0);

        return $favouriteCategory + $favouriteAuthor + $readingHistory + $wantToRead + $rating + $popularity;
    }

    /**
     * The rating-quality signal of a book on the 0-1 scale (the same
     * normalization reviewScoreSignal uses): the approved average
     * rating divided by RATING_MAX, 0 when the book has no reviews.
     */
    public static function ratingQuality(float $averageRating, int $reviewCount): float
    {
        if ($reviewCount <= 0 || $averageRating <= 0) {
            return 0.0;
        }

        return min($averageRating / self::RATING_MAX, 1.0);
    }

    /**
     * The raw co-save count that maps to 100 on the 0-100 scale.
     *
     * A collaborative shelf (co-saved / shared-with-neighbours) ranks
     * books by how many users saved them together; this normalizer
     * says "10 co-saves = a perfect score". The value is a documented
     * constant of the scoring home, not a number inside a service.
     */
    public const CO_SAVED_NORMALIZER = 10.0;

    /**
     * The collaborative score of a book on the shared 0-100 scale.
     *
     * Input:  how many users saved the book alongside the anchor (or
     *         alongside any of the user's library books)
     * Output: an integer percent, 0-100 (saved_count / 10 capped)
     *
     * Business responsibility: same monotonic normalization idea as
     * popularityPercent() - the reported value lives on the one
     * 0-100 scale of the engine, while the shelves rank by the raw
     * count.
     */
    public static function collaborativeScore(int $savedCount): int
    {
        return self::toPercent((float) max(0, $savedCount), self::CO_SAVED_NORMALIZER);
    }

    // -----------------------------------------------------------------
    // Phase 6.5: the 0-100 normalization (one home for every score)
    // -----------------------------------------------------------------

    /**
     * The popularity score on the shared 0-100 scale.
     *
     * Input:  the raw popularity score of a book (the SQL value of
     *         RecommendationScoring::popularitySql(), or the PHP
     *         mirror popularityScore())
     * Output: an integer percent, 0-100
     *
     * Why normalize: the raw popularity formula mixes a normalized
     * rating term with RAW counts (review_count x 0.20 + wishlist_count
     * x 0.30), so its value is unbounded - "92" for one book means
     * nothing compared to "92" for another. The 0-100 normalization
     * divides the raw score by POPULARITY_NORMALIZER (the same value
     * the hybrid formula uses for its own popularity bonus) and caps
     * the result, so every score the application shows lives on the
     * same 0-100 scale as the hybrid score - the ONE reusable scale
     * of the whole engine.
     *
     * The SQL ORDER BY keeps using the RAW score: normalization is
     * monotonic, so the ranking order never changes - only the
     * reported value does.
     */
    public static function popularityPercent(float $rawScore): int
    {
        return self::toPercent($rawScore, self::POPULARITY_NORMALIZER);
    }

    /**
     * The trending score on the shared 0-100 scale.
     *
     * Input:  the raw trending score (trendingSql() / trendingScore())
     * Output: an integer percent, 0-100
     *
     * Why normalize: the trending formula is a raw sum of recent
     * review and wishlist counts, so it is as unbounded as popularity.
     * Dividing by TRENDING_MAX_RAW (documented with the formula) and
     * capping at 100 puts it on the same scale as the hybrid score.
     */
    public static function trendingPercent(float $rawScore): int
    {
        return self::toPercent($rawScore, self::TRENDING_MAX_RAW);
    }

    /**
     * Map a raw score to 0-100: divide by the divisor and cap at 100.
     *
     * @param float $divisor The raw value that represents "perfect"
     */
    private static function toPercent(float $rawScore, float $divisor): int
    {
        if ($divisor <= 0) {
            return 0;
        }

        return max(0, min(100, (int) round($rawScore / $divisor * 100)));
    }
}
