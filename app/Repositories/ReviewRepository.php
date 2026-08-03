<?php

declare(strict_types=1);

namespace BookSphere\App\Repositories;

/**
 * ReviewRepository
 *
 * The data-access layer of the Reviews & Ratings module (Phase 7.1).
 * Every SQL query that touches the reviews table (and the two
 * denormalized rating columns on books) lives here and here only.
 *
 * Responsibilities:
 *
 *     - CRUD: create / update / delete / find
 *     - Reads: findByBook / findByUser / latestReviews + the model
 *       scope queries (latest, oldest, highestRated, lowestRated,
 *       approved)
 *     - Rules: exists() (one review per user per book), the
 *       averageRating() / ratingCount() aggregates
 *     - Book sync: updateBookRatingStats() keeps the denormalized
 *       books.average_rating / books.ratings_count columns in step
 *       with the reviews table, and ratingStats() reads them back
 *       for display
 *
 * Why the denormalized columns:
 *     - Every browse, search and recommendation query sorts and
 *       filters by books.average_rating; recomputing an AVG in a
 *       subquery for every book on every page would be wasteful.
 *       The books table carries the precomputed values and this
 *       repository is the single place that keeps them fresh.
 *
 * Rules enforced by every query:
 *     - Prepared statements everywhere (the db() helper binds
 *       parameters; no value ever lands in the SQL text).
 *     - The status filter: aggregation and the public reads only
 *       count 'approved' reviews, so the future moderation flow
 *       (pending / hidden statuses) already cannot leak into
 *       averages or public lists.
 *     - Column lists are written explicitly (never SELECT *), so
 *       the columns appended by migration 0014 never disturb the
 *       row shape callers expect.
 *
 * Dependencies:
 *     - db() helper (Core\Database singleton) - the shared PDO
 *       connection, exactly like BookRepository.
 *
 * How it fits inside MVC:
 *     Controller -> Service (business rules) -> Review model (facade)
 *     -> ReviewRepository (SQL) -> PDO -> SQLite.
 */
final class ReviewRepository
{
    /**
     * The base SELECT of every public review read: the full review
     * row plus the two display columns every review list needs
     * (the reviewer's name and the book title), fetched with one
     * join instead of N+1 lookups.
     */
    private const SELECT = 'r.*,
        u.full_name AS user_name,
        b.title     AS book_title';

    /**
     * Insert a new review and return its id.
     *
     * @param array<string, mixed> $data Normalized column values:
     *                                   book_id, user_id, rating,
     *                                   title, review, status
     */
    public function create(array $data): int
    {
        db()->execute(
            'INSERT INTO reviews (book_id, user_id, rating, title, review, status, is_edited, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)',
            [
                $data['book_id'],
                $data['user_id'],
                $data['rating'],
                $data['title'],
                $data['review'],
                $data['status'],
                $this->now(),
                $this->now(),
            ],
        );

        return (int) db()->lastInsertId();
    }

    /**
     * Update a review's content.
     *
     * @param array<string, mixed> $data Normalized column values:
     *                                   rating, title, review,
     *                                   is_edited
     */
    public function update(int $id, array $data): bool
    {
        return db()->execute(
            'UPDATE reviews
             SET rating = ?, title = ?, review = ?, is_edited = ?, updated_at = ?
             WHERE id = ?',
            [
                $data['rating'],
                $data['title'],
                $data['review'],
                $data['is_edited'],
                $this->now(),
                $id,
            ],
        ) > 0;
    }

    /**
     * Hard delete a review (reviews have no soft delete: the row is
     * small and its loss is harmless, unlike book rows that carry
     * covers and junction links).
     */
    public function delete(int $id): bool
    {
        return db()->execute('DELETE FROM reviews WHERE id = ?', [$id]) > 0;
    }

    /**
     * Find a single review by primary key.
     *
     * @return array<string, mixed>|null The review row, or null
     */
    public function find(int $id): ?array
    {
        $rows = db()->query(
            'SELECT r.*
             FROM reviews r
             WHERE r.id = ?',
            [$id],
        );

        return $rows[0] ?? null;
    }

    /**
     * The approved reviews of one book, newest first, with the
     * reviewer's name attached for display.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByBook(int $bookId, int $limit = 50): array
    {
        return db()->query(
            'SELECT ' . self::SELECT . '
             FROM reviews r
             JOIN users u ON u.id = r.user_id
             JOIN books b ON b.id = r.book_id
             WHERE r.book_id = ? AND r.status = \'approved\'
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT ?',
            [$bookId, $limit],
        );
    }

    /**
     * The reviews of one user, newest first, with the book title
     * attached for display ("My Reviews" page).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByUser(int $userId, int $limit = 50): array
    {
        return db()->query(
            'SELECT ' . self::SELECT . '
             FROM reviews r
             JOIN users u ON u.id = r.user_id
             JOIN books b ON b.id = r.book_id
             WHERE r.user_id = ?
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT ?',
            [$userId, $limit],
        );
    }

    /**
     * The signed-in user's review of ONE book (the write-form /
     * "already reviewed" decision on the book detail page).
     *
     * The UNIQUE (user_id, book_id) index guarantees at most one
     * row, so the first hit is the answer.
     *
     * @return array<string, mixed>|null The review row (with the
     *                                   reviewer name and the book
     *                                   title attached), or null
     */
    public function findByUserAndBook(int $userId, int $bookId): ?array
    {
        $rows = db()->query(
            'SELECT ' . self::SELECT . '
             FROM reviews r
             JOIN users u ON u.id = r.user_id
             JOIN books b ON b.id = r.book_id
             WHERE r.user_id = ? AND r.book_id = ?',
            [$userId, $bookId],
        );

        return $rows[0] ?? null;
    }

    /**
     * Insert a review - the name the Phase 7.2 brief gives to the
     * create operation. Same prepared statement, same result: this
     * is an alias, not a second implementation.
     *
     * @param array<string, mixed> $data Normalized column values
     */
    public function insert(array $data): int
    {
        return $this->create($data);
    }

    /**
     * Whether a user has already reviewed a book.
     *
     * This is the service's duplicate-prevention read; the UNIQUE
     * (user_id, book_id) index is the last line of defence should
     * two requests ever race past it.
     */
    public function exists(int $userId, int $bookId): bool
    {
        $rows = db()->query(
            'SELECT id
             FROM reviews
             WHERE user_id = ? AND book_id = ?',
            [$userId, $bookId],
        );

        return $rows !== [];
    }

    /**
     * The average rating of a book over its APPROVED reviews.
     *
     * @return float|null The average (1-5 scale), or null when the
     *                    book has no approved reviews yet
     */
    public function averageRating(int $bookId): ?float
    {
        $rows = db()->query(
            'SELECT AVG(rating) AS average
             FROM reviews
             WHERE book_id = ? AND status = \'approved\'',
            [$bookId],
        );

        $average = $rows[0]['average'] ?? null;

        return $average === null ? null : (float) $average;
    }

    /**
     * The number of APPROVED reviews of a book.
     */
    public function ratingCount(int $bookId): int
    {
        $rows = db()->query(
            'SELECT COUNT(*) AS count
             FROM reviews
             WHERE book_id = ? AND status = \'approved\'',
            [$bookId],
        );

        return (int) ($rows[0]['count'] ?? 0);
    }

    /**
     * The rating distribution of a book over its APPROVED reviews:
     * how many reviews each star rating received.
     *
     * This is the PREPARED method behind the future "5-star bars"
     * display of a later phase - the backend answers the question
     * today with one indexed GROUP BY; the UI work is Phase 7.3+.
     *
     * @return array<int, int> Star rating -> review count. The map
     *                         is sparse: a star that received no
     *                         reviews is absent (callers fill the
     *                         1..5 range when they need bars).
     */
    public function ratingDistribution(int $bookId): array
    {
        $rows = db()->query(
            'SELECT rating, COUNT(*) AS count
             FROM reviews
             WHERE book_id = ? AND status = \'approved\'
             GROUP BY rating',
            [$bookId],
        );

        $distribution = [];

        foreach ($rows as $row) {
            $distribution[(int) $row['rating']] = (int) $row['count'];
        }

        return $distribution;
    }

    /**
     * The most recent approved reviews across the whole catalogue
     * (the future "Recent Reviews" dashboard block).
     *
     * @return array<int, array<string, mixed>>
     */
    public function latestReviews(int $limit = 5): array
    {
        return db()->query(
            'SELECT ' . self::SELECT . '
             FROM reviews r
             JOIN users u ON u.id = r.user_id
             JOIN books b ON b.id = r.book_id
             WHERE r.status = \'approved\'
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT ?',
            [$limit],
        );
    }

    /**
     * The newest approved reviews (model scope "latest").
     *
     * @return array<int, array<string, mixed>>
     */
    public function latest(int $limit = 10): array
    {
        return $this->latestReviews($limit);
    }

    /**
     * The oldest approved reviews (model scope "oldest").
     *
     * @return array<int, array<string, mixed>>
     */
    public function oldest(int $limit = 10): array
    {
        return db()->query(
            'SELECT ' . self::SELECT . '
             FROM reviews r
             JOIN users u ON u.id = r.user_id
             JOIN books b ON b.id = r.book_id
             WHERE r.status = \'approved\'
             ORDER BY r.created_at ASC, r.id ASC
             LIMIT ?',
            [$limit],
        );
    }

    /**
     * The highest-rated approved reviews first (model scope
     * "highestRated"); newest wins the ties.
     *
     * @return array<int, array<string, mixed>>
     */
    public function highestRated(int $limit = 10): array
    {
        return db()->query(
            'SELECT ' . self::SELECT . '
             FROM reviews r
             JOIN users u ON u.id = r.user_id
             JOIN books b ON b.id = r.book_id
             WHERE r.status = \'approved\'
             ORDER BY r.rating DESC, r.created_at DESC
             LIMIT ?',
            [$limit],
        );
    }

    /**
     * The lowest-rated approved reviews first (model scope
     * "lowestRated").
     *
     * @return array<int, array<string, mixed>>
     */
    public function lowestRated(int $limit = 10): array
    {
        return db()->query(
            'SELECT ' . self::SELECT . '
             FROM reviews r
             JOIN users u ON u.id = r.user_id
             JOIN books b ON b.id = r.book_id
             WHERE r.status = \'approved\'
             ORDER BY r.rating ASC, r.created_at DESC
             LIMIT ?',
            [$limit],
        );
    }

    /**
     * Only the approved reviews (model scope "approved").
     *
     * @return array<int, array<string, mixed>>
     */
    public function approved(int $limit = 10): array
    {
        return db()->query(
            'SELECT ' . self::SELECT . '
             FROM reviews r
             JOIN users u ON u.id = r.user_id
             JOIN books b ON b.id = r.book_id
             WHERE r.status = \'approved\'
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT ?',
            [$limit],
        );
    }

    /**
     * Recompute the two denormalized rating columns of a book from
     * its approved reviews, in ONE statement.
     *
     * Called by the service after every review create / update /
     * delete, so the browse page, the show page and the
     * recommendation scores always read fresh values without a
     * per-request AVG.
     *
     * The books.updated_at stamp is refreshed too: the row's rating
     * data really changed, so "Recently updated" sorting should
     * reflect it.
     */
    public function updateBookRatingStats(int $bookId): void
    {
        db()->execute(
            'UPDATE books
             SET average_rating = COALESCE((
                     SELECT AVG(r.rating)
                     FROM reviews r
                     WHERE r.book_id = ? AND r.status = \'approved\'
                 ), 0),
                 ratings_count = (
                     SELECT COUNT(*)
                     FROM reviews r
                     WHERE r.book_id = ? AND r.status = \'approved\'
                 ),
                 updated_at = ?
             WHERE id = ?',
            [$bookId, $bookId, $this->now(), $bookId],
        );
    }

    /**
     * Read the stored rating summary of a book (the denormalized
     * columns maintained by updateBookRatingStats()).
     *
     * This is the single read behind the "Average Rating" /
     * "Review Count" book integration: one indexed primary-key
     * lookup, no aggregation.
     *
     * @return array{average: float, count: int}
     */
    public function ratingStats(int $bookId): array
    {
        $rows = db()->query(
            'SELECT average_rating, ratings_count
             FROM books
             WHERE id = ?',
            [$bookId],
        );

        return [
            'average' => (float) ($rows[0]['average_rating'] ?? 0),
            'count'   => (int) ($rows[0]['ratings_count'] ?? 0),
        ];
    }

    /**
     * Current UTC timestamp in the format the other columns use.
     */
    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
