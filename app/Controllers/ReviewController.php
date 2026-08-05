<?php

declare(strict_types=1);

namespace BookSphere\App\Controllers;

use BookSphere\App\Core\Controller;
use BookSphere\App\Core\RateLimiter;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\DTO\ReviewDTO;
use BookSphere\App\Exceptions\ReviewException;
use BookSphere\App\Models\User;
use BookSphere\App\Policies\ReviewPolicy;
use BookSphere\App\Presenters\ReviewListPresenter;
use BookSphere\App\Requests\ReportReviewRequest;
use BookSphere\App\Services\ReviewService;

/**
 * ReviewController
 *
 * The Reviews & Ratings module (Phase 7.1 backend + Phase 7.2
 * complete CRUD + Phase 7.4 professional review lists): writing,
 * viewing, editing and deleting reviews, plus the review pages:
 *
 *     - index       -> "My Reviews" (paginated, sortable, filterable)
 *     - show        -> one review's detail page (Phase 7.2)
 *     - search      -> the server-side review search + community
 *                      timeline (Phase 7.4)
 *     - statistics  -> the platform review statistics (Phase 7.4)
 *     - userReviews -> the public per-user review page (Phase 7.4)
 *     - bookReviews -> all approved reviews of one book (the page
 *                      behind the /books/{id}/reviews route; the
 *                      book detail page embeds the same section)
 *     - store       -> create a review for a book (POST)
 *     - edit        -> the "edit your review" form
 *     - update      -> save the changes (POST)
 *     - destroy     -> delete a review (POST)
 *     - helpful     -> mark a review as helpful (POST, fetch)
 *     - removeHelpful -> remove one's own helpful vote (POST, fetch)
 *     - report      -> file a report about a review (POST, fetch)
 *
 * The Phase 7.5 engagement actions answer with JSON when the
 * caller sends X-Requested-With: fetch (the review-card toggle and
 * the report modal) and with a redirect + flash otherwise - the
 * same dual answer toggleWishlist uses.
 *
 * The controller stays thin (no SQL, no business logic):
 *     1. it collects request data into a ReviewDTO
 *     2. it asks the ReviewPolicy whether the actor may act
 *     3. it asks the ReviewService to validate, persist and read
 *     4. it asks the ReviewListPresenter to shape the list state
 *        (sort / filters / pagination) for the view
 *     5. it renders a view or redirects with a flash message
 *
 * Route protection: every route sits behind AuthMiddleware (guests
 * can never reach a review action); the fine ownership rules
 * (owner-or-admin) are enforced HERE through the policy, per
 * request, because the route table cannot know who owns a review.
 *
 * Error handling:
 *     - missing book / review / user -> 404 (plain, safe message)
 *     - permission denied            -> 403
 *     - duplicate review             -> 409, redirected to the book
 *     - validation errors            -> the form re-renders (edit)
 *                                       or a flash summary (store)
 *     - database / unexpected        -> the ErrorHandler turns them
 *                                       into a generic 500 (logged,
 *                                       never shown to the visitor)
 */
final class ReviewController extends Controller
{
    public function __construct(
        private readonly ReviewService $service,
        private readonly ReviewPolicy $policy,
        ?ReviewListPresenter $presenter = null,
        // Phase 7.7: the write-endpoint throttle (session-backed,
        // wired from routes/web.php like the recommendation one).
        private readonly ?RateLimiter $limiter = null,
    ) {
        // The presenter is optional so callers that wire only the
        // service + policy (e.g. older tests) still work; it is
        // created on demand from the shared service.
        $this->presenter = $presenter ?? new ReviewListPresenter($service);
    }

    private readonly ReviewListPresenter $presenter;

    /**
     * "My Reviews": the signed-in user's reviews, paginated (10/20/50
     * per page), sortable (newest / oldest / highest / lowest /
     * relevant), filterable by star rating and edited-only, with the
     * user's own review statistics on top (Phase 7.4).
     */
    public function index(Request $request, array $params = []): void
    {
        $userId = (int) auth()->id();
        $state  = $this->presenter->state($request);
        $result = $this->service->userReviews($userId, $state, $state['perPage'], $state['page']);
        $stats  = $this->service->reviewStatistics(['user_id' => $userId]);
        $items  = $this->service->attachVoteState($result['items'], $userId);

        $this->view('reviews.index', [
            'title'      => 'My Reviews',
            'active'     => 'reviews',
            'reviews'    => $items,
            'stats'      => $stats,
            'breakdown'  => $this->service->distributionBreakdown($stats['distribution']),
            'toolbar'    => $this->presenter->toolbar($state, '/reviews'),
            'pagination' => $this->presenter->pagination($state, $result, '/reviews'),
        ]);
    }

    /**
     * The server-side review search (Phase 7.4): one keyword applied
     * to the review title, the review body and the reviewer's name
     * inside the SQL, over the approved community reviews - with the
     * same sort, filter and pagination toolbar as every review list.
     * The "My reviews only" chip narrows the search to the signed-in
     * user's own reviews.
     */
    public function search(Request $request, array $params = []): void
    {
        $state = $this->presenter->state($request);

        if ($state['mine'] && auth()?->id() !== null) {
            $state['user_id'] = (int) auth()->id();
        }

        $result = $this->service->searchReviews($state['q'], $state, $state['perPage'], $state['page']);
        $stats  = $this->service->reviewStatistics($state);
        $items  = auth()?->id() !== null
            ? $this->service->attachVoteState($result['items'], (int) auth()->id())
            : $result['items'];

        $this->view('reviews.search', [
            'title'      => 'Review Search',
            'active'     => 'reviews',
            'reviews'    => $items,
            'stats'      => $stats,
            'breakdown'  => $this->service->distributionBreakdown($stats['distribution']),
            'toolbar'    => $this->presenter->toolbar($state, '/reviews/search', ['showMine' => true]),
            'pagination' => $this->presenter->pagination($state, $result, '/reviews/search'),
        ]);
    }

    /**
     * The platform review statistics (Phase 7.4): total reviews,
     * average / highest / lowest rating, the latest review date and
     * the rating distribution across the whole catalogue - plus the
     * signed-in user's own activity and the newest community voices.
     */
    public function statistics(Request $request, array $params = []): void
    {
        $stats = $this->service->reviewStatistics();
        $mine  = null;

        if (($userId = auth()?->id()) !== null) {
            $mine = $this->service->reviewStatistics(['user_id' => $userId]);
        }

        $this->view('reviews.statistics', [
            'title'     => 'Review Statistics',
            'active'    => 'reviews',
            'stats'     => $stats,
            'breakdown' => $this->service->distributionBreakdown($stats['distribution']),
            'mine'      => $mine,
            'recent'    => $userId !== null ? $this->service->attachVoteState($this->service->latestReviews(5), $userId) : $this->service->latestReviews(5),
            'highest'   => $userId !== null ? $this->service->attachVoteState($this->service->highestRatedReviews(5), $userId) : $this->service->highestRatedReviews(5),
        ]);
    }

    /**
     * The public reviews page of ONE user (Phase 7.4): their review
     * statistics and their reviews, paginated and sortable, reached
     * from the reviewer link on every review card.
     */
    public function userReviews(Request $request, array $params = []): void
    {
        $userId = (int) ($params['id'] ?? 0);
        $user   = (new User())->findById($userId);

        if ($user === null) {
            Response::error(404, 'User not found.');
        }

        $state  = $this->presenter->state($request);
        $result = $this->service->userReviews($userId, $state, $state['perPage'], $state['page']);
        $stats  = $this->service->reviewStatistics(['user_id' => $userId]);
        $base   = '/reviews/user/' . $userId;
        $items  = auth()?->id() !== null
            ? $this->service->attachVoteState($result['items'], (int) auth()->id())
            : $result['items'];

        $this->view('reviews.user', [
            'title'      => 'Reviews by ' . $user['full_name'],
            'active'     => 'reviews',
            'profile'    => $user,
            'reviews'    => $items,
            'stats'      => $stats,
            'breakdown'  => $this->service->distributionBreakdown($stats['distribution']),
            'toolbar'    => $this->presenter->toolbar($state, $base),
            'pagination' => $this->presenter->pagination($state, $result, $base),
        ]);
    }

    /**
     * Create a review for a book.
     *
     * The book id comes from the route (/books/{id}/reviews), the
     * author from the session, the content from the form.
     *
     * With JavaScript (X-Requested-With: fetch) the action answers
     * JSON so the Phase 7.2 book-page form can swap the review
     * panel in place; without JavaScript it redirects back to the
     * book with a flash message - the same dual-path pattern as
     * the wishlist toggle.
     *
     * Errors: validation failures answer 422 (JSON) / redirect
     * with the first message (no-JS); a duplicate review answers
     * 409 - the UNIQUE index is the last line of defence either
     * way.
     */
    public function store(Request $request, array $params = []): void
    {
        $bookId = (int) ($params['id'] ?? 0);

        if (!$this->policy->canReview()) {
            Response::error(403, 'You are not allowed to review books.');
        }

        if ($this->service->book($bookId) === null) {
            Response::error(404, 'Book not found.');
        }

        $this->throttle('review_write');

        $data   = $this->reviewInput($request);
        $errors = $this->service->errorsFor($data);

        if ($errors !== []) {
            if ($request->header('X-Requested-With') === 'fetch') {
                Response::json(['ok' => false, 'errors' => $errors], 422);

                return;
            }

            $first = array_values($errors)[0][0] ?? 'The review is not valid.';
            session()->flash('error', $first);
            Response::redirect('/books/' . $bookId);
        }

        try {
            $this->service->store(ReviewDTO::fromArray($data + ['book_id' => $bookId], (int) auth()->id()));
        } catch (ReviewException $exception) {
            Response::error(409, $exception->getMessage());
        }

        if ($request->header('X-Requested-With') === 'fetch') {
            Response::json(['ok' => true, 'message' => 'Review submitted successfully.']);

            return;
        }

        session()->flash('success', 'Review submitted successfully.');
        Response::redirect('/books/' . $bookId);
    }

    /**
     * One review's detail page ("View Review", Phase 7.2): the
     * full review with its book link and - for the owner or an
     * admin - the Edit / Delete actions.
     *
     * The route requires a login (AuthMiddleware); the owner-or-
     * admin gates are answered here for the view, not enforced for
     * a read (a review is public catalogue data - only the write
     * actions carry the fine gate).
     */
    public function show(Request $request, array $params = []): void
    {
        $review = $this->findOrFail((int) ($params['id'] ?? 0));

        $user = (new User())->findById((int) $review['user_id']);

        if (($actorId = auth()?->id()) !== null) {
            $review = $this->service->attachVoteState([$review], $actorId)[0];
        }

        $this->view('reviews.show', [
            'title'    => 'Review by ' . ($user['full_name'] ?? 'Reader'),
            'active'   => 'reviews',
            'review'   => $review,
            'user'     => $user,
            'book'     => $this->service->book((int) $review['book_id']),
            'canEdit'  => $this->policy->canEdit($review),
            'canDelete' => $this->policy->canDelete($review),
        ]);
    }

    /**
     * The "edit your review" form, prefilled from the database.
     * Only the review's owner or an admin reaches this (policy).
     */
    public function edit(Request $request, array $params = []): void
    {
        $review = $this->findOrFail((int) ($params['id'] ?? 0));
        $this->authorizeOrFail($review, 'edit');

        $this->view('reviews.edit', [
            'title'  => 'Edit Review',
            'active' => 'reviews',
            'book'   => $this->service->book((int) $review['book_id']),
            'review' => $review,
            'old'    => $this->formValues($review),
            'errors' => [],
        ]);
    }

    /**
     * Save the changes of an edited review.
     *
     * The book and the author never change on an update - they are
     * carried from the stored row, not from the form, so a
     * tampered request cannot re-point a review at another book.
     */
    public function update(Request $request, array $params = []): void
    {
        $review = $this->findOrFail((int) ($params['id'] ?? 0));
        $this->authorizeOrFail($review, 'edit');

        $this->throttle('review_write');

        $data   = $this->reviewInput($request);
        $errors = $this->service->errorsFor($data);

        if ($errors !== []) {
            $this->view('reviews.edit', [
                'title'  => 'Edit Review',
                'active' => 'reviews',
                'book'   => $this->service->book((int) $review['book_id']),
                'review' => $review,
                'old'    => $data,
                'errors' => $errors,
            ]);

            return;
        }

        $dto = ReviewDTO::fromArray($data + [
            'book_id' => $review['book_id'],
            'user_id' => $review['user_id'],
        ]);

        $this->service->update((int) $review['id'], $dto);

        session()->flash('success', 'Review updated successfully.');
        Response::redirect('/books/' . (int) $review['book_id']);
    }

    /**
     * Delete a review (owner or admin only).
     */
    public function destroy(Request $request, array $params = []): void
    {
        $review = $this->findOrFail((int) ($params['id'] ?? 0));
        $this->authorizeOrFail($review, 'delete');

        $this->service->delete((int) $review['id']);

        session()->flash('success', 'Review deleted successfully.');
        Response::redirect('/books/' . (int) $review['book_id']);
    }

    /**
     * The approved reviews of one book with its rating summary -
     * the dedicated /books/{id}/reviews page. Since Phase 7.4 the
     * list is a professional review list: sortable, searchable
     * (within the book), filterable by rating / edited and paginated.
     * The book detail page embeds the very same section; both share
     * the write form and the user's "already reviewed" panel.
     */
    public function bookReviews(Request $request, array $params = []): void
    {
        $bookId = (int) ($params['id'] ?? 0);
        $book   = $this->service->book($bookId);

        if ($book === null) {
            Response::error(404, 'Book not found.');
        }

        $myReview = null;

        if (($userId = auth()?->id()) !== null) {
            $myReview = $this->service->userReview($userId, $bookId);
        }

        $state  = $this->presenter->state($request);
        $state['book_id'] = $bookId;
        $result = $this->service->paginateReviews($state, $state['perPage'], $state['page']);
        $summary = $this->service->ratingSummary($bookId);
        $base    = '/books/' . $bookId . '/reviews';
        $items   = ($userId = auth()?->id()) !== null
            ? $this->service->attachVoteState($result['items'], $userId)
            : $result['items'];

        $this->view('reviews.book', [
            'title'      => 'Reviews of ' . $book['title'],
            'active'     => 'books',
            'book'       => $book,
            'stats'      => ['average' => $summary['average'], 'count' => $summary['count']],
            // Reuse the summary's distribution - one GROUP BY per page.
            'breakdown'  => $this->service->ratingBreakdown($bookId, $summary['distribution']),
            'reviews'    => $items,
            'myReview'   => $myReview,
            'canManage'  => $myReview !== null || auth_is_admin(),
            'toolbar'    => $this->presenter->toolbar($state, $base),
            'pagination' => $this->presenter->pagination($state, $result, $base),
        ]);
    }

    // --- Phase 7.5: community engagement (helpful votes & reports) -------

    /**
     * Mark a review as helpful (POST /reviews/{id}/helpful).
     *
     * The service returns the fresh vote state, which is answered
     * as JSON for the fetch toggle (the button repaints in place)
     * or with a redirect + flash as the no-JS fallback.
     *
     * Rules enforced here: the review exists (404) and the actor
     * may vote (policy: logged in, not the review's owner -> 403);
     * the service rejects self-votes (409) as defence in depth.
     */
    public function helpful(Request $request, array $params = []): void
    {
        $review = $this->findOrFail((int) ($params['id'] ?? 0));

        if (!$this->policy->canVote($review)) {
            Response::error(403, 'You are not allowed to vote on this review.');
        }

        $this->throttle('review_vote');

        $state = $this->service->markHelpful((int) $review['id'], (int) auth()->id());

        $this->answerEngagement($request, ['voted' => $state['voted'], 'count' => $state['count']]);
    }

    /**
     * Remove one's own helpful vote (POST /reviews/{id}/helpful/remove).
     * Idempotent: removing a vote that does not exist is a no-op.
     */
    public function removeHelpful(Request $request, array $params = []): void
    {
        $review = $this->findOrFail((int) ($params['id'] ?? 0));

        if (!$this->policy->canVote($review)) {
            Response::error(403, 'You are not allowed to vote on this review.');
        }

        $this->throttle('review_vote');

        $state = $this->service->removeHelpful((int) $review['id'], (int) auth()->id());

        $this->answerEngagement($request, ['voted' => $state['voted'], 'count' => $state['count']]);
    }

    /**
     * File a report about a review (POST /reviews/{id}/report).
     *
     * The report modal submits via fetch: validation errors come
     * back as 422 with the per-field messages the modal renders
     * inline; a successful report answers 200 with the thank-you
     * message. The no-JS fallback redirects to the book page.
     */
    public function report(Request $request, array $params = []): void
    {
        $review = $this->findOrFail((int) ($params['id'] ?? 0));

        if (!$this->policy->canReport($review)) {
            Response::error(403, 'You are not allowed to report this review.');
        }

        $this->throttle('review_report');

        $data = [
            'reason'      => (string) $request->input('reason'),
            'description' => trim((string) $request->input('description')),
        ];

        $errors = ReportReviewRequest::validate($data)->errors();

        if ($errors !== []) {
            Response::json(['errors' => $errors], 422);

            return;
        }

        try {
            $this->service->reportReview(
                (int) $review['id'],
                (int) auth()->id(),
                $data['reason'],
                $data['description'],
            );
        } catch (ReviewException $e) {
            Response::json(['error' => $e->getMessage()], 409);

            return;
        }

        if ($request->header('X-Requested-With') === 'fetch') {
            Response::json(['message' => 'Thank you. Your report has been submitted.']);

            return;
        }

        session()->flash('success', 'Thank you. Your report has been submitted.');
        Response::redirect('/books/' . (int) $review['book_id']);
    }

    // --- Internals -------------------------------------------------------

    /**
     * The three review form fields, collected from the request.
     *
     * @return array<string, mixed>
     */
    private function reviewInput(Request $request): array
    {
        return [
            'rating' => $request->input('rating'),
            'title'  => (string) $request->input('title'),
            'review' => (string) $request->input('review'),
        ];
    }

    /**
     * The edit form's old values, prefilled from the stored row.
     *
     * @param array<string, mixed> $review The review row
     * @return array<string, mixed>
     */
    private function formValues(array $review): array
    {
        return [
            'rating' => (string) ($review['rating'] ?? 5),
            'title'  => (string) ($review['title'] ?? ''),
            'review' => (string) ($review['review'] ?? ''),
        ];
    }

    /**
     * Fetch a review or answer 404. Response::error() terminates,
     * so the returned row is guaranteed to exist.
     *
     * @return array<string, mixed>
     */
    private function findOrFail(int $reviewId): array
    {
        $review = $this->service->find($reviewId);

        if ($review === null) {
            Response::error(404, 'Review not found.');
        }

        return $review;
    }

    /**
     * The fine authorization gate: owner or admin may proceed,
     * everyone else gets 403.
     *
     * @param array<string, mixed> $review The review row
     * @param string               $action 'edit' | 'delete'
     */
    private function authorizeOrFail(array $review, string $action): void
    {
        $allowed = $action === 'delete'
            ? $this->policy->canDelete($review)
            : $this->policy->canEdit($review);

        if (!$allowed) {
            Response::error(403, 'You are not allowed to ' . $action . ' this review.');
        }
    }

    /**
     * The dual answer of the helpful toggle: JSON when the caller
     * is fetch (the card repaints with the fresh vote state),
     * redirect + flash otherwise.
     *
     * @param array<string, mixed> $payload
     */
    private function answerEngagement(Request $request, array $payload): void
    {
        if ($request->header('X-Requested-With') === 'fetch') {
            Response::json($payload);

            return;
        }

        $message = $payload['voted']
            ? 'Review marked as helpful.'
            : 'Helpful vote removed.';

        session()->flash('success', $message);
        Response::redirect('/reviews');
    }

    /**
     * The write-endpoint throttle (Phase 7.7 security step, the
     * same pattern as RecommendationController).
     *
     * Input:  the bucket name ('review_write' | 'review_vote' |
     *         'review_report')
     * Output: nothing (a request over the limit exits with HTTP 429)
     *
     * The review writes are already login- and CSRF-protected; the
     * throttle caps how often ONE session may perform them, so a
     * single user can never flood the endpoints. The limits live in
     * config/recommendations.php -> security.rate_limit; when the
     * limiter is not wired (tests) or no limit is configured, the
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
