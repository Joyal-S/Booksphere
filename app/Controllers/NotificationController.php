<?php

declare(strict_types=1);

namespace BookSphere\App\Controllers;

use BookSphere\App\Core\Controller;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Core\View;
use BookSphere\App\Services\NotificationService;

/**
 * NotificationController
 *
 * The backend API of the Notification module (Phase 9.3) plus the
 * Phase 9.4 center surface. The endpoints exist so the UI only ever
 * reads fresh JSON from /notifications and writes its read/delete
 * state back through it:
 *
 *     GET    /notifications                 one page (center feed)
 *     GET    /notifications/center          the rendered center page
 *     GET    /notifications/unread-count    the badge number
 *     PATCH  /notifications/{id}/read       mark one notification read
 *     PATCH  /notifications/{id}/unread     mark one notification unread
 *     PATCH  /notifications/read-all        mark everything read
 *     DELETE /notifications/{id}            delete one notification
 *     POST   /notifications/bulk            delete a set of notifications
 *     DELETE /notifications                 clear the whole history
 *
 * Security model (blueprint Task 9 - ownership):
 *     - every action scopes to the SESSION user ((int) auth()->id());
 *       a user id in the URL is never accepted
 *     - the repository's findOwnedBy() is the IDOR shield: a foreign
 *       row answers null, indistinguishable from a missing one, so a
 *       user can never read, mark or delete another user's rows
 *     - the idempotent writes stay idempotent where it makes sense:
 *       marking an already-read notification still answers ok (the
 *       resource EXISTS, the change is a no-op). Deleting an
 *       already-deleted notification is a 404 - the resource no
 *       longer exists, and the UI treats that as "already gone".
 *
 * Dual answer convention (same as the Phase 7.5 engagement actions):
 * a fetch caller (X-Requested-With: fetch) gets JSON, a plain form
 * gets a redirect + flash. The reads always answer JSON.
 */
final class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    /**
     * The notification feed - one page of the user's rows, newest
     * first, exactly the paginate() payload the library grid ships
     * (items, total, page, pages, per_page, has_prev, has_next). The
     * ?tab=unread|read filter and the ?page / ?per_page bounds travel
     * on the query string (Request::input() reads GET too); the
     * service clamps the bounds and falls an unknown tab back to
     * 'all'. Always answers JSON - the center UI (Phase 9.4) is a
     * fetch consumer.
     */
    public function index(Request $request): void
    {
        $page    = (int) ($request->input('page', 1) ?: 1);
        $perPage = (int) ($request->input('per_page', NotificationService::PER_PAGE_DEFAULT) ?: NotificationService::PER_PAGE_DEFAULT);
        $tab     = (string) ($request->input('tab', 'all') ?: 'all');
        $filter  = (string) ($request->input('filter', '') ?: '');
        $types   = $filter !== '' && isset(NotificationService::FILTER_GROUPS[$filter])
            ? NotificationService::FILTER_GROUPS[$filter]
            : [];

        Response::json($this->notifications->page(
            (int) auth()->id(),
            $tab,
            $page,
            $perPage,
            $types,
        ));
    }

    /**
     * The rendered CENTER PAGE (Phase 9.4): the full notification
     * inbox with the filter chips (tab + type group), the sectioned
     * list, the bulk toolbar and the page intro - wrapped in the
     * master layout. Reads exactly the same query string the JSON
     * feed does (?tab, ?filter, ?page), so the page and the feed can
     * never disagree about which rows a filter means.
     */
    public function center(Request $request): void
    {
        $tab     = (string) ($request->input('tab', 'all') ?: 'all');
        $filter  = (string) ($request->input('filter', '') ?: '');
        $filter  = $filter !== '' && isset(NotificationService::FILTER_GROUPS[$filter]) ? $filter : '';
        $types   = $filter !== '' ? NotificationService::FILTER_GROUPS[$filter] : [];
        $userId  = (int) auth()->id();

        $this->view('notifications.center', [
            'title'   => 'Notifications',
            'active'  => 'notifications',
            'tab'     => $tab,
            'filter'  => $filter,
            'payload' => $this->notifications->page(
                $userId,
                $tab,
                (int) ($request->input('page', 1) ?: 1),
                NotificationService::PER_PAGE_DEFAULT,
                $types,
            ),
            'unread'  => $this->notifications->unreadCount($userId),
        ]);
    }

    /**
     * The badge number (Phase 9.4): the user's unread rows. The bell
     * polls this once on load and re-reads it after every local
     * read/delete cycle, so the badge is always the server's count.
     */
    public function unreadCount(Request $request): void
    {
        Response::json(['count' => $this->notifications->unreadCount((int) auth()->id())]);
    }

    /**
     * The HTML list fragment (Phase 9.4): the center page's
     * [data-notif-results] region, freshly rendered for the SAME
     * query (?tab, ?filter, ?page) through the SAME partial the
     * server-rendered page includes. notifications.js fetches this
     * when a filter chip or a pager link is pressed, so the list and
     * the page around it can never drift apart.
     */
    public function fragment(Request $request): void
    {
        $tab    = (string) ($request->input('tab', 'all') ?: 'all');
        $filter = (string) ($request->input('filter', '') ?: '');
        $filter = $filter !== '' && isset(NotificationService::FILTER_GROUPS[$filter]) ? $filter : '';
        $types  = $filter !== '' ? NotificationService::FILTER_GROUPS[$filter] : [];
        $userId = (int) auth()->id();

        $payload = $this->notifications->page(
            $userId,
            $tab,
            (int) ($request->input('page', 1) ?: 1),
            NotificationService::PER_PAGE_DEFAULT,
            $types,
        );

        Response::json([
            'html'   => View::fragment('notifications.partials._list', [
                'payload' => $payload,
                'tab'     => $tab,
                'filter'  => $filter,
                'base'    => '/notifications/center',
            ]),
            // The numbers notifications.js repaints around the list:
            // the current total (pagination line) and the unread
            // count (the badge + the "Mark all read" state) - so a
            // fetched page and the page around it stay in step.
            'total'  => (int) ($payload['total'] ?? 0),
            'unread' => $this->notifications->unreadCount($userId),
        ]);
    }

    /**
     * Mark ONE notification read. A missing or foreign id is a 404
     * (the findOwnedBy gate); marking an already-read row changes
     * nothing and still answers ok (idempotent on the wire).
     */
    public function markRead(Request $request, array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);

        if ($this->notifications->findOwnedBy($id, (int) auth()->id()) === null) {
            $this->failure($request, 404, 'Notification not found.');

            return;
        }

        $this->notifications->markRead($id, (int) auth()->id());
        $this->answer($request, ['ok' => true]);
    }

    /**
     * Mark one notification UNREAD again - the other half of the
     * read-state toggle (Phase 9.4). Gated (and idempotent on the
     * wire) exactly like markRead: a missing or foreign id is a 404,
     * an already-unread row changes nothing and still answers ok.
     */
    public function markUnread(Request $request, array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);

        if ($this->notifications->findOwnedBy($id, (int) auth()->id()) === null) {
            $this->failure($request, 404, 'Notification not found.');

            return;
        }

        $this->notifications->markUnread($id, (int) auth()->id());
        $this->answer($request, ['ok' => true]);
    }

    /**
     * Mark every notification of the user read. Answers the number of
     * rows actually changed (a second call, with nothing left unread,
     * changes 0 and stays ok).
     */
    public function markAllRead(Request $request): void
    {
        $changed = $this->notifications->markAllRead((int) auth()->id());
        $this->answer($request, ['ok' => true, 'changed' => $changed]);
    }

    /**
     * Delete ONE notification (owner-scoped like markRead: a missing,
     * foreign or already-deleted id is a 404 - the resource no longer
     * exists, and the surface can repaint by "gone").
     */
    public function destroy(Request $request, array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);

        if ($this->notifications->findOwnedBy($id, (int) auth()->id()) === null) {
            $this->failure($request, 404, 'Notification not found.');

            return;
        }

        $this->notifications->delete($id, (int) auth()->id());
        $this->answer($request, ['ok' => true]);
    }

    /**
     * Clear the user's whole notification history. Answers the number
     * of rows removed.
     */
    public function deleteAll(Request $request): void
    {
        $deleted = $this->notifications->deleteAll((int) auth()->id());
        $this->answer($request, ['ok' => true, 'deleted' => $deleted]);
    }

    /**
     * Delete a SET of notifications (the center page's bulk action,
     * Phase 9.4). The ids arrive as ?ids[]= from the checkbox
     * selection; they are coerced to positive integers and the OWNER
     * session clips the set to the user's own rows inside the
     * repository, so foreign ids in the batch are simply skipped -
     * never leaked, never an error. An empty/invalid selection is a
     * 422 (the UI never sends one, but a hand-crafted request is not
     * an opinionated server error).
     */
    public function bulkDestroy(Request $request): void
    {
        $ids = array_values(array_unique(array_map(
            static fn ($id): int => (int) $id,
            array_filter((array) ($request->input('ids') ?? [])),
        )));
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            $this->failure($request, 422, 'No notifications selected.');

            return;
        }

        $deleted = $this->notifications->deleteMany($ids, (int) auth()->id());
        $this->answer($request, ['ok' => true, 'deleted' => $deleted]);
    }

    // --- Internals -------------------------------------------------------

    /**
     * The dual answer of the idempotent writes: {ok: ...} for fetch
     * callers, a redirect back to the referring app path (or home)
     * with a flash for the no-JS form.
     */
    private function answer(Request $request, array $payload): void
    {
        if ($request->header('X-Requested-With') === 'fetch') {
            Response::json($payload);

            return;
        }

        session()->flash('success', 'Notifications updated.');
        Response::redirect($this->back($request));
    }

    /**
     * The 404 answer of a read/delete on a missing or foreign row:
     * JSON {error, "Notification not found."} for fetch callers, a
     * redirect + flash for the no-JS form.
     */
    private function failure(Request $request, int $status, string $message): void
    {
        if ($request->header('X-Requested-With') === 'fetch') {
            Response::json(['error' => $message], $status);

            return;
        }

        session()->flash('error', $message);
        Response::redirect($this->back($request));
    }

    /**
     * The referrer of the request when it is an application path (the
     * no-JS form's natural home), else the app root.
     *
     * Phase 9.6 hardening: ONLY root-relative paths are accepted. A
     * protocol-relative ("//evil.example/"), absolute ("https://…")
     * or backslash-prefixed referer is refused, so a hostile Referer
     * header can never turn the post-action redirect into an open
     * redirect.
     */
    private function back(Request $request): string
    {
        return self::safeBackPath((string) ($request->header('Referer') ?? ''));
    }

    /**
     * The safe subset of a referer path: '' or '/' -prefixed paths
     * that stay inside the app (no second '/', no '\', no NUL). Kept
     * static so the security test suite can verify the rule directly.
     */
    public static function safeBackPath(string $referer): string
    {
        $path = trim($referer);

        if ($path === '' || !str_starts_with($path, '/')) {
            return '/';
        }

        $rest = substr($path, 1);

        if ($rest === '' || str_starts_with($rest, '/') || str_starts_with($rest, '\\') || str_contains($path, "\0")) {
            return '/';
        }

        return $path;
    }
}