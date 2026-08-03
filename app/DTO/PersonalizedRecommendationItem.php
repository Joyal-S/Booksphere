<?php

declare(strict_types=1);

namespace BookSphere\App\DTO;

/**
 * PersonalizedRecommendationItem
 *
 * One book on the personalized shelf (Phase 6.3), carrying the full
 * EXPLANATION of why it was recommended:
 *
 *     - book       -> the book row (the same shape the Phase 6.2
 *                     shelves use, so the book card component can
 *                     render it unchanged)
 *     - score      -> the hybrid score, 0-100, from the weighted
 *                     formula of config/recommendations.php
 *     - reason     -> the human-readable explanation shown to the
 *                     user ("You enjoy Fantasy and Science Fiction
 *                     books.") - composed by the engine, never
 *                     hardcoded in a view
 *     - confidence -> 'high' | 'medium' | 'low', derived from the
 *                     score and the number of matched factors
 *     - matched    -> the factor keys that actually fired
 *                     (['category', 'author', ...]), the machine-
 *                     readable form of the explanation
 *
 * The explanation travels as DATA: the view only prints it, it can
 * never invent it.
 */
final readonly class PersonalizedRecommendationItem
{
    /**
     * @param array<string, mixed> $book
     * @param array<int, string>   $matched
     */
    public function __construct(
        public readonly array $book,
        public readonly float $score,
        public readonly string $reason,
        public readonly string $confidence,
        public readonly array $matched,
    ) {}

    /**
     * Convert the item into the plain book row the shelves render,
     * with the explanation attached as data keys (same convention as
     * the Phase 6.2 "reason" key).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->book,
            'score'      => round($this->score, 1),
            'reason'     => $this->reason,
            'confidence' => $this->confidence,
            'matched'    => $this->matched,
        ];
    }
}
