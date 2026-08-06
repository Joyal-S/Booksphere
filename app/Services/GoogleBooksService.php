<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\Core\Logger;
use BookSphere\App\DTO\ProviderBookDTO;
use BookSphere\App\DTO\ProviderSearchResult;
use BookSphere\App\Exceptions\GoogleBooksException;

/**
 * GoogleBooksService
 *
 * The main entry point of the Google Books module for Phase 10.2
 * (search only). It ORCHESTRATES the provider layer - the controller
 * asks it one question and gets a ready-to-render ProviderSearchResult:
 *
 *     1. disabled      -> an empty result with a friendly notice
 *     2. memoized      -> the same request this page already ran
 *     3. cache fresh   -> served without a provider call
 *     4. breaker open  -> stale cache only, never a live call
 *     5. live call     -> provider + cache write + breaker heal
 *     6. failure       -> stale cache, or a friendly error result
 *
 * Every failure the provider can throw is caught HERE: the service
 * records it for the circuit breaker, logs it once, and answers with
 * a graceful ProviderSearchResult. A broken provider can never take
 * the page down - it just yields an empty page with a safe message.
 *
 * The service owns NO HTTP and NO payload mapping - those live in
 * GoogleBooksClient and GoogleBooksProvider. It decides the WHAT
 * (which query, page size, cache policy, failure strategy), they
 * decide the HOW.
 */
class GoogleBooksService
{
    /** Google Books stops returning results after index 1000. */
    public const MAX_ACCESSIBLE_INDEX = 1000;

    /** Google's per-call ceiling for maxResults (mirrors the client). */
    public const MAX_RESULTS_PER_CALL = 40;

    /** Cache entries are invalidated by this key at the start of run. */
    private array $memo = [];

    public function __construct(
        private readonly GoogleBooksClient $client,
        private readonly GoogleBooksProvider $provider,
        private readonly CacheManager $cache,
        private readonly CircuitBreaker $breaker,
        private readonly Logger $logger,
        private readonly array $config = [],
    ) {}

    /**
     * Whether the module is switched on via config. A disabled module
     * is a pure no-op: no request, no cache write, a friendly notice.
     */
    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    /**
     * The page size to apply - the request's choice, clamped to the
     * configured display limit and the provider's per-call ceiling.
     */
    public function perPage(int $requested = 0): int
    {
        $limit  = max(1, (int) ($this->config['search']['display_limit'] ?? 10));
        $chosen = $requested > 0 ? $requested : $limit;

        return max(1, min($limit, $chosen, self::MAX_RESULTS_PER_CALL));
    }

    /**
     * Perform ONE provider search and return a renderable result.
     *
     * The single search entry point of Phase 10.2 - the controller
     * calls it for the full page and for the live JSON endpoint alike.
     * It NEVER throws: "best-effort search" is the contract, failures
     * degrade to stale cache or a friendly error inside the result.
     *
     * @param array<string, string|int> $filters validated filter map
     *        (the SearchBooksRequest output): 'googleQuery' (the
     *        provider query), 'query' (the display term), ...
     */
    public function search(array $filters, int $page = 1, int $perPage = 0): ProviderSearchResult
    {
        $perPage = $this->perPage($perPage);
        $query   = trim((string) ($filters['googleQuery'] ?? $filters['query'] ?? ''));

        if ($query === '') {
            return new ProviderSearchResult(perPage: $perPage);
        }

        $page = max(1, $page);

        // One in-memory answer per request: a search that runs for the
        // view and again for a partial never hits the file system twice.
        $cacheKey = sha1(serialize([$query, $page, $perPage]));

        if (isset($this->memo[$cacheKey])) {
            return $this->memo[$cacheKey];
        }

        return $this->memo[$cacheKey] = $this->resolve($query, $page, $perPage);
    }

    /**
     * Look up ONE volume by its Google Books id - the Phase 10.3
     * import path. The controller asks this one question and gets a
     * ready-to-import ProviderBookDTO (or null when the record is
     * unusable), with the exact same cache/breaker policy as search():
     *
     *     1. disabled      -> throws unavailable (an import is an
     *                         explicit admin action, so a silent no-op
     *                         would be wrong here - unlike search,
     *                         which degrades into a friendly notice)
     *     2. cache fresh   -> served without a provider call
     *     3. breaker open  -> stale cache only, or unavailable
     *     4. live call     -> provider + cache write + breaker heal
     *     5. failure       -> stale cache, or the typed exception
     *                         rethrown (network / timeout /
     *                         rate_limited / invalid_response /
     *                         not_found)
     *
     * Unlike search(), this method DOES throw: the import needs to
     * know exactly what went wrong to answer the admin with the right
     * message and HTTP status. Returns null only when the record
     * exists but maps to nothing usable (no title).
     */
    public function volume(string $id): ?ProviderBookDTO
    {
        $id = trim($id);

        if ($id === '') {
            return null;
        }

        if (!$this->isEnabled()) {
            throw GoogleBooksException::unavailable();
        }

        // 1. Fresh cache first: a volume looked up within the TTL is
        //    served without touching the provider.
        $cached = $this->cache->get(CacheManager::NS_VOLUME, $id);

        if ($cached !== null) {
            return $this->provider->mapVolume($cached['volume'] ?? []);
        }

        // 2. Circuit open: refuse live calls, serve whatever is cached
        //    (even stale) - or fail loudly, this is an explicit action.
        if ($this->breaker->isOpen()) {
            $stale = $this->cache->stale(CacheManager::NS_VOLUME, $id);

            if ($stale !== null) {
                return $this->provider->mapVolume($stale['volume'] ?? []);
            }

            throw GoogleBooksException::unavailable();
        }

        // 3. Live call: ask the provider for exactly this volume.
        try {
            $payload = $this->client->lookup($id);
        } catch (GoogleBooksException $error) {
            $this->breaker->recordFailure();
            $this->logger->warning('google_books.volume failed', [
                'reason' => $error->reason(),
                'id'     => $id,
            ]);

            // Fail-open like search: prefer a stale copy of this
            // volume over an import the admin cannot complete.
            $stale = $this->cache->stale(CacheManager::NS_VOLUME, $id);

            if ($stale !== null) {
                return $this->provider->mapVolume($stale['volume'] ?? []);
            }

            throw $error;
        }

        $this->breaker->recordSuccess();

        $record = $this->provider->mapVolume($payload);

        if ($record !== null) {
            $this->cache->put(CacheManager::NS_VOLUME, $id, ['volume' => $payload]);
        }

        return $record;
    }

    /**
     * The wrapped provider (checksum validators) - the request layer
     * reads ISBN checksums through it before any API call.
     */
    public function provider(): GoogleBooksProvider
    {
        return $this->provider;
    }

    /**
     * The breaker's current health (admin monitoring page).
     */
    public function breakerStats(): array
    {
        return $this->breaker->stats();
    }

    /**
     * The cache's current health (admin monitoring page).
     */
    public function cacheStats(): array
    {
        return $this->cache->stats();
    }

    private function resolve(string $query, int $page, int $perPage): ProviderSearchResult
    {
        if (!$this->isEnabled()) {
            return new ProviderSearchResult(
                perPage: $perPage,
                error: 'Google Books search is currently disabled.',
            );
        }

        // 1. Fresh cache first: an identical search within the TTL is
        //    served without touching the provider.
        $key = $query . '|' . $page . '|' . $perPage;
        $cached = $this->cache->get(CacheManager::NS_SEARCH, $key);

        if ($cached !== null) {
            return $this->fromPayload($cached, perPage: $perPage, cached: true);
        }

        // 2. Circuit open: refuse live calls, serve whatever is cached
        //    (even stale) - the module degraded to cache-only mode.
        if ($this->breaker->isOpen()) {
            $stale = $this->cache->stale(CacheManager::NS_SEARCH, $key);

            if ($stale !== null) {
                return $this->fromPayload($stale, perPage: $perPage, stale: true);
            }

            return new ProviderSearchResult(
                perPage: $perPage,
                error: 'Search is temporarily unavailable - please try again in a moment.',
            );
        }

        // 3. Live call: ask the provider for exactly this page.
        return $this->fetchAndCache($query, $page, $perPage);
    }

    private function fetchAndCache(string $query, int $page, int $perPage): ProviderSearchResult
    {
        try {
            $startIndex = ($page - 1) * $perPage;
            $payload    = $this->client->search($query, $perPage, $startIndex);
        } catch (GoogleBooksException $error) {
            $this->breaker->recordFailure();
            $this->logger->warning('google_books.search failed', [
                'reason' => $error->reason(),
                'query'  => $query,
                'page'   => $page,
            ]);

            // Fail-open: prefer whatever we cached before over an empty
            // page - the view flags it as stale.
            $stale = $this->cache->stale(CacheManager::NS_SEARCH, $query . '|' . $page . '|' . $perPage);

            if ($stale !== null) {
                return $this->fromPayload($stale, perPage: $perPage, stale: true);
            }

            return new ProviderSearchResult(
                perPage: $perPage,
                error: $error->getMessage(),
            );
        }

        $this->breaker->recordSuccess();

        $records = $this->provider->mapVolumes($payload['items']);

        // Google Books only exposes results up to index 1000 - clamp so
        // the pagination never offers pages that cannot exist.
        $totalItems = min((int) $payload['totalItems'], self::MAX_ACCESSIBLE_INDEX);
        $pages      = max(1, (int) ceil($totalItems / max(1, $perPage)));

        // The requested page can point past the accessible window when
        // the result count changed between runs - clamp to the last
        // real page and fetch it instead of returning an empty page.
        if ($page > $pages) {
            $page        = $pages;
            $startIndex  = ($page - 1) * $perPage;
            $payload     = $this->client->search($query, $perPage, $startIndex);
            $records     = $this->provider->mapVolumes($payload['items']);
        }

        $this->cache->put(CacheManager::NS_SEARCH, $query . '|' . $page . '|' . $perPage, [
            'items'      => $this->serializeRecords($records),
            'totalItems' => $totalItems,
            'page'       => $page,
            'perPage'    => $perPage,
            'pages'      => $pages,
        ]);

        return new ProviderSearchResult(
            items: $records,
            totalItems: $totalItems,
            page: $page,
            perPage: $perPage,
            pages: $pages,
        );
    }

    /**
     * Rebuild a ProviderSearchResult from a cached payload.
     *
     * @param array<string, mixed> $payload
     */
    private function fromPayload(array $payload, int $perPage, bool $cached = false, bool $stale = false): ProviderSearchResult
    {
        $records = [];

        foreach ((array) ($payload['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $record = $this->provider->mapVolume($item);

            if ($record !== null) {
                $records[] = $record;
            }
        }

        return new ProviderSearchResult(
            items: $records,
            totalItems: (int) ($payload['totalItems'] ?? 0),
            page: (int) ($payload['page'] ?? 1),
            perPage: $perPage > 0 ? $perPage : (int) ($payload['perPage'] ?? 1),
            pages: (int) ($payload['pages'] ?? 1),
            stale: $stale,
            cached: $cached,
        );
    }

    /**
     * Records are stored in the cache as provider-shaped items (the
     * same shape the mapper consumes), so the cache stays neutral and
     * re-mapping on a hit is a straight mapVolume() pass.
     *
     * @param array<int, ProviderBookDTO> $records
     */
    private function serializeRecords(array $records): array
    {
        $raw = [];

        foreach ($records as $record) {
            if (!$record instanceof ProviderBookDTO) {
                continue;
            }

            $raw[] = [
                'id' => $record->externalId,
                'volumeInfo' => [
                    'title'         => $record->title,
                    'subtitle'      => $record->subtitle,
                    'authors'       => $record->authors,
                    'categories'    => $record->categories,
                    'description'   => $record->description,
                    'publisher'     => $record->publisher,
                    'publishedDate' => $record->publishedDate,
                    'language'      => $record->language,
                    'pageCount'     => $record->pageCount,
                    'averageRating' => $record->averageRating,
                    'ratingsCount'  => $record->ratingsCount,
                    'industryIdentifiers' => array_values(array_filter([
                        $record->isbn13 !== null ? ['type' => 'ISBN_13', 'identifier' => $record->isbn13] : null,
                        $record->isbn10 !== null ? ['type' => 'ISBN_10', 'identifier' => $record->isbn10] : null,
                    ])),
                    'imageLinks' => array_filter([
                        'thumbnail' => $record->thumbnail,
                    ]),
                    'previewLink' => $record->previewLink,
                    'infoLink'    => $record->infoLink,
                ],
            ];
        }

        return $raw;
    }
}