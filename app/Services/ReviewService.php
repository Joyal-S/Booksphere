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

    /** The maximum title length (the rule's single home is StoreReviewRequest). */
    public const MAX_TITLE_LENGTH = StoreReviewRequest::MAX_TITLE_LENGTH;

    /** The review body bounds (same single home as the title rule). */
    public const MIN_REVIEW_LENGTH = StoreReviewRequest::MIN_REVIEW_LENGTH;
    public const MAX_REVIEW_LENGTH = StoreReviewRequest::MAX_REVIEW_LENGTH;

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

    // --- Phase 7.4: professional review browsing -------------------------
    //
    // The browsing vocabulary of the review interface: sort, search,
    // filters, pagination and statistics. Every method is a thin
    // delegation to the repository - the SQL stays in the data layer
    // and the controllers stay thin - and normalizeListOptions() is
    // the single gate through which every query-string value passes
    // before it reaches a repository query.

    /** The allowed sort keys with their human labels (the sort select). */
    public const SORT_OPTIONS = [
        'newest'   => 'Newest first',
        'oldest'   => 'Oldest first',
        'highest'  => 'Highest rated',
        'lowest'   => 'Lowest rated',
        'relevant' => 'Most relevant',
    ];

    /** The allowed page sizes of the per-page select. */
    public const PER_PAGE_OPTIONS = [10, 20, 50];

    /**
     * The normalized sort key for the given input (allowlist, see
     * ReviewRepository::sort() - unknown keys fall back to 'newest').
     */
    public function sortReviews(string $sort): string
    {
        return $this->reviews->sort($sort);
    }

    /**
     * The single gate for every review-list query string: casts the
     * incoming values (sort, page size, page number, search term,
     * rating filter, edited filter, "mine" flag, book and user
     * scope) into the shape the repository expects. An invalid or
     * missing value becomes the safe default, never a crash.
     *
     * @param array<string, mixed> $input The raw request values
     * @return array<string, mixed> Normalized values with the keys
     *                              sort, perPage, page, q, rating,
     *                              edited, mine, book_id, user_id
     */
    public function normalizeListOptions(array $input): array
    {
        $rating = (int) ($input['rating'] ?? 0);
        $pageSize = (int) ($input['perPage'] ?? self::DEFAULT_PAGE_SIZE);

        return [
            'sort'    => $this->sortReviews((string) ($input['sort'] ?? 'newest')),
            'perPage' => in_array($pageSize, self::PER_PAGE_OPTIONS, true) ? $pageSize : self::DEFAULT_PAGE_SIZE,
            'page'    => max(1, (int) ($input['page'] ?? 1)),
            'q'       => trim((string) ($input['q'] ?? '')),
            'rating'  => $rating >= 1 && $rating <= 5 ? $rating : 0,
            'edited'  => !empty($input['edited']),
            'mine'    => !empty($input['mine']),
            'book_id' => (int) ($input['book_id'] ?? 0),
            'user_id' => (int) ($input['user_id'] ?? 0),
        ];
    }

    /**
     * The paginated review list (search + filters + sort + pages in
     * one repository query).
     *
     * @param array<string, mixed> $options Normalized list options
     * @return array{items: array, total: int, page: int, perPage: int,
     *               pages: int}
     */
    public function paginateReviews(array $options, int $perPage = self::DEFAULT_PAGE_SIZE, int $page = 1): array
    {
        return $this->reviews->paginate($options, $perPage, $page);
    }

    /**
     * The server-side review search: the keyword is applied to the
     * review title, the review body and the reviewer's name inside
     * the SQL, so the result set (and its pagination) is always
     * truthful.
     *
     * @return array{items: array, total: int, page: int, perPage: int,
     *               pages: int}
     */
    public function searchReviews(string $query, array $options = [], int $perPage = self::DEFAULT_PAGE_SIZE, int $page = 1): array
    {
        return $this->reviews->search($query, $options, $perPage, $page);
    }

    /**
     * The review statistics over the filtered rows: total, average,
     * highest, lowest, latest review date and the star distribution.
     *
     * @param array<string, mixed> $options Normalized list options
     * @return array{total: int, average: ?float, highest: ?int,
     *               lowest: ?int, latest: ?string, distribution: array}
     */
    public function reviewStatistics(array $options = []): array
    {
        return $this->reviews->statistics($options);
    }

    /**
     * The paginated reviews of ONE user (the "My Reviews" page and
     * the public /reviews/user/{id} page).
     *
     * @return array{items: array, total: int, page: int, perPage: int,
     *               pages: int}
     */
    public function userReviews(int $userId, array $options = [], int $perPage = self::DEFAULT_PAGE_SIZE, int $page = 1): array
    {
        return $this->reviews->userReviews($userId, $options, $perPage, $page);
    }

    /**
     * The highest-rated approved reviews first (the dashboard's
     * "Highest Rated Reviews" shelf); newest wins the ties.
     *
     * @return array<int, array<string, mixed>>
     */
    public function highestRatedReviews(int $limit = 5): array
    {
        return $this->reviews->highestRated($limit);
    }

    /**
     * The display-ready distribution rows from a statistics payload:
     * one row per star (5 down to 1) with the count and the share of
     * reviews - the same shape as ratingBreakdown(), so the shared
     * distribution partial can render statistics and book pages with
     * one markup.
     *
     * @param array<int, int> $distribution Star -> count (from
     *                                      reviewStatistics())
     * @return array<int, array<string, mixed>> Rows with stars,
     *                                          count, percent, total
     */
    public function distributionBreakdown(array $distribution): array
    {
        $total     = (int) array_sum($distribution);
        $percentages = $this->percentageMap($distribution);

        $breakdown = [];

        for ($star = 5; $star >= 1; $star--) {
            $count = (int) ($distribution[$star] ?? 0);

            $breakdown[] = [
                'stars'   => $star,
                'count'   => $count,
                'percent' => $percentages[$star],
                'total'   => $total,
            ];
        }

        return $breakdown;
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

    // --- Phase 7.3: rating analytics -------------------------------------

    /**
     * The average rating of one book over its approved reviews,
     * computed LIVE by the repository (never the denormalized
     * column, so analytics always reflect the truth).
     *
     * @return float|null The average on the 1-5 scale, or null when
     *                    the book has no approved reviews
     */
    public function calculateAverage(int $bookId): ?float
    {
        return $this->reviews->averageRating($bookId);
    }

    /**
     * The overall average rating across the WHOLE catalogue over
     * approved reviews (the admin analytics headline number).
     */
    public function overallAverage(): ?float
    {
        return $this->reviews->overallAverage();
    }

    /**
     * The overall rating DISTRIBUTION across the whole catalogue:
     * star -> approved review count, 5 down to 1 (the admin
     * analytics distribution bars).
     *
     * @return array<int, int> Star rating -> review count
     */
    public function overallDistribution(): array
    {
        return $this->reviews->overallDistribution();
    }

    /**
     * The rating PERCENTAGES of a book: each star (5 down to 1) as
     * the share of the approved reviews, rounded to whole percents.
     * Stars with no reviews are present with 0, so callers always
     * get the full 1..5 range.
     *
     * @return array<int, int> Star rating -> percent (0-100)
     */
    public function ratingPercentage(int $bookId): array
    {
        return $this->percentageMap($this->ratingDistribution($bookId));
    }

    /**
     * The full rating SUMMARY of a book: the average, the review
     * count and the distribution with percentages, all in one read.
     * This is the single source behind the rating summary panel of
     * the book detail page.
     *
     * The distribution is fetched ONCE: the percentages are derived
     * from the same result in PHP (percentageMap), so the summary
     * never runs the GROUP BY twice for the same book.
     *
     * @return array<string, mixed> average (float), count (int),
     *                              distribution (star -> count),
     *                              percentages (star -> percent)
     */
    public function ratingSummary(int $bookId): array
    {
        $distribution = $this->ratingDistribution($bookId);

        return [
            'average'      => $this->calculateAverage($bookId) ?? 0.0,
            'count'        => $this->reviews->ratingCount($bookId),
            'distribution' => $distribution,
            'percentages'  => $this->percentageMap($distribution),
        ];
    }

    /**
     * The rating BREAKDOWN of a book: the display-ready rows of the
     * animated distribution bars - one row per star (5 down to 1)
     * with the review count and the percentage, so the view only
     * prints what it receives.
     *
     * A caller that already fetched the star distribution (the book
     * detail page gets it inside ratingSummary()) hands it in via
     * $distribution - otherwise the GROUP BY runs here. Reusing it
     * keeps the aggregation at ONE query per book page.
     *
     * @param array<int, int>|null $distribution Star -> review count
     * @return array<int, array<string, mixed>> Rows with stars,
     *                                          count, percent
     */
    public function ratingBreakdown(int $bookId, ?array $distribution = null): array
    {
        $distribution = $distribution ?? $this->ratingDistribution($bookId);
        $total        = (int) array_sum($distribution);
        $percentages  = $this->percentageMap($distribution);

        $breakdown = [];

        for ($star = 5; $star >= 1; $star--) {
            $breakdown[] = [
                'stars'   => $star,
                'count'   => (int) ($distribution[$star] ?? 0),
                'percent' => $percentages[$star],
                'total'   => $total,
            ];
        }

        return $breakdown;
    }

    /**
     * The highest-rated books by real approved review activity.
     *
     * @return array<int, array<string, mixed>>
     */
    public function highestRatedBooks(int $limit = 5): array
    {
        return $this->reviews->highestRatedBooks($limit);
    }

    /**
     * The lowest-rated books by real approved review activity.
     *
     * @return array<int, array<string, mixed>>
     */
    public function lowestRatedBooks(int $limit = 5): array
    {
        return $this->reviews->lowestRatedBooks($limit);
    }

    /**
     * The books that received no approved review yet.
     *
     * @return array<int, array<string, mixed>>
     */
    public function booksWithoutRatings(int $limit = 10): array
    {
        return $this->reviews->booksWithoutRatings($limit);
    }

    /**
     * The average rating per category over approved reviews.
     *
     * @return array<int, array<string, mixed>>
     */
    public function categoryAverage(): array
    {
        return $this->reviews->categoryAverage();
    }

    /**
     * The complete rating analytics payload of the ADMIN dashboard:
     * the catalogue average and review total, the catalogue rating
     * distribution, the highest/lowest rated books, the books
     * without ratings, the most active reviewers, the most reviewed
     * categories, the per-category averages and the per-author
     * averages - composed from the repository aggregates in one
     * call.
     *
     * Phase 7.6: the payload grew from the six Phase 7.3 blocks to
     * the full platform picture (the brief's analytics list). The
     * overallAverage key is shaped as ['average' => float,
     * 'count' => int] so the admin view reads one object; the
     * ReviewService::overallAverage() scalar read stays unchanged
     * for the other callers.
     *
     * @return array<string, mixed>
     */
    public function adminAnalytics(): array
    {
        $platform = $this->reviews->platformStatistics();

        return [
            'overallAverage'        => [
                'average' => $platform['average'],
                'count'   => $platform['totalReviews'],
            ],
            'distribution'          => $this->reviews->overallDistribution(),
            'highestRated'          => $platform['highestRated'],
            'lowestRated'           => $platform['lowestRated'],
            'booksWithoutRatings'   => $this->reviews->booksWithoutRatings(10),
            'categoryAverage'       => $platform['categoryAverage'],
            'totalReviews'          => $platform['totalReviews'],
            'activeReviewers'       => $platform['activeReviewers'],
            'booksWithoutReviews'   => $platform['booksWithoutReviews'],
            'mostActiveReviewers'   => $platform['mostActiveReviewers'],
            'mostReviewedCategories'=> $platform['mostReviewedCategories'],
            'authorAverage'         => $platform['authorAverage'],
        ];
    }

    /**
     * The rating profile of one user (the profile page's "rating
     * activity" block): average rating given, total reviews, the
     * highest-rated book and the latest rating.
     *
     * @return array<string, mixed>
     */
    public function profileStats(int $userId): array
    {
        return $this->reviews->userRatingStats($userId);
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

    // --- Phase 7.5: community engagement (votes, reports, moderation) ---
    //
    // The engagement operations follow the same shape as the CRUD
    // ones: the controller gates on the policy, this service owns
    // the RULES (review exists, no self-vote, no duplicate report)
    // and every state change lands in the repository. Two notes:
    //
    //     - markHelpful() / removeHelpful() return the fresh vote
    //       state so the fetch toggle can repaint the button
    //       without a second round trip.
    //     - hideReview() recomputes the book rating stats: a review
    //       dropping out of the approved set changes the average
    //       every other page reads.

    /** The allowed report statuses (array keys are the stored values). */
    public const REPORT_STATUSES = [
        'pending'   => 'Pending',
        'reviewed'  => 'Reviewed',
        'dismissed' => 'Dismissed',
        'resolved'  => 'Resolved',
    ];

    /**
     * Mark a review as helpful for the user.
     *
     * The call is idempotent (INSERT OR IGNORE): a repeated click
     * cannot double-count. Returns the new vote state.
     *
     * @return array{voted: bool, count: int}
     *
     * @throws ReviewException When the review is missing or the user
     *                         votes on their own review
     */
    public function markHelpful(int $reviewId, int $userId): array
    {
        $review = $this->requireReview($reviewId);

        if ((int) $review['user_id'] === $userId) {
            throw ReviewException::selfVote($reviewId);
        }

        $this->reviews->addHelpfulVote($reviewId, $userId);

        $this->logger->info('review.helpful', [
            'review_id' => $reviewId,
            'user_id'   => $userId,
            'book_id'   => (int) $review['book_id'],
        ]);

        return [
            'voted' => true,
            'count' => $this->reviews->helpfulCount($reviewId),
        ];
    }

    /**
     * Remove the user's helpful vote (the toggle's off state).
     *
     * @return array{voted: bool, count: int}
     *
     * @throws ReviewException When the review is missing
     */
    public function removeHelpful(int $reviewId, int $userId): array
    {
        $review = $this->requireReview($reviewId);

        $this->reviews->removeHelpfulVote($reviewId, $userId);

        $this->logger->info('review.helpful.removed', [
            'review_id' => $reviewId,
            'user_id'   => $userId,
            'book_id'   => (int) $review['book_id'],
        ]);

        return [
            'voted' => false,
            'count' => $this->reviews->helpfulCount($reviewId),
        ];
    }

    /**
     * Whether the user already voted on the review.
     */
    public function hasUserVoted(int $reviewId, int $userId): bool
    {
        return $this->reviews->userHasHelpfulVote($reviewId, $userId);
    }

    /**
     * How many helpful votes the review received.
     */
    public function helpfulCount(int $reviewId): int
    {
        return $this->reviews->helpfulCount($reviewId);
    }

    /**
     * Attach the current user's vote state to review rows so the
     * cards can repaint their Helpful buttons truthfully: the
     * review's helpful_count already travels inside every list row
     * (the shared SELECT), this only adds the per-actor facts -
     * whether THEY voted and whether the review is THEIRS (owners
     * cannot vote on or report their own review).
     *
     * @param array<int, array<string, mixed>> $reviews
     * @return array<int, array<string, mixed>>
     */
    public function attachVoteState(array $reviews, int $userId): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (array $review): int => (int) ($review['id'] ?? 0),
            $reviews,
        )));

        $voted = $this->reviews->userHelpfulVotes($userId, $ids);

        foreach ($reviews as &$review) {
            $reviewId                = (int) ($review['id'] ?? 0);
            $review['helpful_count'] = (int) ($review['helpful_count'] ?? $this->reviews->helpfulCount($reviewId));
            $review['helpful_voted'] = isset($voted[$reviewId]);
            $review['is_owner']      = (int) ($review['user_id'] ?? 0) === $userId;
        }
        unset($review);

        return $reviews;
    }

    /**
     * File a report about a review.
     *
     * @throws ReviewException When the review is missing, the user
     *                         reports their own review, or the user
     *                         already reported it (one report per
     *                         user per review)
     */
    public function reportReview(int $reviewId, int $reportedBy, string $reason, string $description): int
    {
        $review = $this->requireReview($reviewId);

        if ((int) $review['user_id'] === $reportedBy) {
            throw ReviewException::selfReport($reviewId);
        }

        if ($this->reviews->userReportedReview($reportedBy, $reviewId)) {
            throw ReviewException::alreadyReported($reportedBy, $reviewId);
        }

        $reportId = $this->reviews->createReport([
            'review_id'   => $reviewId,
            'reported_by' => $reportedBy,
            'reason'      => $reason,
            'description' => $description,
        ]);

        $this->logger->info('review.reported', [
            'review_id'   => $reviewId,
            'reported_by' => $reportedBy,
            'book_id'     => (int) $review['book_id'],
            'reason'      => $reason,
            'report_id'   => $reportId,
        ]);

        return $reportId;
    }

    /**
     * Every report filed about one review, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function reviewReports(int $reviewId): array
    {
        return $this->reviews->reviewReports($reviewId);
    }

    /**
     * The open moderation queue (pending reports with their review,
     * reviewer and book context).
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendingReports(int $limit = 50): array
    {
        return $this->reviews->pendingReports($limit);
    }

    /**
     * The moderation queue filtered by one lifecycle status (the
     * admin page's tabs).
     *
     * @return array<int, array<string, mixed>>
     */
    public function reportsByStatus(string $status, int $limit = 50): array
    {
        return $this->reviews->reportsByStatus($status, $limit);
    }

    /**
     * The currently hidden reviews (the admin page's "Hidden" tab).
     *
     * @return array<int, array<string, mixed>>
     */
    public function hiddenReviews(int $limit = 50): array
    {
        return $this->reviews->hiddenReviews($limit);
    }

    /**
     * The moderation overview numbers (total, per-status counts,
     * per-reason counts, hidden reviews).
     *
     * @return array<string, mixed>
     */
    public function reportStatistics(): array
    {
        return $this->reviews->reportStatistics();
    }

    /**
     * The community panel data of one book (total reviews, helpful
     * votes, average rating, most helpful / newest / highest rated
     * review).
     *
     * @return array<string, mixed>
     */
    public function communityStats(int $bookId): array
    {
        return $this->reviews->communityStats($bookId);
    }

    /**
     * The reputation snapshot of one user (helpful votes received,
     * reviews written, most helpful review). Badge tiers stay a
     * Phase 7.6 concern - this is the Helpful Score only.
     *
     * @return array<string, mixed>
     */
    public function reviewReputation(int $userId): array
    {
        return $this->reviews->reviewReputation($userId);
    }

    /**
     * Hide a review (admin moderation action, Phase 7.5 foundation).
     *
     * The review's status flips to 'hidden' (or back to 'approved'
     * when $hidden is false), the book rating stats are recomputed
     * (the review left or re-entered the approved set) and the
     * event is logged. The moderation decision itself stays the
     * admin's: this method only records it.
     *
     * @throws ReviewException When the review does not exist
     */
    public function hideReview(int $reviewId, bool $hidden = true): bool
    {
        $review = $this->requireReview($reviewId);

        $updated = $this->reviews->updateStatus(
            $reviewId,
            $hidden ? 'hidden' : 'approved',
        );

        if ($updated) {
            $this->reviews->updateBookRatingStats((int) $review['book_id']);

            $this->logger->info($hidden ? 'review.hidden' : 'review.unhidden', [
                'review_id' => $reviewId,
                'book_id'   => (int) $review['book_id'],
            ]);
        }

        return $updated;
    }

    /**
     * Move a report along its lifecycle (admin moderation action).
     * The status is checked against the allowlist, so a caller can
     * never smuggle a free-form string into the column.
     *
     * @throws ReviewException When the report does not exist
     */
    public function updateReportStatus(int $reportId, string $status): bool
    {
        if (!array_key_exists($status, self::REPORT_STATUSES)) {
            throw new ReviewException("Invalid report status: {$status}.");
        }

        $report = $this->reviews->findReport($reportId);

        if ($report === null) {
            throw new ReviewException("Report not found: {$reportId}.");
        }

        return $this->reviews->updateReportStatus($reportId, $status);
    }

    // --- Phase 7.6: reviews & ratings across the whole platform ----------
    //
    // The integration vocabulary the other modules ask for: the
    // dashboard shelves, the author and category pages, the enriched
    // user profile and the extended admin analytics. Every method is
    // a thin delegation to the repository (the SQL stays in the data
    // layer) or a small composition of existing reads - no module
    // ever touches the reviews tables directly.
    //
    // Naming note: latestCommunityReviews() and communityFavorites()
    // are the brief's names for reads the module already exposed as
    // latestReviews() / mostReviewedBooks() - one implementation,
    // two names, so the integration code reads the way the brief
    // describes it.

    /**
     * The most recent approved community reviews (the dashboard's
     * "Recently Reviewed Books" shelf and the category page).
     *
     * @return array<int, array<string, mixed>>
     */
    public function latestCommunityReviews(int $limit = 5): array
    {
        return $this->reviews->latestReviews($limit);
    }

    /**
     * The highest-rated books across the catalogue.
     *
     * @return array<int, array<string, mixed>>
     */
    public function topRatedBooks(int $limit = 5): array
    {
        return $this->reviews->topRatedBooks($limit);
    }

    /**
     * The most-reviewed books across the catalogue.
     *
     * @return array<int, array<string, mixed>>
     */
    public function mostReviewedBooks(int $limit = 5): array
    {
        return $this->reviews->mostReviewedBooks($limit);
    }

    /**
     * The community favourites: the most-reviewed books (the brief's
     * name for the "Community Favourite Books" shelf; one query with
     * mostReviewedBooks()).
     *
     * @return array<int, array<string, mixed>>
     */
    public function communityFavorites(int $limit = 5): array
    {
        return $this->reviews->mostReviewedBooks($limit);
    }

    /**
     * The most active reviewers of the platform (admin analytics and
     * the author page's "Top reviewers").
     *
     * @return array<int, array<string, mixed>>
     */
    public function mostActiveReviewers(int $limit = 5): array
    {
        return $this->reviews->mostActiveReviewers($limit);
    }

    /**
     * The average rating per author over approved reviews (the author
     * directory and the admin analytics).
     *
     * @return array<int, array<string, mixed>>
     */
    public function authorAverage(): array
    {
        return $this->reviews->authorAverage();
    }

    /**
     * The enriched rating profile of one user (Total Reviews,
     * Average Rating Given, Highest Rated Book, Most Reviewed
     * Category and Favourite Genres - the Phase 7.6 profile page).
     *
     * @return array<string, mixed>
     */
    public function userReviewStatistics(int $userId): array
    {
        return $this->reviews->userStatistics($userId);
    }

    /**
     * The user's review activity timeline (approved reviews per
     * month, newest first - the profile page's activity strip).
     *
     * @return array<int, array<string, mixed>>
     */
    public function reviewActivityTimeline(int $userId): array
    {
        return $this->reviews->reviewActivityTimeline($userId);
    }

    /**
     * The user's own highest-rated book, as a renderable book row
     * (the dashboard's "My Highest Rated Book" card).
     *
     * @return array<string, mixed>|null
     */
    public function userHighestRatedBook(int $userId): ?array
    {
        return $this->reviews->userHighestRatedBook($userId);
    }

    /**
     * The full rating profile of one author (author page): average
     * author rating, books reviewed, highest rated book, most
     * reviewed book, recent community reviews and top reviewers.
     *
     * @return array<string, mixed>
     */
    public function authorStatistics(int $authorId): array
    {
        return $this->reviews->authorStatistics($authorId);
    }

    /**
     * The full rating profile of one category (category page): the
     * average category rating, top rated / most reviewed books, the
     * community favourite and the recent community reviews.
     *
     * @return array<string, mixed>
     */
    public function categoryStatistics(int $categoryId): array
    {
        return $this->reviews->categoryStatistics($categoryId);
    }

    /**
     * The platform-wide rating summary (extended admin analytics):
     * total reviews, the catalogue average, active reviewers, books
     * without reviews, highest / lowest rated books, most active
     * reviewers, most reviewed categories, per-category averages
     * and per-author averages.
     *
     * @return array<string, mixed>
     */
    public function platformStatistics(): array
    {
        return $this->reviews->platformStatistics();
    }

    /**
     * The complete dashboard payload of one user, composed in one
     * call: the Top Rated shelf, the Recently Reviewed shelf, the
     * Community Favourite Books, the recent highest-rated community
     * reviews, the user's latest review and their highest rated
     * book.
     *
     * @return array<string, mixed>
     */
    public function dashboardStatistics(int $userId): array
    {
        return [
            'topRated'             => $this->topRatedBooks(4),
            'recentlyReviewed'     => $this->latestReviews(4),
            'communityFavourites'  => $this->communityFavorites(4),
            'recentCommunityReviews' => $this->highestRatedReviews(4),
            'myLatestReview'       => $this->reviewsByUser($userId, 1)[0] ?? null,
            'myHighestRatedBook'   => $this->userHighestRatedBook($userId),
        ];
    }

    /**
     * The review-score weight the recommendation engine uses for a
     * book's community review quality (Phase 7.6 recommendation
     * integration).
     *
     * The engine keeps every weight in config/recommendations.php -
     * this read is the single way the Reviews module learns its own
     * weight, so the recommendation page can show "Review score
     * contributes X%" and the value can never drift from the
     * engine's.
     */
    public function recommendationWeight(): int
    {
        return (int) config('recommendations.hybrid_weights.review_score', 10);
    }

    // --- Internals ------------------------------------------------------

    /**
     * The percentage share of every star (5 down to 1) from a
     * star -> count distribution map, rounded to whole percents.
     * Stars with no reviews are present with 0, so callers always
     * get the full 1..5 range. The single implementation behind
     * ratingPercentage(), distributionBreakdown() and
     * ratingBreakdown() - one formula, three callers.
     *
     * @param array<int, int> $distribution Star -> count
     * @return array<int, int> Star -> percent (0-100)
     */
    private function percentageMap(array $distribution): array
    {
        $total = (int) array_sum($distribution);

        $percentages = [];

        for ($star = 5; $star >= 1; $star--) {
            $count               = (int) ($distribution[$star] ?? 0);
            $percentages[$star]  = $total > 0 ? (int) round($count / $total * 100) : 0;
        }

        return $percentages;
    }

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
