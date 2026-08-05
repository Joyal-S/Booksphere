<?php

declare(strict_types=1);

namespace BookSphere\App\Exceptions;

use RuntimeException;

/**
 * RecommendationException
 *
 * The single exception type of the recommendations module.
 *
 * The pipeline fails loudly and early, and every failure has a
 * meaningful message:
 *
 *     - unknownStrategy()   -> the caller asked for a strategy that
 *                              does not exist (a wiring bug)
 *     - unsupportedContext()-> a strategy cannot run with the input
 *                              it received (e.g. a book-based
 *                              strategy without a book id)
 *     - bookNotFound()      -> the anchor book of a "more like
 *                              this" / category request is missing
 *                              (or soft-deleted)
 *     - categoryNotFound()  -> an explicit category id does not
 *                              exist (invalid request)
 *     - authorNotFound()    -> an explicit author id does not exist
 *     - missingCategories() -> an anchor book has no categories, so
 *                              there is nothing to recommend from
 *     - missingAuthors()    -> an anchor book has no authors, so
 *                              there is nothing to recommend from
 *
 * The controller catches the exception and turns it into a 404 with
 * the message as the body; nowhere in the module is it silently
 * swallowed. Database errors from PDO are deliberately NOT wrapped:
 * they bubble up to the application error handler, which logs and
 * reports them once, in one place.
 */
final class RecommendationException extends RuntimeException
{
    public static function unknownStrategy(string $key): self
    {
        return new self("Unknown recommendation strategy: '{$key}'.");
    }

    public static function unsupportedContext(string $key): self
    {
        return new self("The '{$key}' strategy cannot run with the given context.");
    }

    public static function bookNotFound(int $bookId): self
    {
        return new self("Book not found: {$bookId}.");
    }

    public static function categoryNotFound(int $categoryId): self
    {
        return new self("Category not found: {$categoryId}.");
    }

    public static function authorNotFound(int $authorId): self
    {
        return new self("Author not found: {$authorId}.");
    }

    public static function missingCategories(int $bookId): self
    {
        return new self("The book {$bookId} has no categories to recommend from.");
    }

    public static function missingAuthors(int $bookId): self
    {
        return new self("The book {$bookId} has no authors to recommend from.");
    }

    public static function unknownLibrarySection(string $section): self
    {
        return new self("Unknown library recommendation section: '{$section}'.");
    }
}
