<?php

declare(strict_types=1);

namespace BookSphere\App\Models;

use BookSphere\App\Repositories\ReviewRepository;

/**
 * Review
 *
 * The domain representation of a Review and the public API of the
 * Reviews module's data layer - a THIN FACADE over ReviewRepository,
 * following the exact pattern of the Book model: no business logic,
 * no SQL, just one predictable interface for the service and the
 * views.
 *
 * Entity columns (the reviews table, migration 0007 + 0014):
 *
 *     id         INTEGER PRIMARY KEY
 *     book_id    INTEGER NOT NULL  (FK books.id, ON DELETE CASCADE)
 *     user_id    INTEGER NOT NULL  (FK users.id, ON DELETE CASCADE)
 *     rating     INTEGER NOT NULL CHECK 1-5
 *     title      TEXT    NOT NULL DEFAULT ''      (max 120)
 *     review     TEXT    (20-2000 chars, validated)
 *     status     TEXT    NOT NULL DEFAULT 'approved'
 *                          (approved | pending | hidden - moderation)
 *     is_edited  INTEGER NOT NULL DEFAULT 0
 *     created_at TEXT
 *     updated_at TEXT
 *
 * The project returns plain associative arrays from the database
 * (see the "developer notes" in docs/ARCHITECTURE.md), so "casts"
 * are documented here rather than enforced by a property list:
 * rating and is_edited arrive as integers, status/created_at as
 * strings.
 *
 * Relationships (one-to-many, established by the foreign keys):
 *     reviews n---1 books    (a book has many reviews)
 *     reviews n---1 users    (a user has many reviews)
 *     The relationship METHODS below (book(), user()) resolve the
 *     related row on demand - the project has no lazy-loading
 *     magic, so they are explicit helpers.
 *
 * Query scopes (convenience wrappers over repository reads):
 *     latest() / oldest() / highestRated() / lowestRated() /
 *     approved() - each returns ready-to-render review rows.
 *
 * Dependencies:
 *     - ReviewRepository (the actual PDO/prepared-statement SQL).
 *     - Book + User models (for the relationship lookups).
 *
 * How it fits inside MVC:
 *     Controller -> Service (business rules) -> Review (facade)
 *     -> ReviewRepository (SQL) -> PDO -> SQLite.
 */
final class Review
{
    public function __construct(private readonly ReviewRepository $repository = new ReviewRepository()) {}

    // --- CRUD ---------------------------------------------------------

    /**
     * Find a single review by id.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->repository->find($id);
    }

    /**
     * Find a review regardless of moderation status (for write path re-reads).
     *
     * @return array<string, mixed>|null
     */
    public function findAny(int $id): ?array
    {
        return $this->repository->findAny($id);
    }

    /**
     * Create a new review row and return its id.
     *
     * @param array<string, mixed> $data Normalized column values
     */
    public function create(array $data): int
    {
        return $this->repository->create($data);
    }

    /**
     * Insert a review row - the alias name of create() used by the
     * Phase 7.2 CRUD inventory.
     *
     * @param array<string, mixed> $data Normalized column values
     */
    public function insert(array $data): int
    {
        return $this->repository->insert($data);
    }

    /**
     * Update an existing review row.
     *
     * @param array<string, mixed> $data Normalized column values
     */
    public function update(int $id, array $data): bool
    {
        return $this->repository->update($id, $data);
    }

    /**
     * Delete a review row.
     */
    public function delete(int $id): bool
    {
        return $this->repository->delete($id);
    }

    /**
     * The approved reviews of one book (newest first), with the
     * reviewer's name attached.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByBook(int $bookId, int $limit = 50): array
    {
        return $this->repository->findByBook($bookId, $limit);
    }

    /**
     * The reviews of one user (newest first), with the book title
     * attached.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByUser(int $userId, int $limit = 50): array
    {
        return $this->repository->findByUser($userId, $limit);
    }

    /**
     * Whether a user has already reviewed a book.
     */
    public function exists(int $userId, int $bookId): bool
    {
        return $this->repository->exists($userId, $bookId);
    }

    /**
     * A user's review of ONE book (the book page's write-form /
     * "already reviewed" decision).
     *
     * @return array<string, mixed>|null
     */
    public function findByUserAndBook(int $userId, int $bookId): ?array
    {
        return $this->repository->findByUserAndBook($userId, $bookId);
    }

    /**
     * The per-star review counts of a book (the prepared rating
     * distribution read; UI rendering is a later phase).
     *
     * @return array<int, int> Star rating -> review count (sparse)
     */
    public function ratingDistribution(int $bookId): array
    {
        return $this->repository->ratingDistribution($bookId);
    }

    /**
     * The average rating of a book over its APPROVED reviews,
     * computed live from the reviews table (the truth behind the
     * rating summary panel).
     *
     * @return float|null The average on the 1-5 scale, or null when
     *                    the book has no approved reviews
     */
    public function averageRating(int $bookId): ?float
    {
        return $this->repository->averageRating($bookId);
    }

    /**
     * The number of APPROVED reviews a book received (the count
     * shown next to the rating stars).
     */
    public function ratingCount(int $bookId): int
    {
        return $this->repository->ratingCount($bookId);
    }

    /**
     * Recompute the book's denormalized rating columns (average,
     * count) from its approved reviews - called by the service
     * after every review write.
     */
    public function updateBookRatingStats(int $bookId): void
    {
        $this->repository->updateBookRatingStats($bookId);
    }

    /**
     * Read the stored rating summary of a book.
     *
     * @return array{average: float, count: int}
     */
    public function ratingStats(int $bookId): array
    {
        return $this->repository->ratingStats($bookId);
    }

    // --- Relationships ------------------------------------------------

    /**
     * The book a review belongs to (belongsTo).
     *
     * @param array<string, mixed> $review A review row
     * @return array<string, mixed>|null
     */
    public function book(array $review): ?array
    {
        return (new Book())->findById((int) ($review['book_id'] ?? 0));
    }

    /**
     * The user who wrote a review (belongsTo).
     *
     * @param array<string, mixed> $review A review row
     * @return array<string, mixed>|null
     */
    public function user(array $review): ?array
    {
        return (new User())->findById((int) ($review['user_id'] ?? 0));
    }

    // --- Query scopes -------------------------------------------------

    /**
     * The newest approved reviews (scope: latest).
     *
     * @return array<int, array<string, mixed>>
     */
    public function latest(int $limit = 10): array
    {
        return $this->repository->latest($limit);
    }

    /**
     * The oldest approved reviews (scope: oldest).
     *
     * @return array<int, array<string, mixed>>
     */
    public function oldest(int $limit = 10): array
    {
        return $this->repository->oldest($limit);
    }

    /**
     * The highest-rated approved reviews first (scope: highestRated).
     *
     * @return array<int, array<string, mixed>>
     */
    public function highestRated(int $limit = 10): array
    {
        return $this->repository->highestRated($limit);
    }

    /**
     * The lowest-rated approved reviews first (scope: lowestRated).
     *
     * @return array<int, array<string, mixed>>
     */
    public function lowestRated(int $limit = 10): array
    {
        return $this->repository->lowestRated($limit);
    }

    /**
     * Only the approved reviews (scope: approved).
     *
     * @return array<int, array<string, mixed>>
     */
    public function approved(int $limit = 10): array
    {
        return $this->repository->approved($limit);
    }

    // --- Phase 7.3: rating analytics (thin facade forwards) ------------

    /**
     * The overall average rating across the catalogue (approved
     * reviews only).
     */
    public function overallAverage(): ?float
    {
        return $this->repository->overallAverage();
    }

    /**
     * The overall rating distribution across the catalogue.
     *
     * @return array<int, int> Star rating -> review count
     */
    public function overallDistribution(): array
    {
        return $this->repository->overallDistribution();
    }

    /**
     * The highest-rated books by real approved review activity.
     *
     * @return array<int, array<string, mixed>>
     */
    public function highestRatedBooks(int $limit = 5): array
    {
        return $this->repository->highestRatedBooks($limit);
    }

    /**
     * The lowest-rated books by real approved review activity.
     *
     * @return array<int, array<string, mixed>>
     */
    public function lowestRatedBooks(int $limit = 5): array
    {
        return $this->repository->lowestRatedBooks($limit);
    }

    /**
     * The books without any approved review.
     *
     * @return array<int, array<string, mixed>>
     */
    public function booksWithoutRatings(int $limit = 10): array
    {
        return $this->repository->booksWithoutRatings($limit);
    }

    /**
     * The average rating per category over approved reviews.
     *
     * @return array<int, array<string, mixed>>
     */
    public function categoryAverage(): array
    {
        return $this->repository->categoryAverage();
    }

    /**
     * The rating profile of one user (average given, count, highest
     * rated book, latest rating).
     *
     * @return array<string, mixed>
     */
    public function userRatingStats(int $userId): array
    {
        return $this->repository->userRatingStats($userId);
    }

    // --- Phase 7.4: professional review browsing (thin forwards) ---------

    /**
     * The normalized sort key for the given input (allowlist with a
     * 'newest' fallback - the repository's sort()).
     */
    public function sort(string $sort): string
    {
        return $this->repository->sort($sort);
    }

    /**
     * The paginated review list (search + filters + sort + pages in
     * one query).
     *
     * @return array{items: array, total: int, page: int, perPage: int,
     *               pages: int}
     */
    public function paginate(array $options = [], int $perPage = 10, int $page = 1): array
    {
        return $this->repository->paginate($options, $perPage, $page);
    }

    /**
     * The server-side review search (keyword against title, body and
     * reviewer name).
     *
     * @return array{items: array, total: int, page: int, perPage: int,
     *               pages: int}
     */
    public function search(string $q, array $options = [], int $perPage = 10, int $page = 1): array
    {
        return $this->repository->search($q, $options, $perPage, $page);
    }

    /**
     * The review statistics over the filtered rows (total, average,
     * highest, lowest, latest, distribution).
     *
     * @return array<string, mixed>
     */
    public function statistics(array $options = []): array
    {
        return $this->repository->statistics($options);
    }

    /**
     * The paginated reviews of one user.
     *
     * @return array{items: array, total: int, page: int, perPage: int,
     *               pages: int}
     */
    public function userReviews(int $userId, array $options = [], int $perPage = 10, int $page = 1): array
    {
        return $this->repository->userReviews($userId, $options, $perPage, $page);
    }

    // --- Phase 7.5: community engagement (thin facade forwards) -----------

    /**
     * Record a helpful vote (idempotent).
     */
    public function addHelpfulVote(int $reviewId, int $userId): void
    {
        $this->repository->addHelpfulVote($reviewId, $userId);
    }

    /**
     * Remove the user's own helpful vote.
     */
    public function removeHelpfulVote(int $reviewId, int $userId): void
    {
        $this->repository->removeHelpfulVote($reviewId, $userId);
    }

    /**
     * How many helpful votes a review has.
     */
    public function helpfulCount(int $reviewId): int
    {
        return $this->repository->helpfulCount($reviewId);
    }

    /**
     * Whether the user already voted on the review.
     */
    public function userHasHelpfulVote(int $reviewId, int $userId): bool
    {
        return $this->repository->userHasHelpfulVote($reviewId, $userId);
    }

    /**
     * The review ids one user voted on, among the given review ids
     * (the batched read behind attachVoteState()).
     *
     * @param array<int, int> $reviewIds
     * @return array<int, true>
     */
    public function userHelpfulVotes(int $userId, array $reviewIds): array
    {
        return $this->repository->userHelpfulVotes($userId, $reviewIds);
    }

    /**
     * Insert a report and return its id.
     *
     * @param array<string, mixed> $data review_id, reported_by, reason,
     *                                   description
     */
    public function createReport(array $data): int
    {
        return $this->repository->createReport($data);
    }

    /**
     * Whether the user already reported the review.
     */
    public function userReportedReview(int $userId, int $reviewId): bool
    {
        return $this->repository->userReportedReview($userId, $reviewId);
    }

    /**
     * Every report filed about one review, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function reviewReports(int $reviewId): array
    {
        return $this->repository->reviewReports($reviewId);
    }

    /**
     * The open moderation queue.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendingReports(int $limit = 50): array
    {
        return $this->repository->pendingReports($limit);
    }

    /**
     * The moderation queue by lifecycle status.
     *
     * @return array<int, array<string, mixed>>
     */
    public function reportsByStatus(string $status, int $limit = 50): array
    {
        return $this->repository->reportsByStatus($status, $limit);
    }

    /**
     * The currently hidden reviews.
     *
     * @return array<int, array<string, mixed>>
     */
    public function hiddenReviews(int $limit = 50): array
    {
        return $this->repository->hiddenReviews($limit);
    }

    /**
     * The moderation overview numbers.
     *
     * @return array<string, mixed>
     */
    public function reportStatistics(): array
    {
        return $this->repository->reportStatistics();
    }

    /**
     * The one-book community panel data.
     *
     * @return array<string, mixed>
     */
    public function communityStats(int $bookId): array
    {
        return $this->repository->communityStats($bookId);
    }

    /**
     * The reputation snapshot of one user.
     *
     * @return array<string, mixed>
     */
    public function reviewReputation(int $userId): array
    {
        return $this->repository->reviewReputation($userId);
    }

    /**
     * Flip a review's moderation status.
     */
    public function updateStatus(int $id, string $status): bool
    {
        return $this->repository->updateStatus($id, $status);
    }

    /**
     * Find one report by id.
     *
     * @return array<string, mixed>|null
     */
    public function findReport(int $reportId): ?array
    {
        return $this->repository->findReport($reportId);
    }

    /**
     * Move a report along its lifecycle.
     */
    public function updateReportStatus(int $reportId, string $status): bool
    {
        return $this->repository->updateReportStatus($reportId, $status);
    }

    // --- Phase 7.6: cross-platform ratings integration (forwards) ---------

    /**
     * The highest-rated books across the catalogue.
     *
     * @return array<int, array<string, mixed>>
     */
    public function topRatedBooks(int $limit = 5): array
    {
        return $this->repository->topRatedBooks($limit);
    }

    /**
     * The most-reviewed books across the catalogue.
     *
     * @return array<int, array<string, mixed>>
     */
    public function mostReviewedBooks(int $limit = 5): array
    {
        return $this->repository->mostReviewedBooks($limit);
    }

    /**
     * The average rating per author over approved reviews.
     *
     * @return array<int, array<string, mixed>>
     */
    public function authorAverage(): array
    {
        return $this->repository->authorAverage();
    }

    /**
     * The most active reviewers of the platform.
     *
     * @return array<int, array<string, mixed>>
     */
    public function mostActiveReviewers(int $limit = 5): array
    {
        return $this->repository->mostActiveReviewers($limit);
    }

    /**
     * The platform-wide rating summary.
     *
     * @return array<string, mixed>
     */
    public function platformStatistics(): array
    {
        return $this->repository->platformStatistics();
    }

    /**
     * The full rating profile of one author.
     *
     * @return array<string, mixed>
     */
    public function authorStatistics(int $authorId): array
    {
        return $this->repository->authorStatistics($authorId);
    }

    /**
     * The full rating profile of one category.
     *
     * @return array<string, mixed>
     */
    public function categoryStatistics(int $categoryId): array
    {
        return $this->repository->categoryStatistics($categoryId);
    }

    /**
     * The enriched rating profile of one user (favourite genres
     * included).
     *
     * @return array<string, mixed>
     */
    public function userStatistics(int $userId): array
    {
        return $this->repository->userStatistics($userId);
    }

    /**
     * The review activity timeline of one user (per month).
     *
     * @return array<int, array<string, mixed>>
     */
    public function reviewActivityTimeline(int $userId): array
    {
        return $this->repository->reviewActivityTimeline($userId);
    }

    /**
     * The user's own highest-rated book, as a book row.
     *
     * @return array<string, mixed>|null
     */
    public function userHighestRatedBook(int $userId): ?array
    {
        return $this->repository->userHighestRatedBook($userId);
    }
}
