<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\Core\Logger;
use BookSphere\App\DTO\ReviewDTO;
use BookSphere\App\Exceptions\ReviewException;
use BookSphere\App\Models\Book;
use BookSphere\App\Models\Review;
use BookSphere\App\Requests\StoreReviewRequest;

/**
 * ReviewService
 *
 * The business logic of the Reviews & Ratings module (Phase 7.1).
 * Controllers stay thin: they translate the request into a
 * ReviewDTO, ask the policy for permission, and hand the DTO to
 * this service. Every DECISION lives here:
 *
 *     - validation (the field rules of StoreReviewRequest)
 *     - the "book must exist" rule (ReviewException::bookNotFound)
 *     - duplicate prevention - one review per user per book
 *       (ReviewException::duplicateReview, backed by the UNIQUE
 *       index as the last line of defence)
 *     - automatic book sync after every write: average rating and
 *       review count are recomputed from the approved reviews and
 *       stored on books (average_rating / ratings_count), so the
 *       browse page, the book page and the recommendation scores
 *       always read fresh values
 *     - the is_edited flag: set when an update actually changes
 *       content, so views can show "Edited" without a per-request
 *       diff
 *     - exception handling: every failed rule raises a
 *       ReviewException with a meaningful message - SQL errors
 *       never leak to the caller
 *     - logging: every write is logged with the review id, the
 *       user id and the book id (the Logger stamps the timestamp
 *       itself)
 *     - the recommendation hook: a review write changes the
 *       personal signals (rating history), so the user's cached
 *       recommendation shelf is invalidated - the hook Phase 6.3
 *       reserved for this exact moment
 *
 * Phase 7.2: the complete CRUD inventory. The named operations
 * createReview() / updateReview() / deleteReview() / validateReview()
 * are the brief's vocabulary for the store() / update() / delete() /
 * errorsFor() operations above - thin delegations, never a second
 * implementation. The rule reads canUserReview() / userHasReviewed()
 * power the book page's "Write Review" vs "You have already
 * reviewed this book" decision, recalculateBookRating() /
 * recalculateReviewCount() both delegate to the ONE atomic
 * repository statement that refreshes average and count together
 * (see updateBookRatingStats - the two columns can never drift
 * apart), and ratingDistribution() is the prepared read behind the
 * future star-bar display.
 *
 * Future extension hooks (all prepared, none implemented):
 *     - Moderation: the status column (approved | pending |
 *       hidden) already exists; future approve() / reject() /
 *       hide() methods will flip it, and the repository's
 *       aggregates already count approved reviews only.
 *     - Helpful votes: a helpful_votes table + a vote() method
 *       will slot beside store() without touching it.
 *     - Reports: a review_reports table + report() will slot
 *       beside the moderation hooks.
 *     - Notifications: afterCreate()/afterUpdate()/afterDelete()
 *       hooks can be added here when a notification channel
 *       arrives.
 *
 * Dependencies:
 *     - Review model (facade) for the reviews table.
 *     - Book model (facade) for existence checks.
 *     - Logger (optional, defaults to the application log) for the
 *       write audit trail.
 *     - RecommendationService (optional) for the per-user cache
 *       invalidation hook.
 *
 * How it fits inside MVC:
 *     Controller -> ReviewService (rules) -> Review/Book models
 *     -> ReviewRepository/BookRepository (SQL) -> PDO -> SQLite.
 */
final class ReviewService
{
    /** The allowed moderation states (array keys are the stored values). */
    public const STATUSES = [
        'approved' => 'Approved',
        'pending'  => 'Pending review',
        'hidden'   => 'Hidden',
    ];

    /** The maximum title length (mirrors the request rule). */
    public const MAX_TITLE_LENGTH = 120;

    /** The review body bounds (mirror the request rules). */
    public const MIN_REVIEW_LENGTH = 20;
    public const MAX_REVIEW_LENGTH = 2000;

    /** The default page size of the "My Reviews" list. */
    public const DEFAULT_PAGE_SIZE = 10;

    private readonly Logger $logger;

    public function __construct(
        private readonly Review $reviews,
        private readonly Book $books,
        private readonly ?RecommendationService $recommendations = null,
        ?Logger $logger = null,
    ) {
        $this->logger = $logger ?? new Logger(root_path('storage/logs/application.log'));
    }

    // --- Validation ----------------------------------------------------

    /**
     * Validate a submitted review form (rating 1-5, title <= 120,
     * review 20-2000). The pure field rules live in
     * StoreReviewRequest; this method is the service-level entry
     * point so the controller never touches the validator directly.
     *
     * @param array<string, mixed> $data The submitted form values
     * @return array<string, array<int, string>> Field -> error messages
     */
    public function errorsFor(array $data): array
    {
        return StoreReviewRequest::validate($data)->errors();
    }

    /**
     * The validation entry point of the Phase 7.2 CRUD inventory -
     * same rules, same result as errorsFor(): an empty array means
     * the review passes every rule.
     *
     * @param array<string, mixed> $data The submitted form values
     * @return array<string, array<int, string>> Field -> error messages
     */
    public function validateReview(array $data): array
    {
        return $this->errorsFor($data);
    }

    // --- Reads ---------------------------------------------------------

    /**
     * Find a single review.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->reviews->find($id);
    }

    /**
     * Find a book, or null (the controller answers 404).
     *
     * @return array<string, mixed>|null
     */
    public function book(int $bookId): ?array
    {
        return $this->books->findById($bookId);
    }

    /**
     * The approved reviews of one book (newest first).
     *
     * @return array<int, array<string, mixed>>
     */
    public function reviewsForBook(int $bookId, int $limit = 50): array
    {
        return $this->reviews->findByBook($bookId, $limit);
    }

    /**
     * The reviews of one user, newest first ("My Reviews" page).
     *
     * @return array<int, array<string, mixed>>
     */
    public function reviewsByUser(int $userId, int $limit = 50): array
    {
        return $this->reviews->findByUser($userId, $limit);
    }

    /**
     * The most recent approved reviews across the catalogue (the
     * "Recent Reviews" dashboard block of a later sub-phase).
     *
     * @return array<int, array<string, mixed>>
     */
    public function latestReviews(int $limit = 5): array
    {
        return $this->reviews->latest($limit);
    }

    // --- Book integration ----------------------------------------------

    /**
     * The rating summary of a book (Average Rating + Review Count).
     *
     * This is the single source the Book module integration draws
     * from: the values are the denormalized books columns kept in
     * step by every write in this service, so the book show page
     * (Phase 7.2) can render "4.3 / 12 reviews" with one cheap
     * primary-key read.
     *
     * @return array{average: float, count: int}
     */
    public function statsForBook(int $bookId): array
    {
        return $this->reviews->ratingStats($bookId);
    }

    /**
     * The rating distribution of a book over its approved reviews
     * (star -> count). Prepared for the future star-bar display;
     * the backend answers the question today.
     *
     * @return array<int, int> Star rating -> review count (sparse)
     */
    public function ratingDistribution(int $bookId): array
    {
        return $this->reviews->ratingDistribution($bookId);
    }

    // --- Rule reads (Phase 7.2) -----------------------------------------

    /**
     * Whether the user has already reviewed the book (the book
     * page's "You have already reviewed this book." decision).
     *
     * Being logged in is the policy's job (canReview); this method
     * answers the second half of the one-review rule.
     */
    public function userHasReviewed(int $userId, int $bookId): bool
    {
        return $this->reviews->exists($userId, $bookId);
    }

    /**
     * Whether a user MAY review a book: they are allowed to write
     * exactly one review, so "may" means "has not reviewed it yet".
     */
    public function canUserReview(int $userId, int $bookId): bool
    {
        return !$this->userHasReviewed($userId, $bookId);
    }

    /**
     * The signed-in user's own review of one book, with the book
     * title and reviewer name attached (used by the book detail
     * page and the "already reviewed" panel).
     *
     * @return array<string, mixed>|null
     */
    public function userReview(int $userId, int $bookId): ?array
    {
        return $this->reviews->findByUserAndBook($userId, $bookId);
    }

    // --- Book statistics maintenance (Phase 7.2) ------------------------

    /**
     * Recompute a book's average rating from its approved reviews.
     *
     * Delegates to the repository's single atomic statement
     * (updateBookRatingStats) that refreshes the average AND the
     * count together in one UPDATE - so a call here is also always
     * a correct review count. Both names exist so callers can
     * express the intent the brief asks for; the SQL is never
     * duplicated.
     */
    public function recalculateBookRating(int $bookId): void
    {
        $this->reviews->updateBookRatingStats($bookId);
    }

    /**
     * Recompute a book's review count from its approved reviews -
     * same atomic statement as recalculateBookRating().
     */
    public function recalculateReviewCount(int $bookId): void
    {
        $this->reviews->updateBookRatingStats($bookId);
    }

    // --- Writes ---------------------------------------------------------

    /**
     * Create a review.
     *
     * Business rules enforced here:
     *     1. the book must exist (and not be soft-deleted)
     *     2. the user must not have reviewed it yet
     *     3. the row is stored with status 'approved' and is_edited 0
     *
     * After the insert:
     *     - the book's average_rating / ratings_count are
     *       recomputed (one UPDATE, both columns)
     *     - the author's cached recommendation shelf is invalidated
     *       (their rating signals changed)
     *     - the event is logged with user id and book id
     *
     * @return int The id of the new review
     * @throws ReviewException When the book is missing or the user
     *                         already reviewed it
     */
    public function store(ReviewDTO $dto): int
    {
        $this->assertBookExists($dto->bookId);
        $this->assertNotDuplicate($dto->userId, $dto->bookId);

        $id = $this->reviews->create([
            'book_id' => $dto->bookId,
            'user_id' => $dto->userId,
            'rating'  => $dto->rating,
            'title'   => $dto->title,
            'review'  => $dto->review,
            'status'  => 'approved',
        ]);

        $this->afterWrite($dto->bookId, $dto->userId, 'review.created', $id);

        return $id;
    }

    /**
     * Create a review - the CRUD inventory name for store() (the
     * Phase 7.2 brief's vocabulary; one implementation only).
     *
     * @throws ReviewException When the book is missing or the user
     *                         already reviewed it
     */
    public function createReview(ReviewDTO $dto): int
    {
        return $this->store($dto);
    }

    /**
     * Update a review's content.
     *
     * The review is fetched by id (missing rows raise
     * ReviewException::reviewNotFound - the authorization gate is
     * the ReviewPolicy's job, checked by the controller before this
     * call). When the submitted content actually differs from the
     * stored values, is_edited is set to 1 so the UI can show
     * "Edited"; a save without changes leaves the flag untouched.
     *
     * After the update the book stats are recomputed, the author's
     * recommendation cache is invalidated and the event is logged.
     *
     * @throws ReviewException When the review does not exist
     */
    public function update(int $reviewId, ReviewDTO $dto): bool
    {
        $review = $this->requireReview($reviewId);

        $changed = (int) $dto->rating !== (int) ($review['rating'] ?? 0)
            || $dto->title !== $review['title']
            || $dto->review !== $review['review'];

        $updated = $this->reviews->update($reviewId, [
            'rating'    => $dto->rating,
            'title'     => $dto->title,
            'review'    => $dto->review,
            'is_edited' => $changed ? 1 : (int) ($review['is_edited'] ?? 0),
        ]);

        $this->afterWrite((int) $review['book_id'], (int) $review['user_id'], 'review.updated', $reviewId);

        return $updated;
    }

    /**
     * Update a review - the CRUD inventory name for update().
     *
     * @throws ReviewException When the review does not exist
     */
    public function updateReview(int $reviewId, ReviewDTO $dto): bool
    {
        return $this->update($reviewId, $dto);
    }

    /**
     * Delete a review.
     *
     * The row is hard-deleted; the book's average_rating /
     * ratings_count are recomputed, the author's recommendation
     * cache is invalidated and the event is logged.
     *
     * @throws ReviewException When the review does not exist
     */
    public function delete(int $reviewId): bool
    {
        $review = $this->requireReview($reviewId);

        $deleted = $this->reviews->delete($reviewId);

        if ($deleted) {
            $this->afterWrite((int) $review['book_id'], (int) $review['user_id'], 'review.deleted', $reviewId);
        }

        return $deleted;
    }

    /**
     * Delete a review - the CRUD inventory name for delete().
     *
     * @throws ReviewException When the review does not exist
     */
    public function deleteReview(int $reviewId): bool
    {
        return $this->delete($reviewId);
    }

    // --- Internals ------------------------------------------------------

    /**
     * The book behind a review must exist (and not be soft-deleted).
     */
    private function assertBookExists(?int $bookId): void
    {
        if ($bookId === null || $this->books->findById($bookId) === null) {
            throw ReviewException::bookNotFound((int) $bookId);
        }
    }

    /**
     * A user may review a book only once.
     */
    private function assertNotDuplicate(?int $userId, ?int $bookId): void
    {
        if ($userId !== null && $bookId !== null && $this->reviews->exists($userId, $bookId)) {
            throw ReviewException::duplicateReview($userId, $bookId);
        }
    }

    /**
     * Fetch a review or raise the not-found exception.
     *
     * @return array<string, mixed>
     */
    private function requireReview(int $reviewId): array
    {
        $review = $this->reviews->find($reviewId);

        if ($review === null) {
            throw ReviewException::reviewNotFound($reviewId);
        }

        return $review;
    }

    /**
     * The shared post-write pipeline of every review write:
     *
     *     1. recompute the book's average_rating / ratings_count
     *     2. invalidate the author's cached recommendation shelf
     *        (a rating change alters their personal signals)
     *     3. log the event with the review id, the user id and the
     *        book id (the Logger stamps the timestamp)
     *
     * @param string $event 'review.created' | 'review.updated' | 'review.deleted'
     */
    private function afterWrite(int $bookId, int $userId, string $event, ?int $reviewId = null): void
    {
        $this->reviews->updateBookRatingStats($bookId);

        if ($this->recommendations !== null) {
            $this->recommendations->invalidatePersonalization($userId);
        }

        $this->logger->info($event, [
            'review_id' => $reviewId,
            'user_id'   => $userId,
            'book_id'   => $bookId,
        ]);
    }
}
