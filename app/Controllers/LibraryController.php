<?php

declare(strict_types=1);

namespace BookSphere\App\Controllers;

use BookSphere\App\Core\Controller;
use BookSphere\App\Core\RateLimiter;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Core\View;
use BookSphere\App\DTO\LibraryItemDTO;
use BookSphere\App\Exceptions\LibraryException;
use BookSphere\App\Policies\LibraryPolicy;
use BookSphere\App\Services\LibraryService;
use BookSphere\App\Services\RecommendationService;

/**
 * LibraryController
 *
 * The Wishlist & Personal Reading Library module: the complete
 * BACKEND (Phase 8.1), the library UI (Phase 8.2 - the "My Library"
 * page with status sections, the library search, the statistics
 * page, and the fetch-driven favourite / progress / status
 * interactions on the book detail page and the dashboard) and the
 * PREMIUM LIBRARY DASHBOARD (Phase 8.3 - the header with the streak
 * and reading-progress chips, the statistics cards, the quick
 * actions, the Continue Reading shelf, the filter / sort / view bar,
 * the grid & list book views with pagination, and the reading
 * summary - all backed by LibraryService's 8.3 readers and the
 * user_preferences table).
 *
 *     - index           -> the user's full library (GET /library):
 *                           the JSON payload for fetch callers, the
 *                           Phase 8.3 dashboard for browsers
 *     - store           -> add a book (POST /library)
 *     - update          -> change the status of one record
 *                          (POST /library/{id})
 *     - toggleFavourite -> flip the favourite star (POST /library/{id}/favorite)
 *     - updateProgress  -> set 0-100 reading progress (POST /library/{id}/progress)
 *     - destroy         -> remove a book (POST /library/{id}/delete)
 *     - wishlist        -> the want-to-read shelf (GET /library/wishlist)
 *     - currentlyReading-> the reading-now shelf (GET /library/currently-reading)
 *     - finished        -> the finished shelf (GET /library/finished)
 *     - favorites       -> the starred shelf (GET /library/favorites)
 *     - search          -> the search/filter JSON endpoint (GET /library/search)
 *     - statistics      -> the library overview (GET /library/statistics)
 *     - continueReading -> the resume-shelf JSON fragment (GET /library/continue-reading)
 *     - filter          -> the grid JSON endpoint (GET /library/filter):
 *                           search + filters + sort + page, one
 *                           fragment for every change
 *     - sort            -> the sort change (GET /library/sort): persist
 *                           the sort preference + the grid fragment
 *     - viewMode        -> the view-mode change (POST /library/view-mode):
 *                           persist the grid/list preference
 *     - bulk            -> the bulk actions endpoint (POST /library/bulk,
 *                           Phase 8.4): move / favourite / un-favourite /
 *                           remove the selected records in one request
 *
 * Every read answers with JSON when the caller sends the fetch
 * header (the Phase 8.1 API surface - and the data the Phase 8.2
 * fetch interactions repaint with) and with a rendered view
 * otherwise (the browser gets the real page). Every write answers
 * JSON for fetch callers and redirect + flash as the no-JS
 * fallback - the same dual answer the review module uses.
 *
 * The controller stays thin (no SQL, no business logic):
 *     1. it asks the LibraryPolicy whether the actor may act
 *     2. it validates the form through the service (Store / Update
 *        LibraryRequest rules)
 *     3. it asks the LibraryService to persist / read
 *     4. it answers JSON (fetch) or renders / redirects (no-JS)
 *
 * Route protection: every route sits behind AuthMiddleware (guests
 * can never reach a library action); the ownership rule (own record
 * only - even for admins) is enforced HERE through the policy, per
 * request. The user id of every write comes from the SESSION, never
 * from the form, so a tampered request cannot re-point a record.
 *
 * Error handling:
 *     - missing record               -> 404 (plain, safe message)
 *     - permission denied            -> 403
 *     - duplicate book / bad state   -> 409
 *     - validation errors            -> 422 (JSON) / flash (no-JS)
 *     - database / unexpected        -> the ErrorHandler turns them
 *       into a generic 500 (logged, never shown to the visitor)
 */
final class LibraryController extends Controller
{
    public function __construct(
        private readonly LibraryService $service,
        private readonly LibraryPolicy $policy,
        // The write-endpoint throttle (session-backed, wired from
        // routes/web.php like the review and recommendation ones).
        private readonly ?RateLimiter $limiter = null,
        // Phase 8.5: the SHARED RecommendationService - the library
        // dashboard's recommendation sections ("Because this is in
        // your library", "People who saved this also liked", ...).
        private readonly ?RecommendationService $recommendations = null,
    ) {}

    /**
     * The user's full library (GET /library). With the fetch header
     * the Phase 8.1 JSON payload is answered; otherwise the Phase 8.3
     * PREMIUM LIBRARY DASHBOARD renders - the greeting header with
     * the streak + reading-progress chips, the statistics cards, the
     * quick actions, the Continue Reading shelf, the search + filter
     * + sort + view bar, the shelf tabs, and the paginated book grid
     * (grid or list view). Every filter / sort / page parameter in
     * the query string shapes the grid; an unknown one is ignored,
     * never an error (the service falls back to the defaults).
     */
    public function index(Request $request, array $params = []): void
    {
        $this->requireAccess();

        if ($request->header('X-Requested-With') === 'fetch') {
            Response::json(['library' => $this->service->userLibrary((int) auth()->id())]);

            return;
        }

        $this->view('library.index', $this->dashboardViewData($request, 'all'));
    }

    /**
     * The want-to-read shelf - the modern wishlist (GET /library/wishlist).
     */
    public function wishlist(Request $request, array $params = []): void
    {
        $this->requireAccess();

        if ($request->header('X-Requested-With') === 'fetch') {
            Response::json(['items' => $this->service->wishlist((int) auth()->id())]);

            return;
        }

        $this->view('library.index', $this->dashboardViewData($request, 'want_to_read'));
    }

    /**
     * The currently-reading shelf (GET /library/currently-reading).
     */
    public function currentlyReading(Request $request, array $params = []): void
    {
        $this->requireAccess();

        if ($request->header('X-Requested-With') === 'fetch') {
            Response::json(['items' => $this->service->currentlyReading((int) auth()->id())]);

            return;
        }

        $this->view('library.index', $this->dashboardViewData($request, 'currently_reading'));
    }

    /**
     * The finished shelf (GET /library/finished).
     */
    public function finished(Request $request, array $params = []): void
    {
        $this->requireAccess();

        if ($request->header('X-Requested-With') === 'fetch') {
            Response::json(['items' => $this->service->finished((int) auth()->id())]);

            return;
        }

        $this->view('library.index', $this->dashboardViewData($request, 'finished'));
    }

    /**
     * The favourites shelf (GET /library/favorites).
     */
    public function favorites(Request $request, array $params = []): void
    {
        $this->requireAccess();

        if ($request->header('X-Requested-With') === 'fetch') {
            Response::json(['items' => $this->service->favoriteBooks((int) auth()->id())]);

            return;
        }

        $this->view('library.index', $this->dashboardViewData($request, 'favorites'));
    }

/**
     * The library search / filter endpoint (GET /library/search): the
     * Phase 8.2 live-search JSON endpoint, upgraded in Phase 8.3 to
     * the full grid fragment - the same [data-library-results] region
     * the no-JS page renders server-side, so the live search and the
     * page can never drift. Every filter parameter travels with the
     * query string (q, status, category, author, rating, favourite,
     * recency, sort, page, view); the query stays inside the user's
     * own records.
     */
    public function search(Request $request, array $params = []): void
    {
        $this->requireAccess();

        $filters = $this->parseFilters($request);
        $query   = (string) ($filters['q'] ?? '');

        if ($request->header('X-Requested-With') !== 'fetch') {
            Response::redirect('/library' . ($query !== '' ? '?q=' . urlencode($query) : ''));

            return;
        }

        $this->jsonGrid($request, $filters, $this->sortParameter($request), $this->pageParameter($request));
    }

    /**
     * The sort change of the dashboard grid (GET /library/sort): like
     * filter() but the requested sort is PERSISTED into the user's
     * preferences (user_preferences.library_sort) so the next visit
     * opens on the same ordering. Answers the grid fragment for fetch
     * callers, redirects to the no-JS page otherwise.
     */
    public function sort(Request $request, array $params = []): void
    {
        $this->filteredGrid($request);
    }

    /**
     * A filter change of the dashboard grid (GET /library/filter): the
     * fetch-driven endpoint the search box, the filter selects and the
     * favourites / recency toggles all hit. One call carries every
     * active parameter and answers the freshly rendered grid fragment
     * (chips + grid/list + pagination). The no-JS browser gets a
     * redirect to the same page with the same query - the two paths
     * render the identical fragment.
     */
    public function filter(Request $request, array $params = []): void
    {
        $this->filteredGrid($request);
    }

    /**
     * The view-mode change of the dashboard grid (POST /library/view-mode):
     * the grid/list toggle. The chosen view is PERSISTED into the
     * user's preferences (user_preferences.library_view), so the
     * dashboard reopens in the view the visitor last picked.
     */
    public function viewMode(Request $request, array $params = []): void
    {
        $this->requireAccess();
        $this->throttle('library_write');

        $view = trim((string) $request->input('view'));
        $prefs = $this->service->viewPreference(
            (int) auth()->id(),
            null,
            in_array($view, LibraryService::VIEWS, true) ? $view : null,
        );

        if ($request->header('X-Requested-With') === 'fetch') {
            Response::json(['ok' => true, 'view' => $prefs['view']]);

            return;
        }

        session()->flash('success', 'Library view updated.');
        Response::redirect('/library');
    }

    /**
     * The Continue Reading resume-shelf endpoint (GET /library/continue-reading):
     * the freshly rendered continue-shelf fragment (cards or the
     * empty state) plus the raw rows - what library.js fetches after
     * a write that may have moved a book off the shelf (a status
     * flip to finished, an auto-finish at 100%).
     */
    public function continueReading(Request $request, array $params = []): void
    {
        $this->requireAccess();

        $items = $this->service->continueReading((int) auth()->id());

        Response::json([
            'html'  => View::fragment('library.partials._continue-grid', [
                'continue'     => $items,
                'statusLabels' => LibraryService::STATUSES,
            ]),
            'total' => count($items),
            'items' => $items,
        ]);
    }

    /**
     * The library overview numbers (GET /library/statistics): totals,
     * shelf counts, favourites, average progress, books added this
     * month - as the JSON payload for fetch callers or as the
     * statistics page (statistic cards) for the browser.
     */
    public function statistics(Request $request, array $params = []): void
    {
        $this->requireAccess();

        $userId = (int) auth()->id();

        if ($request->header('X-Requested-With') === 'fetch') {
            Response::json([
                'statistics'  => $this->service->libraryStatistics($userId),
                // Phase 8.4: the collection occupancy numbers, so the
                // collections rail repaints in place after a write.
                'collections' => $this->service->collectionStatistics($userId),
            ]);

            return;
        }

        $this->view('library.statistics', [
            'title'  => 'Library Statistics',
            'active' => 'library',
            'stats'  => $this->service->libraryStatistics($userId),
            'counts' => $this->service->statusCounts($userId),
        ]);
    }

    /**
     * Add a book to the user's library (POST /library).
     *
     * The book id and the status come from the form, the owner from
     * the session - a tampered user_id in the request is ignored.
     * Validation failures answer 422 (JSON) / redirect with the first
     * message (no-JS); a duplicate book answers 409 - the UNIQUE
     * index is the last line of defence either way.
     */
    public function store(Request $request, array $params = []): void
    {
        $this->requireAccess();
        $this->throttle('library_write');

        $data   = $this->storeInput($request);
        $errors = $this->service->errorsFor($data);

        if ($errors !== []) {
            $this->validation($request, $errors);

            return;
        }

        $dto = LibraryItemDTO::fromArray($data, (int) auth()->id());

        try {
            $this->service->addBook($dto);
        } catch (LibraryException $exception) {
            Response::json(['ok' => false, 'error' => $exception->getMessage()], 409);

            return;
        }

        $this->answer($request, 'Book added to your library.', '/library');
    }

    /**
     * Change one library record (POST /library/{id}): status,
     * progress and/or favourite - whichever fields the form sent.
     * Only the owner reaches this (policy); the user id is always
     * the session user, so the record can never be re-pointed.
     */
    public function update(Request $request, array $params = []): void
    {
        $this->requireAccess();
        $this->throttle('library_write');

        $record = $this->findOrFail((int) ($params['id'] ?? 0));
        $this->authorizeOrFail($record);

        $data   = $this->updateInput($request);
        $errors = $this->service->updateErrorsFor($data);

        if ($errors !== []) {
            $this->validation($request, $errors);

            return;
        }

        $userId = (int) auth()->id();
        $bookId = (int) $record['book_id'];

        try {
            if (($favorite = $request->input('favorite')) !== null) {
                $this->service->toggleFavorite($userId, $bookId);
            }

            if (($progress = $request->input('progress')) !== null) {
                $this->service->updateProgress($userId, $bookId, (int) $progress);
            }

            if (($status = $request->input('status')) !== null) {
                $this->service->updateStatus($userId, $bookId, (string) $status);
            }
        } catch (LibraryException $exception) {
            Response::json(['ok' => false, 'error' => $exception->getMessage()], 409);

            return;
        }

        $this->answer($request, 'Library updated.', '/library');
    }

    /**
     * Flip the favourite star of one library record
     * (POST /library/{id}/favorite). The fetch-driven heart buttons
     * repaint with the fresh state; the no-JS fallback redirects
     * with a flash. Only the owner reaches this (policy); the user
     * id is always the session user.
     */
    public function toggleFavourite(Request $request, array $params = []): void
    {
        $this->requireAccess();
        $this->throttle('library_write');

        $record = $this->findOrFail((int) ($params['id'] ?? 0));
        $this->authorizeOrFail($record);

        try {
            $favorite = $this->service->toggleFavorite((int) auth()->id(), (int) $record['book_id']);
        } catch (LibraryException $exception) {
            Response::json(['ok' => false, 'error' => $exception->getMessage()], 409);

            return;
        }

        if ($request->header('X-Requested-With') === 'fetch') {
            Response::json(['ok' => true, 'favorite' => $favorite]);

            return;
        }

        session()->flash('success', $favorite
            ? 'Book added to your favourites.'
            : 'Book removed from your favourites.');
        Response::redirect('/library');
    }

    /**
     * Set the reading progress of one library record (0-100,
     * POST /library/{id}/progress). Reaching 100 auto-finishes the
     * record (the Phase 8.1 invariant); the UI asks the visitor for
     * confirmation BEFORE sending the 100. The JSON answer carries
     * the fresh progress and status so the card repaints in place.
     */
    public function updateProgress(Request $request, array $params = []): void
    {
        $this->requireAccess();
        $this->throttle('library_write');

        $record = $this->findOrFail((int) ($params['id'] ?? 0));
        $this->authorizeOrFail($record);

        $data   = ['progress' => $request->input('progress')];
        $errors = $this->service->updateErrorsFor($data);

        if ($errors !== []) {
            $this->validation($request, $errors);

            return;
        }

        try {
            $this->service->updateProgress((int) auth()->id(), (int) $record['book_id'], (int) $data['progress']);
        } catch (LibraryException $exception) {
            Response::json(['ok' => false, 'error' => $exception->getMessage()], 409);

            return;
        }

        $fresh = $this->service->find((int) $record['id']);

        if ($request->header('X-Requested-With') === 'fetch') {
            Response::json([
                'ok'       => true,
                'progress' => (int) ($fresh['progress_percentage'] ?? 0),
                'status'   => $fresh['library_status'] ?? null,
                'message'  => 'Progress updated.',
            ]);

            return;
        }

        session()->flash('success', 'Progress updated.');
        Response::redirect('/library');
    }

    /**
     * Remove a book from the user's library (POST /library/{id}/delete).
     */
    public function destroy(Request $request, array $params = []): void
    {
        $this->requireAccess();
        $this->throttle('library_write');

        $record = $this->findOrFail((int) ($params['id'] ?? 0));
        $this->authorizeOrFail($record);

        $this->service->removeBook((int) auth()->id(), (int) $record['book_id']);

        $this->answer($request, 'Book removed from your library.', '/library');
    }

    /**
     * The bulk actions endpoint (POST /library/bulk, Phase 8.4): the
     * selected books of the dashboard grid, acted on in ONE request.
     *
     * Recognized actions (the closed allowlist - anything else answers
     * 422):
     *
     *     - move_status -> move every record to $request status
     *     - favorite    -> mark every record as a favourite
     *     - unfavorite  -> un-mark every record
     *     - delete      -> remove every record (the client confirms
     *                      BEFORE sending - the server still re-checks
     *                      that the caller owns each record)
     *
     * The record ids come from the form (the "ids[]" checkboxes), the
     * owner from the SESSION; the repository re-gates every id with
     * the user_id guard, so a tampered list can never touch another
     * user's rows. JSON for fetch callers (affected count), redirect
     * + flash for the no-JS browser.
     */
    public function bulk(Request $request, array $params = []): void
    {
        $this->requireAccess();
        $this->throttle('library_write');

        $userId = (int) auth()->id();
        $ids    = $request->input('ids', []);
        $action = (string) $request->input('action');

        if (!is_array($ids) || $ids === []) {
            $this->validation($request, ['ids' => ['Select at least one book.']]);

            return;
        }

        try {
            $affected = match ($action) {
                'move_status' => $this->service->bulkStatus($userId, $ids, (string) $request->input('status', 'want_to_read')),
                'favorite'    => $this->service->bulkFavorite($userId, $ids, true),
                'unfavorite'  => $this->service->bulkFavorite($userId, $ids, false),
                'delete'      => $this->service->bulkDelete($userId, $ids),
                default       => throw new \InvalidArgumentException('Unknown bulk action.'),
            };
        } catch (\InvalidArgumentException $exception) {
            $this->validation($request, ['action' => [$exception->getMessage()]]);

            return;
        } catch (LibraryException $exception) {
            Response::json(['ok' => false, 'error' => $exception->getMessage()], 409);

            return;
        }

        $message = $affected === 0
            ? 'Nothing changed - the selected books were already in that state.'
            : $this->bulkMessage($action, $affected);

        if ($request->header('X-Requested-With') === 'fetch') {
            Response::json(['ok' => true, 'affected' => $affected, 'message' => $message]);

            return;
        }

        session()->flash($affected === 0 ? 'info' : 'success', $message);
        Response::redirect($this->redirectTarget($request));
    }

    /**
     * The human message of a completed bulk action.
     */
    private function bulkMessage(string $action, int $affected): string
    {
        return match ($action) {
            'move_status' => $affected . ' book' . ($affected === 1 ? '' : 's') . ' moved.',
            'favorite'    => $affected . ' book' . ($affected === 1 ? '' : 's') . ' added to your favourites.',
            'unfavorite'  => $affected . ' book' . ($affected === 1 ? '' : 's') . ' removed from your favourites.',
            'delete'      => $affected . ' book' . ($affected === 1 ? '' : 's') . ' removed from your library.',
            default       => 'Library updated.',
        };
    }

    // --- Internals -------------------------------------------------------

    /**
     * The shared view payload of the Phase 8.3 library dashboard
     * (index and the four shelf routes all render the same template):
     *
     *     - the greeting header data: the dashboard() payload
     *       (statistics / reading summary / streak) and the Continue
     *       Reading shelf
     *     - the grid: the filtered + sorted + paginated books with
     *       the filter vocabulary and the recommended badge set
     *     - the preferences: the persisted sort and view the grid
     *       rendered with
     *
     * The $focus parameter ('all' | one status | 'favorites') decides
     * the shelf the grid opens on when the page is reached through a
     * shelf route or ?status= link (the tab highlight follows it).
     *
     * @return array<string, mixed>
     */
    private function dashboardViewData(Request $request, string $focus = 'all'): array
    {
        $userId = (int) auth()->id();

        // The persisted preferences first - a sort / view in the
        // request is applied AND persisted (viewPreference returns
        // the merged source of truth for this request).
        $prefs = $this->service->viewPreference(
            $userId,
            $this->sortParameter($request),
            in_array($request->input('view'), LibraryService::VIEWS, true) ? (string) $request->input('view') : null,
        );

        $filters = $this->parseFilters($request);

        // A shelf route (or ?status=) focuses the grid on that shelf;
        // the favourites route pins the favourite flag instead.
        if ($focus === 'favorites') {
            $filters['favorite'] = 1;
            unset($filters['status']);
        } elseif (array_key_exists($focus, LibraryService::STATUSES)) {
            $filters['status'] = $focus;
        }

        $grid = $this->buildGrid($userId, $filters, $prefs['sort'], $this->pageParameter($request), $prefs['view']);

        return [
            'title'        => 'My Library',
            'active'       => 'library',
            'dashboard'    => $this->service->libraryDashboard($userId),
            'continue'     => $this->service->continueReading($userId),
            'collections'  => $this->service->collectionStatistics($userId),
            'grid'         => $grid,
            'prefs'        => $prefs,
            'statusLabels' => LibraryService::STATUSES,
            'activeShelf'  => $grid['activeShelf'],
            // Phase 8.5: the library-page recommendation sections -
            // best effort like the optional engine wiring elsewhere:
            // an unwired engine means no sections, never an error.
            'libraryRecommendations' => $this->recommendations?->libraryPageRecommendations($userId, null) ?? [],
        ];
    }

    /**
     * The shared grid assembler: the service's filtered + sorted +
     * paginated page, enriched with everything the grid fragment and
     * the page around it need (the sort menu, the filter vocabulary,
     * the tab counters, the recommendation badge set, the status
     * labels and the active tab). One array - the page render and the
     * JSON endpoints ship the exact same data through the same
     * partial, so they can never drift.
     *
     * @param array<string, mixed> $filters The parsed filter set
     * @return array<string, mixed>
     */
    private function buildGrid(int $userId, array $filters, string $sort, int $page, string $view): array
    {
        $paginated = $this->service->filterLibrary(
            $userId,
            $filters,
            $sort,
            $page,
            $view === 'list' ? LibraryService::PER_PAGE_LIST : LibraryService::PER_PAGE_GRID,
        );

        return [
            'items'        => $paginated['items'],
            'total'        => $paginated['total'],
            'page'         => $paginated['page'],
            'pages'        => $paginated['pages'],
            'per_page'     => $paginated['per_page'],
            'has_prev'     => $paginated['has_prev'],
            'has_next'     => $paginated['has_next'],
            'view'         => $view,
            'sort'         => $sort,
            'sorts'        => LibraryService::SORTS,
            'filters'      => $filters,
            'options'      => $this->service->filterOptions($userId),
            'counts'       => $this->service->statusCounts($userId),
            'recommended'  => array_flip($this->service->recommendedFor($userId)),
            'statusLabels' => LibraryService::STATUSES,
            'activeShelf'  => array_key_exists('status', $filters)
                ? $filters['status']
                : (!empty($filters['favorite']) ? 'favorites' : 'all'),
        ];
    }

    /**
     * The shared filter-change pipeline of the filter / sort
     * endpoints: parse the request, render the grid fragment, answer
     * JSON (fetch) or redirect to the same page with the same query
     * (no-JS). The grid fragment comes from the SAME partial the
     * server-rendered page includes, so the two paths can never
     * drift apart.
     */
    private function filteredGrid(Request $request): void
    {
        $this->requireAccess();

        if ($request->header('X-Requested-With') !== 'fetch') {
            Response::redirect($this->redirectTarget($request));

            return;
        }

        $this->jsonGrid(
            $request,
            $this->parseFilters($request),
            $this->sortParameter($request),
            $this->pageParameter($request),
        );
    }

    /**
     * The JSON grid answer: the freshly rendered [data-library-results]
     * fragment plus the numbers library.js repaints (total / page /
     * pages) and the effective state (sort / view / query) so the
     * region and the page around it stay in step.
     */
    private function jsonGrid(Request $request, array $filters, ?string $sort, int $page): void
    {
        // The sort / view in the query are applied AND persisted.
        $prefs = $this->service->viewPreference(
            (int) auth()->id(),
            $sort,
            in_array($request->input('view'), LibraryService::VIEWS, true) ? (string) $request->input('view') : null,
        );

        $grid = $this->buildGrid((int) auth()->id(), $filters, $prefs['sort'], $page, $prefs['view']);

        Response::json([
            'html'  => View::fragment('library.partials._grid', ['grid' => $grid]),
            'total' => $grid['total'],
            'page'  => $grid['page'],
            'pages' => $grid['pages'],
            'sort'  => $grid['sort'],
            'view'  => $grid['view'],
            'query' => (string) ($grid['filters']['q'] ?? ''),
        ]);
    }

    /**
     * The active filter set from the request (the filter bar + the
     * query string): q, status, category, author, rating, favorite,
     * recently_added, recently_updated. Unknown / malformed values
     * are silently ignored - the service re-normalizes the rest.
     *
     * @return array<string, mixed>
     */
    private function parseFilters(Request $request): array
    {
        $filters = [];

        $query = trim((string) $request->input('q'));
        if ($query !== '') {
            $filters['q'] = $query;
        }

        $status = (string) $request->input('status');
        if (array_key_exists($status, LibraryService::STATUSES)) {
            $filters['status'] = $status;
        }

        $category = (int) $request->input('category');
        if ($category > 0) {
            $filters['category'] = $category;
        }

        $author = (int) $request->input('author');
        if ($author > 0) {
            $filters['author'] = $author;
        }

        $rating = (int) $request->input('rating');
        if ($rating >= 1 && $rating <= 5) {
            $filters['rating'] = $rating;
        }

        foreach (['favorite', 'recently_added', 'recently_updated'] as $flag) {
            $value = $request->input($flag);
            if ($value === true || (string) $value === '1' || (string) $value === 'true') {
                $filters[$flag] = 1;
            }
        }

        return $filters;
    }

    /**
     * The sort id from the request (or null when the request has
     * NONE - which keeps the user's stored sort, through
     * viewPreference(), instead of resetting it to the default on
     * every plain grid request). The service validates the allowlist
     * again on the way through - an unknown id is ignored there.
     */
    private function sortParameter(Request $request): ?string
    {
        $sort = trim((string) $request->input('sort'));

        return $sort !== '' ? $sort : null;
    }

    /**
     * The page number from the request (1 when absent or invalid).
     */
    private function pageParameter(Request $request): int
    {
        $page = (int) $request->input('page', 1);

        return max(1, $page);
    }

    /**
     * The no-JS redirect target of the filter / sort endpoints: the
     * library dashboard with every active parameter preserved, so the
     * browser lands on exactly the grid the fetch call would have
     * rendered.
     */
    private function redirectTarget(Request $request): string
    {
        $filters = $this->parseFilters($request);
        $query   = array_merge($filters, ['sort' => $this->sortParameter($request)]);

        $view = $request->input('view');
        if (in_array($view, LibraryService::VIEWS, true)) {
            $query['view'] = (string) $view;
        }

        return '/library' . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    /**
     * The library entry form fields (book_id, status, progress,
     * favourite), collected from the request.
     *
     * @return array<string, mixed>
     */
    private function storeInput(Request $request): array
    {
        return [
            'book_id'  => $request->input('book_id'),
            'status'   => $request->input('status', 'want_to_read'),
            'progress' => $request->input('progress'),
            'favorite' => $request->input('favorite'),
        ];
    }

    /**
     * The update form fields - each optional, each validated against
     * the same bounds as the store form.
     *
     * @return array<string, mixed>
     */
    private function updateInput(Request $request): array
    {
        return [
            'status'   => $request->input('status'),
            'progress' => $request->input('progress'),
            'favorite' => $request->input('favorite'),
        ];
    }

    /**
     * The shared gate of every action: only authenticated users can
     * touch a library (the route middleware already enforces this,
     * this is the in-controller answer for the JSON reads).
     */
    private function requireAccess(): void
    {
        if (!$this->policy->canAccess()) {
            Response::error(403, 'You must be signed in to use your library.');
        }
    }

    /**
     * Fetch a library record or answer 404. Response::error()
     * terminates, so the returned row is guaranteed to exist.
     *
     * @return array<string, mixed>
     */
    private function findOrFail(int $id): array
    {
        $record = $this->service->find($id);

        if ($record === null) {
            Response::error(404, 'Library entry not found.');
        }

        return $record;
    }

    /**
     * The fine authorization gate: only the OWNER may manage a
     * record (admins may view but never modify another user's
     * library - LibraryPolicy::canManage).
     *
     * @param array<string, mixed> $record The library row
     */
    private function authorizeOrFail(array $record): void
    {
        if (!$this->policy->canManage($record)) {
            Response::error(403, 'You are not allowed to modify this library entry.');
        }
    }

    /**
     * The validation failure answer: 422 with the per-field messages
     * for fetch callers, a redirect with the first message otherwise.
     *
     * @param array<string, array<int, string>> $errors
     */
    private function validation(Request $request, array $errors): void
    {
        if ($request->header('X-Requested-With') === 'fetch') {
            Response::json(['ok' => false, 'errors' => $errors], 422);

            return;
        }

        $first = array_values($errors)[0][0] ?? 'The library entry is not valid.';
        session()->flash('error', $first);
        Response::redirect('/library');
    }

    /**
     * The dual answer of every write: JSON for fetch callers (the
     * Phase 8.2 dashboard will repaint in place), redirect + flash
     * as the no-JS fallback.
     *
     * @param string $message The success flash message
     * @param string $back    The no-JS redirect target
     */
    private function answer(Request $request, string $message, string $back): void
    {
        if ($request->header('X-Requested-With') === 'fetch') {
            Response::json(['ok' => true, 'message' => $message]);

            return;
        }

        session()->flash('success', $message);
        Response::redirect($back);
    }

    /**
     * The write-endpoint throttle (the same session-backed pattern as
     * ReviewController and RecommendationController).
     *
     * Input:  the bucket name ('library_write')
     * Output: nothing (a request over the limit exits with HTTP 429)
     *
     * The library writes are already login- and CSRF-protected; the
     * throttle caps how often ONE session may perform them. The limit
     * lives in config/recommendations.php -> security.rate_limit; when
     * the limiter is not wired (tests) or no limit is configured, the
     * gate simply lets the request through.
     */
    private function throttle(string $bucket): void
    {
        $limits = (array) config('recommendations.security.rate_limit', []);
        $spec   = (array) ($limits[$bucket] ?? []);

        $limit  = (int) ($spec['limit'] ?? 0);
        $window = (int) ($spec['window_seconds'] ?? 0);

        if ($this->limiter === null || $limit < 1 || $window < 1) {
            return;
        }

        if (!$this->limiter->allow($bucket, $limit, $window)) {
            Response::error(429, 'Too many requests - please try again in a minute.');
        }
    }
}
