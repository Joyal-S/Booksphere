<?php

declare(strict_types=1);

namespace BookSphere\App\Strategies;

use BookSphere\App\DTO\RecommendationContext;
use BookSphere\App\DTO\RecommendationResult;

/**
 * RecentlyAddedStrategy
 *
 * Purpose:
 *     Recommend the newest arrivals - the "fresh from the
 *     catalogue" discovery shelf.
 *
 * Algorithm:
 *     One query ordered by created_at DESC (newest first), id DESC
 *     as a deterministic tie-breaker. The limit is the caller's
 *     configuration (the context's limit, clamped 1-50).
 *
 * Advantages:
 *     - The simplest, most predictable shelf: no scoring, no
 *       thresholds, nothing to tune.
 *     - Perfect for discovery - new books get visibility while
 *       they still have no reviews.
 *
 * Limitations:
 *     - Purely recency-based: no quality signal at all, so the
 *       shelf can contain low-rated arrivals.
 *     - Never rotates unless new books are added.
 *
 * When to use:
 *     The "Recently Added" page and as the fresh-content component
 *     of the merged default shelf.
 */
final class RecentlyAddedStrategy extends AbstractRecommendationStrategy
{
    public function key(): string
    {
        return 'recent';
    }

    public function label(): string
    {
        return 'Recently Added';
    }

    public function description(): string
    {
        return 'Fresh arrivals, newest first - a discovery shelf for the whole catalogue.';
    }

    public function icon(): string
    {
        return 'fa-clock';
    }

    /**
     * Whether the strategy can run with the given context.
     *
     * Input:  a RecommendationContext
     * Output: true (recency needs no user, category or book)
     *
     * Business responsibility: the shelf is context-free.
     */
    public function supports(RecommendationContext $context): bool
    {
        return true;
    }

    /**
     * Run the recency algorithm.
     *
     * Input:  the context (only its limit is used)
     * Output: a RecommendationResult with the newest books, each
     *         explained with "Recently added to the catalogue"
     *
     * Business responsibility: delegate the read to the repository,
     * explain the result, return the DTO.
     */
    public function recommend(RecommendationContext $context): RecommendationResult
    {
        $items = $this->repository->recentlyAddedBooks($context->limit);

        return $this->resultFor(
            'Newest arrivals first, up to ' . $context->limit . ' books.',
            $items,
            'Recently added to the catalogue',
        );
    }
}
