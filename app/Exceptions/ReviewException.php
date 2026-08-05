<?php

declare(strict_types=1);

namespace BookSphere\App\Exceptions;

use RuntimeException;

/**
 * ReviewException
 *
 * The single exception type of the Reviews & Ratings module (Phase
 * 7.1), mirroring RecommendationException of the engine module.
 *
 * The module fails loudly with meaningful messages:
 *
 *     - bookNotFound()        -> the book being reviewed does not
 *                                 exist (or is soft-deleted)
 *     - reviewNotFound()      -> the review being edited / deleted
 *                                 is missing
 *     - duplicateReview()     -> the user already reviewed the book
 *                                 (also enforced by the UNIQUE index)
 *     - permissionDenied()    -> the actor is neither the owner nor
 *                                 an admin (defence in depth behind
 *                                 the ReviewPolicy gate)
 *     - selfVote()            -> a review owner marked their own
 *                                 review as helpful (Phase 7.5)
 *     - alreadyReported()     -> the user filed a second report on
 *                                 the same review (Phase 7.5)
 *
 * How it is used:
 *     - The service throws these when a business rule fails.
 *     - The controller catches the exception and answers with the
 *       correct HTTP status (404 / 403) and a plain, safe message -
 *       never a SQL error.
 *     - PDO database errors are deliberately NOT wrapped: they
 *       bubble up to the application ErrorHandler, which logs and
 *       reports them once, in one place.
 *
 * Future extension:
 *     - Moderation (Phase 7.4+): a moderationException() factory
 *       can be added here when admin approve / reject flows arrive;
 *       the status enum column is already in place.
 */
final class ReviewException extends RuntimeException
{
    public static function bookNotFound(int $bookId): self
    {
        return new self("Book not found: {$bookId}.");
    }

    public static function reviewNotFound(int $reviewId): self
    {
        return new self("Review not found: {$reviewId}.");
    }

    public static function duplicateReview(int $userId, int $bookId): self
    {
        return new self("The user {$userId} already reviewed book {$bookId}.");
    }

    public static function permissionDenied(string $action): self
    {
        return new self("You are not allowed to {$action} this review.");
    }

    public static function selfVote(int $reviewId): self
    {
        return new self("You cannot mark your own review {$reviewId} as helpful.");
    }

    public static function selfReport(int $reviewId): self
    {
        return new self("You cannot report your own review {$reviewId}.");
    }

    public static function alreadyReported(int $userId, int $reviewId): self
    {
        return new self("The user {$userId} already reported review {$reviewId}.");
    }
}
