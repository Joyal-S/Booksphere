<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\DTO\SearchQuerySpec;
use BookSphere\App\Repositories\SearchRepository;

/**
 * SqliteSearchProvider
 *
 * The Phase 11.2 SearchProvider implementation: translates a
 * provider-NEUTRAL SearchQuerySpec into the module's SQL. All SQL
 * lives in SearchRepository; this class only ROUTES the spec to the
 * repository method of its entity and guards the seams:
 *
 *     books                 -> SearchRepository::searchBooks()
 *     authors               -> searchAuthors()
 *     categories            -> searchCategories()
 *     publishers            -> searchPublishers()
 *     reviews               -> searchReviews()
 *     anything else         -> an empty page (never an error - the
 *                              builder only emits enabled scopes)
 *
 * Determinism (Task 9): every scope is sorted on its own stable
 * key (books.title ASC, authors.name ASC, categories.name ASC,
 * publishers.name ASC, reviews.created_at DESC) so pages are
 * reproducible across requests and DB rows.
 *
 * suggest() (Phase 11.4) fetches the candidate POOL of the four
 * suggestion sources - book titles, author names, categories and
 * publishers - each capped by the spec page size, and returns every
 * row tagged with its type + deep link so the service can rank,
 * dedupe and format without any SQL. The deep links are the SAME
 * shapes the search-card formatter already produces.
 */
final class SqliteSearchProvider implements SearchProvider
{
    public function __construct(private readonly SearchRepository $repository) {}

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function search(SearchQuerySpec $query): array
    {
        return match ($query->entity) {
            SearchQuerySpec::SCOPE_BOOKS      => $this->repository->searchBooks($query),
            SearchQuerySpec::SCOPE_AUTHORS    => $this->repository->searchAuthors($query),
            SearchQuerySpec::SCOPE_CATEGORIES => $this->repository->searchCategories($query),
            SearchQuerySpec::SCOPE_PUBLISHERS => $this->repository->searchPublishers($query),
            SearchQuerySpec::SCOPE_REVIEWS    => $this->repository->searchReviews($query),
            default                            => ['items' => [], 'total' => 0],
        };
    }

    /**
     * The raw suggestion pool: every source contributes its LIMIT-capped
     * matches, each row already tagged with the source type and the
     * deep link a selection navigates to.
     *
     * @return array<int, array{type: string, label: string, subtitle: string, url: string}>
     */
    public function suggest(SearchQuerySpec $query): array
    {
        $candidates = [];

        foreach ($this->repository->suggestBooks($query) as $row) {
            $candidates[] = [
                'type'     => 'book',
                'label'    => (string) ($row['title'] ?? ''),
                'subtitle' => (string) ($row['subtitle'] ?? ''),
                'url'      => '/books/' . (int) ($row['id'] ?? 0),
            ];
        }

        foreach ($this->repository->suggestAuthors($query) as $row) {
            $candidates[] = [
                'type'     => 'author',
                'label'    => (string) ($row['name'] ?? ''),
                'subtitle' => 'Author',
                'url'      => '/books?author_id=' . (int) ($row['id'] ?? 0),
            ];
        }

        foreach ($this->repository->suggestCategories($query) as $row) {
            $candidates[] = [
                'type'     => 'category',
                'label'    => (string) ($row['name'] ?? ''),
                'subtitle' => 'Category',
                'url'      => '/books?category_id=' . (int) ($row['id'] ?? 0),
            ];
        }

        foreach ($this->repository->suggestPublishers($query) as $row) {
            $name = (string) ($row['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $candidates[] = [
                'type'     => 'publisher',
                'label'    => $name,
                'subtitle' => 'Publisher',
                'url'      => '/books?publisher=' . rawurlencode($name),
            ];
        }

        return $candidates;
    }

    /**
     * @return array{categories: array<int, array{id: int, name: string}>, authors: array<int, array{id: int, name: string}>, publishers: array<int, string>}
     */
    public function filterOptions(): array
    {
        return $this->repository->filterOptions();
    }
}