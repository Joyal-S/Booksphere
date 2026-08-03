<?php

declare(strict_types=1);

namespace BookSphere\App\Strategies;

use BookSphere\App\DTO\RecommendationContext;
use BookSphere\App\DTO\RecommendationResult;
use BookSphere\App\Services\RecommendationScoring;

/**
 * HighestRatedStrategy
 *
 * Purpose:
 *     Recommend the books with the best community ratings - the
 *     "quality first" shelf.
 *
 * Algorithm:
 *     One SQL query over the reviews table (aggregated, no N+1):
 *
 *         review_count = COUNT(reviews of the book)
 *         average_rating = AVG(rating of the book)
 *
 *     Confidence threshold: a book must have at least
 *     RecommendationScoring::MIN_REVIEWS_FOR_RATING reviews (5) -
 *     a single 5-star review is not enough to call a book "top
 *     rated". Books sort by average rating DESC, then review count
 *     DESC (a tie-breaker that prefers the more trusted book).
 *
 * Advantages:
 *     - The minimum-review threshold kills the "one lucky rating"
 *       problem in one WHERE clause.
 *     - Aggregate sorting in SQL: the top of the list is exactly
 *       what the formula promises, no PHP re-sorting.
 *     - The threshold is a constant in RecommendationScoring.
 *
 * Limitations:
 *     - With a small community, many good books simply have fewer
 *       than 5 reviews and never appear - a documented trade-off
 *       of the confidence rule.
 *     - Average alone ignores rating distribution; a stricter
 *       statistical confidence (e.g. Wilson score) is a Phase 6.3
 *       improvement.
 *
 * When to use:
 *     The "Top Rated" page and the quality component of the merged
 *     default shelf.
 */
final class HighestRatedStrategy extends AbstractRecommendationStrategy
{
    public function key(): string
    {
        return 'rating';
    }

    public function label(): string
    {
        return 'Top Rated';
    }

    public function description(): string
    {
        return 'The best-reviewed books, ranked by their average rating - at least ' . RecommendationScoring::MIN_REVIEWS_FOR_RATING . ' reviews each.';
    }

    public function icon(): string
    {
        return 'fa-star';
    }

    /**
     * Whether the strategy can run with the given context.
     *
     * Input:  a RecommendationContext
     * Output: true (ratings need no user, category or book)
     *
     * Business responsibility: the shelf is context-free.
     */
    public function supports(RecommendationContext $context): bool
    {
        return true;
    }

    /**
     * Run the highest-rated algorithm.
     *
     * Input:  the context (only its limit is used)
     * Output: a RecommendationResult with the best-rated books
     *         (minimum review count enforced by the repository),
     *         each explained with "Top rated - ..."
     *
     * Business responsibility: delegate the aggregation and the
     * confidence filter to the repository, explain the result,
     * return the DTO.
     */
    public function recommend(RecommendationContext $context): RecommendationResult
    {
        $items = $this->repository->highestRatedBooks($context->limit);

        return $this->resultFor(
            'Books with at least ' . RecommendationScoring::MIN_REVIEWS_FOR_RATING
                . ' reviews, best average first (review count breaks ties).',
            $items,
            'Top rated - among the best-reviewed books',
        );
    }
}
