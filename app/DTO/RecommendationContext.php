<?php

declare(strict_types=1);

namespace BookSphere\App\DTO;

/**
 * RecommendationContext
 *
 * The INPUT of every recommendation strategy: an immutable value
 * object that carries everything an algorithm may look at.
 *
 *     - userId     -> who the recommendations are for (personalized)
 *     - bookId     -> the anchor book of "more like this" requests
 *     - categoryId -> an explicit category the caller wants filled
 *     - authorId   -> an explicit author the caller wants filled
 *     - limit      -> how many suggestions to return (clamped)
 *
 * Immutability is deliberate: strategies receive the same context
 * without ever being able to mutate it, so a request is free of
 * side effects as it travels Controller -> Service -> Strategy.
 *
 * Request validation happens HERE, at the edge (the sanitizer is
 * the single place raw values turn into safe integers), which is
 * why the controller can build a context directly from route and
 * query parameters - nothing unsanitized ever reaches a strategy.
 */
final readonly class RecommendationContext
{
    /** The number of suggestions when the caller does not ask. */
    public const DEFAULT_LIMIT = 10;

    /** The hard upper bound - a caller can never request more. */
    public const MAX_LIMIT = 50;

    public function __construct(
        public readonly ?int $userId = null,
        public readonly ?int $bookId = null,
        public readonly ?int $categoryId = null,
        public readonly ?int $authorId = null,
        public readonly int $limit = self::DEFAULT_LIMIT,
    ) {}

    /**
     * Build a context from raw (possibly untrusted) input.
     *
     * Every value is sanitized here:
     *
     *     - ids must be positive integers, anything else becomes null
     *     - user_id falls back to the session id the caller passes
     *     - limit is clamped to [1, MAX_LIMIT] with DEFAULT_LIMIT
     *       when the input is not numeric
     *
     * @param array<string, mixed> $raw     Raw route/query values
     * @param int|null             $userId  The logged-in user id, or null
     */
    public static function fromArray(array $raw, ?int $userId = null): self
    {
        return new self(
            userId:     self::positiveId($raw['user_id'] ?? null, $userId),
            bookId:     self::positiveId($raw['book_id'] ?? null),
            categoryId: self::positiveId($raw['category_id'] ?? null),
            authorId:   self::positiveId($raw['author_id'] ?? null),
            limit:      self::limit($raw['limit'] ?? self::DEFAULT_LIMIT),
        );
    }

    /**
     * A positive integer id, or null.
     *
     * @param int|null $fallback Used when the raw value is junk
     *                           (e.g. the session user id).
     */
    private static function positiveId(mixed $value, ?int $fallback = null): ?int
    {
        if (is_numeric($value) && (int) $value > 0) {
            return (int) $value;
        }

        return $fallback !== null && $fallback > 0 ? $fallback : null;
    }

    /**
     * A limit clamped to the allowed range.
     *
     * Follows the codebase norm for continuous ranges (see
     * BookService: the page number is clamped the same way):
     * anything below 1 becomes 1, anything above MAX_LIMIT
     * becomes MAX_LIMIT, non-numeric input falls back to the
     * default.
     */
    private static function limit(mixed $value): int
    {
        if (!is_numeric($value)) {
            return self::DEFAULT_LIMIT;
        }

        return max(1, min((int) $value, self::MAX_LIMIT));
    }
}
