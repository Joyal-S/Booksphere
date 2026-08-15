<?php

declare(strict_types=1);

namespace BookSphere\App\Controllers;

use BookSphere\App\Core\Controller;
use BookSphere\App\Core\RateLimiter;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Exceptions\FollowException;
use BookSphere\App\Models\Author;
use BookSphere\App\Policies\FollowPolicy;
use BookSphere\App\Requests\FollowRequest;
use BookSphere\App\Services\FollowService;
use BookSphere\App\Services\ReviewService;

/**
 * AuthorController
 *
 * The author pages of the platform (Phase 7.6): a listing of every
 * author with their community rating (index) and the full author
 * page (show).
 *
 *     - index    -> the author directory with the average author
 *                   rating per author (ReviewService::authorAverage())
 *     - show     -> one author's page: average author rating, books
 *                   reviewed, highest rated book, most reviewed book,
 *                   recent community reviews and top reviewers - all
 *                   aggregated by the Reviews module. Since Phase 9.2
 *                   the page also carries the FOLLOW surface: the
 *                   follow button state and the follower count, read
 *                   through the shared FollowService.
 *     - follow   -> POST /authors/{id}/follow (Phase 9.2)
 *     - unfollow -> DELETE /authors/{id}/follow (Phase 9.2; the
 *                   no-JS form fallback posts _method=DELETE)
 *
 * Route protection: AuthMiddleware in the route table (like every
 * catalogue page); the fine gates (canFollow / canUnfollow) run here
 * through FollowPolicy. The follow writes answer JSON when the
 * caller sends X-Requested-With: fetch (the button repaints in
 * place) and a redirect + flash otherwise - the same dual answer the
 * review engagement actions use.
 *
 * The controller stays thin: it resolves the author row, asks the
 * ReviewService for one statistics payload, delegates every follow
 * rule to the FollowService, and renders the answer.
 */
final class AuthorController extends Controller
{
    public function __construct(
        private readonly Author $authors,
        private readonly ?ReviewService $reviews = null,
        // Phase 9.2: the Follow Authors module - the service owns the
        // rules, the policy the fine gate, the limiter the write
        // throttle. Each is optional so older callers (tests that
        // wire only the author + review service) keep working.
        private readonly ?FollowService $follows = null,
        private readonly ?FollowPolicy $policy = null,
        private readonly ?RateLimiter $limiter = null,
    ) {}

    /**
     * The author directory: every author with the average rating
     * their books earned (real aggregation over approved reviews).
     */
    public function index(Request $request, array $params = []): void
    {
        $averages = $this->reviews?->authorAverage() ?? [];
        $byId     = array_column($averages, null, 'id');

        $authors = array_map(
            fn (array $author): array => [
                'id'      => (int) $author['id'],
                'name'    => $author['name'],
                'average' => (float) ($byId[(int) $author['id']]['average'] ?? 0),
                'count'   => (int) ($byId[(int) $author['id']]['count'] ?? 0),
            ],
            $this->authors->all(),
        );

        $this->view('authors.index', [
            'title'   => 'Authors',
            'active'  => 'authors',
            'authors' => $authors,
        ]);
    }

    /**
     * One author's page: the aggregated rating profile of their
     * books (ReviewService::authorStatistics()), or 404 when the
     * author does not exist. Phase 9.2: the follow button state and
     * the follower count ride along for the signed-in visitor.
     */
    public function show(Request $request, array $params = []): void
    {
        $author = $this->authors->findById((int) ($params['id'] ?? 0));

        if ($author === null) {
            Response::error(404, 'Author not found.');
        }

        $authorId  = (int) $author['id'];
        $followed  = false;
        $followers = 0;

        if ($this->follows !== null && auth_check()) {
            $followed  = $this->follows->isFollowing((int) auth()->id(), $authorId);
            $followers = $this->follows->followerCount($authorId);
        }

        $this->view('authors.show', [
            'title'      => $author['name'],
            'active'     => 'authors',
            'author'     => $author,
            'statistics' => $this->reviews?->authorStatistics($authorId) ?? [],
            // Phase 9.2: the follow surface of the author page.
            'followed'   => $followed,
            'followers'  => $followers,
        ]);
    }

    /**
     * Follow an author (POST /authors/{id}/follow, Phase 9.2).
     *
     * The actor's id comes from the session - a tampered user_id in
     * the form is ignored (FollowDTO sanitizes it away). Errors:
     * a missing author answers 404, following yourself 400, a
     * duplicate follow 409 - each as JSON for fetch or a redirect +
     * flash for the no-JS form.
     */
    public function follow(Request $request, array $params = []): void
    {
        $authorId = (int) ($params['id'] ?? 0);

        if ($this->follows === null) {
            Response::error(500, 'The follow service is not available.');
        }

        if (!$this->policy?->canFollow()) {
            Response::error(403, 'You are not allowed to follow authors.');
        }

        $this->throttle('follow_write');

        $errors = FollowRequest::validate(['author_id' => $params['id'] ?? ''])->errors();

        if ($errors !== []) {
            $this->validationFailure($request, $authorId);

            return;
        }

        try {
            $this->follows->follow((int) auth()->id(), $authorId);
        } catch (FollowException $exception) {
            $this->followFailure($request, $authorId, $exception);

            return;
        }

        $this->answerFollow($request, $authorId, true);
    }

    /**
     * Unfollow an author (DELETE /authors/{id}/follow, Phase 9.2;
     * the no-JS form posts _method=DELETE).
     *
     * IDEMPOTENT: a second unfollow of the same pair answers the
     * same {following: false} - deleting a non-existent row is a
     * silent no-op. Only the follow row's owner may unfollow
     * (FollowPolicy); the pair is always the session user's, so the
     * DELETE can never touch another user's rows.
     */
    public function unfollow(Request $request, array $params = []): void
    {
        $authorId = (int) ($params['id'] ?? 0);
        $userId   = (int) auth()->id();

        if ($this->follows === null) {
            Response::error(500, 'The follow service is not available.');
        }

        if (!$this->policy?->canFollow()) {
            Response::error(403, 'You are not allowed to unfollow authors.');
        }

        $this->throttle('follow_write');

        $row = $this->follows->followRow($userId, $authorId);

        if ($row !== null && !$this->policy?->canUnfollow($row, $userId)) {
            Response::error(403, 'You are not allowed to unfollow this author.');
        }

        $this->follows->unfollow($userId, $authorId);

        $this->answerFollow($request, $authorId, false);
    }

    /**
     * The followers of one author (GET /authors/{id}/followers,
     * Phase 9.2): the list of people following the author, newest
     * first, plus the visitor's own follow state (so the page can
     * link back to the author with the button in the same state).
     *
     * Follows are PRIVATE: the author page shows only the follower
     * COUNT; this list resolves the follower names through the shared
     * FollowService and is gated by the module's fine policy (the
     * default rule keeps the names visible to signed-in readers -
     * the reverse of the user's OWN following list, which stays
     * owner-or-admin).
     */
    public function followers(Request $request, array $params = []): void
    {
        $author = $this->authors->findById((int) ($params['id'] ?? 0));

        if ($author === null) {
            Response::error(404, 'Author not found.');
        }

        $authorId = (int) $author['id'];

        // Phase 9.6: the list is PAGINATED (it used to truncate
        // silently at 50 rows while the lead text under-counted), so
        // the lead shows the honest total and every follower is
        // reachable.
        $page    = max(1, (int) $request->input('page', 1));
        $perPage = (int) $request->input('per_page', 20);
        $pageData = $this->follows !== null
            ? $this->follows->followersPage($authorId, $page, $perPage)
            : ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => 20];

        $this->view('authors.followers', [
            'title'     => 'Followers of ' . $author['name'],
            'active'    => 'authors',
            'author'    => $author,
            'followers' => $pageData['items'],
            'total'     => (int) $pageData['total'],
            'following' => $this->follows !== null && auth_check()
                ? $this->follows->isFollowing((int) auth()->id(), $authorId)
                : false,
            'pagination' => [
                'base'       => '/authors/' . $authorId . '/followers',
                'params'     => [],
                'page'       => (int) $pageData['page'],
                'pages'      => (int) $pageData['pages'],
                'total'      => (int) $pageData['total'],
                'perPage'    => (int) $pageData['per_page'],
                'perPages'   => [10, 20, 50],
                'label'      => 'person',
                'pagerLabel' => 'Follower pages',
            ],
        ]);
    }

    // --- Internals -------------------------------------------------------

    /**
     * The dual answer of follow / unfollow: {following: bool} for
     * fetch callers (the button repaints in place), a redirect back
     * to the author page with a flash for the no-JS form.
     */
    private function answerFollow(Request $request, int $authorId, bool $following): void
    {
        if ($request->header('X-Requested-With') === 'fetch') {
            Response::json(['following' => $following]);

            return;
        }

        // Resolve the author's name for the human flash message (the
        // route already proved the author exists on a successful
        // write - and the follow button only renders for real
        // authors, so the fallback is unreachable in practice).
        $author = $this->authors->findById($authorId);
        $name   = (string) ($author['name'] ?? 'this author');

        session()->flash('success', $following
            ? 'You are now following ' . $name . '.'
            : 'You unfollowed ' . $name . '.');
        Response::redirect('/authors/' . $authorId);
    }

    /**
     * Map a FollowException to its HTTP answer: JSON {error} for
     * fetch callers, a redirect + flash for the no-JS form. The
     * status follows the exception's own fixed message text (the
     * static factories make them stable): a duplicate follow is a
     * 409, a self-follow a 400, a missing author a 404.
     */
    private function followFailure(Request $request, int $authorId, FollowException $exception): void
    {
        $message = $exception->getMessage();
        $status  = str_contains($message, 'already follow') ? 409
            : (str_contains($message, 'follow themselves') ? 400 : 404);

        if ($request->header('X-Requested-With') === 'fetch') {
            Response::json(['error' => $message], $status);

            return;
        }

        session()->flash('error', $message);
        Response::redirect('/authors/' . $authorId);
    }

    /**
     * The field-validation failure answer: 422 for fetch callers, a
     * redirect + flash for the no-JS form.
     */
    private function validationFailure(Request $request, int $authorId): void
    {
        if ($request->header('X-Requested-With') === 'fetch') {
            Response::json(['error' => 'The author is not valid.'], 422);

            return;
        }

        session()->flash('error', 'The author is not valid.');
        Response::redirect('/authors/' . $authorId);
    }

    /**
     * The write-endpoint throttle (the same session-backed pattern as
     * ReviewController and LibraryController - Phase 9.2 wires the
     * 'follow_write' bucket from config/recommendations.php).
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

        $userId = auth()?->id();
        $persistentKey = $userId !== null ? 'user:' . $userId : 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

        if (!$this->limiter->allow($bucket, $limit, $window, $persistentKey)) {
            $seconds = max(1, $this->limiter->remainingSeconds($bucket, $window, $persistentKey));

            if (!headers_sent()) {
                header('Retry-After: ' . $seconds);
            }

            Response::error(429, 'Too many requests - please try again in a minute.');
        }
    }
}