<?php

declare(strict_types=1);

namespace BookSphere\App\Strategies;

use BookSphere\App\DTO\RecommendationContext;
use BookSphere\App\DTO\RecommendationResult;
use BookSphere\App\Exceptions\RecommendationException;

/**
 * SameAuthorStrategy
 *
 * Purpose:
 *     Recommend books by the same authors - the "more like this"
 *     shelf of a book's detail page.
 *
 * Algorithm:
 *     Two input paths, both resolving to the repository:
 *
 *         1. Explicit author (authorId in the context):
 *            booksByAuthor() - one EXISTS filter.
 *
 *         2. Anchor book (bookId in the context):
 *            resolve the book's authors through the repository
 *            (delegates to BookRepository), then booksInAuthors()
 *            with ALL of them - TRUE multi-author support (a book
 *            by Rowling + Galbraith draws from both) - and the
 *            anchor book itself excluded.
 *
 *     Every read excludes deleted and draft books (repository rule)
 *     and the anchor book where one exists.
 *
 * Advantages:
 *     - Multi-author support is the point: the anchor path uses
 *       every author of the book, in one query.
 *     - No user profile needed - works for a brand-new user.
 *     - Loud, meaningful failures: missing anchor book -> "Book not
 *       found"; anchor without authors -> "no authors to recommend
 *       from".
 *
 * Limitations:
 *     - Shelf is ordered by title, not quality or relevance.
 *     - A prolific author can flood the shelf; a "most similar"
 *       ranking is a Phase 6.3 improvement.
 *
 * When to use:
 *     The per-book "More Like This" page
 *     (/recommendations/book/{id}).
 */
final class SameAuthorStrategy extends AbstractRecommendationStrategy
{
    public function key(): string
    {
        return 'author';
    }

    public function label(): string
    {
        return 'More Like This';
    }

    public function description(): string
    {
        return 'Other books by the authors of the book you are viewing.';
    }

    public function icon(): string
    {
        return 'fa-user-pen';
    }

    /**
     * Whether the strategy can run with the given context.
     *
     * Input:  a RecommendationContext
     * Output: true when an author id or an anchor book id is
     *         present, false otherwise
     *
     * Business responsibility: there must be an author to base the
     * picks on; the service refuses an empty context loudly.
     */
    public function supports(RecommendationContext $context): bool
    {
        return $context->authorId !== null || $context->bookId !== null;
    }

    /**
     * Run the same-author algorithm.
     *
     * Input:  the context (author id OR anchor book id + limit)
     * Output: a RecommendationResult with books by the same
     *         author(s), the anchor book excluded, each explained
     *         with "By one of the authors of this book"
     *
     * Business responsibility: resolve the input path, validate the
     * anchor book (meaningful exceptions), delegate the reads to
     * the repository, explain, return the DTO.
     *
     * @throws RecommendationException When the anchor book is
     *                                 missing or has no authors
     */
    public function recommend(RecommendationContext $context): RecommendationResult
    {
        if ($context->bookId !== null && $context->authorId === null) {
            return $this->recommendFromBook($context);
        }

        return $this->resultFor(
            'Other books by this author, title A-Z (deleted and draft books excluded).',
            $this->repository->booksByAuthor($context->authorId, $context->limit),
            'By the same author',
        );
    }

    /**
     * The book-anchored path: the authors of the anchor book, then
     * every book by those authors, minus the anchor.
     */
    private function recommendFromBook(RecommendationContext $context): RecommendationResult
    {
        $bookId = (int) $context->bookId;

        if (!$this->repository->bookExists($bookId)) {
            throw RecommendationException::bookNotFound($bookId);
        }

        $authorIds = array_map(
            fn (array $author): int => (int) $author['id'],
            $this->repository->authorsForBook($bookId),
        );

        if ($authorIds === []) {
            throw RecommendationException::missingAuthors($bookId);
        }

        return $this->resultFor(
            'Other books by the ' . count($authorIds) . ' author' . (count($authorIds) === 1 ? '' : 's')
                . ' of this book, excluding the book itself.',
            $this->repository->booksInAuthors($authorIds, $context->limit, $bookId),
            'By one of the authors of this book',
        );
    }
}
