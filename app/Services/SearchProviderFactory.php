<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\Exceptions\SearchException;
use BookSphere\App\Repositories\SearchRepository;

/**
 * SearchProviderFactory
 *
 * The provider registry of the Phase 11.1 architecture
 * (Task 11), the exact RecommendationFactory pattern: ONE place
 * that resolves the provider NAME from config('search.provider')
 * to the SearchProvider class. Everything else (application logic,
 * views) only ever sees the SearchProvider interface.
 *
 * Adding a future backend (meilisearch / elasticsearch / typesense /
 * algolia) = one new case here, no change anywhere upstream.
 */
final class SearchProviderFactory
{
    public function __construct(private readonly array $config) {}

    public function create(): SearchProvider
    {
        $name = (string) ($this->config['provider'] ?? 'sqlite');

        return match ($name) {
            'sqlite'   => new SqliteSearchProvider(new SearchRepository()),
            default    => throw SearchException::unsupported(
                "Unknown search provider '$name'.",
            ),
        };
    }
}