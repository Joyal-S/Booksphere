<?php

declare(strict_types=1);

namespace BookSphere\App\Strategies;

use BookSphere\App\DTO\RecommendationContext;
use BookSphere\App\DTO\RecommendationResult;
use BookSphere\App\Services\RecommendationScoring;

/**
 * TrendingBooksStrategy
 *
 * Purpose:
 *     Recommend the books that are BECOMING popular - momentum
 *     instead of all-time popularity.
 *
 * Algorithm:
 *     One SQL query over the two time-stamped signal tables,
 *     restricted to the last RecommendationScoring::TRENDING_WINDOW_DAYS
 *     days (30):
 *
 *         recent_review_count   (reviews.created_at >= cutoff)
 *         recent_wishlist_count (wishlist.created_at >= cutoff)
 *
 *     The weighted formula (RecommendationScoring, weights as
 *     constants, bound as parameters; cutoff also bound):
 *
 *         trending = recent_reviews  x 0.50
 *                  + recent_wishlists x 0.50
 *
 *     Books with zero recent activity are excluded (a book with no
 *     momentum is not "trending"), the rest sort by the score,
 *     descending.
 *
 * Advantages:
 *     - Measures CHANGE, not volume: a book that was quiet for a
 *       year and exploded this week ranks above a steady old
 *       favourite.
 *     - The 30-day window is a constant; the cutoff timestamp is a
 *       bound parameter, so the window can be tuned in one place.
 *     - Aggregated in SQL with the existing indexes - no N+1.
 *
 * Limitations:
 *     - "Views" are not in the formula yet: the schema has no views
 *       tracking column, so recent REVIEWS and WISHLIST SAVES are
 *       the only momentum signals (see RecommendationScoring).
 *     - On a quiet community the shelf can be empty or small -
 *       honest, but a fallback is a Phase 6.3 improvement.
 *
 * When to use:
 *     The "Trending" page (/recommendations/trending) and the
 *     momentum component of the merged default shelf.
 */
final class TrendingBooksStrategy extends AbstractRecommendationStrategy
{
    public function key(): string
    {
        return 'trending';
    }

    public function label(): string
    {
        return 'Trending';
    }

    public function description(): string
    {
        return 'Becoming popular recently - the most reviews and wishlist saves in the last '
            . RecommendationScoring::TRENDING_WINDOW_DAYS . ' days.';
    }

    public function icon(): string
    {
        return 'fa-chart-line';
    }

    /**
     * Whether the strategy can run with the given context.
     *
     * Input:  a RecommendationContext
     * Output: true (trending needs no user, category or book)
     *
     * Business responsibility: the shelf is context-free.
     */
    public function supports(RecommendationContext $context): bool
    {
        return true;
    }

    /**
     * Run the trending algorithm.
     *
     * Input:  the context (only its limit is used)
     * Output: a RecommendationResult with the books gaining
     *         momentum, each explained with "Trending - recent
     *         reviews and wishlist saves"
     *
     * Business responsibility: delegate the windowed aggregation to
     * the repository, explain the result, return the DTO.
     */
    public function recommend(RecommendationContext $context): RecommendationResult
    {
        $items = $this->repository->trendingBooks($context->limit);

        return $this->resultFor(
            'Trending = reviews x 0.50 + wishlist saves x 0.50 in the last '
                . RecommendationScoring::TRENDING_WINDOW_DAYS . ' days (views tracking arrives in a later phase).',
            $items,
            'Trending - recent reviews and wishlist saves',
        );
    }
}
