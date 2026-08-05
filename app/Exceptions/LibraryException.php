<?php

declare(strict_types=1);

namespace BookSphere\App\Exceptions;

use RuntimeException;

/**
 * LibraryException
 *
 * The single exception type of the Wishlist & Personal Reading
 * Library module (Phase 8.1), mirroring ReviewException and
 * RecommendationException of the other modules.
 *
 * The module fails loudly with meaningful, user-friendly messages:
 *
 *     - bookNotFound()      -> the book being added does not exist
 *                               (or is soft-deleted)
 *     - duplicateEntry()    -> the book is already in the user's
 *                               library (also enforced by the UNIQUE
 *                               (user_id, book_id) index)
 *     - recordNotFound()    -> the library record being updated /
 *                               removed is missing
 *     - permissionDenied()  -> the actor is not the owner of the
 *                               library record (defence in depth
 *                               behind the LibraryPolicy gate)
 *     - invalidStatus()     -> a status outside the five-shelf
 *                               enum was submitted
 *     - invalidProgress()   -> a progress value outside 0-100 was
 *                               submitted
 *
 * How it is used:
 *     - The service throws these when a business rule fails.
 *     - The controller catches the exception and answers with the
 *       correct HTTP status (404 / 403 / 409) and a plain, safe
 *       message - never a SQL error.
 *     - PDO database errors are deliberately NOT wrapped: they
 *       bubble up to the application ErrorHandler, which logs and
 *       reports them once, in one place.
 *
 * Future extension:
 *     - Reading goals / challenges (a later phase) can add their
 *       own factories here (e.g. goalNotFound()) without touching
 *       the library ones.
 */
final class LibraryException extends RuntimeException
{
    public static function bookNotFound(int $bookId): self
    {
        return new self("Book not found: {$bookId}.");
    }

    public static function duplicateEntry(int $userId, int $bookId): self
    {
        return new self("Book {$bookId} is already in your library.");
    }

    public static function recordNotFound(int $userId, int $bookId): self
    {
        return new self("No library entry found for book {$bookId}.");
    }

    public static function permissionDenied(string $action): self
    {
        return new self("You are not allowed to {$action} this library entry.");
    }

    public static function invalidStatus(string $status): self
    {
        return new self("Invalid library status: {$status}.");
    }

    public static function invalidProgress(int $progress): self
    {
        return new self("Progress must be a number between 0 and 100, {$progress} given.");
    }
}