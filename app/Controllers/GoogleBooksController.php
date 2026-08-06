<?php

declare(strict_types=1);

namespace BookSphere\App\Controllers;

use BookSphere\App\Core\Controller;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Core\View;
use BookSphere\App\DTO\ProviderSearchResult;
use BookSphere\App\Exceptions\GoogleBooksException;
use BookSphere\App\Requests\SearchBooksRequest;
use BookSphere\App\Services\BookImportService;
use BookSphere\App\Services\GoogleBooksService;

/**
 * GoogleBooksController
 *
 * The Google Books module (Phase 10.2 search + Phase 10.3 import).
 * Three admin-only routes power the module:
 *
 *     index   GET  /admin/google-books          the search page
 *     search  GET  /admin/google-books/search   live results (JSON)
 *     import  POST /admin/google-books/import   import one result
 *
 * All stay behind AdminMiddleware in the route table; the import POST
 * carries CSRF protection like every other data change.
 *
 * Import flow (Phase 10.3): the search card submits the provider's
 * volume id. The controller RE-FETCHES the volume through
 * GoogleBooksService::volume() (never trusts the card's rendered
 * data - the server always re-reads the source of truth), then hands
 * the record to BookImportService, which dedupes and inserts it as a
 * published catalogue entry. The controller alone maps the provider
 * failures (GoogleBooksException) onto the answer the caller asked
 * for: JSON for fetch (X-Requested-With: fetch), redirect + flash for
 * the no-JavaScript form - the app's standard dual answer.
 *
 * The search side stays exactly as it was: the service NEVER throws
 * for search (best-effort degradation into a graceful result), so the
 * search endpoints have no try/catch path at all.
 */
final class GoogleBooksController extends Controller
{
    public function __construct(
        private readonly GoogleBooksService $service,
        private readonly BookImportService $importer,
    ) {}

    /**
     * The search page. Without a query it renders the empty state;
     * with valid GET terms it runs the search server-side (the no-JS
     * path) and renders the same partial the live endpoint returns.
     */
    public function index(Request $request, array $params = []): void
    {
        $booksRequest = $this->buildRequest();

        $result = ($booksRequest->valid() && $booksRequest->hasQuery())
            ? $this->service->search($booksRequest->filters(), $booksRequest->page(), $booksRequest->perPage())
            : null;

        $this->view('admin.google-books', $this->viewData($result, $booksRequest));
    }

    /**
     * The live search endpoint used by the page's JavaScript
     * (debounced, abortable - see public/assets/js/google-books.js).
     *
     * The response mirrors the browse module's searchJson contract:
     *     json { html, total, page, pages, perPage, query }
     * plus { type, stale, cached } so the view can flag cache/breaker
     * states. Invalid input answers 422 with the field error map.
     */
    public function searchJson(Request $request, array $params = []): void
    {
        $booksRequest = $this->buildRequest();

        if (!$booksRequest->valid()) {
            Response::json(['ok' => false, 'errors' => $booksRequest->errors()], 422);

            return;
        }

        if (!$booksRequest->hasQuery()) {
            Response::json([
                'ok'      => true,
                'html'    => '',
                'total'   => 0,
                'page'    => 1,
                'pages'   => 1,
                'perPage' => $this->service->perPage(),
                'query'   => '',
                'stale'   => false,
                'cached'  => false,
            ]);

            return;
        }

        $result = $this->service->search(
            $booksRequest->filters(),
            $booksRequest->page(),
            $booksRequest->perPage(),
        );

        Response::json([
            'ok'       => true,
            'html'     => View::fragment('admin.google-books.partials._results', [
                'result'   => $result,
                'query'    => (string) $booksRequest->query(),
                'existing' => $this->importer->importedMap($result->items),
            ]),
            'total'    => $result->totalItems,
            'page'     => $result->page,
            'pages'    => $result->pages,
            'perPage'  => $result->perPage,
            'query'    => (string) $booksRequest->query(),
            'stale'    => $result->stale,
            'cached'   => $result->cached,
        ]);
    }

    /**
     * Import ONE provider record into the local catalogue.
     *
     * The card submits the volume id; the server re-fetches the volume
     * (GoogleBooksService::volume()) and hands it to BookImportService,
     * which dedupes and inserts it as a published catalogue entry.
     *
     * Dual answer (the app's standard pattern):
     *     - fetch   -> JSON { ok, status: imported|duplicate, bookId,
     *                   message } on success, or { ok: false, error,
     *                   reason } + the mapped HTTP status on failure
     *     - no-JS   -> redirect back to the search page + a flash
     *
     * Provider failures are the ONLY typed exceptions here (the
     * database layer's PDO errors follow the shared ErrorHandler
     * path like every other module).
     */
    public function import(Request $request, array $params = []): void
    {
        $id      = trim((string) $request->post('google_book_id', ''));
        $isFetch = $request->header('X-Requested-With') === 'fetch';

        if ($id === '' || mb_strlen($id) > 128) {
            $message = 'The Google Books record id is missing or invalid.';

            if ($isFetch) {
                Response::json(['ok' => false, 'errors' => ['google_book_id' => [$message]]], 422);

                return;
            }

            session()->flash('error', $message);
            Response::redirect('/admin/google-books');
        }

        try {
            $book = $this->service->volume($id);
        } catch (GoogleBooksException $error) {
            $this->fail($error, $isFetch);

            return;
        }

        if ($book === null) {
            $message = 'This Google Books record has no usable title and cannot be imported.';

            if ($isFetch) {
                Response::json(['ok' => false, 'error' => $message], 422);

                return;
            }

            session()->flash('error', $message);
            Response::redirect('/admin/google-books');
        }

        $result = $this->importer->import($book);

        if ($isFetch) {
            Response::json([
                'ok'      => true,
                'status'  => $result->status,
                'bookId'  => $result->bookId,
                'message' => $result->message,
            ]);

            return;
        }

        session()->flash($result->isDuplicate() ? 'warning' : 'success', $result->message);
        Response::redirect('/admin/google-books');
    }

    /**
     * Answer a provider failure with the right tone and HTTP status
     * for whichever caller asked (JSON for fetch, flash + redirect
     * otherwise).
     */
    private function fail(GoogleBooksException $error, bool $isFetch): void
    {
        $status = match ($error->reason()) {
            'not_found'    => 404,
            'rate_limited' => 503,
            'unavailable'  => 503,
            default        => 502,
        };

        if ($isFetch) {
            Response::json([
                'ok'     => false,
                'reason' => $error->reason(),
                'error'  => $error->getMessage(),
            ], $status);

            return;
        }

        session()->flash('error', $error->getMessage());
        Response::redirect('/admin/google-books');
    }

    /**
     * Filter the raw $_GET into the SearchBooksRequest used by BOTH
     * the full page and the live endpoint.
     */
    private function buildRequest(): SearchBooksRequest
    {
        return new SearchBooksRequest(
            array_merge($_GET, ['_max_length' => (int) ($this->config()['search']['query_max_length'] ?? 100)]),
            $this->service->provider(),
        );
    }

    /**
     * The view bundle for admin.google-books.
     */
    private function viewData(?ProviderSearchResult $result, SearchBooksRequest $request): array
    {
        return [
            'title'        => 'Google Books Search',
            'result'       => $result,
            'request'      => $request,
            'enabled'      => $this->service->isEnabled(),
            'breaker'      => $this->service->breakerStats(),
            'cache'        => $this->service->cacheStats(),
            'existing'     => $this->importer->importedMap($result?->items ?? []),
            'config'       => [
                'base_url'  => (string) $this->config()['base_url'],
                'display'   => $this->service->perPage(),
                'search_ttl_seconds' => (int) ($this->config()['cache']['search_ttl_seconds'] ?? 900),
            ],
            'active'       => 'admin',
            'error'        => $result?->error,
        ];
    }

    private function config(): array
    {
        static $config = null;

        return $config ??= config('google_books') ?? [];
    }
}