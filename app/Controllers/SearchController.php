<?php

declare(strict_types=1);

namespace BookSphere\App\Controllers;

use BookSphere\App\Core\Controller;
use BookSphere\App\Core\RateLimiter;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Core\View;
use BookSphere\App\DTO\SearchResult;
use BookSphere\App\Requests\SearchQueryRequest;
use BookSphere\App\Requests\SearchSuggestRequest;
use BookSphere\App\Services\SearchHistoryService;
use BookSphere\App\Services\SearchService;
use BookSphere\App\Services\SearchSuggestionService;

/**
 * SearchController
 *
 * The Phase 11.2 global search controller - deliberately THIN (the
 * Phase 11.1 architecture, Task 8: "the controller orchestrates - it
 * does NOT decide scoring, SQL or validation rules"). All search
 * decisions live in SearchService / the search provider; here a
 * single action serves BOTH consumers:
 *
 *     index   GET /search          (X-Requested-With: fetch -> the live
 *                                   JSON results partial; otherwise the
 *                                   full search page rendered server-side)
 *     suggest GET /search/suggest (Phase 11.4) - the JSON type-ahead
 *                                   endpoint of the autocomplete box
 *     deleteHistory  DELETE /search/history/{id}  (Phase 11.5) - remove
 *                                   ONE row of the signed-in user's
 *                                   search history, the no-JS form's
 *                                   POST with _method=DELETE
 *     clearHistory   DELETE /search/history (Phase 11.5) - clear the
 *                                   user's whole search history
 *
 * Failures are polite and layered, the app-wide idiom:
 *     - module disabled            -> 503 JSON + a friendly notice,
 *                                      or the "disabled" HTML state
 *     - rate limit hit             -> 429 (one sliding window per
 *                                      session, config rate_limit)
 *     - invalid term / scope       -> 422 with the field error map
 *     - provider failure / timeout -> the service already answered
 *                                      an errored SearchResult; the
 *                                      partial/JSON carries its safe
 *                                      message (never a stack trace)
 *
 * Both the page and the live endpoint build the SAME request object,
 * so the two can never disagree about what is valid.
 */
final class SearchController extends Controller
{
    public function __construct(
        private readonly SearchService $service,
        private readonly SearchSuggestionService $suggestions,
        private readonly SearchHistoryService $history,
        private readonly RateLimiter $limiter,
    ) {}

    /**
     * The search page. Answers JSON for fetch() callers and a full
     * HTML page for everyone else - the exact same query, the exact
     * same result, only the envelope differs.
     */
    public function index(Request $request, array $params = []): void
    {
        $isFetch = $request->header('X-Requested-With') === 'fetch';
        $config  = $this->service->config();

        if (!$this->service->enabled()) {
            $this->respondDisabled($isFetch);

            return;
        }

        $ipKey = 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

        if (!$this->limiter->allow(
            'search',
            (int) ($config['rate_limit']['search']['limit'] ?? 60),
            (int) ($config['rate_limit']['search']['window_seconds'] ?? 60),
            $ipKey,
        )) {
            $seconds = $this->limiter->remainingSeconds('search', (int) ($config['rate_limit']['search']['window_seconds'] ?? 60), $ipKey);
            $this->respondRateLimited($isFetch, $seconds);

            return;
        }

        $query = new SearchQueryRequest($this->inputValues($request), $config);

        if (!$query->valid()) {
            $this->respondInvalid($query, $isFetch);

            return;
        }

        $result = $this->service->search($query);

        if ($isFetch) {
            $this->respondJson($result, $query->scope(), $query->filters());

            return;
        }

        // Phase 11.5: search history. A search is recorded ONLY when
        // it is a real full-page search - never the live fetch()
        // typing preview, never a pagination hop (page must be 1) -
        // and only when the search itself succeeded. The service
        // double-checks the same gates (module + history switches,
        // valid request with a term) before it writes a row.
        $historyRows = [];

        if ($query->page() === 1 && auth_check() && $this->history->enabled()) {
            if ($query->hasQuery() && $result->ok()) {
                $this->history->record($query, (int) auth()->id());
            }

            $historyRows = $this->history->list((int) auth()->id());
        }

        $this->view('search.index', [
            'result'         => $result,
            'scope'          => $query->scope(),
            'query'          => $query->term(),
            'filters'        => $query->filters(),
            'options'        => $this->service->filterOptions(),
            'scopes'         => $this->enabledScopes($config),
            'history'        => $historyRows,
            'historyEnabled' => $this->history->enabled(),
            'title'          => 'Search',
            'active'         => 'search',
        ]);
    }

    /**
     * The live type-ahead endpoint (Phase 11.4): a tiny JSON-only
     * action - gate, throttle, ask the suggestion service, answer.
     * The service never throws; a provider/timeout problem degrades
     * to an empty suggestion list (ok still true), so the dropdown
     * simply shows nothing instead of an error.
     */
    public function suggest(Request $request, array $params = []): void
    {
        $config = $this->service->config();
        $term   = $request->input('q', '');

        if (!$this->suggestions->enabled()) {
            Response::json(['ok' => false, 'error' => 'Suggestions are currently disabled.'], 503);

            return;
        }

        $ipKey = 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $windowSeconds = (int) ($config['rate_limit']['suggestions']['window_seconds'] ?? 60);

        if (!$this->limiter->allow(
            'suggestions',
            (int) ($config['rate_limit']['suggestions']['limit'] ?? 120),
            $windowSeconds,
            $ipKey,
        )) {
            $seconds = $this->limiter->remainingSeconds('suggestions', $windowSeconds, $ipKey);

            if (!headers_sent()) {
                header('Retry-After: ' . max(1, $seconds));
            }

            Response::json([
                'ok'          => false,
                'error'       => 'Too many requests. Please wait a moment, then try again.',
                'term'        => $term,
                'retry_after' => $seconds,
            ], 429);

            return;
        }

        $query = new SearchSuggestRequest(['q' => $term], $config);

        if (!$query->valid()) {
            Response::json(['ok' => false, 'errors' => $query->errors(), 'term' => $query->term()], 422);

            return;
        }

        Response::json($this->suggestions->suggest($query));
    }

    /**
     * Delete ONE row of the signed-in user's search history (Phase
     * 11.5). The route is DELETE; the no-JS UI submits a POST with
     * _method=DELETE, exactly like the notification center's delete
     * forms. Owner-scoping lives inside the history service: a
     * foreign row id removes nothing and — unlike a book — is not an
     * error (the search data has simply changed), so the message
     * stays friendly either way. Follows the dual-answer idiom of the
     * other write endpoints: JSON for fetch callers, flash +
     * redirect for the no-JS form.
     */
    public function deleteHistory(Request $request, array $params = []): void
    {
        $id      = max(0, (int) ($params['id'] ?? 0));
        $removed = $this->history->remove($id, (int) auth()->id());

        if ($request->header('X-Requested-With') === 'fetch') {
            Response::json(['ok' => true, 'removed' => $removed, 'id' => $id]);

            return;
        }

        session()->flash(
            $removed ? 'success' : 'info',
            $removed ? 'The saved search was removed from your history.' : 'That saved search is no longer in your history.',
        );

        Response::redirect($this->back($request));
    }

    /**
     * Clear the signed-in user's whole search history (Phase 11.5).
     * No confirmation state survives on the server — the UI's confirm
     * modal is pure client polish (progressive enhancement), the no-JS
     * form posts straight to this same route. JSON for fetch callers,
     * a flash + redirect (back to the search page) otherwise.
     */
    public function clearHistory(Request $request): void
    {
        $cleared = $this->history->clear((int) auth()->id());

        if ($request->header('X-Requested-With') === 'fetch') {
            Response::json(['ok' => true, 'cleared' => $cleared]);

            return;
        }

        session()->flash(
            $cleared > 0 ? 'success' : 'info',
            $cleared > 0 ? 'Your search history was cleared.' : 'Your search history was already empty.',
        );

        Response::redirect($this->back($request));
    }

    /**
     * The safe redirect target of the history writes: the referrer of
     * the form when it is an in-app root-relative path, else the
     * search home (the no-JS history forms live on the search page,
     * so they arrive back with their current term + filters intact).
     */
    private function back(Request $request): string
    {
        $referer = (string) ($request->header('Referer') ?? '');

        return NotificationController::safeBackPath($referer) === '/'
            ? '/search'
            : NotificationController::safeBackPath($referer);
    }

    /**
     * The raw query-string values the search request reads.
     *
     * @return array<string, mixed>
     */
    private function inputValues(Request $request): array
    {
        return [
            'q'           => $request->input('q', ''),
            'scope'       => $request->input('scope', 'all'),
            'page'        => $request->input('page', '1'),
            'per_page'    => $request->input('per_page', '0'),
            'status'      => $request->input('status', ''),
            'language'    => $request->input('language', ''),
            'min_rating'  => $request->input('min_rating', ''),
            'year_from'   => $request->input('year_from', ''),
            'year_to'     => $request->input('year_to', ''),
            'category_id' => $request->input('category_id', ''),
            'author_id'   => $request->input('author_id', ''),
            'publisher'   => $request->input('publisher', ''),
        ];
    }

    /**
     * The JSON envelope of the live endpoint: the freshly rendered
     * results partial plus the numbers the live JS announces. The
     * partial is rendered WITH the same view data as the full page so
     * its pagination links keep every active filter in the query
     * string.
     */
    private function respondJson(SearchResult $result, string $scope, array $filters = []): void
    {
        Response::json([
            'ok'      => $result->ok(),
            'html'    => View::fragment('search.partials._results', [
                'result'  => $result,
                'scope'   => $scope,
                'filters' => $filters,
            ]),
            'total'   => $result->total,
            'page'    => $result->page,
            'pages'   => $result->pages,
            'perPage' => $result->perPage,
            'query'   => $result->query,
            'error'   => $result->error,
        ]);
    }

    private function respondDisabled(bool $isFetch): void
    {
        if ($isFetch) {
            Response::json(['ok' => false, 'error' => 'Search is currently disabled.'], 503);

            return;
        }

        $this->view('search.index', [
            'result'  => null,
            'scope'   => 'all',
            'query'   => '',
            'filters' => [],
            'options' => $this->service->filterOptions(),
            'scopes'  => [],
            'error'   => 'Search is currently disabled.',
            'title'   => 'Search',
            'active'  => 'search',
        ]);
    }

    private function respondRateLimited(bool $isFetch): void
    {
        $message = 'Too many searches. Please wait a moment, then try again.';

        if ($isFetch) {
            Response::json(['ok' => false, 'error' => $message], 429);

            return;
        }

        $this->view('search.index', [
            'result'  => null,
            'scope'   => 'all',
            'query'   => '',
            'filters' => [],
            'options' => $this->service->filterOptions(),
            'scopes'  => $this->enabledScopes($this->service->config()),
            'error'   => $message,
            'title'   => 'Search',
            'active'  => 'search',
        ]);
    }

    private function respondInvalid(SearchQueryRequest $query, bool $isFetch): void
    {
        if ($isFetch) {
            Response::json(['ok' => false, 'errors' => $query->errors()], 422);

            return;
        }

        $this->view('search.index', [
            'result'  => $this->service->search($query),
            'scope'   => $query->scope(),
            'query'   => $query->term(),
            'filters' => $query->filters(),
            'options' => $this->service->filterOptions(),
            'scopes'  => $this->enabledScopes($this->service->config()),
            'errors'  => $query->errors(),
            'title'   => 'Search',
            'active'  => 'search',
        ]);
    }

    /**
     * The enabled scope list for the scope tabs (name -> display
     * label), read from the config entity catalog.
     *
     * @return array<string, string>
     */
    private function enabledScopes(array $config): array
    {
        $labels = [
            'all'        => 'All',
            'books'      => 'Books',
            'authors'    => 'Authors',
            'categories' => 'Categories',
            'publishers' => 'Publishers',
            'reviews'    => 'Reviews',
        ];

        $scopes   = [];
        $entities = (array) ($config['entities'] ?? []);

        foreach ($entities as $key => $entity) {
            if (is_array($entity) && !empty($entity['enabled'])) {
                $scopes[(string) $key] = $labels[(string) $key] ?? ucfirst((string) $key);
            }
        }

        return $scopes;
    }
}