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
}
