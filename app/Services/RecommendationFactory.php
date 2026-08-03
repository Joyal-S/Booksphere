<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\Exceptions\RecommendationException;
use BookSphere\App\Strategies\RecommendationStrategy;

/**
 * RecommendationFactory
 *
 * The registry of recommendation strategies. It receives every
 * strategy through the constructor (the wiring lives in
 * routes/web.php), indexes them by their key, and hands them out
 * on demand.
 *
 * Why the factory exists:
 *     - The service and the controller only ever ask by KEY
 *       ("popular", "category", ...) - neither knows the concrete
 *       class it is running.
 *     - Adding a strategy (e.g. a "Bestsellers" or a hybrid one)
 *       means writing the class and registering it here; nothing
 *       else in the pipeline changes (Open/Closed principle).
 *     - An unknown key fails loudly with a RecommendationException
 *       instead of silently returning nothing.
 *
 * The constructor is variadic so the wiring reads naturally:
 *
 *     new RecommendationFactory(
 *         new PopularStrategy($repository),
 *         new RatingStrategy($repository),
 *         ...
 *     );
 */
final class RecommendationFactory
{
    /**
     * Strategies indexed by their key, registration order kept.
     *
     * @var array<string, RecommendationStrategy>
     */
    private readonly array $strategies;

    public function __construct(RecommendationStrategy ...$strategies)
    {
        $map = [];

        foreach ($strategies as $strategy) {
            $map[$strategy->key()] = $strategy;
        }

        $this->strategies = $map;
    }

    /**
     * The strategy behind a key, or a loud failure.
     */
    public function make(string $key): RecommendationStrategy
    {
        if (!isset($this->strategies[$key])) {
            throw RecommendationException::unknownStrategy($key);
        }

        return $this->strategies[$key];
    }

    /**
     * Every registered strategy, in registration order.
     *
     * @return array<int, RecommendationStrategy>
     */
    public function all(): array
    {
        return array_values($this->strategies);
    }
}
