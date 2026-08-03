<?php

declare(strict_types=1);

namespace BookSphere\App\Strategies;

use BookSphere\App\DTO\RecommendationResult;
use BookSphere\App\Repositories\RecommendationRepository;

/**
 * AbstractRecommendationStrategy
 *
 * The shared scaffold of every concrete strategy: the injected data
 * channel and the result-building helpers. Concrete strategies only
 * declare their metadata and their recommend() algorithm.
 *
 * Dependencies:
 *     - RecommendationRepository is injected by the factory wiring
 *       (routes/web.php). It is the ONLY way a strategy talks to
 *       the database - strategies never touch PDO themselves. All
 *       SQL, aggregation and prepared statements live in the
 *       repository; a strategy calls one repository method, decorates
 *       the rows with explainable reasons, and returns the DTO.
 *
 * Shared helpers:
 *     - resultFor()  -> build a RecommendationResult from items +
 *                       note (total derived, timestamped)
 *     - withReason() -> attach the same explanation text to every
 *                       item of a shelf (the "because ..." layer of
 *                       the explainable recommendations)
 */
abstract class AbstractRecommendationStrategy implements RecommendationStrategy
{
    public function __construct(
        protected readonly RecommendationRepository $repository,
    ) {}

    /**
     * Build the strategy's result from a shelf of books.
     *
     * Input:  the run note and the recommended book rows
     * Output: a complete RecommendationResult DTO
     *
     * Business responsibility: the single result factory of every
     * algorithm - total is derived from the items so it can never
     * disagree with the list.
     *
     * @param array<int, array<string, mixed>> $items
     */
    protected function resultFor(string $note, array $items, ?string $reason = null): RecommendationResult
    {
        return RecommendationResult::fromBooks(
            $this->key(),
            $this->label(),
            $reason !== null ? $this->withReason($reason, $items) : $items,
            $note,
        );
    }

    /**
     * Attach an explanation to every book of a shelf.
     *
     * Input:  the reason text and the book rows
     * Output: the same rows, each carrying a "reason" key
     *
     * Business responsibility: the explainability layer of the
     * module - every recommended book tells the user WHY it was
     * suggested ("Because it is popular", "Shares a category with
     * this book", ...). The reason is plain data added by the
     * strategy; it never touches the repository.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function withReason(string $reason, array $items): array
    {
        foreach ($items as &$item) {
            $item['reason'] = $reason;
        }

        return $items;
    }
}
