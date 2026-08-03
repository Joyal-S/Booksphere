<?php

declare(strict_types=1);

namespace BookSphere\App\Strategies;

use BookSphere\App\DTO\RecommendationContext;
use BookSphere\App\DTO\RecommendationResult;

/**
 * RecommendationStrategy
 *
 * The contract every recommendation algorithm must fulfil.
 *
 * The module is built on the STRATEGY pattern: each recommendation
 * algorithm is its own class, interchangeable at runtime through
 * RecommendationFactory. The service never knows which concrete
 * strategy it is running - it only talks to this interface.
 *
 * Responsibilities of a strategy:
 *
 *     - key()         -> the unique identifier routes and the
 *                        factory use ("popular", "category", ...)
 *     - label()       -> the human name shown on pages
 *     - description() -> the one-line explanation shown on the
 *                        overview cards
 *     - icon()        -> the Font Awesome icon of the card
 *     - supports()    -> whether the strategy can run with the
 *                        given context (e.g. a book-based strategy
 *                        needs a book id)
 *     - recommend()   -> run the algorithm and return the result
 *
 * The interface is deliberately small: a new strategy (e.g. a
 * "Bestsellers" or a hybrid one) is added by writing one class and
 * registering it in the factory - nothing else changes.
 */
interface RecommendationStrategy
{
    public function key(): string;

    public function label(): string;

    public function description(): string;

    public function icon(): string;

    public function supports(RecommendationContext $context): bool;

    public function recommend(RecommendationContext $context): RecommendationResult;
}
