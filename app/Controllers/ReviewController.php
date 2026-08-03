<?php

declare(strict_types=1);

namespace BookSphere\App\Controllers;

use BookSphere\App\Core\Controller;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\DTO\ReviewDTO;
use BookSphere\App\Exceptions\ReviewException;
use BookSphere\App\Models\User;
use BookSphere\App\Policies\ReviewPolicy;
use BookSphere\App\Services\ReviewService;

/**
 * ReviewController
 *
 * The Reviews & Ratings module (Phase 7.1 backend + Phase 7.2
 * complete CRUD): writing, viewing, editing and deleting reviews,
 * plus the two list pages:
 *
 *     - index       -> "My Reviews" (the signed-in user's reviews)
 *     - show        -> one review's detail page (Phase 7.2)
 *     - bookReviews -> all approved reviews of one book (the page
 *                      behind the /books/{id}/reviews route; the
 *                      book detail page embeds the same data)
 *     - store       -> create a review for a book (POST)
 *     - edit        -> the "edit your review" form
 *     - update      -> save the changes (POST)
 *     - destroy     -> delete a review (POST)
 *
 * The controller stays thin (no SQL, no business logic):
 *     1. it collects request data into a ReviewDTO
 *     2. it asks the ReviewPolicy whether the actor may act
 *     3. it asks the ReviewService to validate and persist
 *     4. it renders a view or redirects with a flash message
 *
 * Route protection: every route sits behind AuthMiddleware (guests
 * can never reach a review action); the fine ownership rules
 * (owner-or-admin) are enforced HERE through the policy, per
 * request, because the route table cannot know who owns a review.
 *
 * Error handling:
 *     - missing book / review      -> 404 (plain, safe message)
 *     - permission denied          -> 403
 *     - duplicate review           -> 409, redirected to the book
 *     - validation errors          -> the form re-renders (edit)
 *                                      or a flash summary (store)
 *     - database / unexpected      -> the ErrorHandler turns them
 *                                      into a generic 500 (logged,
 *                                      never shown to the visitor)
 */
final class ReviewController extends Controller
{
    public function __construct(
        private readonly ReviewService $service,
        private readonly ReviewPolicy $policy,
    ) {}

    /**
     * "My Reviews": every review of the signed-in user, newest
     * first, with the book title and the edit/delete actions.
     */
    public function index(Request $request, array $params = []): void
    {
        $this->view('reviews.index', [
            'title'   => 'My Reviews',
            'active'  => 'reviews',
            'reviews' => $this->service->reviewsByUser((int) auth()->id()),
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
     * the dedicated /books/{id}/reviews page. The book detail page
     * embeds the same data as a section; both include the write
     * form and the user's "already reviewed" panel.
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

        $this->view('reviews.book', [
            'title'    => 'Reviews of ' . $book['title'],
            'active'   => 'books',
            'book'     => $book,
            'stats'    => $this->service->statsForBook($bookId),
            'reviews'  => $this->service->reviewsForBook($bookId),
            'myReview' => $myReview,
            'canManage' => $myReview !== null || auth_is_admin(),
        ]);
    }

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
}
