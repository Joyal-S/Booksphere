<?php

declare(strict_types=1);

namespace BookSphere\App\Strategies;

use BookSphere\App\DTO\RecommendationContext;
use BookSphere\App\DTO\RecommendationResult;
use BookSphere\App\Exceptions\RecommendationException;

/**
 * SameCategoryStrategy
 *
 * Purpose:
 *     Recommend books that share a category - either the category
 *     the caller asked for explicitly, or the categories of an
 *     anchor book ("if you liked this, try these").
 *
 * Algorithm:
 *     Two input paths, both resolving to the repository:
 *
 *         1. Explicit category (categoryId in the context):
 *            booksByCategory() - one EXISTS filter, no row
 *            multiplication for multi-category books.
 *
 *         2. Anchor book (bookId in the context):
 *            resolve the book's categories through the repository
 *            (delegates to BookRepository), then booksInCategories()
 *            with ALL of them - TRUE multi-category support - and
 *            the anchor book itself excluded.
 *
 *     Every read excludes deleted and draft books (repository rule)
 *     and the anchor book where one exists.
 *
 * Advantages:
 *     - Multi-category support is the point: an anchor book in
 *       three categories draws from all three in one query.
 *     - The anchor path needs no user profile - it works the day
 *       the catalogue exists (no cold-start problem).
 *     - Loud, meaningful failures: missing anchor book -> "Book not
 *       found"; anchor without categories -> "no categories to
 *       recommend from".
 *
 * Limitations:
 *     - Shelf is ordered by title, not quality: category similarity
 *       alone decides membership, and inside a category the order
 *       is alphabetical. A rating-aware ordering is a Phase 6.3
 *       improvement.
 *     - The explicit-category path is single-category by design
 *       (the multi-category path is book-anchored).
 *
 * When to use:
 *     The "By Category" page (/recommendations/category/{id}) and
 *     the per-book "more like this" fallback.
 */
final class SameCategoryStrategy extends AbstractRecommendationStrategy
{
    public function key(): string
    {
        return 'category';
    }

    public function label(): string
    {
        return 'By Category';
    }

    public function description(): string
    {
        return 'Books from the same categories - picked explicitly or from a book you are viewing.';
    }

    public function icon(): string
    {
        return 'fa-tags';
    }

    /**
     * Whether the strategy can run with the given context.
     *
     * Input:  a RecommendationContext
     * Output: true when a category id or an anchor book id is
     *         present, false otherwise
     *
     * Business responsibility: there must be something to base the
     * picks on; the service refuses an empty context loudly.
     */
    public function supports(RecommendationContext $context): bool
    {
        return $context->categoryId !== null || $context->bookId !== null;
    }

    /**
     * Run the same-category algorithm.
     *
     * Input:  the context (category id OR anchor book id + limit)
     * Output: a RecommendationResult with books sharing the
     *         category(ies), the anchor book excluded, each
     *         explained with "Shares a category with ..."
     *
     * Business responsibility: resolve the input path, validate the
     * anchor book (meaningful exceptions), delegate the reads to
     * the repository, explain, return the DTO.
     *
     * @throws RecommendationException When the anchor book is
     *                                 missing or has no categories
     */
    public function recommend(RecommendationContext $context): RecommendationResult
    {
        if ($context->bookId !== null && $context->categoryId === null) {
            return $this->recommendFromBook($context);
        }

        return $this->resultFor(
            'Books in this category, title A-Z (deleted and draft books excluded).',
            $this->repository->booksByCategory($context->categoryId, $context->limit),
            'Shares a category with this selection',
        );
    }

    /**
     * The book-anchored path: the categories of the anchor book,
     * then every book in those categories, minus the anchor.
     */
    private function recommendFromBook(RecommendationContext $context): RecommendationResult
    {
        $bookId = (int) $context->bookId;

        if (!$this->repository->bookExists($bookId)) {
            throw RecommendationException::bookNotFound($bookId);
        }

        $categoryIds = array_map(
            fn (array $category): int => (int) $category['id'],
            $this->repository->categoriesForBook($bookId),
        );

        if ($categoryIds === []) {
            throw RecommendationException::missingCategories($bookId);
        }

        return $this->resultFor(
            'Books in the same ' . count($categoryIds) . ' categor' . (count($categoryIds) === 1 ? 'y' : 'ies')
                . ' as this book, excluding the book itself.',
            $this->repository->booksInCategories($categoryIds, $context->limit, $bookId),
            'Shares a category with this book',
        );
    }
}
