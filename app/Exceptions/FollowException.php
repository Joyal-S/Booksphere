<?php

declare(strict_types=1);

namespace BookSphere\App\Exceptions;

use RuntimeException;

/**
 * FollowException
 *
 * The single exception type of the Follow Authors module (Phase 9.2),
 * mirroring LibraryException and ReviewException of the other
 * modules. The module fails loudly with meaningful, user-friendly
 * messages:
 *
 *     - authorNotFound()   -> the author being followed does not
 *                              exist (deleted author; the CASCADE
 *                              already cleaned the follow rows)
 *     - cannotFollowSelf() -> the actor is the author themselves
 *                              (defence in depth - "you cannot follow
 *                              yourself" is a service rule because a
 *                              CHECK across two tables is impossible)
 *     - duplicateFollow()  -> the follow row already exists (service
 *                              guard or the UNIQUE (user_id,
 *                              author_id) index race - the last line
 *                              of defence)
 *     - permissionDenied() -> a policy-bypass attempt (defence in
 *                              depth behind the FollowPolicy gate)
 *
 * How it is used:
 *     - The service throws these when a business rule fails.
 *     - The controller catches the exception and answers with the
 *       correct HTTP status (404 / 400 / 409 / 403) and a plain,
 *       safe message - never a SQL error.
 *     - PDO database errors are deliberately NOT wrapped: they
 *       bubble up to the application ErrorHandler, which logs and
 *       reports them once, in one place (the documented project rule).
 */
final class FollowException extends RuntimeException
{
    public static function authorNotFound(int $authorId): self
    {
        return new self("Author not found: {$authorId}.");
    }

    public static function cannotFollowSelf(int $userId): self
    {
        return new self("User {$userId} cannot follow themselves.");
    }

    public static function duplicateFollow(int $userId, int $authorId): self
    {
        return new self("You already follow this author (user {$userId}, author {$authorId}).");
    }

    public static function permissionDenied(string $action): self
    {
        return new self("You are not allowed to {$action} this author.");
    }
}
