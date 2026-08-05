<?php

declare(strict_types=1);

namespace BookSphere\App\DTO;

/**
 * LibraryItemDTO
 *
 * The INPUT of the library workflows: an immutable value object that
 * transfers validated library data between the layers:
 *
 *     Controller -> Service -> Repository
 *
 *     - userId           -> the library owner (always present, from
 *                            the session, never from the user input)
 *     - bookId           -> the book being added
 *     - status           -> one of the five library shelves
 *     - isFavorite       -> whether the book is starred (independent
 *                            of the status)
 *     - progress         -> 0-100 reading progress
 *     - startedReadingAt -> when the user started the book (nullable)
 *     - finishedReadingAt-> when the user finished the book (nullable)
 *
 * Immutability is deliberate (the same decision as ReviewDTO and the
 * other DTOs): the value travels through the layers without ever
 * being mutated mid-flight.
 *
 * The DTO is the TRANSPORT, not the guard: field rules live in
 * StoreLibraryRequest / UpdateLibraryRequest (statuses, progress
 * bounds, the boolean favourite). fromArray() only performs the
 * cheap, structural sanitization (positive ids, integer progress,
 * boolean cast, trimmed status) so the service and repository always
 * receive typed, predictable values.
 */
final readonly class LibraryItemDTO
{
    public function __construct(
        public readonly ?int $userId,
        public readonly ?int $bookId,
        public readonly ?string $status,
        public readonly ?bool $isFavorite,
        public readonly ?int $progress,
        public readonly ?string $startedReadingAt,
        public readonly ?string $finishedReadingAt,
    ) {}

    /**
     * Build a DTO from raw (possibly untrusted) input.
     *
     * @param array<string, mixed> $raw     The submitted values
     * @param int|null             $userId  The logged-in user id, used
     *                                      as the user_id fallback
     *                                      (the same sanitization as
     *                                      ReviewDTO / RecommendationContext)
     */
    public static function fromArray(array $raw, ?int $userId = null): self
    {
        return new self(
            userId:           self::positiveId($raw['user_id'] ?? null, $userId),
            bookId:           self::positiveId($raw['book_id'] ?? null),
            status:           isset($raw['status']) ? trim((string) $raw['status']) : null,
            isFavorite:       self::boolean($raw['favorite'] ?? null),
            progress:         self::progress($raw['progress'] ?? null),
            startedReadingAt: isset($raw['started_reading_at']) ? trim((string) $raw['started_reading_at']) : null,
            finishedReadingAt: isset($raw['finished_reading_at']) ? trim((string) $raw['finished_reading_at']) : null,
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

    /**
     * The favourite flag: any of the accepted boolean spellings
     * ("1"/"0", "true"/"false") becomes a bool; junk stays null.
     */
    private static function boolean(mixed $value): ?bool
    {
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }

        if ($value === false || $value === 0 || $value === '0' || $value === '') {
            return false;
        }

        return $value === 'true' ? true : ($value === 'false' ? false : null);
    }

    /**
     * The progress percentage: a numeric value is clamped into a
     * nullable integer (the 0-100 range is the request's job, this
     * only casts); junk stays null.
     */
    private static function progress(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}