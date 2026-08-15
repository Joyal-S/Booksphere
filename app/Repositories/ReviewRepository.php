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
     * join instead of N+1 lookups - and the review's helpful-vote
     * count (Phase 7.5), so every card shows a truthful number
     * without any extra query.
     */
    private const SELECT = 'r.*,
        u.full_name AS user_name,
        b.title     AS book_title,
        (SELECT COUNT(*) FROM review_helpful_votes hv WHERE hv.review_id = r.id) AS helpful_count';

    /**
     * The moderation statuses of the reviews table (the stored
     * values of ReviewService::STATUSES) plus the review_reports
     * default - the single spelling every SQL string here uses, so
     * a future moderation feature can never leave a stray literal
     * behind (the Phase 7.7 status-literal audit).
     */
    private const STATUS_APPROVED = 'approved';
    private const STATUS_HIDDEN   = 'hidden';
    private const STATUS_PENDING  = 'pending';

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
     * Flip a review's moderation status (approved | pending |
     * hidden) - the single write behind the Phase 7.5 admin hide /
     * unhide actions. The allowed values are validated by the
     * service (ReviewService::STATUSES).
     */
    public function updateStatus(int $id, string $status): bool
    {
        return db()->execute(
            'UPDATE reviews SET status = ?, updated_at = ? WHERE id = ?',
            [$status, $this->now(), $id],
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
     * Phase 13.1 (security audit): a review that is NOT approved is
     * only visible to its own author or to an admin - everyone else
     * gets null, so a hidden or pending review can never be read
     * through a guessed sequential id.
     *
     * @param int  $id           The review id
     * @param int  $actorId      The logged-in user id (0 = guest)
     * @param bool $actorIsAdmin Whether the actor is an admin
     * @return array<string, mixed>|null The review row, or null
     */
    public function find(int $id, int $actorId = 0, bool $actorIsAdmin = false): ?array
    {
        if ($actorIsAdmin) {
            $where  = 'WHERE r.id = ?';
            $params = [$id];
        } else {
            $where  = $actorId > 0
                ? 'WHERE r.id = ? AND (r.status = \'' . self::STATUS_APPROVED . '\' OR r.user_id = ?)'
                : 'WHERE r.id = ? AND r.status = \'' . self::STATUS_APPROVED . '\'';
            $params = $actorId > 0 ? [$id, $actorId] : [$id];
        }

        $rows = db()->query(
            'SELECT r.*
             FROM reviews r
             ' . $where,
            $params,
        );

        return $rows[0] ?? null;
    }

    /**
     * Internal write-path fetch: the review row by primary key,
     * regardless of moderation state.
     *
     * Used only by ReviewService::requireReview() AFTER the caller
     * has already passed the public read gate (find()), so a write
     * flow keeps working on the author's own pending/hidden rows.
     * Public reads must always use find() - moderation states stay
     * invisible to everyone but the author and admins.
     *
     * @return array<string, mixed>|null The review row, or null
     */
    public function findAny(int $id): ?array
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
             WHERE r.book_id = ? AND r.status = \'' . self::STATUS_APPROVED . '\'
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
             WHERE book_id = ? AND status = \'' . self::STATUS_APPROVED . '\'',
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
             WHERE book_id = ? AND status = \'' . self::STATUS_APPROVED . '\'',
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
             WHERE book_id = ? AND status = \'' . self::STATUS_APPROVED . '\'
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
             WHERE r.status = \'' . self::STATUS_APPROVED . '\'
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
             WHERE r.status = \'' . self::STATUS_APPROVED . '\'
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
             WHERE r.status = \'' . self::STATUS_APPROVED . '\'
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
             WHERE r.status = \'' . self::STATUS_APPROVED . '\'
             ORDER BY r.rating ASC, r.created_at DESC
             LIMIT ?',
            [$limit],
        );
    }

    /**
     * Only the approved reviews (model scope "approved") - newest
     * first, the exact same query as latestReviews(): one SQL
     * implementation, two names (the scope vocabulary and the
     * Phase 7.4 "recent reviews" read).
     *
     * @return array<int, array<string, mixed>>
     */
    public function approved(int $limit = 10): array
    {
        return $this->latestReviews($limit);
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
                     WHERE r.book_id = ? AND r.status = \'' . self::STATUS_APPROVED . '\'
                 ), 0),
                 ratings_count = (
                     SELECT COUNT(*)
                     FROM reviews r
                     WHERE r.book_id = ? AND r.status = \'' . self::STATUS_APPROVED . '\'
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

    // --- Phase 7.3: rating analytics (SQL aggregation) ------------------

    /**
     * The overall average rating across the WHOLE catalogue, over
     * approved reviews only.
     *
     * @return float|null The catalogue average, or null when no
     *                    approved review exists anywhere
     */
    public function overallAverage(): ?float
    {
        $rows = db()->query(
            'SELECT AVG(rating) AS average
             FROM reviews
             WHERE status = \'' . self::STATUS_APPROVED . '\'',
        );

        $average = $rows[0]['average'] ?? null;

        return $average === null ? null : (float) $average;
    }

    /**
     * The overall rating DISTRIBUTION across the whole catalogue:
     * how many approved reviews carry each star (5 down to 1). This
     * powers the "Distribution" panel of the admin analytics.
     *
     * @return array<int, int> Star rating -> review count
     */
    public function overallDistribution(): array
    {
        $rows = db()->query(
            'SELECT rating, COUNT(*) AS count
             FROM reviews
             WHERE status = \'' . self::STATUS_APPROVED . '\'
             GROUP BY rating',
        );

        $distribution = [];

        foreach ($rows as $row) {
            $distribution[(int) $row['rating']] = (int) $row['count'];
        }

        return $this->normalizeDistribution($distribution);
    }

    /**
     * The highest-rated books by REAL approved review activity
     * (admin analytics): books that received at least one approved
     * review, ordered by the aggregated average then the aggregated
     * count. The aggregation is computed from the reviews table
     * itself - never from the seeded sample columns - so the admin
     * page always shows the truth.
     *
     * @return array<int, array<string, mixed>> Rows with id, title,
     *                                          cover_image, average,
     *                                          count
     */
    public function highestRatedBooks(int $limit = 5): array
    {
        return $this->topRatedBooksQuery('DESC', $limit);
    }

    /**
     * The lowest-rated books by real approved review activity
     * (admin analytics). Only books WITH at least one approved
     * review appear here - never the untouched ones.
     *
     * @return array<int, array<string, mixed>>
     */
    public function lowestRatedBooks(int $limit = 5): array
    {
        return $this->topRatedBooksQuery('ASC', $limit);
    }

    /**
     * The shared SQL behind highestRatedBooks() / lowestRatedBooks()
     * and the Phase 7.6 topRatedBooks() / categoryStatistics() reads:
     * one aggregation over approved reviews joined to the book row,
     * ordered by the requested direction. Only books with at least
     * one approved review qualify, so unrated books can never pollute
     * the "rated" lists.
     *
     * Phase 7.6: an optional $categoryId narrows the aggregation to
     * one category (the category page's Top Rated shelf). The join
     * introduces a second link row per book, so the review count is
     * COUNT(DISTINCT r.id) - the average is unaffected by the join
     * (the same rating values are repeated), but the count must be
     * deduplicated to stay truthful.
     *
     * @return array<int, array<string, mixed>>
     */
    private function topRatedBooksQuery(string $direction, int $limit, ?int $categoryId = null): array
    {
        $params = [];

        $join = '';

        if ($categoryId !== null) {
            $join    = ' JOIN book_categories bc ON bc.book_id = b.id AND bc.category_id = ?';
            $params[] = $categoryId;
        }

        $params[] = $limit;

        return db()->query(
            'SELECT b.id,
                    b.title,
                    b.cover_image,
                    AVG(r.rating)     AS average,
                    COUNT(DISTINCT r.id) AS count
             FROM reviews r
             JOIN books b ON b.id = r.book_id' . $join . '
             WHERE r.status = \'' . self::STATUS_APPROVED . '\' AND b.deleted_at IS NULL
             GROUP BY b.id
             HAVING COUNT(DISTINCT r.id) > 0
             ORDER BY average ' . ($direction === 'ASC' ? 'ASC' : 'DESC') . ', count DESC, b.title ASC
             LIMIT ?',
            $params,
        );
    }

    /**
     * The books that have received NO approved review yet (admin
     * analytics: the "Books Without Ratings" list). The books table
     * sample columns are ignored - the reviews table is the judge.
     *
     * @return array<int, array<string, mixed>> Rows with id, title,
     *                                          cover_image
     */
    public function booksWithoutRatings(int $limit = 10): array
    {
        return db()->query(
            'SELECT b.id, b.title, b.cover_image
             FROM books b
             WHERE b.deleted_at IS NULL
               AND NOT EXISTS (
                   SELECT 1
                   FROM reviews r
                   WHERE r.book_id = b.id AND r.status = \'' . self::STATUS_APPROVED . '\'
               )
             ORDER BY b.title ASC
             LIMIT ?',
            [$limit],
        );
    }

    /**
     * The average rating per category over approved reviews (admin
     * analytics: "Average Ratings by Category"). One aggregation
     * across the book_categories junction, ordered by average then
     * count, so the strongest-reviewed categories lead.
     *
     * @return array<int, array<string, mixed>> Rows with name,
     *                                          average, count
     */
    public function categoryAverage(): array
    {
        return db()->query(
            'SELECT c.id,
                    c.name,
                    AVG(r.rating) AS average,
                    COUNT(r.id)   AS count
             FROM reviews r
             JOIN book_categories bc ON bc.book_id = r.book_id
             JOIN categories c       ON c.id = bc.category_id
             WHERE r.status = \'' . self::STATUS_APPROVED . '\'
             GROUP BY c.id
             ORDER BY average DESC, count DESC, c.name ASC',
        );
    }

    /**
     * The rating profile of one user (the "My rating activity"
     * block of the profile page): how many approved reviews they
     * wrote, what their average given rating is, which book they
     * rated highest and what their most recent rating was.
     *
     * @return array<string, mixed> average (float|null), count,
     *                              highest (title|null),
     *                              latest (title|null, rating|null,
     *                              created_at|null)
     */
    public function userRatingStats(int $userId): array
    {
        $stats = db()->query(
            'SELECT COUNT(r.id)        AS count,
                    AVG(r.rating)      AS average
             FROM reviews r
             WHERE r.user_id = ? AND r.status = \'' . self::STATUS_APPROVED . '\'',
            [$userId],
        )[0];

        $highest = db()->query(
            'SELECT b.title
             FROM reviews r
             JOIN books b ON b.id = r.book_id
             WHERE r.user_id = ? AND r.status = \'' . self::STATUS_APPROVED . '\'
             ORDER BY r.rating DESC, r.created_at DESC
             LIMIT 1',
            [$userId],
        );

        $latest = db()->query(
            'SELECT b.title, r.rating, r.created_at
             FROM reviews r
             JOIN books b ON b.id = r.book_id
             WHERE r.user_id = ? AND r.status = \'' . self::STATUS_APPROVED . '\'
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT 1',
            [$userId],
        );

        return [
            'average' => $stats['average'] === null ? null : (float) $stats['average'],
            'count'   => (int) ($stats['count'] ?? 0),
            'highest' => $highest[0]['title'] ?? null,
            'latest'  => [
                'title' => $latest[0]['title'] ?? null,
                'rating' => isset($latest[0]['rating']) ? (int) $latest[0]['rating'] : null,
                'created_at' => $latest[0]['created_at'] ?? null,
            ],
        ];
    }

    // --- Phase 7.5: community engagement (votes, reports, reputation) ---
    //
    // The two engagement tables (review_helpful_votes and
    // review_reports, migration 0015) are owned by this repository
    // exactly like the reviews table: every SQL against them lives
    // here. The rules enforced above the repository are:
    //
    //     - one vote per user per review (also a UNIQUE index)
    //     - a user can remove only their own vote
    //     - a review owner can neither vote nor report their own review
    //       (checked by the service, defended by UNIQUE + policies)
    //     - reports always start 'pending' and the reason must be one
    //       of the six fixed values (CHECK constraint)

    /**
     * Record a helpful vote. INSERT OR IGNORE keeps the UNIQUE
     * (review_id, user_id) constraint a silent no-op for a repeated
     * click, so the vote state can never flip twice.
     */
    public function addHelpfulVote(int $reviewId, int $userId): void
    {
        db()->execute(
            'INSERT OR IGNORE INTO review_helpful_votes (review_id, user_id, created_at)
             VALUES (?, ?, ?)',
            [$reviewId, $userId, $this->now()],
        );
    }

    /**
     * Remove the user's own helpful vote (a no-op when it did not
     * exist - the toggle is idempotent).
     */
    public function removeHelpfulVote(int $reviewId, int $userId): void
    {
        db()->execute(
            'DELETE FROM review_helpful_votes WHERE review_id = ? AND user_id = ?',
            [$reviewId, $userId],
        );
    }

    /**
     * How many helpful votes a review has.
     */
    public function helpfulCount(int $reviewId): int
    {
        $row = db()->query(
            'SELECT COUNT(*) AS count FROM review_helpful_votes WHERE review_id = ?',
            [$reviewId],
        )[0];

        return (int) ($row['count'] ?? 0);
    }

    /**
     * Whether the user already voted on the review.
     */
    public function userHasHelpfulVote(int $reviewId, int $userId): bool
    {
        $row = db()->query(
            'SELECT id FROM review_helpful_votes WHERE review_id = ? AND user_id = ? LIMIT 1',
            [$reviewId, $userId],
        );

        return $row !== [];
    }

    /**
     * The review ids ONE user voted on, among the given review ids.
     *
     * The batched cousin of userHasHelpfulVote(): list rendering
     * asks "which of these rows did the actor vote on?" in ONE query
     * instead of one per row (the review-list N+1 the Phase 7.7
     * audit removed).
     *
     * @param array<int, int> $reviewIds
     * @return array<int, true> Voted review ids as keys
     */
    public function userHelpfulVotes(int $userId, array $reviewIds): array
    {
        $reviewIds = array_values(array_unique(array_map('intval', $reviewIds)));

        if ($reviewIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($reviewIds), '?'));

        $rows = db()->query(
            'SELECT review_id
             FROM review_helpful_votes
             WHERE user_id = ? AND review_id IN (' . $placeholders . ')',
            array_merge([$userId], $reviewIds),
        );

        $voted = [];

        foreach ($rows as $row) {
            $voted[(int) $row['review_id']] = true;
        }

        return $voted;
    }

    /**
     * Insert a report about a review and return its id.
     *
     * @param array<string, mixed> $data Normalized column values:
     *                                   review_id, reported_by, reason,
     *                                   description
     */
    public function createReport(array $data): int
    {
        db()->execute(
            'INSERT INTO review_reports (review_id, reported_by, reason, description, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, \'' . self::STATUS_PENDING . '\', ?, ?)',
            [
                $data['review_id'],
                $data['reported_by'],
                $data['reason'],
                $data['description'],
                $this->now(),
                $this->now(),
            ],
        );

        return (int) db()->lastInsertId();
    }

    /**
     * Whether the user already filed a report on the review (the
     * one-report-per-user-per-review rule, checked by the service
     * before createReport()).
     */
    public function userReportedReview(int $userId, int $reviewId): bool
    {
        $row = db()->query(
            'SELECT id FROM review_reports WHERE reported_by = ? AND review_id = ? LIMIT 1',
            [$userId, $reviewId],
        );

        return $row !== [];
    }

    /**
     * Find one report by id.
     *
     * @return array<string, mixed>|null
     */
    public function findReport(int $reportId): ?array
    {
        $rows = db()->query(
            'SELECT * FROM review_reports WHERE id = ? LIMIT 1',
            [$reportId],
        );

        return $rows[0] ?? null;
    }

    /**
     * Move a report along its lifecycle (pending -> reviewed ->
     * resolved / dismissed). The allowed values are validated by
     * the service; the database CHECK constraint is the last line
     * of defence.
     */
    public function updateReportStatus(int $reportId, string $status): bool
    {
        return db()->execute(
            'UPDATE review_reports SET status = ?, updated_at = ? WHERE id = ?',
            [$status, $this->now(), $reportId],
        ) > 0;
    }

    /**
     * Every report filed about one review, newest first, with the
     * reporter's name joined in (the review's own "reported"
     * indicator and the admin detail screens both read this).
     */
    public function reviewReports(int $reviewId): array
    {
        return db()->query(
            'SELECT p.*, u.full_name AS reporter_name
             FROM review_reports p
             JOIN users u ON u.id = p.reported_by
             WHERE p.review_id = ?
             ORDER BY p.created_at DESC, p.id DESC',
            [$reviewId],
        );
    }

    /**
     * The moderation queue by lifecycle status (the admin page's
     * Pending / Reviewed / Dismissed / Resolved tabs): the reports
     * of one status joined with the reported review, its book title
     * and the reviewer name, newest report first.
     */
    public function reportsByStatus(string $status, int $limit = 50): array
    {
        return db()->query(
            'SELECT p.id            AS report_id,
                    p.reason,
                    p.description,
                    p.status,
                    p.created_at     AS reported_at,
                    p.updated_at     AS handled_at,
                    p.reported_by,
                    r.id             AS review_id,
                    r.title          AS review_title,
                    r.rating,
                    r.status         AS review_status,
                    u.full_name      AS reporter_name,
                    v.full_name      AS reviewer_name,
                    b.title          AS book_title,
                    b.id             AS book_id
             FROM review_reports p
             JOIN reviews r ON r.id = p.review_id
             JOIN users   u ON u.id = p.reported_by
             JOIN users   v ON v.id = r.user_id
             JOIN books   b ON b.id = r.book_id
             WHERE p.status = ?
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT ' . max(1, (int) $limit),
            [$status],
        );
    }

    /**
     * The open moderation queue: the pending slice of
     * reportsByStatus() (the tab the admin lands on).
     */
    public function pendingReports(int $limit = 50): array
    {
        return $this->reportsByStatus(self::STATUS_PENDING, $limit);
    }

    /**
     * The currently hidden reviews (the admin page's "Hidden" tab),
     * with their book and author context.
     */
    public function hiddenReviews(int $limit = 50): array
    {
        return db()->query(
            'SELECT r.id, r.title, r.rating, r.status,
                    r.created_at, r.updated_at,
                    u.full_name AS user_name,
                    b.title     AS book_title,
                    b.id        AS book_id
             FROM reviews r
             JOIN users u ON u.id = r.user_id
             JOIN books b ON b.id = r.book_id
             WHERE r.status = \'' . self::STATUS_HIDDEN . '\'
             ORDER BY r.updated_at DESC, r.id DESC
             LIMIT ' . max(1, (int) $limit),
        );
    }

    /**
     * The moderation overview numbers: total reports, counts per
     * status, counts per reason, and how many distinct reviews are
     * currently hidden.
     *
     * @return array<string, mixed>
     */
    public function reportStatistics(): array
    {
        $statuses = db()->query(
            'SELECT status, COUNT(*) AS count FROM review_reports GROUP BY status',
        );

        $reasons = db()->query(
            'SELECT reason, COUNT(*) AS count FROM review_reports GROUP BY reason',
        );

        $hidden = db()->query(
            'SELECT COUNT(*) AS count FROM reviews WHERE status = \'' . self::STATUS_HIDDEN . '\'',
        )[0];

        $total = db()->query(
            'SELECT COUNT(*) AS count FROM review_reports',
        )[0];

        return [
            'total'     => (int) ($total['count'] ?? 0),
            'statuses'  => $statuses,
            'reasons'   => $reasons,
            'hidden'    => (int) ($hidden['count'] ?? 0),
        ];
    }

    /**
     * The one-book community panel of the book details page: total
     * approved reviews, the helpful votes those reviews received, the
     * average rating, and the three spotlight rows (most helpful,
     * newest, highest rated) - each with its own scalar query so a
     * failing subquery can never take the whole panel down.
     *
     * @return array<string, mixed>
     */
    public function communityStats(int $bookId): array
    {
        $totals = db()->query(
            'SELECT COUNT(r.id) AS reviews,
                    COALESCE(SUM((SELECT COUNT(*) FROM review_helpful_votes hv WHERE hv.review_id = r.id)), 0) AS helpful
             FROM reviews r
             WHERE r.book_id = ? AND r.status = \'' . self::STATUS_APPROVED . '\'',
            [$bookId],
        )[0];

        return [
            'totalReviews'   => (int) ($totals['reviews'] ?? 0),
            'helpfulVotes'   => (int) ($totals['helpful'] ?? 0),
            'averageRating'  => $this->averageRating($bookId),
            'mostHelpful'    => $this->bookSpotlight($bookId, 'helpful'),
            'newest'         => $this->bookSpotlight($bookId, 'newest'),
            'highestRated'   => $this->bookSpotlight($bookId, 'rating'),
        ];
    }

    /**
     * The ONE spotlight review of a book: the same select shape with
     * an allowlisted ORDER BY (the "most helpful" / "newest" /
     * "highest rated" trio of the community panel - the ORDER BY key
     * comes from an internal call site, never from a caller string).
     *
     * @return array<string, mixed>|null
     */
    private function bookSpotlight(int $bookId, string $order): ?array
    {
        $orders = [
            'helpful' => '(SELECT COUNT(*) FROM review_helpful_votes hv WHERE hv.review_id = r.id) DESC, r.created_at DESC',
            'newest'  => 'r.created_at DESC, r.id DESC',
            'rating'  => 'r.rating DESC, r.created_at DESC',
        ];

        $rows = db()->query(
            'SELECT r.id, r.title, r.rating, r.review, r.created_at, u.full_name AS user_name
             FROM reviews r
             JOIN users u ON u.id = r.user_id
             WHERE r.book_id = ? AND r.status = \'' . self::STATUS_APPROVED . '\'
             ORDER BY ' . ($orders[$order] ?? $orders['newest']) . '
             LIMIT 1',
            [$bookId],
        );

        return $rows[0] ?? null;
    }

    /**
     * The reputation snapshot shown on a user's profile: how many
     * helpful votes the user's approved reviews received in total,
     * how many reviews they wrote, and their most-helpful review.
     * Badge tiers (Verified / Top Reviewer / Expert) are a Phase 7.6
     * concern - this read only feeds the Helpful Score, as scoped.
     *
     * @return array<string, mixed>
     */
    public function reviewReputation(int $userId): array
    {
        $totals = db()->query(
            'SELECT COUNT(r.id) AS reviews,
                    COALESCE(SUM((SELECT COUNT(*) FROM review_helpful_votes hv WHERE hv.review_id = r.id)), 0) AS helpful
             FROM reviews r
             WHERE r.user_id = ? AND r.status = \'' . self::STATUS_APPROVED . '\'',
            [$userId],
        )[0];

        $mostHelpful = db()->query(
            'SELECT r.id, r.title, r.rating, r.review, r.created_at, b.title AS book_title, b.id AS book_id
             FROM reviews r
             JOIN books b ON b.id = r.book_id
             WHERE r.user_id = ? AND r.status = \'' . self::STATUS_APPROVED . '\'
             ORDER BY (SELECT COUNT(*) FROM review_helpful_votes hv WHERE hv.review_id = r.id) DESC, r.created_at DESC
             LIMIT 1',
            [$userId],
        );

        return [
            'helpfulReceived' => (int) ($totals['helpful'] ?? 0),
            'reviewsWritten'  => (int) ($totals['reviews'] ?? 0),
            'mostHelpful'     => $mostHelpful[0] ?? null,
        ];
    }

    // --- Phase 7.4: review browsing (search, sort, filters, pages) ------
    //
    // The four methods below power every professional review list of
    // the module (book page, book reviews page, My Reviews, community
    // search, statistics timeline and the per-user page). They share
    // ONE query builder (where()) so the WHERE rules can never drift
    // between the list and the aggregate reads: whatever the list
    // shows, the statistics count exactly the same rows.
    //
    // Sorting lives in the SQL ORDER BY (allowlisted below - the sort
    // key from the URL is a column of this map or it is 'newest', so
    // no caller-controlled text ever reaches the SQL text).

    /**
     * The allowed sort keys mapped to their ORDER BY clauses.
     *
     * "relevant" is the temporary Phase 7.4 ordering (rating first,
     * newest wins the ties) until the Phase 7.5 relevance algorithm
     * supplies a real "Most relevant" sort.
     */
    private const SORTS = [
        'newest'   => 'r.created_at DESC, r.id DESC',
        'oldest'   => 'r.created_at ASC, r.id ASC',
        'highest'  => 'r.rating DESC, r.created_at DESC',
        'lowest'   => 'r.rating ASC, r.created_at DESC',
        'relevant' => 'r.rating DESC, r.created_at DESC',
    ];

    /**
     * The normalized sort key for the given input. Unknown keys fall
     * back to 'newest', so an arbitrary sort parameter can never
     * inject SQL or crash the query.
     */
    public function sort(string $sort): string
    {
        return array_key_exists($sort, self::SORTS) ? $sort : 'newest';
    }

    /**
     * The paginated review list: one composed query that applies the
     * search term, the filters (book / user / rating / edited), the
     * sort order and the page window in one SELECT, plus one COUNT
     * over the very same WHERE so the pager always agrees with the
     * list.
     *
     * @param array<string, mixed> $options book_id (int), user_id
     *                                      (int), rating (1-5),
     *                                      edited (bool), q (string),
     *                                      sort (string)
     * @return array{items: array, total: int, page: int, perPage: int,
     *               pages: int}
     */
    public function paginate(array $options = [], int $perPage = 10, int $page = 1): array
    {
        [$where, $params] = $this->where($options);

        $total = (int) (db()->query(
            'SELECT COUNT(r.id) AS count
             FROM reviews r
             JOIN users u ON u.id = r.user_id
             JOIN books b ON b.id = r.book_id' . $where,
            $params,
        )[0]['count'] ?? 0);

        $perPage = max(1, $perPage);
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min((int) $page, $pages));

        $items = db()->query(
            'SELECT ' . self::SELECT . '
             FROM reviews r
             JOIN users u ON u.id = r.user_id
             JOIN books b ON b.id = r.book_id' . $where . '
             ORDER BY ' . self::SORTS[$this->sort((string) ($options['sort'] ?? 'newest'))] . '
             LIMIT ? OFFSET ?',
            [...$params, $perPage, ($page - 1) * $perPage],
        );

        return [
            'items'   => $items,
            'total'   => $total,
            'page'    => $page,
            'perPage' => $perPage,
            'pages'   => $pages,
        ];
    }

    /**
     * The server-side review search: the same paginated list as
     * paginate(), with the keyword applied to the review title, the
     * review body and the reviewer's name (a LIKE over the joined
     * users table - one query, no PHP-side filtering).
     *
     * @return array{items: array, total: int, page: int, perPage: int,
     *               pages: int}
     */
    public function search(string $q, array $options = [], int $perPage = 10, int $page = 1): array
    {
        return $this->paginate(array_merge($options, ['q' => $q]), $perPage, $page);
    }

    /**
     * The review statistics over exactly the rows the list query
     * filters: total count, average, highest and lowest rating, the
     * most recent review date and the star distribution (5 down to
     * 1, missing stars filled with 0). One aggregate query + one
     * GROUP BY, both sharing the list's WHERE builder.
     *
     * @param array<string, mixed> $options Same filters as paginate()
     * @return array{total: int, average: ?float, highest: ?int,
     *               lowest: ?int, latest: ?string, distribution: array}
     */
    public function statistics(array $options = []): array
    {
        [$where, $params] = $this->where($options);

        $base = 'FROM reviews r
                 JOIN users u ON u.id = r.user_id
                 JOIN books b ON b.id = r.book_id' . $where;

        $aggregate = db()->query(
            'SELECT COUNT(r.id)  AS total,
                    AVG(r.rating)  AS average,
                    MAX(r.rating)  AS highest,
                    MIN(r.rating)  AS lowest,
                    MAX(r.created_at) AS latest ' . $base,
            $params,
        )[0];

        $rows = db()->query(
            'SELECT r.rating, COUNT(*) AS count ' . $base . '
             GROUP BY r.rating',
            $params,
        );

        $distribution = [];

        foreach ($rows as $row) {
            $distribution[(int) $row['rating']] = (int) $row['count'];
        }

        return [
            'total'        => (int) ($aggregate['total'] ?? 0),
            'average'      => $aggregate['average'] === null ? null : (float) $aggregate['average'],
            'highest'      => $aggregate['highest'] === null ? null : (int) $aggregate['highest'],
            'lowest'       => $aggregate['lowest'] === null ? null : (int) $aggregate['lowest'],
            'latest'       => $aggregate['latest'] === null ? null : (string) $aggregate['latest'],
            'distribution' => $this->normalizeDistribution($distribution),
        ];
    }

    /**
     * The paginated reviews of ONE user ("My Reviews" and the public
     * per-user page) - a fixed user filter on top of the same
     * paginated list.
     *
     * @return array{items: array, total: int, page: int, perPage: int,
     *               pages: int}
     */
    public function userReviews(int $userId, array $options = [], int $perPage = 10, int $page = 1): array
    {
        return $this->paginate(array_merge($options, ['user_id' => $userId]), $perPage, $page);
    }

    /**
     * The shared WHERE builder of every Phase 7.4 list and aggregate
     * read. Every condition value is a prepared parameter; the only
     * non-parameter fragment is the hard-coded status literal.
     *
     * The status rule: a list scoped to a user shows all of that
     * user's reviews to the SAME user (their own rows, matching the
     * existing findByUser() behaviour) and to admins; a visitor
     * asking for someone else's list sees only 'approved' rows.
     * Every community read only counts 'approved' reviews, so
     * moderation states can never leak into public lists or
     * statistics.
     *
     * @param array<string, mixed> $options
     * @return array{0: string, 1: array} The WHERE fragment (with the
     *                                    leading ' WHERE ') and its
     *                                    bound parameters
     */
    private function where(array $options): array
    {
        $conditions = ['1 = 1'];
        $params     = [];

        $bookId = (int) ($options['book_id'] ?? 0);

        if ($bookId > 0) {
            $conditions[] = 'r.book_id = ?';
            $params[]     = $bookId;
        }

        $userId     = (int) ($options['user_id'] ?? 0);
        $actorId    = (int) ($options['actor_id'] ?? 0);
        $actorAdmin = !empty($options['actor_is_admin']);

        if ($userId > 0) {
            $conditions[] = 'r.user_id = ?';
            $params[]     = $userId;

            // Phase 13.1 (security audit): the full (unfiltered)
            // view of a user's reviews is an OWNER/ADMIN privilege.
            if (!$actorAdmin && $actorId !== $userId) {
                $conditions[] = 'r.status = \'' . self::STATUS_APPROVED . '\'';
            }
        } else {
            $conditions[] = 'r.status = \'' . self::STATUS_APPROVED . '\'';
        }

        $rating = (int) ($options['rating'] ?? 0);

        if ($rating >= 1 && $rating <= 5) {
            $conditions[] = 'r.rating = ?';
            $params[]     = $rating;
        }

        if (!empty($options['edited'])) {
            $conditions[] = 'r.is_edited = 1';
        }

        $q = trim((string) ($options['q'] ?? ''));

        if ($q !== '') {
            $conditions[] = '(r.title LIKE ? OR r.review LIKE ? OR u.full_name LIKE ?)';
            $like         = '%' . $q . '%';
            array_push($params, $like, $like, $like);
        }

        return [' WHERE ' . implode(' AND ', $conditions), $params];
    }

    // --- Phase 7.6: cross-platform ratings integration -------------------
    //
    // The aggregation reads behind every "Reviews & Ratings across the
    // platform" surface: the dashboard shelves (top rated, most
    // reviewed), the author and category pages (statistics, top
    // reviewers), the enriched user profile and the extended admin
    // analytics. Every query keeps the two house rules: approved
    // reviews only, and soft-deleted books never appear (deleted_at
    // IS NULL).
    //
    // The multi-entity reads (a review can belong to several
    // categories, a book to several authors) use EXISTS subqueries
    // instead of JOINs whenever a JOIN would duplicate rows, and
    // COUNT(DISTINCT ...) wherever a join is unavoidable - so the
    // numbers can never inflate.

    /**
     * The highest-rated books across the catalogue (Phase 7.6 name
     * of the aggregation behind the admin's "highest rated" and the
     * dashboard's "Top Rated" shelf). The admin read
     * (highestRatedBooks) delegates to this public method - one SQL
     * implementation, two names.
     *
     * @return array<int, array<string, mixed>> Rows with id, title,
     *                                          cover_image, average,
     *                                          count
     */
    public function topRatedBooks(int $limit = 5): array
    {
        return $this->topRatedBooksQuery('DESC', $limit);
    }

    /**
     * The most-reviewed books across the catalogue: books with at
     * least one approved review, ordered by review count first (the
     * "community favourites" of the dashboard and the category page).
     *
     * @return array<int, array<string, mixed>> Rows with id, title,
     *                                          cover_image, average,
     *                                          count
     */
    public function mostReviewedBooks(int $limit = 5): array
    {
        return db()->query(
            'SELECT b.id,
                    b.title,
                    b.cover_image,
                    AVG(r.rating) AS average,
                    COUNT(r.id)   AS count
             FROM reviews r
             JOIN books b ON b.id = r.book_id
             WHERE r.status = \'' . self::STATUS_APPROVED . '\' AND b.deleted_at IS NULL
             GROUP BY b.id
             HAVING COUNT(r.id) > 0
             ORDER BY count DESC, average DESC, b.title ASC
             LIMIT ?',
            [$limit],
        );
    }

    /**
     * The average rating per author over approved reviews (the
     * author pages and the admin analytics). One aggregation across
     * the book_authors junction, ordered by average then count.
     *
     * @return array<int, array<string, mixed>> Rows with id, name,
     *                                          average, count
     */
    public function authorAverage(): array
    {
        return db()->query(
            'SELECT a.id,
                    a.name,
                    AVG(r.rating)       AS average,
                    COUNT(DISTINCT r.id) AS count
             FROM reviews r
             JOIN books b ON b.id = r.book_id
             JOIN book_authors ba ON ba.book_id = b.id
             JOIN authors a       ON a.id = ba.author_id
             WHERE r.status = \'' . self::STATUS_APPROVED . '\' AND b.deleted_at IS NULL
             GROUP BY a.id
             ORDER BY average DESC, count DESC, a.name ASC',
        );
    }

    /**
     * The categories with the most approved reviews (the admin
     * analytics "Most reviewed categories").
     *
     * @return array<int, array<string, mixed>> Rows with id, name,
     *                                          count, average
     */
    public function mostReviewedCategories(int $limit = 5): array
    {
        return db()->query(
            'SELECT c.id,
                    c.name,
                    COUNT(DISTINCT r.id) AS count,
                    AVG(r.rating)        AS average
             FROM reviews r
             JOIN books b ON b.id = r.book_id
             JOIN book_categories bc ON bc.book_id = b.id
             JOIN categories c       ON c.id = bc.category_id
             WHERE r.status = \'' . self::STATUS_APPROVED . '\' AND b.deleted_at IS NULL
             GROUP BY c.id
             ORDER BY count DESC, c.name ASC
             LIMIT ?',
            [$limit],
        );
    }

    /**
     * The most active reviewers of the platform: users ordered by
     * their approved review count, with the average rating they give
     * and the helpful votes their approved reviews received (the
     * admin analytics and the author page's "Top reviewers"). The
     * platform rule applies: reviews of soft-deleted books never
     * count.
     *
     * @return array<int, array<string, mixed>> Rows with id,
     *                                          user_name, count,
     *                                          average, helpful
     */
    public function mostActiveReviewers(int $limit = 5): array
    {
        return db()->query(
            'SELECT u.id,
                    u.full_name AS user_name,
                    COUNT(r.id) AS count,
                    AVG(r.rating) AS average,
                    COALESCE((
                        SELECT COUNT(*)
                        FROM review_helpful_votes hv
                        JOIN reviews vr ON vr.id = hv.review_id
                        JOIN books vb ON vb.id = vr.book_id
                        WHERE vr.user_id = u.id AND vr.status = \'' . self::STATUS_APPROVED . '\'
                          AND vb.deleted_at IS NULL
                    ), 0) AS helpful
             FROM reviews r
             JOIN users u ON u.id = r.user_id
             JOIN books b ON b.id = r.book_id
             WHERE r.status = \'' . self::STATUS_APPROVED . '\' AND b.deleted_at IS NULL
             GROUP BY u.id
             ORDER BY count DESC, u.full_name ASC
             LIMIT ?',
            [$limit],
        );
    }

    /**
     * The complete platform-wide rating summary (the admin
     * analytics payload): total approved reviews, the catalogue
     * average, the count of distinct active reviewers, how many
     * books have no approved review yet, the highest / lowest rated
     * books, the most active reviewers, the most reviewed
     * categories, the per-category averages and the per-author
     * averages - composed from the aggregates above in one call.
     *
     * @return array<string, mixed>
     */
    public function platformStatistics(): array
    {
        $totals = db()->query(
            'SELECT COUNT(r.id)          AS total_reviews,
                    COALESCE(AVG(r.rating), 0) AS average,
                    COUNT(DISTINCT r.user_id)  AS active_reviewers
             FROM reviews r
             JOIN books b ON b.id = r.book_id
             WHERE r.status = \'' . self::STATUS_APPROVED . '\' AND b.deleted_at IS NULL',
        )[0];

        $without = db()->query(
            'SELECT COUNT(*) AS count
             FROM books b
             WHERE b.deleted_at IS NULL
               AND NOT EXISTS (
                   SELECT 1
                   FROM reviews r
                   WHERE r.book_id = b.id AND r.status = \'' . self::STATUS_APPROVED . '\'
               )',
        )[0];

        return [
            'totalReviews'          => (int) ($totals['total_reviews'] ?? 0),
            'average'               => (float) ($totals['average'] ?? 0),
            'activeReviewers'       => (int) ($totals['active_reviewers'] ?? 0),
            'booksWithoutReviews'   => (int) ($without['count'] ?? 0),
            'highestRated'          => $this->topRatedBooks(5),
            'lowestRated'           => $this->topRatedBooksQuery('ASC', 5),
            'mostActiveReviewers'   => $this->mostActiveReviewers(5),
            'mostReviewedCategories'=> $this->mostReviewedCategories(5),
            'categoryAverage'       => $this->categoryAverage(),
            'authorAverage'         => $this->authorAverage(),
        ];
    }

    /**
     * The full rating profile of ONE author (the author page):
     * total approved reviews across their books, how many distinct
     * books were reviewed, the average author rating, their highest
     * rated book, their most reviewed book, the recent community
     * reviews of their books and the top reviewers of those books.
     *
     * The per-book reads join through book_authors; the review list
     * uses EXISTS so a multi-author book never duplicates a row.
     *
     * @return array<string, mixed> reviews (int), booksReviewed
     *                              (int), average (float),
     *                              highestRated (?array),
     *                              mostReviewed (?array),
     *                              recentReviews (array),
     *                              topReviewers (array)
     */
    public function authorStatistics(int $authorId): array
    {
        $overview = db()->query(
            'SELECT COUNT(DISTINCT r.id) AS reviews,
                    COUNT(DISTINCT b.id) AS books_reviewed,
                    COALESCE(AVG(r.rating), 0) AS average
             FROM reviews r
             JOIN books b ON b.id = r.book_id
             JOIN book_authors ba ON ba.book_id = b.id
             WHERE r.status = \'' . self::STATUS_APPROVED . '\'
               AND ba.author_id = ?
               AND b.deleted_at IS NULL',
            [$authorId],
        )[0];

        $highest = $this->authorTopBook($authorId, 'highest');

        $mostReviewed = $this->authorTopBook($authorId, 'most');

        $recent = db()->query(
            'SELECT ' . self::SELECT . ', b.cover_image
             FROM reviews r
             JOIN users u ON u.id = r.user_id
             JOIN books b ON b.id = r.book_id
             WHERE r.status = \'' . self::STATUS_APPROVED . '\'
               AND b.deleted_at IS NULL
               AND EXISTS (
                   SELECT 1
                   FROM book_authors ba
                   WHERE ba.book_id = b.id AND ba.author_id = ?
               )
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT ?',
            [$authorId, 5],
        );

        $reviewers = db()->query(
            'SELECT u.id,
                    u.full_name AS user_name,
                    COUNT(r.id) AS count,
                    AVG(r.rating) AS average
             FROM reviews r
             JOIN users u ON u.id = r.user_id
             JOIN books b ON b.id = r.book_id
             WHERE r.status = \'' . self::STATUS_APPROVED . '\'
               AND b.deleted_at IS NULL
               AND EXISTS (
                   SELECT 1
                   FROM book_authors ba
                   WHERE ba.book_id = b.id AND ba.author_id = ?
               )
             GROUP BY u.id
             ORDER BY count DESC, u.full_name ASC
             LIMIT ?',
            [$authorId, 5],
        );

        return [
            'reviews'        => (int) ($overview['reviews'] ?? 0),
            'booksReviewed'  => (int) ($overview['books_reviewed'] ?? 0),
            'average'        => (float) ($overview['average'] ?? 0),
            'highestRated'   => $highest,
            'mostReviewed'   => $mostReviewed,
            'recentReviews'  => $recent,
            'topReviewers'   => $reviewers,
        ];
    }

    /**
     * The ONE top book of an author's reviewed catalogue: the same
     * aggregation shape with an allowlisted ORDER BY - 'highest'
     * (average first, the "highest rated" card) or 'most' (count
     * first, the "most reviewed" card). The ORDER BY key comes from
     * an internal call site, never from a caller string.
     *
     * @return array<string, mixed>|null
     */
    private function authorTopBook(int $authorId, string $order): ?array
    {
        $orders = [
            'highest' => 'average DESC, count DESC, b.title ASC',
            'most'    => 'count DESC, average DESC, b.title ASC',
        ];

        $rows = db()->query(
            'SELECT b.id, b.title, b.cover_image,
                    AVG(r.rating) AS average,
                    COUNT(r.id)   AS count
             FROM reviews r
             JOIN books b ON b.id = r.book_id
             JOIN book_authors ba ON ba.book_id = b.id
             WHERE r.status = \'' . self::STATUS_APPROVED . '\'
               AND ba.author_id = ?
               AND b.deleted_at IS NULL
             GROUP BY b.id
             HAVING COUNT(r.id) > 0
             ORDER BY ' . ($orders[$order] ?? $orders['highest']) . '
             LIMIT 1',
            [$authorId],
        );

        return $rows[0] ?? null;
    }

    /**
     * The full rating profile of ONE category (the category page):
     * total approved reviews, how many distinct books were
     * reviewed, the average category rating, the top rated books,
     * the most reviewed books, the community favourite (the current
     * top-rated book) and the recent community reviews of the
     * category's books.
     *
     * @return array<string, mixed> reviews (int), booksReviewed
     *                              (int), average (float),
     *                              topRated (array), mostReviewed
     *                              (array), communityFavourite
     *                              (?array), recentReviews (array)
     */
    public function categoryStatistics(int $categoryId): array
    {
        $overview = db()->query(
            'SELECT COUNT(DISTINCT r.id) AS reviews,
                    COUNT(DISTINCT b.id) AS books_reviewed,
                    COALESCE(AVG(r.rating), 0) AS average
             FROM reviews r
             JOIN books b ON b.id = r.book_id
             JOIN book_categories bc ON bc.book_id = b.id
             WHERE r.status = \'' . self::STATUS_APPROVED . '\'
               AND bc.category_id = ?
               AND b.deleted_at IS NULL',
            [$categoryId],
        )[0];

        $recent = db()->query(
            'SELECT ' . self::SELECT . ', b.cover_image
             FROM reviews r
             JOIN users u ON u.id = r.user_id
             JOIN books b ON b.id = r.book_id
             WHERE r.status = \'' . self::STATUS_APPROVED . '\'
               AND b.deleted_at IS NULL
               AND EXISTS (
                   SELECT 1
                   FROM book_categories bc
                   WHERE bc.book_id = b.id AND bc.category_id = ?
               )
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT ?',
            [$categoryId, 5],
        );

        $topRated = $this->topRatedBooksQuery('DESC', 5, $categoryId);

        $mostReviewed = db()->query(
            'SELECT b.id, b.title, b.cover_image,
                    AVG(r.rating) AS average,
                    COUNT(DISTINCT r.id) AS count
             FROM reviews r
             JOIN books b ON b.id = r.book_id
             JOIN book_categories bc ON bc.book_id = b.id AND bc.category_id = ?
             WHERE r.status = \'' . self::STATUS_APPROVED . '\' AND b.deleted_at IS NULL
             GROUP BY b.id
             HAVING COUNT(DISTINCT r.id) > 0
             ORDER BY count DESC, average DESC, b.title ASC
             LIMIT ?',
            [$categoryId, 5],
        );

        return [
            'reviews'           => (int) ($overview['reviews'] ?? 0),
            'booksReviewed'     => (int) ($overview['books_reviewed'] ?? 0),
            'average'           => (float) ($overview['average'] ?? 0),
            'topRated'          => $topRated,
            'mostReviewed'      => $mostReviewed,
            'communityFavourite'=> $topRated[0] ?? null,
            'recentReviews'     => $recent,
        ];
    }

    /**
     * The enriched rating profile of ONE user (the profile page's
     * Phase 7.6 extension): everything userRatingStats() reports
     * plus the user's favourite genres (the categories they review
     * most) and their most-reviewed category.
     *
     * @return array<string, mixed> average, count, highest, latest
     *                              (from userRatingStats), plus
     *                              favouriteCategories (array) and
     *                              mostReviewedCategory (?string)
     */
    public function userStatistics(int $userId): array
    {
        $stats = $this->userRatingStats($userId);

        $favourites = db()->query(
            'SELECT c.id,
                    c.name,
                    COUNT(r.id) AS count
             FROM reviews r
             JOIN books b ON b.id = r.book_id
             JOIN book_categories bc ON bc.book_id = b.id
             JOIN categories c       ON c.id = bc.category_id
             WHERE r.user_id = ? AND r.status = \'' . self::STATUS_APPROVED . '\'
               AND b.deleted_at IS NULL
             GROUP BY c.id
             ORDER BY count DESC, c.name ASC
             LIMIT ?',
            [$userId, 3],
        );

        $stats['favouriteCategories']   = $favourites;
        $stats['mostReviewedCategory']  = $favourites[0]['name'] ?? null;

        return $stats;
    }

    /**
     * The review activity timeline of one user: how many approved
     * reviews they wrote per month, newest month first (the profile
     * page's "Review activity" strip).
     *
     * @return array<int, array<string, mixed>> Rows with month
     *                                          (Y-m) and count
     */
    public function reviewActivityTimeline(int $userId): array
    {
        return db()->query(
            "SELECT strftime('%Y-%m', r.created_at) AS month,
                    COUNT(r.id) AS count
             FROM reviews r
             WHERE r.user_id = ? AND r.status = '" . self::STATUS_APPROVED . "'
             GROUP BY month
             ORDER BY month DESC",
            [$userId],
        );
    }

    /**
     * The user's own highest-rated book, as a book row (the
     * dashboard's "My Highest Rated Book" card): the book they
     * rated highest in a review, with their rating attached.
     *
     * @return array<string, mixed>|null The book row with id,
     *                                   title, cover_image, count,
     *                                   average (the user's own
     *                                   rating), or null when the
     *                                   user has no reviews
     */
    public function userHighestRatedBook(int $userId): ?array
    {
        $rows = db()->query(
            'SELECT b.id,
                    b.title,
                    b.cover_image,
                    r.rating   AS average,
                    b.ratings_count AS count
             FROM reviews r
             JOIN books b ON b.id = r.book_id
             WHERE r.user_id = ? AND r.status = \'' . self::STATUS_APPROVED . '\'
               AND b.deleted_at IS NULL
             ORDER BY r.rating DESC, r.created_at DESC
             LIMIT 1',
            [$userId],
        );

        return $rows[0] ?? null;
    }

    /**
     * The display shape of a star -> count map: every star of the
     * 1..5 range present (missing stars filled with 0), ordered 5
     * down to 1 - the single implementation behind every
     * distribution read, so no caller can ever render a gap.
     *
     * @param array<int, int> $distribution Sparse star -> count
     * @return array<int, int> Full 5..1 map
     */
    private function normalizeDistribution(array $distribution): array
    {
        for ($star = 5; $star >= 1; $star--) {
            $distribution[$star] ??= 0;
        }

        krsort($distribution);

        return $distribution;
    }

    /**
     * Current UTC timestamp in the format the other columns use.
     */
    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}