<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\DTO\SearchQuerySpec;

/**
 * SearchProvider
 *
 * The provider seam of the global search (Phase 11.2) - the exact
 * "SearchProvider interface" of the Phase 11.1 architecture
 * (Task 11), following the RecommendationStrategy / EmailTransport
 * interface placement of the rest of the app. The application
 * logic and views depend ONLY on this contract; swapping 'sqlite'
 * for 'meilisearch' | 'elasticsearch' | 'typesense' | 'algolia'
 * later is one line in config/search.php.
 *
 * Contract:
 *     search(SearchQuerySpec): provider-neutral rows formatted the
 *         way SearchResultFormatter expects - a raw items/total
 *         pair. The provider is the ONLY place search SQL (or a
 *         future query DSL) is written; application logic never
 *         builds SQL beyond here.
 *     suggest(SearchQuerySpec): prefix suggestions for the live
 *         box (Phase 11.4). Defined NOW so the interface never
 *         breaks; returns an empty page until the feature ships.
 *
 * A provider does NOT validate or paginate-clamp the spec (the
 * builder already normalized it).
 */
interface SearchProvider
{
    /**
     * Run a search for a spec and return the raw item page.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function search(SearchQuerySpec $query): array;

    /**
     * Prefix suggestions for the live search box (Phase 11.4;
     * empty until then).
     *
     * @return array<int, array<string, mixed>>
     */
    public function suggest(SearchQuerySpec $query): array;

    /**
     * The filter toolbar vocabulary the search page's dropdowns
     * render (Phase 11.3): the DISTINCT catalogue values for
     * category / author / publisher. The static vocabularies (status
     * / language / rating) come from config/search.php, NOT from the
     * provider.
     *
     * @return array{categories: array<int, array{id: int, name: string}>, authors: array<int, array{id: int, name: string}>, publishers: array<int, string>}
     */
    public function filterOptions(): array;
}