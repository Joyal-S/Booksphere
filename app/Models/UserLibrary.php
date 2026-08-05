<?php

declare(strict_types=1);

namespace BookSphere\App\Models;

use BookSphere\App\Repositories\LibraryRepository;

/**
 * UserLibrary
 *
 * The domain representation of one user's personal library record
 * and the public API of the library module's data layer - a THIN
 * FACADE over LibraryRepository, following the exact pattern of the
 * Review model: no business logic, no SQL, just one predictable
 * interface for the service and the views.
 *
 * Entity columns (the user_library table, migration 0017):
 *
 *     id                  INTEGER PRIMARY KEY
 *     user_id             INTEGER NOT NULL  (FK users.id, ON DELETE CASCADE)
 *     book_id             INTEGER NOT NULL  (FK books.id, ON DELETE CASCADE)
 *     library_status      TEXT    NOT NULL DEFAULT 'want_to_read'
 *                                   (want_to_read | currently_reading |
 *                                    finished | on_hold | dropped)
 *     is_favorite         INTEGER NOT NULL DEFAULT 0
 *     progress_percentage INTEGER NOT NULL DEFAULT 0 (0-100)
 *     started_reading_at  TEXT    (nullable)
 *     finished_reading_at TEXT    (nullable)
 *     created_at / updated_at
 *
 * The project returns plain associative arrays from the database
 * (see the "developer notes" in docs/ARCHITECTURE.md), so "casts"
 * are documented here rather than enforced by a property list:
 * is_favorite arrives as 0/1, progress_percentage as an integer,
 * the timestamps as strings (or null).
 *
 * Relationships (established by the foreign keys):
 *     user_library n---1 users  (a library record belongs to a user)
 *     user_library n---1 books  (a library record belongs to a book)
 *     The relationship METHODS below (book(), user()) resolve the
 *     related row on demand - the project has no lazy-loading magic,
 *     so they are explicit helpers.
 *
 * Query scopes (convenience wrappers over repository reads):
 *     wishlist() / currentlyReading() / finished() / favorites() -
 *     each returns the ready-to-render rows of one shelf.
 *
 * Dependencies:
 *     - LibraryRepository (the actual PDO/prepared-statement SQL).
 *     - Book + User models (for the relationship lookups).
 *
 * How it fits inside MVC:
 *     Controller -> Service (business rules) -> UserLibrary (facade)
 *     -> LibraryRepository (SQL) -> PDO -> SQLite.
 */
final class UserLibrary
{
    public function __construct(private readonly LibraryRepository $repository = new LibraryRepository()) {}

    // --- CRUD ---------------------------------------------------------

    /**
     * Find a single library record by id.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->repository->find($id);
    }

    /**
     * Create a new library record and return its id.
     *
     * @param array<string, mixed> $data Normalized column values
     */
    public function create(array $data): int
    {
        return $this->repository->create($data);
    }

    /**
     * Update an existing library record (partial - only the fields
     * present in $data are written).
     *
     * @param array<string, mixed> $data Subset of the mutable columns
     */
    public function update(int $id, array $data): bool
    {
        return $this->repository->update($id, $data);
    }

    /**
     * Delete a library record.
     */
    public function delete(int $id): bool
    {
        return $this->repository->delete($id);
    }

    /**
     * Every library record of one user, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByUser(int $userId, int $limit = 50): array
    {
        return $this->repository->findByUser($userId, $limit);
    }

    /**
     * The user's single library record for ONE book.
     *
     * @return array<string, mixed>|null
     */
    public function findByBook(int $userId, int $bookId): ?array
    {
        return $this->repository->findByBook($userId, $bookId);
    }

    /**
     * One status shelf of the user (the generic status bucket).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByStatus(int $userId, string $status, int $limit = 50): array
    {
        return $this->repository->findByStatus($userId, $status, $limit);
    }

    /**
     * Search a user's own library by title, author or category.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(int $userId, string $query, int $limit = 50): array
    {
        return $this->repository->search($userId, $query, $limit);
    }

    /**
     * Whether a user already has a record for the book.
     */
    public function exists(int $userId, int $bookId): bool
    {
        return $this->repository->exists($userId, $bookId);
    }

    // --- Relationships ------------------------------------------------

    /**
     * The book a library record belongs to (belongsTo).
     *
     * @param array<string, mixed> $record A library row
     * @return array<string, mixed>|null
     */
    public function book(array $record): ?array
    {
        return (new Book())->findById((int) ($record['book_id'] ?? 0));
    }

    /**
     * The user who owns a library record (belongsTo).
     *
     * @param array<string, mixed> $record A library row
     * @return array<string, mixed>|null
     */
    public function user(array $record): ?array
    {
        return (new User())->findById((int) ($record['user_id'] ?? 0));
    }

    // --- Query scopes -------------------------------------------------

    /**
     * The user's "want to read" shelf (scope: wishlist).
     *
     * @return array<int, array<string, mixed>>
     */
    public function wishlist(int $userId, int $limit = 50): array
    {
        return $this->repository->wishlist($userId, $limit);
    }

    /**
     * The user's "currently reading" shelf (scope: currentlyReading).
     *
     * @return array<int, array<string, mixed>>
     */
    public function currentlyReading(int $userId, int $limit = 50): array
    {
        return $this->repository->currentlyReading($userId, $limit);
    }

    /**
     * The user's "currently reading" shelf under the resume-dashboard
     * name (Phase 8.3) - the same shelf, the same ordering.
     *
     * @return array<int, array<string, mixed>>
     */
    public function continueReading(int $userId, int $limit = 12): array
    {
        return $this->repository->continueReading($userId, $limit);
    }

    /**
     * A page of the user's library grid, filtered and sorted (Phase
     * 8.3). The recognized $filters keys and the $sort spellings are
     * documented on LibraryRepository::filter().
     *
     * @return array<int, array<string, mixed>>
     */
    public function filter(int $userId, array $filters = [], string $sort = 'newest_added', int $offset = 0, int $limit = 50): array
    {
        return $this->repository->filter($userId, $filters, $sort, $offset, $limit);
    }

    /**
     * The total row count behind a filter set (the pagination
     * denominator of the Grid).
     */
    public function countFiltered(int $userId, array $filters = []): int
    {
        return $this->repository->countFiltered($userId, $filters);
    }

    /**
     * The combined pagination answer of the dashboard grid (Phase 8.3).
     *
     * @return array<string, mixed> Keys: items, total, page, pages,
     *                              per_page, has_prev, has_next
     */
    public function paginate(int $userId, array $filters = [], string $sort = 'newest_added', int $page = 1, int $perPage = 12): array
    {
        return $this->repository->paginate($userId, $filters, $sort, $page, $perPage);
    }

    /**
     * The distinct category / author dropdown vocabulary of the
     * user's library (Phase 8.3).
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function filterOptions(int $userId): array
    {
        return $this->repository->filterOptions($userId);
    }

    /**
     * The user's finished books (scope: finished), most recently
     * finished first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function finished(int $userId, int $limit = 50): array
    {
        return $this->repository->finished($userId, $limit);
    }

    /**
     * The user's favourite books (scope: favorites).
     *
     * @return array<int, array<string, mixed>>
     */
    public function favorites(int $userId, int $limit = 50): array
    {
        return $this->repository->favorites($userId, $limit);
    }

    /**
     * The per-user library overview (shelf counts, favourites,
     * average progress).
     *
     * @return array<string, mixed>
     */
    public function statistics(int $userId): array
    {
        return $this->repository->statistics($userId);
    }

    /**
     * The one-composed-call payload of the library dashboard (Phase
     * 8.3): statistics + reading summary + reading streak.
     *
     * @return array<string, mixed>
     */
    public function dashboard(int $userId): array
    {
        return $this->repository->dashboard($userId);
    }

    /**
     * The reading summary statistics of the dashboard (favourite
     * genre / author, average rating given, average progress).
     *
     * @return array<string, mixed>
     */
    public function readingSummary(int $userId): array
    {
        return $this->repository->readingSummary($userId);
    }

    /**
     * The current / longest consecutive-day library-activity streak.
     *
     * @return array<string, int>
     */
    public function readingStreak(int $userId): array
    {
        return $this->repository->readingStreak($userId);
    }

    /**
     * One preference value of the user's dashboard preferences row
     * (library_sort / library_view), or the fallback.
     */
    public function preference(int $userId, string $key, ?string $default = null): ?string
    {
        return $this->repository->preference($userId, $key, $default);
    }

    /**
     * Merge values into the user's dashboard preferences row (an
     * upsert - one row per user).
     *
     * @param array<string, mixed> $values library_sort / library_view
     */
    public function savePreferences(int $userId, array $values): void
    {
        $this->repository->savePreferences($userId, $values);
    }

    /**
     * The genre preferences derived from the user's library (the
     * Phase 8.5 recommendation hook read).
     *
     * @return array<int, array<string, mixed>>
     */
    public function preferredGenres(int $userId, int $limit = 5): array
    {
        return $this->repository->preferredGenres($userId, $limit);
    }

    // --- Phase 8.4: collections, recent activity, bulk writes ----------

    /**
     * The collection statistics of the user's library: the count,
     * average rating and last-updated stamp of every collection
     * ("all", the five shelves, "favorites").
     *
     * @return array<string, array<string, mixed>>
     */
    public function collectionStatistics(int $userId): array
    {
        return $this->repository->collectionStatistics($userId);
    }

    /**
     * The user's most recently added books, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentlyAdded(int $userId, int $limit = 12): array
    {
        return $this->repository->recentlyAdded($userId, $limit);
    }

    /**
     * The user's most recently updated books, most recent first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentlyUpdated(int $userId, int $limit = 12): array
    {
        return $this->repository->recentlyUpdated($userId, $limit);
    }

    /**
     * Move several of the user's records to one shelf (a bulk status
     * update - only the user's own rows are ever touched).
     *
     * @param array<int|string> $ids Record ids
     * @return int The number of records actually moved
     */
    public function bulkStatus(int $userId, array $ids, string $status): int
    {
        return $this->repository->bulkStatus($userId, $ids, $status);
    }

    /**
     * Mark or un-mark several of the user's books as favourites.
     *
     * @param array<int|string> $ids Record ids
     * @param bool $favorite The value to set (true = favourite)
     * @return int The number of records actually updated
     */
    public function bulkFavorite(int $userId, array $ids, bool $favorite): int
    {
        return $this->repository->bulkFavorite($userId, $ids, $favorite);
    }

    /**
     * Remove several of the user's library records.
     *
     * @param array<int|string> $ids Record ids
     * @return int The number of records actually removed
     */
    public function bulkDelete(int $userId, array $ids): int
    {
        return $this->repository->bulkDelete($userId, $ids);
    }
}