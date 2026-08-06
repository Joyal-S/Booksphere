<?php

declare(strict_types=1);

namespace BookSphere\App\DTO;

/**
 * FollowDTO
 *
 * The INPUT of the follow workflows: an immutable value object that
 * transfers the follow data between the layers:
 *
 *     Controller -> Service -> Repository
 *
 *     - userId    -> the follower (always present, from the session,
 *                    never from the user input)
 *     - authorId  -> the author being followed
 *
 * Immutability is deliberate (the same decision as ReviewDTO and
 * LibraryItemDTO): the value travels through the layers without
 * ever being mutated mid-flight.
 *
 * The DTO is the TRANSPORT, not the guard: the field rules live in
 * FollowRequest (author_id required + whole number), the business
 * rules in FollowService (author exists, no self-follow, no
 * duplicate). fromArray() only performs the cheap, structural
 * sanitization (positive ids) so the service and repository always
 * receive typed, predictable values.
 */
final readonly class FollowDTO
{
    public function __construct(
        public readonly ?int $userId,
        public readonly ?int $authorId,
    ) {}

    /**
     * Build a DTO from raw (possibly untrusted) input.
     *
     * The ACTOR's id never comes from the submitted payload (Phase
     * 9.6 hardening: a crafted "user_id" field could otherwise make
     * user A follow or unfollow on behalf of user B): it is always
     * the session value the caller hands in. Only the author id is
     * read from the form.
     *
     * @param array<string, mixed> $raw    The submitted values
     * @param int|null             $userId The logged-in user id - the
     *                                     ONLY accepted actor id
     */
    public static function fromArray(array $raw, ?int $userId = null): self
    {
        return new self(
            userId:   $userId !== null && $userId > 0 ? $userId : null,
            authorId: self::positiveId($raw['author_id'] ?? null),
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
