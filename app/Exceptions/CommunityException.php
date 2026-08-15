<?php

declare(strict_types=1);

namespace BookSphere\App\Exceptions;

use RuntimeException;

/**
 * CommunityException
 *
 * The single exception type of the Community module (Phase C3-A).
 * Mirrors ReviewException, FollowException, LibraryException:
 * one class with named static factories so the service fails loudly
 * and the controller maps each to the correct HTTP status.
 *
 * Factories:
 *   postNotFound()     -> 404
 *   commentNotFound()  -> 404
 *   bookNotFound()     -> 404
 *   permissionDenied() -> 403 (owner/admin check in service)
 *   invalidInput()     -> 422 (validation failure)
 *   duplicateLike()    -> 409 (UNIQUE index race; used internally)
 *   invalidTarget()    -> 422 (report has no post/comment)
 *   invalidReason()    -> 422 (bad report reason enum)
 */
final class CommunityException extends RuntimeException
{
    public static function postNotFound(int $postId): self
    {
        return new self("Community post not found: {$postId}.");
    }

    public static function commentNotFound(int $commentId): self
    {
        return new self("Community comment not found: {$commentId}.");
    }

    public static function bookNotFound(int $bookId): self
    {
        return new self("Book not found: {$bookId}.");
    }

    public static function permissionDenied(string $action): self
    {
        return new self("You are not allowed to {$action} this community content.");
    }

    public static function invalidInput(string $field, string $reason): self
    {
        return new self("Invalid input for '{$field}': {$reason}.");
    }

    public static function duplicateLike(int $postId, int $userId): self
    {
        return new self("User {$userId} has already liked post {$postId}.");
    }

    public static function invalidTarget(): self
    {
        return new self("A community report must target a post or a comment.");
    }

    public static function invalidReason(string $reason): self
    {
        return new self("Invalid report reason: '{$reason}'.");
    }

    public static function alreadyReported(): self
    {
        return new self('You have already reported this content.');
    }

    public static function userNotFound(int $userId): self
    {
        return new self("User not found: {$userId}.");
    }
}
