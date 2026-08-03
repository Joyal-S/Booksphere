<?php

declare(strict_types=1);

namespace BookSphere\App\Models;

use BookSphere\App\Repositories\BookRepository;

/**
 * Book
 *
 * Purpose:
 *     The domain representation of a Book and the public API of
 *     the Book module's data layer. It holds the book ENTITY
 *     (the columns of the books table, e.g. id, title, isbn,
 *     status) and every reusable lookup the rest of the
 *     application needs.
 *
 * Why it exists:
 *     - The BookService and the views talk to this model instead
 *       of the database directly, giving them one predictable
 *       interface ({@brief find, paginate, create, update, ...}).
 *     - All SQL lives in BookRepository; this class is a THIN
 *       FACADE: each method simply forwards to the repository.
 *       That keeps models clean, swap-friendly and easy to test.
 *
 * Dependencies:
 *     - BookRepository (the actual PDO/prepared-statement SQL).
 *     - db() helper behind the repository (Core\Database).
 *
 * How it fits inside MVC:
 *     Controller -> Service (business rules) -> Book (facade)
 *     -> BookRepository (SQL) -> PDO -> SQLite.
 *
 * Relationships (established via the junction tables in the
 * database, all many-to-many / one-to-many):
 *     - books 1---n book_authors       -> many authors
 *     - books 1---n book_categories    -> many categories
 *     - books 1---n reviews
 *     - books 1---n wishlist
 *     - books 1---n recommendations
 *     - books 1---n notifications      (future)
 */
final class Book
{
    /**
     * The entity's columns, documented here so the whole team
     * agrees on the canonical book shape. Kept as values in the
     * row arrays returned by the repository; not a property list
     * of this class (the project returns plain associative arrays
     * from the database).
     *
     * id              INTEGER PRIMARY KEY
     * google_book_id  TEXT UNIQUE   (Google Books import, later)
     * isbn            TEXT UNIQUE
     * title           TEXT NOT NULL
     * subtitle        TEXT
     * description     TEXT
     * publisher       TEXT
     * published_year  INTEGER
     * language        TEXT DEFAULT 'en'
     * page_count      INTEGER
     * cover_image     TEXT           (local path or remote URL)
     * average_rating  REAL DEFAULT 0 (denormalized from reviews)
     * ratings_count   INTEGER DEFAULT 0
     * status          TEXT DEFAULT 'published' (draft|published|archived)
     * created_at      TEXT
     * updated_at      TEXT
     * deleted_at      TEXT           (soft delete timestamp)
     */

    public function __construct(private readonly BookRepository $repository = new BookRepository()) {}

    /**
     * Find a single book by id.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->repository->findById($id);
    }

    /**
     * Find one book including its related authors and categories
     * (relation arrays attached as 'authors' and 'categories').
     *
     * @return array<string, mixed>|null
     */
    public function findWithRelations(int $id): ?array
    {
        return $this->repository->findWithRelations($id);
    }

    /**
     * Search, filter, sort and paginate the active catalogue.
     *
     * Delegates to the repository with sanitized options.
     *
     * @param array<string, mixed> $options pagination/filter options
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function browse(array $options): array
    {
        return $this->repository->browse($options);
    }

    /**
     * The distinct values of one whitelisted column, used to fill
     * the publisher filter dropdown.
     *
     * @return array<int, mixed>
     */
    public function distinct(string $column): array
    {
        return $this->repository->distinct($column);
    }

    /**
     * Create a new book row and return its id.
     *
     * @param array<string, mixed> $data normalized column values
     */
    public function create(array $data): int
    {
        return $this->repository->create($data);
    }

    /**
     * Update an existing book row.
     *
     * @param array<string, mixed> $data normalized column values
     */
    public function update(int $id, array $data): bool
    {
        return $this->repository->update($id, $data);
    }

    /**
     * Soft delete a book (mark deleted_at; the row stays).
     */
    public function softDelete(int $id): bool
    {
        return $this->repository->softDelete($id);
    }

    /**
     * Replace the full author selection of a book.
     *
     * @param array<int, int> $authorIds
     */
    public function replaceAuthors(int $bookId, array $authorIds): void
    {
        $this->repository->replaceAuthors($bookId, $authorIds);
    }

    /**
     * Replace the full category selection of a book.
     *
     * @param array<int, int> $categoryIds
     */
    public function replaceCategories(int $bookId, array $categoryIds): void
    {
        $this->repository->replaceCategories($bookId, $categoryIds);
    }

    /**
     * Whether an ISBN is already taken by another active book.
     *
     * @param int|null $exceptId The book being edited
     */
    public function isbnExists(string $isbn, ?int $exceptId = null): bool
    {
        return $this->repository->isbnExists($isbn, $exceptId);
    }
}