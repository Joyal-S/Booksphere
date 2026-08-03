<?php

declare(strict_types=1);

namespace BookSphere\App\DTO;

/**
 * ReviewDTO
 *
 * The INPUT of the review workflows: an immutable value object that
 * transfers validated review data between the layers:
 *
 *     Controller -> Service -> Repository
 *
 *     - bookId  -> the book being reviewed (always present on store)
 *     - userId  -> the review's author (always present on store)
 *     - rating  -> 1-5, sanitized to an integer
 *     - title   -> the review headline (trimmed)
 *     - review  -> the review body (trimmed)
 *
 * Immutability is deliberate (same decision as RecommendationContext
 * and the other DTOs): the value travels through the layers without
 * ever being mutated mid-flight, and the service can safely use the
 * same instance to decide what changed on an update.
 *
 * The DTO is the TRANSPORT, not the guard: field rules live in
 * StoreReviewRequest / UpdateReviewRequest (ratings 1-5, title
 * <= 120, review 20-2000). fromArray() only performs the cheap,
 * structural sanitization (positive ids, integer ratings, trimmed
 * strings) so the service and repository always receive typed,
 * predictable values.
 */
final readonly class ReviewDTO
{
    public function __construct(
        public readonly ?int $bookId,
        public readonly ?int $userId,
        public readonly ?int $rating,
        public readonly ?string $title,
        public readonly ?string $review,
    ) {}

    /**
     * Build a DTO from raw (possibly untrusted) input.
     *
     * @param array<string, mixed> $raw     The submitted values
     * @param int|null             $userId  The logged-in user id,
     *                                      used as the user_id
     *                                      fallback (like the
     *                                      RecommendationContext
     *                                      sanitizer).
     */
    public static function fromArray(array $raw, ?int $userId = null): self
    {
        return new self(
            bookId: self::positiveId($raw['book_id'] ?? null),
            userId: self::positiveId($raw['user_id'] ?? null, $userId),
            rating: is_numeric($raw['rating'] ?? null) ? (int) $raw['rating'] : null,
            title:  isset($raw['title']) ? trim((string) $raw['title']) : null,
            review: isset($raw['review']) ? trim((string) $raw['review']) : null,
        );
    }

    /**
     * A positive integer id, with an optional fallback used when the
     * raw value is junk (e.g. the session user id).
     */
    private static function positiveId(mixed $value, ?int $fallback = null): ?int
    {
        if (is_numeric($value) && (int) $value > 0) {
            return (int) $value;
        }

        return $fallback !== null && $fallback > 0 ? $fallback : null;
    }
}
