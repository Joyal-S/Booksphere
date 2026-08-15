<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\DTO\SearchHit;
use BookSphere\App\DTO\SearchQuerySpec;
use BookSphere\App\DTO\SearchResult;

/**
 * SearchResultFormatter
 *
 * The THIN presentation translation between raw provider rows and the
 * provider-neutral SearchResult/SearchHit objects the views render.
 * It normalizes one row of EVERY searchable entity into the single
 * display shape the search card understands:
 *
 *     - title      the primary display name (book title, author
 *                  name, category name, publisher name, book title)
 *     - subtitle   the secondary line (book subtitle / author list)
 *     - url        the "open" link of the hit - to the book show
 *                  page, the review page, or the browse module
 *                  pre-filtered by author/category/publisher
 *     - data       the entity's own row, kept whole so the view can
 *                  read anything it needs (cover, rating, ...)
 *
 * No business logic lives here (scoring/ranking comes later): it
 * only SHAPES already-correct rows.
 */
final class SearchResultFormatter
{
    /**
     * Normalize a raw provider page into a SearchResult the views
     * can render directly.
     *
     * @return SearchResult
     */
    public function format(SearchQuerySpec $spec, array $raw): SearchResult
    {
        $items = [];

        foreach ($raw['items'] ?? [] as $row) {
            $entity = $row['_entity'] ?? $row['entity'] ?? $spec->entity;
            $items[] = $this->hit($entity, $row);
        }

        $total = max(0, (int) ($raw['total'] ?? 0));
        $pages = $spec->perPage > 0 ? max(1, (int) ceil($total / $spec->perPage)) : 1;
        $page  = min($spec->page, $pages);

        return new SearchResult(
            hits:      $items,
            total:     $total,
            page:      $page,
            perPage:   $spec->perPage,
            pages:     $pages,
            query:     $spec->term,
        );
    }

    /** An already-errored result (the search failed gracefully). */
    public function error(SearchQuerySpec $spec, string $message): SearchResult
    {
        return new SearchResult(
            page:    $spec->page,
            perPage: $spec->perPage,
            query:   $spec->term,
            error:   $message,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hit(string $entity, array $row): SearchHit
    {
        return match ($entity) {
            SearchQuerySpec::SCOPE_BOOKS      => $this->bookHit($row),
            SearchQuerySpec::SCOPE_AUTHORS    => $this->authorHit($row),
            SearchQuerySpec::SCOPE_CATEGORIES => $this->categoryHit($row),
            SearchQuerySpec::SCOPE_PUBLISHERS => $this->publisherHit($row),
            SearchQuerySpec::SCOPE_REVIEWS    => $this->reviewHit($row),
            default                            => $this->plainHit($entity, $row),
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function bookHit(array $row): SearchHit
    {
        $subtitle = $row['subtitle'] ?? '';
        $authors  = (string) ($row['authors_list'] ?? '');
        $line     = $subtitle !== '' ? $subtitle : $authors;

        return new SearchHit(
            entity:   SearchQuerySpec::SCOPE_BOOKS,
            title:    (string) ($row['title'] ?? ''),
            data:     $row,
            subtitle: $line !== '' ? $line : null,
            url:      '/books/' . (int) $row['id'],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function authorHit(array $row): SearchHit
    {
        $count = (int) ($row['book_count'] ?? 0);
        $avg   = isset($row['average_rating']) && $row['average_rating'] !== null ? format_rating($row['average_rating']) . ' average rating' : '';
        $sub   = $count . ' ' . ($count === 1 ? 'book' : 'books') . ($avg !== '' ? ' · ' . $avg : '');

        return new SearchHit(
            entity:   SearchQuerySpec::SCOPE_AUTHORS,
            title:    (string) ($row['name'] ?? ''),
            data:     $row,
            subtitle: $sub,
            url:      isset($row['id']) ? '/authors/' . (int) $row['id'] : '/books?author_id=' . (int) ($row['id'] ?? 0),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function categoryHit(array $row): SearchHit
    {
        $count = (int) ($row['book_count'] ?? 0);
        $sub   = $count > 0 ? $count . ' ' . ($count === 1 ? 'book' : 'books') : 'Category';

        return new SearchHit(
            entity:   SearchQuerySpec::SCOPE_CATEGORIES,
            title:    (string) ($row['name'] ?? ''),
            data:     $row,
            subtitle: $sub,
            url:      isset($row['id']) ? '/categories/' . (int) $row['id'] : '/books?category_id=' . (int) ($row['id'] ?? 0),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function publisherHit(array $row): SearchHit
    {
        $count = (int) ($row['book_count'] ?? 0);
        $sub   = $count > 0 ? $count . ' ' . ($count === 1 ? 'book' : 'books') : 'Publisher';

        return new SearchHit(
            entity:   SearchQuerySpec::SCOPE_PUBLISHERS,
            title:    (string) ($row['name'] ?? ''),
            data:     $row,
            subtitle: $sub,
            url:      '/books' . (($row['name'] ?? '') !== ''
                    ? '?publisher=' . rawurlencode((string) $row['name'])
                    : ''),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function reviewHit(array $row): SearchHit
    {
        $text    = trim((string) ($row['review'] ?? ''));
        $snippet = $text !== '' ? '"' . mb_strimwidth($text, 0, 90, '...') . '"' : (string) ($row['book_title'] ?? 'Review');
        $meta    = '— ' . ($row['reviewer_name'] ?? 'Community Reviewer') . (!empty($row['book_title']) ? ' on ' . $row['book_title'] : '');

        return new SearchHit(
            entity:   SearchQuerySpec::SCOPE_REVIEWS,
            title:    $snippet,
            data:     $row,
            subtitle: $meta,
            url:      '/reviews/' . (int) $row['id'],
        );
    }

    /**
     * A perfectly view-shaped fallback for a not-yet-known entity
     * (the formatter keeps working when a future entity arrives;
     * its own path renders what its spec knows).
     *
     * @param array<string, mixed> $row
     */
    private function plainHit(string $entity, array $row): SearchHit
    {
        $title = (string) ($row['title'] ?? '');
        if ($title === '' && isset($row['name'])) {
            $title = (string) $row['name'];
        }

        return new SearchHit(entity: $entity, title: $title, data: $row);
    }
}