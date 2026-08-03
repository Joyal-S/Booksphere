<?php

declare(strict_types=1);

namespace BookSphere\App\DTO;

/**
 * RecommendationResult
 *
 * The OUTPUT of every recommendation strategy: an immutable value
 * object the pipeline returns to the controller, whatever the
 * strategy did internally.
 *
 *     - strategyKey   -> which strategy (or combination) produced
 *                        this result
 *     - strategyLabel -> the human-readable name shown in the UI
 *     - items         -> the recommended book rows. Each row is the
 *                        repository's book array plus a "reason"
 *                        key: the explainable, human-readable
 *                        "why this book was suggested" text every
 *                        shelf card shows ("Because it is popular",
 *                        "Shares a category with this book", ...)
 *     - total         -> how many books were returned
 *     - note          -> a strategy-level explanation of the run
 *                        (formula, thresholds, data sources)
 *     - generatedAt   -> when the result was produced (UTC)
 *
 * Immutability is deliberate: the result travels back through the
 * service unchanged, so the controller can trust what it renders.
 *
 * Phase 6.2: strategies build real results through fromBooks().
 * placeholder() is retained only for future phases that need a
 * "no algorithm ran yet" shape (e.g. the Phase 6.3 personalization
 * landing page).
 */
final readonly class RecommendationResult
{
    public function __construct(
        public readonly string $strategyKey,
        public readonly string $strategyLabel,
        public readonly array $items,
        public readonly int $total,
        public readonly string $note,
        public readonly string $generatedAt,
    ) {}

    /**
     * Build a result from the books a strategy actually recommends.
     *
     * Input:  strategy identity, the recommended book rows (each
     *         optionally carrying its own "reason" already) and the
     *         run note
     * Output: a complete, timestamped RecommendationResult
     *
     * Business responsibility: the shared factory of every Phase 6.2
     * algorithm - total is derived from the items so it can never
     * disagree with the list.
     */
    public static function fromBooks(string $strategyKey, string $strategyLabel, array $items, string $note): self
    {
        return new self(
            strategyKey:   $strategyKey,
            strategyLabel: $strategyLabel,
            items:         $items,
            total:         count($items),
            note:          $note,
            generatedAt:   gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    /**
     * An empty result that still carries the pipeline's metadata.
     *
     * @param string $strategyKey   The producing strategy's key
     * @param string $strategyLabel The producing strategy's label
     * @param string $note          A transparent status message
     */
    public static function placeholder(string $strategyKey, string $strategyLabel, string $note): self
    {
        return new self(
            strategyKey:   $strategyKey,
            strategyLabel: $strategyLabel,
            items:         [],
            total:         0,
            note:          $note,
            generatedAt:   gmdate('Y-m-d\TH:i:s\Z'),
        );
    }
}
