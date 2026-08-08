<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\Builders\SearchQueryBuilder;
use BookSphere\App\DTO\SearchResult;
use BookSphere\App\Exceptions\SearchException;
use BookSphere\App\Requests\SearchQueryRequest;

/**
 * SearchService
 *
 * The orchestrator/facade of the global search module (Phase 11.2),
 * the one public entry point every consumer (controller, future
 * suggestion endpoint, future widgets) uses. It never asks the
 * application to build SQL: it validates, builds, executes and
 * formats - and it NEVER lets a failed search 500 the page.
 *
 * Flow (the exact Phase 11.1 pipeline):
 *     SearchQueryRequest (validated gate)
 *       -> SearchQueryBuilder (neutral spec)
 *       -> SearchProvider::search() (SqliteSearchProvider)
 *       -> SearchResultFormatter (SearchResult the views render)
 *
 * The single search() method serves BOTH the full page and the live
 * ajax endpoint - the request object is the same, only the output
 * differs (HTML page vs JSON partial) and that choice lives in the
 * controller, never here.
 *
 * Error strategy:
 *     - module disabled   -> a graceful "search is disabled" result
 *     - an invalid gate   -> the controller answers 422 (the request
 *                            already carries the field errors); the
 *                            service never double-checks validity
 *     - provider failure  -> the SearchException is caught here,
 *                            logged once, and answered as an errored
 *                            SearchResult (no stack trace to a user)
 *     - timeout           -> the configured wall-clock budget is
 *                            enforced around the provider call; an
 *                            overrun answers the timeout message
 *
 * Unsupported/unknown providers (SearchProviderFactory) are the one
 * case that DOES throw - that is an operator configuration mistake,
 * not runtime data, and should surface loudly at wiring time.
 */
final class SearchService
{
    public function __construct(
        private readonly SearchProvider $provider,
        private readonly SearchQueryBuilder $builder,
        private readonly SearchResultFormatter $formatter,
        private readonly array $config,
    ) {}

    /**
     * Run a search and answer a result the view can render directly.
     */
    public function search(SearchQueryRequest $request): SearchResult
    {
        if (!$this->enabled()) {
            return $this->formatter->error(
                $this->builder->build($request),
                'Search is currently disabled.',
            );
        }

        if (!$request->valid()) {
            return $this->formatter->error(
                $this->builder->build($request),
                'Your search could not be processed.',
            );
        }

        $spec = $this->builder->build($request);

        // An empty term AND no filters is the EMPTY PAGE, never a
        // full scan: the controller renders the "type to search"
        // state. No provider call is made for it. But an empty term
        // WITH active filters is a real, meaningful request (Phase
        // 11.3): "show me the published fantasy books" needs no
        // keywords - the repository filters the catalogue just like
        // the browse module.
        if (!$request->hasQuery() && !$spec->hasFilters()) {
            return $this->formatter->format($spec, ['items' => [], 'total' => 0]);
        }

        $budget = max(0.05, (float) ($this->config['performance']['timeout_seconds'] ?? 5.0));
        $start  = microtime(true);

        try {
            $raw = $this->provider->search($spec);
        } catch (SearchException $e) {
            $this->report($e);

            return $this->formatter->error($spec, $e->getMessage());
        }

        if ((microtime(true) - $start) > $budget) {
            return $this->formatter->error($spec, 'The search timed out - please try again.');
        }

        return $this->formatter->format($spec, $raw);
    }

    /** Whether the search module is switched on (config master switch). */
    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    /**
     * The config the controller/view layer needs for hint text and
     * pagination controls (immutable snapshot).
     */
    public function config(): array
    {
        return $this->config;
    }

    /**
     * The filter toolbar vocabulary of the search page (Phase 11.3).
     * Static whitelists come from config/search.php (the SAME maps the
     * request gateway validates against), catalogue-derived values from
     * the provider (the distinct category / author / publisher sets).
     *
     * @return array{categories: array<int, array{id: int, name: string}>, authors: array<int, array{id: int, name: string}>, publishers: array<int, string>, statuses: array<string, string>, languages: array<string, string>, ratings: array<string, string>}
     */
    public function filterOptions(): array
    {
        $filters = (array) ($this->config['filters'] ?? []);

        return array_merge(
            $this->provider->filterOptions(),
            [
                'statuses'  => (array) ($filters['status']['values'] ?? []),
                'languages' => (array) ($filters['language']['values'] ?? []),
                'ratings'   => (array) ($filters['rating']['values'] ?? []),
            ],
        );
    }

    /**
     * Build the URL of the search page for a set of (normalized)
     * inputs - the single place that knows how search filters map to
     * the query string, so the filter chips, the pagination bar and
     * the search form can never disagree again (the exact mirror of
     * BookService::queryString for the search module).
     *
     *     SearchService::queryString(['q' => 'harry', 'status' => 'published'])
     *         -> "/search?q=harry&status=published"            (baseline)
     *
     *     SearchService::queryString(['q' => 'harry', 'status' => 'published'], ['status'])
     *         -> "/search?q=harry"                             (drop a filter)
     *
     *     SearchService::queryString(['q' => 'harry'], [], ['page' => 2])
     *         -> "/search?q=harry&page=2"                       (next page)
     *
     * Empty values are dropped, so the URL only ever carries active
     * filters.
     *
     * @param array<string, mixed> $filters   Normalized filters (+ 'q' + 'scope')
     * @param array<int, string>   $remove    Filter/param keys to drop
     * @param array<string, mixed> $overrides Params to replace
     * @return string An absolute app path, e.g. "/search?q=harry"
     */
    public static function queryString(array $filters, array $remove = [], array $overrides = []): string
    {
        $params = [
            'q'           => (string) ($filters['q'] ?? ''),
            'scope'       => (string) ($filters['scope'] ?? 'books'),
            'per_page'    => (string) ($filters['per_page'] ?? ''),
            'status'      => (string) ($filters['status'] ?? ''),
            'language'    => (string) ($filters['language'] ?? ''),
            'min_rating'  => (string) ($filters['min_rating'] ?? ''),
            'year_from'   => (string) ($filters['year_from'] ?? ''),
            'year_to'     => (string) ($filters['year_to'] ?? ''),
            'category_id' => isset($filters['category_id']) && $filters['category_id'] !== '' ? (string) $filters['category_id'] : '',
            'author_id'   => isset($filters['author_id']) && $filters['author_id'] !== '' ? (string) $filters['author_id'] : '',
            'publisher'   => (string) ($filters['publisher'] ?? ''),
        ];

        foreach ($remove as $key) {
            unset($params[$key]);
        }

        foreach ($overrides as $key => $value) {
            $params[$key] = $value === null ? '' : (string) $value;
        }

        $params = array_filter($params, static fn ($value): bool => $value !== '' && $value !== null);

        return '/search' . ($params === [] ? '' : '?' . http_build_query($params));
    }

    /**
     * Log a search failure once (error_reporting or the app logger
     * idiom; the database/mail queue never sees it).
     */
    private function report(SearchException $e): void
    {
        error_log('[search] ' . $e->reason() . ': ' . $e->getMessage());
    }
}