<?php

declare(strict_types=1);

namespace BookSphere\App\Strategies;

use BookSphere\App\DTO\RecommendationContext;
use BookSphere\App\DTO\RecommendationResult;
use BookSphere\App\Services\RecommendationScoring;

/**
 * PopularBooksStrategy
 *
 * Purpose:
 *     Recommend the books the community is most active on - the
 *     "what everyone is reading" shelf.
 *
 * Algorithm:
 *     For every published, non-deleted book the repository computes
 *     three signals in ONE SQL query (aggregated via indexed
 *     subqueries, no N+1):
 *
 *         review_count    (reviews table)
 *         wishlist_count  (wishlist table)
 *         average_rating  (books column)
 *
 *     The weighted formula (RecommendationScoring, weights as
 *     constants, bound as parameters):
 *
 *         popularity = (average_rating / 5) x 0.50
 *                    + review_count        x 0.20
 *                    + wishlist_count      x 0.30
 *
 *     Books sort by that score, descending.
 *
 * Advantages:
 *     - One query, no in-memory scoring, no N+1.
 *     - Weights are constants: tuning the formula is a one-line
 *       change in RecommendationScoring.
 *     - Transparent and explainable ("popular because of reviews
 *       and wishlist saves").
 *     - Degrades gracefully: with no wishlist data yet, the shelf
 *       is driven by ratings + reviews only.
 *
 * Limitations:
 *     - The count terms are used raw, so a book with many reviews
 *       can outscore a perfectly rated book - acceptable while the
 *       catalogue is small; normalization (or log scaling) is a
 *       Phase 6.3 improvement.
 *     - "Views" are not part of the formula: the schema has no
 *       views tracking column yet (see RecommendationScoring).
 *
 * When to use:
 *     The default, context-free shelf - cold-start users, the
 *     overview page, and as the anchor shelf of the merged
 *     getRecommendations() result.
 */
final class PopularBooksStrategy extends AbstractRecommendationStrategy
{
    public function key(): string
    {
        return 'popular';
    }

    public function label(): string
    {
        return 'Popular';
    }

    public function description(): string
    {
        return 'The most active books right now, scored by ratings, wishlist saves and reviews.';
    }

    public function icon(): string
    {
        return 'fa-fire';
    }

    /**
     * Whether the strategy can run with the given context.
     *
     * Input:  a RecommendationContext
     * Output: true (popularity needs no user, category or book)
     *
     * Business responsibility: the shelf is context-free - it is
     * the same for every visitor.
     */
    public function supports(RecommendationContext $context): bool
    {
        return true;
    }

    /**
     * Run the popularity algorithm.
     *
     * Input:  the context (only its limit is used)
     * Output: a RecommendationResult with the most popular books,
     *         each explained with "Popular - high review and
     *         wishlist activity"
     *
     * Business responsibility: delegate the aggregation to the
     * repository, explain the result, return the DTO - no SQL and
     * no scoring math lives in this class.
     */
    public function recommend(RecommendationContext $context): RecommendationResult
    {
        $items = $this->repository->popularBooks($context->limit);

        $weights = RecommendationScoring::POPULARITY_WEIGHTS;

        return $this->resultFor(
            'Scored as (average rating / 5) x ' . $weights['rating']
                . ' + reviews x ' . $weights['review']
                . ' + wishlist saves x ' . $weights['wishlist'] . ', highest first.',
            $items,
            'Popular - high review and wishlist activity',
        );
    }
}
