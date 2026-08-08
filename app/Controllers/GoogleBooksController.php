<?php

declare(strict_types=1);

namespace BookSphere\App\Controllers;

use BookSphere\App\Core\Controller;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Core\View;
use BookSphere\App\DTO\ProviderSearchResult;
use BookSphere\App\DTO\SyncReport;
use BookSphere\App\Exceptions\GoogleBooksException;
use BookSphere\App\Requests\BulkImportRequest;
use BookSphere\App\Requests\SearchBooksRequest;
use BookSphere\App\Services\BookImportService;
use BookSphere\App\Services\BulkImportService;
use BookSphere\App\Services\GoogleBooksService;
use BookSphere\App\Services\GoogleBooksSyncService;

/**
 * GoogleBooksController
 *
 * The Google Books module (Phase 10.2 search + Phase 10.3 import +
 * Phase 10.5 bulk import + Phase 10.6 synchronization). All routes
 * stay behind AdminMiddleware in the route table; every data POST
 * carries CSRF protection like every other data change.
 *
 *     index       GET  /admin/google-books              the search page
 *     search      GET  /admin/google-books/search       live results (JSON)
 *     import      POST /admin/google-books/import       import one result
 *     importBulk  POST /admin/google-books/bulk-import  import the selection
 *     sync        POST /admin/google-books/sync         sync one imported book
 *     syncBulk    POST /admin/google-books/sync-bulk    sync the selection
 *     syncAll     POST /admin/google-books/sync-all     sync every imported book
 *
 * Import flow (Phase 10.3): the search card submits the provider's
 * volume id. The controller RE-FETCHES the volume through
 * GoogleBooksService::volume() (never trusts the card's rendered data)
 * and hands the record to BookImportService, which dedupes and inserts
 * it as a published catalogue entry. Failures are mapped onto the
 * caller's answer: JSON for fetch, redirect + flash otherwise.
 *
 * Bulk import (Phase 10.5) and synchronization (Phase 10.6) share the
 * same dual answer: the run streams per-book `progress` plus a final
 * `summary` Server-Sent Event for fetch callers (the page shows live
 * progress and can cancel), while the no-JavaScript form gets the same
 * report as a flash + redirect. The difference is only WHICH service
 * produces the events - the stream plumbing is one helper.
 *
 * The sync endpoints delegate ALL decisions to GoogleBooksSyncService
 * (change detection, protected fields, cover reuse, failure
 * isolation); the controller only gates the batch ceiling and answers.
 */
final class GoogleBooksController extends Controller
{
    public function __construct(
        private readonly GoogleBooksService $service,
        private readonly BookImportService $importer,
        private readonly BulkImportService $bulkService,
        private readonly GoogleBooksSyncService $syncService,
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
                'syncInfo' => $this->syncService->syncMap($this->googleIds($result->items)),
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
     * Import the ADMIN'S SELECTION in one operation (Phase 10.5).
     * See the class docblock for the dual answer (SSE / flash+redirect).
     */
    public function importBulk(Request $request, array $params = []): void
    {
        $isFetch = $request->header('X-Requested-With') === 'fetch';

        $bulkRequest = new BulkImportRequest(
            $this->selectedIds($request),
            $this->bulkService->maxBatch(),
        );

        if (!$bulkRequest->valid()) {
            $message = ($bulkRequest->errors()['google_book_id'] ?? ['Nothing was selected.'])[0];

            if ($isFetch) {
                Response::json(['ok' => false, 'errors' => $bulkRequest->errors()], 422);

                return;
            }

            session()->flash('error', $message);
            Response::redirect('/admin/google-books');
        }

        $this->prepareFor();

        if ($isFetch) {
            $this->sseStream(fn (callable $advance): array => $this->bulkService->import($bulkRequest->ids(), $advance)->toArray());

            return;
        }

        $report = $this->bulkService->import($bulkRequest->ids());

        session()->flash(
            $report->hasFailures() || $report->imported === 0 ? 'warning' : 'success',
            $report->summary(),
        );
        Response::redirect('/admin/google-books');
    }

    /**
     * Synchronize ONE imported book (Phase 10.6).
     *
     * The card (an already-imported record) submits its google id; the
     * sync service looks up the local row, refreshes it against the
     * provider with change detection, and returns a per-book SyncReport
     * outcome. Answers JSON for fetch and redirect + flash otherwise.
     */
    public function sync(Request $request, array $params = []): void
    {
        $isFetch = $request->header('X-Requested-With') === 'fetch';

        if (!$this->syncEnabled($isFetch)) {
            return;
        }

        $id = trim((string) $request->post('google_book_id', ''));

        if ($id === '' || mb_strlen($id) > 128) {
            $message = 'The Google Books record id is missing or invalid.';

            if ($isFetch) {
                Response::json(['ok' => false, 'errors' => ['google_book_id' => [$message]]], 422);

                return;
            }

            session()->flash('error', $message);
            Response::redirect('/admin/google-books');
        }

        $report = $this->syncService->sync([$id]);
        $outcome = $report->results[0] ?? null;

        if ($isFetch) {
            Response::json([
                'ok'      => true,
                'status'  => $outcome['status'] ?? SyncReport::STATUS_UNCHANGED,
                'bookId'  => $outcome['bookId'] ?? null,
                'changes' => $outcome['changes'] ?? 0,
                'cover'   => (bool) ($outcome['cover'] ?? false),
                'message' => $outcome['message'] ?? 'Unknown outcome.',
                'report'  => $report->toArray(),
            ]);

            return;
        }

        session()->flash($this->flashTone($outcome['status'] ?? 'unchanged'), $outcome['message'] ?? $report->summary());
        Response::redirect('/admin/google-books');
    }

    /**
     * Synchronize the ADMIN'S SELECTION (Phase 10.6). Identical shape
     * to importBulk - the list gate is the same BulkImportRequest, the
     * run is the sync service's, and the fetch answer is the shared
     * SSE stream.
     */
    public function syncBulk(Request $request, array $params = []): void
    {
        $isFetch = $request->header('X-Requested-With') === 'fetch';

        if (!$this->syncEnabled($isFetch)) {
            return;
        }

        $bulkRequest = new BulkImportRequest(
            $this->selectedIds($request),
            $this->syncService->maxBatch(),
        );

        if (!$bulkRequest->valid()) {
            $message = ($bulkRequest->errors()['google_book_id'] ?? ['Nothing was selected.'])[0];

            if ($isFetch) {
                Response::json(['ok' => false, 'errors' => $bulkRequest->errors()], 422);

                return;
            }

            session()->flash('error', $message);
            Response::redirect('/admin/google-books');
        }

        $this->prepareFor();

        if ($isFetch) {
            $this->sseStream(fn (callable $advance): array => $this->syncService->sync($bulkRequest->ids(), $advance)->toArray());

            return;
        }

        $report = $this->syncService->sync($bulkRequest->ids());

        session()->flash($this->flashTone($report), $report->summary());
        Response::redirect('/admin/google-books');
    }

    /**
     * Synchronize EVERY imported book (Phase 10.6). The confirmation
     * happens client-side (a modal before the request); server-side the
     * run is just the sync pipeline over the whole imported catalogue.
     */
    public function syncAll(Request $request, array $params = []): void
    {
        $isFetch = $request->header('X-Requested-With') === 'fetch';

        if (!$this->syncEnabled($isFetch)) {
            return;
        }

        $this->prepareFor();

        if ($isFetch) {
            $this->sseStream(fn (callable $advance): array => $this->syncService->syncAll($advance)->toArray());

            return;
        }

        $report = $this->syncService->syncAll();

        session()->flash($this->flashTone($report), $report->summary());
        Response::redirect('/admin/google-books');
    }

    /**
     * The shared SSE streaming helper behind every long run (Phase
     * 10.5 bulk import + Phase 10.6 sync). Emits a `progress` event
     * per processed book and a final `summary` event carrying the
     * run's toArray() report.
     *
     * @param callable(callable(array<string, mixed>): bool): array $vendor
     *        the run to execute; it receives the progress/cancel hook.
     */
    private function sseStream(callable $run): void
    {
        header('Content-Type: text/event-stream; charset=UTF-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');

        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ob_implicit_flush(false);

        // An SSE comment pads the first flush past buffering proxies so
        // the browser sees the connection open immediately.
        flush();
        echo ':' . str_repeat(' ', 2048) . PHP_EOL;
        flush();

        $advance = function (array $event): bool {
            echo 'event: progress' . PHP_EOL
                . 'data: ' . json_encode($event, JSON_UNESCAPED_SLASHES) . PHP_EOL . PHP_EOL;
            flush();

            return connection_aborted() === 0;
        };

        $report = $run($advance);

        echo 'event: summary' . PHP_EOL
            . 'data: ' . json_encode($report, JSON_UNESCAPED_SLASHES) . PHP_EOL . PHP_EOL;
        flush();
    }

    /**
     * A long-lived run (hundreds of books) must not hold the PHP
     * session lock for the other tabs/requests, and must outlive the
     * default execution ceiling.
     */
    private function prepareFor(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        set_time_limit(0);
    }

    /**
     * Gate a sync action on the module + the sync flag. Answers the
     * caller's protocol when disabled and returns false.
     */
    private function syncEnabled(bool $isFetch): bool
    {
        if ($this->syncService->isEnabled()) {
            return true;
        }

        $message = 'Google Books synchronization is currently disabled.';

        if ($isFetch) {
            Response::json(['ok' => false, 'error' => $message], 503);

            return false;
        }

        session()->flash('error', $message);
        Response::redirect('/admin/google-books');

        return false;
    }

    /**
     * The flash tone of a sync outcome: success for updates, info for
     * no-op syncs, warning when books were skipped, danger on failure.
     */
    private function flashTone(SyncReport|string $report): string
    {
        if (is_string($report)) {
            return match ($report) {
                SyncReport::STATUS_UPDATED => 'success',
                SyncReport::STATUS_FAILED  => 'danger',
                SyncReport::STATUS_SKIPPED => 'warning',
                default                    => 'info',
            };
        }

        if ($report->hasFailures()) {
            return 'danger';
        }

        return $report->updated > 0 ? 'success' : 'info';
    }

    /**
     * The POSTed google_book_id list for the bulk/sync forms, or [].
     *
     * @return array<int, mixed>
     */
    private function selectedIds(Request $request): array
    {
        $raw = $request->post('google_book_id');

        return is_array($raw) ? array_values($raw) : [];
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
        $items = $result?->items ?? [];

        return [
            'title'        => 'Google Books Search',
            'result'       => $result,
            'request'      => $request,
            'enabled'      => $this->service->isEnabled(),
            'breaker'      => $this->service->breakerStats(),
            'cache'        => $this->service->cacheStats(),
            'existing'     => $this->importer->importedMap($items),
            'syncInfo'     => $this->syncService->syncMap($this->googleIds($items)),
            'syncEnabled'  => $this->syncService->isEnabled(),
            'config'       => [
                'base_url'           => (string) $this->config()['base_url'],
                'display'            => $this->service->perPage(),
                'search_ttl_seconds' => (int) ($this->config()['cache']['search_ttl_seconds'] ?? 900),
                'bulk'               => [
                    'max_batch'  => $this->syncService->maxBatch(),
                ],
            ],
            'active'       => 'admin',
            'error'        => $result?->error,
        ];
    }

    /**
     * The google ids of a set of provider records.
     *
     * @param array<int, \BookSphere\App\DTO\ProviderBookDTO> $items
     * @return array<int, string>
     */
    private function googleIds(array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            if ($item instanceof \BookSphere\App\DTO\ProviderBookDTO) {
                $ids[] = $item->externalId;
            }
        }

        return $ids;
    }

    private function config(): array
    {
        static $config = null;

        return $config ??= (array) (config('google_books') ?? []);
    }
}