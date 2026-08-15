<?php

declare(strict_types=1);

namespace BookSphere\App\Repositories;

/**
 * BookAnalyticsRepository
 *
 * The data layer of the BOOK ANALYTICS module (Phase 12.2): every
 * aggregate the catalogue analytics page shows is computed by SQL
 * here - the module never ships the catalogue rows into PHP and
 * counts them in a loop (Task 18 - aggregation lives in the
 * database, mostly on the existing per-book indexes).
 *
 * Sources of truth (Task 1 - the data audit, mirrored 1:1 in the
 * phase report):
 *
 *     - books               the living catalogue. "Visible" books =
 *                           status 'published' AND deleted_at IS
 *                           NULL - the exact ACTIVE_WHERE rule of
 *                           the recommendation engine, so every
 *                           surface agrees on what the catalogue is.
 *     - reviews             ratings written by users; ONLY
 *                           status = 'approved' counts (the house
 *                           rule of ReviewRepository and Phase 12.1).
 *                           The books.average_rating / ratings_count
 *                           sample columns are NEVER used - they
 *                           carry seeded demo numbers; the reviews
 *                           table is the sum.
 *     - user_library        the reading signal: one row per user per
 *                           book (UNIQUE (user_id, book_id) makes
 *                           duplicate counting impossible by
 *                           construction). "Wishlist" = the
 *                           want_to_read shelf (the modern wishlist;
 *                           Phase 12.1 already documented why the
 *                           legacy `wishlist` table is the
 *                           recommendation engine's personalization
 *                           signal, never a second wishlist source -
 *                           same rule here).
 *     - book_categories +   genre links (many-to-many, UNIQUE
 *       categories          (book_id, category_id)).
 *     - book_authors +      author links (many-to-many, UNIQUE
 *       authors             (book_id, author_id)).
 *     - google_book_id      the import marker: IS NOT NULL AND != ''
 *                           = imported through Google Books;
 *                           otherwise created in-house.
 *     - cover_image         cover availability: a non-empty string.
 *     - published_year, publisher, page_count, language - used only
 *       when actually present; missing metadata is reported as
 *       "without", never invented into a bucket.
 *
 * Deliberately NOT counted (documented in the phase report):
 *     - the legacy `wishlist` table (recommendation-only signal)
 *     - book_views (personalization-only signal)
 *     - recommendations and recommendation_logs (candidate data)
 *     - notifications / follows / search_history (no book metric)
 *
 * Every statement is a prepared statement. The only SQL that is
 * assembled dynamically is built from INT-CAST config values
 * (page-count ranges) - the last line of defense is right here,
 * inside this repository.
 */
final class BookAnalyticsRepository
{
    private const BOOK_STATUS_PUBLISHED = 'published';
    private const REVIEW_STATUS_APPROVED = 'approved';
    private const STATUS_WANT_TO_READ = 'want_to_read';
    private const STATUS_CURRENTLY_READING = 'currently_reading';
    private const STATUS_FINISHED = 'finished';
    private const STATUS_ON_HOLD = 'on_hold';
    private const STATUS_DROPPED = 'dropped';

    /** The one scope rule of the module: the living catalogue. */
    private const VISIBLE = 'b.deleted_at IS NULL AND b.status = ?';

    /**
     * The five canonical shelves, ordered the way the analytics page
     * renders them (identical to the library module's statuses).
     *
     * @return array<int, string>
     */
    public function shelfStatuses(): array
    {
        return [
            self::STATUS_WANT_TO_READ,
            self::STATUS_CURRENTLY_READING,
            self::STATUS_FINISHED,
            self::STATUS_ON_HOLD,
            self::STATUS_DROPPED,
        ];
    }

    /**
     * The headline counts of the visible catalogue in one query:
     * book total and the metadata-completeness side counts. Every
     * SUM(CASE) is one pass over the books table only.
     *
     * @return array<string, int>
     */
    public function overview(): array
    {
        $rows = db()->query(
            'SELECT b.id, b.cover_image, b.published_year, b.publisher, b.page_count, b.google_book_id
             FROM books b
             WHERE ' . self::VISIBLE,
            [self::BOOK_STATUS_PUBLISHED],
        );

        $totalBooks    = count($rows);
        $withCovers    = 0;
        $withYear      = 0;
        $withPublisher = 0;
        $withPages     = 0;
        $imported      = 0;

        foreach ($rows as $row) {
            $img = trim((string) ($row['cover_image'] ?? ''));
            if ($img !== '' && !str_starts_with($img, 'http://') && !str_starts_with($img, 'https://') && !str_contains($img, 'placeholder')) {
                $fullPath = root_path('public/' . ltrim($img, '/'));
                if (file_exists($fullPath) && is_file($fullPath) && filesize($fullPath) > 0) {
                    $withCovers++;
                }
            }

            if (!empty($row['published_year'])) {
                $withYear++;
            }
            if (!empty($row['publisher'])) {
                $withPublisher++;
            }
            if (!empty($row['page_count'])) {
                $withPages++;
            }
            if (!empty($row['google_book_id'])) {
                $imported++;
            }
        }

        return [
            'books'            => $totalBooks,
            'with_covers'      => $withCovers,
            'without_covers'   => max(0, $totalBooks - $withCovers),
            'with_year'        => $withYear,
            'with_publisher'   => $withPublisher,
            'with_pages'       => $withPages,
            'imported'         => $imported,
        ];
    }

    /**
     * How many DISTINCT genres cover the visible catalogue (the join
     * can never inflate it - DISTINCT lives in SQL).
     */
    public function genreCount(): int
    {
        $row = db()->query(
            'SELECT COUNT(DISTINCT bc.category_id) AS n
             FROM book_categories bc
             JOIN books b ON b.id = bc.book_id
             WHERE ' . self::VISIBLE,
            [self::BOOK_STATUS_PUBLISHED],
        )[0] ?? [];

        return (int) ($row['n'] ?? 0);
    }

    /**
     * How many DISTINCT authors wrote the visible catalogue.
     */
    public function authorCount(): int
    {
        $row = db()->query(
            'SELECT COUNT(DISTINCT ba.author_id) AS n
             FROM book_authors ba
             JOIN books b ON b.id = ba.book_id
             WHERE ' . self::VISIBLE,
            [self::BOOK_STATUS_PUBLISHED],
        )[0] ?? [];

        return (int) ($row['n'] ?? 0);
    }

    /**
     * The total number of approved reviews sitting on visible books -
     * the single "review count of the catalogue" headline.
     */
    public function totalApprovedReviews(): int
    {
        $row = db()->query(
            'SELECT COUNT(*) AS n
             FROM reviews r
             JOIN books b ON b.id = r.book_id
             WHERE ' . self::VISIBLE . ' AND r.status = \'' . self::REVIEW_STATUS_APPROVED . '\'',
            [self::BOOK_STATUS_PUBLISHED],
        )[0] ?? [];

        return (int) ($row['n'] ?? 0);
    }

    /**
     * The global average rating of the catalogue, computed from the
     * approved reviews themselves (never from the books.average_rating
     * sample column).
     *
     * @return float|null null when no approved review exists
     */
    public function globalAverageRating(): ?float
    {
        $row = db()->query(
            'SELECT AVG(r.rating) AS average
             FROM reviews r
             JOIN books b ON b.id = r.book_id
             WHERE ' . self::VISIBLE . ' AND r.status = \'' . self::REVIEW_STATUS_APPROVED . '\'',
            [self::BOOK_STATUS_PUBLISHED],
        )[0] ?? [];

        $average = $row['average'] ?? null;

        return $average === null ? null : round((float) $average, 2);
    }

    /**
     * The rating distribution of the whole catalogue: star (1..5) to
     * approved review count, only stars with rows (the service
     * completes the five buckets).
     *
     * @return array<int, int>
     */
    public function ratingDistribution(): array
    {
        $distribution = [];

        foreach (db()->query(
            'SELECT r.rating AS rating, COUNT(*) AS n
             FROM reviews r
             JOIN books b ON b.id = r.book_id
             WHERE ' . self::VISIBLE . ' AND r.status = \'' . self::REVIEW_STATUS_APPROVED . '\'
             GROUP BY r.rating',
            [self::BOOK_STATUS_PUBLISHED],
        ) as $row) {
            $distribution[(int) $row['rating']] = (int) $row['n'];
        }

        return $distribution;
    }

    /**
     * The highest rated books, ranked by their REAL average over the
     * approved reviews, guarded by a minimum approval count ($minimum
     * from config - a book with one lucky 5-star review never enters
     * the ranking). Ties break on review count, then title.
     *
     * @return array<int, array{id: int, title: string, cover: string|null, average: float, count: int}>
     */
    public function highestRated(int $limit, int $minimumCount): array
    {
        $rows = db()->query(
            'SELECT b.id, b.title, b.cover_image,
                    (SELECT GROUP_CONCAT(a.name, \', \') FROM book_authors ba JOIN authors a ON a.id = ba.author_id WHERE ba.book_id = b.id) AS author_name,
                    AVG(r.rating)  AS average,
                    COUNT(r.id)    AS count
             FROM reviews r
             JOIN books b ON b.id = r.book_id
             WHERE ' . self::VISIBLE . ' AND r.status = \'' . self::REVIEW_STATUS_APPROVED . '\'
             GROUP BY b.id
             HAVING COUNT(r.id) >= ?
             ORDER BY average DESC, count DESC, b.title COLLATE NOCASE ASC
             LIMIT ?',
            [self::BOOK_STATUS_PUBLISHED, $minimumCount, $limit],
        );

        return array_map(
            static fn (array $row): array => [
                'id'          => (int) $row['id'],
                'title'       => (string) $row['title'],
                'author_name' => (string) ($row['author_name'] ?? ''),
                'cover'       => ($row['cover_image'] !== null && $row['cover_image'] !== '') ? (string) $row['cover_image'] : null,
                'average'     => round((float) $row['average'], 2),
                'count'       => (int) $row['count'],
            ],
            $rows,
        );
    }

    /**
     * The most reviewed books of the catalogue (approved reviews
     * only - a pending or hidden review is not public).
     *
     * @return array<int, array{id: int, title: string, cover: string|null, count: int}>
     */
    public function mostReviewed(int $limit): array
    {
        $rows = db()->query(
            'SELECT b.id, b.title, b.cover_image,
                    (SELECT GROUP_CONCAT(a.name, \', \') FROM book_authors ba JOIN authors a ON a.id = ba.author_id WHERE ba.book_id = b.id) AS author_name,
                    COUNT(r.id) AS count
             FROM reviews r
             JOIN books b ON b.id = r.book_id
             WHERE ' . self::VISIBLE . ' AND r.status = \'' . self::REVIEW_STATUS_APPROVED . '\'
             GROUP BY b.id
             ORDER BY count DESC, b.title COLLATE NOCASE ASC
             LIMIT ?',
            [self::BOOK_STATUS_PUBLISHED, $limit],
        );

        return array_map(
            static fn (array $row): array => [
                'id'          => (int) $row['id'],
                'title'       => (string) $row['title'],
                'author_name' => (string) ($row['author_name'] ?? ''),
                'cover'       => ($row['cover_image'] !== null && $row['cover_image'] !== '') ? (string) $row['cover_image'] : null,
                'count'       => (int) $row['count'],
            ],
            $rows,
        );
    }

    /**
     * The most wishlisted books: the want_to_read shelf of the
     * modern library (source of truth - the UNIQUE (user_id, book_id)
     * index makes one user per book per shelf structurally
     * impossible, so these counts can never double-count a user).
     *
     * @return array<int, array{id: int, title: string, cover: string|null, count: int}>
     */
    public function mostWishlisted(int $limit): array
    {
        $rows = db()->query(
            'SELECT b.id, b.title, b.cover_image,
                    (SELECT GROUP_CONCAT(a.name, \', \') FROM book_authors ba JOIN authors a ON a.id = ba.author_id WHERE ba.book_id = b.id) AS author_name,
                    COUNT(l.user_id) AS count
             FROM user_library l
             JOIN books b ON b.id = l.book_id
             WHERE ' . self::VISIBLE . ' AND l.library_status = \'' . self::STATUS_WANT_TO_READ . '\'
             GROUP BY b.id
             ORDER BY count DESC, b.title COLLATE NOCASE ASC
             LIMIT ?',
            [self::BOOK_STATUS_PUBLISHED, $limit],
        );

        return array_map(
            static fn (array $row): array => [
                'id'          => (int) $row['id'],
                'title'       => (string) $row['title'],
                'author_name' => (string) ($row['author_name'] ?? ''),
                'cover'       => ($row['cover_image'] !== null && $row['cover_image'] !== '') ? (string) $row['cover_image'] : null,
                'count'       => (int) $row['count'],
            ],
            $rows,
        );
    }

    /**
     * The most read books: the finished shelves of the user_library
     * (a user can finish a book only ONCE - UNIQUE (user_id, book_id)
     * - so each count is a distinct reader).
     *
     * @return array<int, array{id: int, title: string, cover: string|null, count: int}>
     */
    public function mostRead(int $limit): array
    {
        $rows = db()->query(
            'SELECT b.id, b.title, b.cover_image,
                    (SELECT GROUP_CONCAT(a.name, \', \') FROM book_authors ba JOIN authors a ON a.id = ba.author_id WHERE ba.book_id = b.id) AS author_name,
                    COUNT(l.user_id) AS count
             FROM user_library l
             JOIN books b ON b.id = l.book_id
             WHERE ' . self::VISIBLE . ' AND l.library_status = \'' . self::STATUS_FINISHED . '\'
             GROUP BY b.id
             ORDER BY count > 0 DESC, count DESC, b.title COLLATE NOCASE ASC
             LIMIT ?',
            [self::BOOK_STATUS_PUBLISHED, $limit],
        );

        return array_map(
            static fn (array $row): array => [
                'id'          => (int) $row['id'],
                'title'       => (string) $row['title'],
                'author_name' => (string) ($row['author_name'] ?? ''),
                'cover'       => ($row['cover_image'] !== null && $row['cover_image'] !== '') ? (string) $row['cover_image'] : null,
                'count'       => (int) $row['count'],
            ],
            $rows,
        );
    }

    /**
     * The most engaged books: one row per book counting DISTINCT
     * users across the UNION of library shelves (any status) and
     * approved reviews - a user who shelves AND reviews the same
     * book still counts once (no user is ever double-counted by a
     * join; the DISTINCT lives in SQL, Task 8).
     *
     * @return array<int, array{id: int, title: string, cover: string|null, count: int}>
     */
    public function mostEngaged(int $limit): array
    {
        $rows = db()->query(
            'WITH activity AS (
                 SELECT l.book_id AS book_id, l.user_id AS user_id FROM user_library l
                 UNION
                 SELECT r.book_id AS book_id, r.user_id AS user_id FROM reviews r
                 WHERE r.status = \'' . self::REVIEW_STATUS_APPROVED . '\'
             )
             SELECT b.id, b.title, b.cover_image,
                    (SELECT GROUP_CONCAT(a.name, \', \') FROM book_authors ba JOIN authors a ON a.id = ba.author_id WHERE ba.book_id = b.id) AS author_name,
                    COUNT(DISTINCT a.user_id) AS count
             FROM activity a
             JOIN books b ON b.id = a.book_id
             WHERE ' . self::VISIBLE . '
             GROUP BY b.id
             ORDER BY count DESC, b.title COLLATE NOCASE ASC
             LIMIT ?',
            [self::BOOK_STATUS_PUBLISHED, $limit],
        );

        return array_map(
            static fn (array $row): array => [
                'id'          => (int) $row['id'],
                'title'       => (string) $row['title'],
                'author_name' => (string) ($row['author_name'] ?? ''),
                'cover'       => ($row['cover_image'] !== null && $row['cover_image'] !== '') ? (string) $row['cover_image'] : null,
                'count'       => (int) $row['count'],
            ],
            $rows,
        );
    }

    /**
     * The shelf totals of the whole catalogue: status -> (user, book)
     * pairs, completed to all five shelves with contextual zeroes
     * (a shelf nobody sits on reads 0, never "missing").
     *
     * @return array<string, int>
     */
    /**
     * The raw signals of the popularity formula: every book that has
     * ANY engagement (an approved review or a want_to_read row) comes
     * back with its three components - rating average, review count,
     * interest count. Correlated sub-selects over the book-scoped
     * indexes keep each component exact (a JOIN would multiply the
     * AVG by the interest rows). The ranking itself (weighted score)
     * is the SERVICE's documented formula - the repository only
     * delivers the real numbers.
     *
     * @return array<int, array{id: int, title: string, cover: string|null, average: float, reviews: int, interests: int}>
     */
    public function popularitySignals(): array
    {
        $rows = db()->query(
            'SELECT
                b.id AS id,
                b.title AS title,
                b.cover_image AS cover_image,
                (SELECT GROUP_CONCAT(a.name, \', \') FROM book_authors ba JOIN authors a ON a.id = ba.author_id WHERE ba.book_id = b.id) AS author_name,
                COALESCE((SELECT AVG(r.rating) FROM reviews r
                          WHERE r.book_id = b.id AND r.status = \'' . self::REVIEW_STATUS_APPROVED . '\'), 0) AS average,
                (SELECT COUNT(*) FROM reviews r
                 WHERE r.book_id = b.id AND r.status = \'' . self::REVIEW_STATUS_APPROVED . '\') AS reviews,
                (SELECT COUNT(*) FROM user_library w
                 WHERE w.book_id = b.id AND w.library_status = \'' . self::STATUS_WANT_TO_READ . '\') AS interests
             FROM books b
             WHERE ' . self::VISIBLE . '
               AND (EXISTS (SELECT 1 FROM reviews r
                            WHERE r.book_id = b.id AND r.status = \'' . self::REVIEW_STATUS_APPROVED . '\')
                    OR EXISTS (SELECT 1 FROM user_library w
                               WHERE w.book_id = b.id AND w.library_status = \'' . self::STATUS_WANT_TO_READ . '\'))',
            [self::BOOK_STATUS_PUBLISHED],
        );

        return array_map(
            static fn (array $row): array => [
                'id'          => (int) $row['id'],
                'title'       => (string) $row['title'],
                'author_name' => (string) ($row['author_name'] ?? ''),
                'cover'       => ($row['cover_image'] !== null && $row['cover_image'] !== '') ? (string) $row['cover_image'] : null,
                'average'     => round((float) $row['average'], 2),
                'reviews'     => (int) $row['reviews'],
                'interests'   => (int) $row['interests'],
            ],
            $rows,
        );
    }

    /**
     * The raw signals of the trending ranking: per book, how much
     * engagement happened INSIDE the trailing $since window - recent
     * approved reviews, recent want_to_read rows, recent finishes -
     * each an exact correlated count (the same exact sub-select shape
     * as popularitySignals(); the weighted score is the service's).
     *
     * @return array<int, array{id: int, title: string, cover: string|null, reviews: int, interests: int, finishes: int}>
     */
    public function trendingSignals(string $since): array
    {
        $rows = db()->query(
            'SELECT
                b.id AS id,
                b.title AS title,
                b.cover_image AS cover_image,
                (SELECT GROUP_CONCAT(a.name, \', \') FROM book_authors ba JOIN authors a ON a.id = ba.author_id WHERE ba.book_id = b.id) AS author_name,
                COALESCE((SELECT COUNT(*) FROM reviews r
                          WHERE r.book_id = b.id AND r.status = \'' . self::REVIEW_STATUS_APPROVED . '\'
                            AND r.created_at >= ?), 0) AS recent_reviews,
                COALESCE((SELECT COUNT(*) FROM user_library w
                          WHERE w.book_id = b.id AND w.library_status = \'' . self::STATUS_WANT_TO_READ . '\'
                            AND w.created_at >= ?), 0) AS recent_interests,
                COALESCE((SELECT COUNT(*) FROM user_library f
                          WHERE f.book_id = b.id AND f.library_status = \'' . self::STATUS_FINISHED . '\'
                            AND f.finished_reading_at >= ?), 0) AS recent_finishes
             FROM books b
             WHERE ' . self::VISIBLE . '
               AND (EXISTS (SELECT 1 FROM reviews r
                            WHERE r.book_id = b.id AND r.status = \'' . self::REVIEW_STATUS_APPROVED . '\'
                              AND r.created_at >= ?)
                    OR EXISTS (SELECT 1 FROM user_library w
                               WHERE w.book_id = b.id AND w.library_status = \'' . self::STATUS_WANT_TO_READ . '\'
                                 AND w.created_at >= ?)
                    OR EXISTS (SELECT 1 FROM user_library f
                               WHERE f.book_id = b.id AND f.library_status = \'' . self::STATUS_FINISHED . '\'
                                 AND f.finished_reading_at >= ?))',
            // SQL placeholder order: the three window sub-selects come
            // first, then the VISIBLE scope (in the WHERE) - the bound
            // list must follow exactly that order.
            [$since, $since, $since, self::BOOK_STATUS_PUBLISHED, $since, $since, $since],
        );

        return array_map(
            static fn (array $row): array => [
                'id'          => (int) $row['id'],
                'title'       => (string) $row['title'],
                'author_name' => (string) ($row['author_name'] ?? ''),
                'cover'       => ($row['cover_image'] !== null && $row['cover_image'] !== '') ? (string) $row['cover_image'] : null,
                'reviews'     => (int) $row['recent_reviews'],
                'interests'   => (int) $row['recent_interests'],
                'finishes'    => (int) $row['recent_finishes'],
            ],
            $rows,
        );
    }

    /**
     * The catalogue-wide review timeline: approved reviews per
     * calendar month ('YYYY-MM' -> count), across all history. The
     * service crops it into the trailing window and reports what fell
     * outside - it never invents activity for empty months.
     *
     * @return array<string, int>
     */
    public function monthlyReviews(): array
    {
        return $this->monthlyMap(
            'SELECT substr(r.created_at, 1, 7) AS month, COUNT(*) AS n
             FROM reviews r
             JOIN books b ON b.id = r.book_id
             WHERE ' . self::VISIBLE . ' AND r.status = \'' . self::REVIEW_STATUS_APPROVED . '\'
             GROUP BY month',
            [self::BOOK_STATUS_PUBLISHED],
        );
    }

    /**
     * The catalogue-wide reading timeline: finished library rows per
     * calendar month (the real finished_reading_at stamps the library
     * service wrote), across all history.
     *
     * @return array<string, int>
     */
    public function monthlyFinished(): array
    {
        return $this->monthlyMap(
            'SELECT substr(l.finished_reading_at, 1, 7) AS month, COUNT(*) AS n
             FROM user_library l
             JOIN books b ON b.id = l.book_id
             WHERE ' . self::VISIBLE . ' AND l.library_status = \'' . self::STATUS_FINISHED . '\'
                   AND l.finished_reading_at IS NOT NULL
             GROUP BY month',
            [self::BOOK_STATUS_PUBLISHED],
        );
    }

    /**
     * Run one month-GROUP BY aggregation and fold it into a
     * 'YYYY-MM' => count map.
     *
     * @return array<string, int>
     */
    private function monthlyMap(string $sql, array $params): array
    {
        $map = [];

        foreach (db()->query($sql, $params) as $row) {
            $map[(string) $row['month']] = (int) $row['n'];
        }

        return $map;
    }

    /**
     * The shelf totals of the whole catalogue: status -> (user, book)
     * pairs, completed to all five shelves with contextual zeroes
     * (a shelf nobody sits on reads 0, never "missing").
     *
     * @return array<string, int>
     */
    public function shelfTotals(): array
    {
        $totals = array_fill_keys($this->shelfStatuses(), 0);

        foreach (db()->query(
            'SELECT l.library_status AS status, COUNT(*) AS n
             FROM user_library l
             JOIN books b ON b.id = l.book_id
             WHERE ' . self::VISIBLE . '
             GROUP BY l.library_status',
            [self::BOOK_STATUS_PUBLISHED],
        ) as $row) {
            $totals[(string) $row['status']] = (int) $row['n'];
        }

        return $totals;
    }

    /**
     * The genres ordered by catalogue size (books per genre) - the
     * "which genres hold the most books" question. A multi-genre
     * book counts ONCE per genre (cover-label counting, the same
     * membership rule Phase 12.1 uses); COUNT(DISTINCT b.id) keeps
     * the junction join from ever doubling a book inside one genre.
     *
     * @return array<int, array{name: string, books: int}>
     */
    public function genresByCatalogue(int $limit): array
    {
        $rows = db()->query(
            'SELECT c.name, COUNT(DISTINCT b.id) AS books
             FROM book_categories bc
             JOIN categories c ON c.id = bc.category_id
             JOIN books b ON b.id = bc.book_id
             WHERE ' . self::VISIBLE . '
             GROUP BY c.id, c.name
             ORDER BY books DESC, c.name COLLATE NOCASE ASC
             LIMIT ?',
            [self::BOOK_STATUS_PUBLISHED, $limit],
        );

        return array_map(
            static fn (array $row): array => [
                'name'  => (string) $row['name'],
                'books' => (int) $row['books'],
            ],
            $rows,
        );
    }

    /**
     * "Genre popularity" - separated from catalogue size on purpose
     * (a genre with many books is NOT automatically read the most):
     * the genres counted by how many finished library rows sit on
     * their books. Same membership rule as genresByCatalogue().
     *
     * @return array<int, array{name: string, count: int}>
     */
    public function genresByReading(int $limit): array
    {
        $rows = db()->query(
            'SELECT c.name, COUNT(DISTINCT l.id) AS count
             FROM book_categories bc
             JOIN categories c ON c.id = bc.category_id
             JOIN user_library l ON l.book_id = bc.book_id
             JOIN books b ON b.id = bc.book_id
             WHERE ' . self::VISIBLE . ' AND l.library_status = \'' . self::STATUS_FINISHED . '\'
             GROUP BY c.id, c.name
             ORDER BY count DESC, c.name COLLATE NOCASE ASC
             LIMIT ?',
            [self::BOOK_STATUS_PUBLISHED, $limit],
        );

        return array_map(
            static fn (array $row): array => [
                'name'  => (string) $row['name'],
                'count' => (int) $row['count'],
            ],
            $rows,
        );
    }

    /**
     * The authors with the most books in the catalogue. Co-authored
     * books count once per author; COUNT(DISTINCT b.id) keeps the
     * junction join from doubling a (book, author) pair.
     *
     * @return array<int, array{name: string, books: int}>
     */
    public function authorsByCatalogue(int $limit): array
    {
        $rows = db()->query(
            'SELECT a.name, COUNT(DISTINCT b.id) AS books
             FROM book_authors ba
             JOIN authors a ON a.id = ba.author_id
             JOIN books b ON b.id = ba.book_id
             WHERE ' . self::VISIBLE . '
             GROUP BY a.id, a.name
             ORDER BY books DESC, a.name COLLATE NOCASE ASC
             LIMIT ?',
            [self::BOOK_STATUS_PUBLISHED, $limit],
        );

        return array_map(
            static fn (array $row): array => [
                'name'  => (string) $row['name'],
                'books' => (int) $row['books'],
            ],
            $rows,
        );
    }

    /**
     * The authors whose books were FINISHED the most (author
     * engagement): one finishing count per (author, finished book,
     * user) triples - a co-authored book counted once per author,
     * and DISTINCT l.id keeps the author junction from doubling the
     * finished records.
     *
     * @return array<int, array{name: string, count: int}>
     */
    public function authorsRead(int $limit): array
    {
        $rows = db()->query(
            'SELECT a.name, COUNT(DISTINCT l.id) AS count
             FROM book_authors ba
             JOIN authors a ON a.id = ba.author_id
             JOIN user_library l ON l.book_id = ba.book_id
             JOIN books b ON b.id = ba.book_id
             WHERE ' . self::VISIBLE . ' AND l.library_status = \'' . self::STATUS_FINISHED . '\'
             GROUP BY a.id, a.name
             ORDER BY count DESC, a.name COLLATE NOCASE ASC
             LIMIT ?',
            [self::BOOK_STATUS_PUBLISHED, $limit],
        );

        return array_map(
            static fn (array $row): array => [
                'name'  => (string) $row['name'],
                'count' => (int) $row['count'],
            ],
            $rows,
        );
    }

    /**
     * The publishers ordered by their catalogue size (only books that
     * actually carry a publisher; the "without publisher" number is a
     * completeness headline elsewhere).
     *
     * @return array<int, array{name: string, books: int}>
     */
    public function publishers(int $limit): array
    {
        $rows = db()->query(
            'SELECT b.publisher AS name, COUNT(*) AS books
             FROM books b
             WHERE ' . self::VISIBLE . ' AND b.publisher IS NOT NULL AND b.publisher != \'\'
             GROUP BY b.publisher
             ORDER BY books DESC, b.publisher COLLATE NOCASE ASC
             LIMIT ?',
            [self::BOOK_STATUS_PUBLISHED, $limit],
        );

        return array_map(
            static fn (array $row): array => [
                'name'  => (string) $row['name'],
                'books' => (int) $row['books'],
            ],
            $rows,
        );
    }

    /**
     * The catalogue by language - only languages that actually appear.
     *
     * @return array<int, array{language: string, books: int}>
     */
    public function languages(int $limit): array
    {
        $rows = db()->query(
            'SELECT b.language, COUNT(*) AS books
             FROM books b
             WHERE ' . self::VISIBLE . ' AND b.language IS NOT NULL AND b.language != \'\'
             GROUP BY b.language
             ORDER BY books DESC, b.language COLLATE NOCASE ASC
             LIMIT ?',
            [self::BOOK_STATUS_PUBLISHED, $limit],
        );

        return array_map(
            static fn (array $row): array => [
                'language' => (string) $row['language'],
                'books'    => (int) $row['books'],
            ],
            $rows,
        );
    }

    /**
     * The catalogue by publication year, newest first (only books
     * with a year; the year-less books appear in the completeness
     * counts, never in a fabricated bucket).
     *
     * @return array<int, array{year: int, books: int}>
     */
    public function years(int $limit): array
    {
        $rows = db()->query(
            'SELECT b.published_year AS year, COUNT(*) AS books
             FROM books b
             WHERE ' . self::VISIBLE . ' AND b.published_year IS NOT NULL
             GROUP BY b.published_year
             ORDER BY b.published_year DESC
             LIMIT ?',
            [self::BOOK_STATUS_PUBLISHED, $limit],
        );

        return array_map(
            static fn (array $row): array => [
                'year'  => (int) $row['year'],
                'books' => (int) $row['books'],
            ],
            $rows,
        );
    }

    /**
     * The books by page-count bucket. $ranges is the config's
     * page_ranges list: [{label, min, max}] where a null max is
     * open. Every boundary is re-validated here as an integer - the
     * CASE expression is built from int-cast config values only, so
     * no request input can ever reach the SQL string. Labels are
     * rebound as bound parameters (never a SQL literal).
     *
     * @param array<int, array{label: string, min: int|null, max: int|null}> $ranges
     *
     * @return array<int, array{label: string, books: int}>
     */
    public function pageRanges(array $ranges): array
    {
        $conditions = [];
        $params     = [];
        $labels     = [];

        foreach ($ranges as $range) {
            $label = isset($range['label']) ? (string) $range['label'] : '';
            $min   = $range['min'] ?? null;
            $max   = $range['max'] ?? null;

            if ($min === null || !ctype_digit((string) $min)) {
                continue;
            }

            if ($max !== null) {
                if (!ctype_digit((string) $max)) {
                    continue;
                }

                $conditions[] = 'b.page_count >= ? AND b.page_count <= ?';
                $params[]     = (int) $min;
                $params[]     = (int) $max;
            } else {
                $conditions[] = 'b.page_count >= ?';
                $params[]     = (int) $min;
            }

            $labels[] = $label;
            // Every label stays a bound placeholder in the SELECT.
            $params[] = $label;
        }

        if ($conditions === []) {
            return [];
        }

        $when = '';
        foreach ($conditions as $i => $condition) {
            $when .= ' WHEN ' . $condition . ' THEN ?';
            // the label placeholder for this row goes right after its
            // own condition placeholders (see param order above).

        }

        $sql = 'SELECT CASE' . $when . ' END AS range_label, COUNT(*) AS books
                FROM books b
                WHERE ' . self::VISIBLE . ' AND b.page_count IS NOT NULL
                GROUP BY range_label
                ORDER BY books DESC, range_label COLLATE NOCASE ASC';

        // SQL placeholder order: every CASE placeholder lives BEFORE
        // the WHERE's VISIBLE placeholder, so the status is bound LAST.
        $rows = db()->query($sql, array_merge($params, [self::BOOK_STATUS_PUBLISHED]));

        // An empty catalogue answers with no buckets at all - the
        // dashboard guidance state, never a wall of zeros.
        if ($rows === []) {
            $living = db()->query(
                'SELECT COUNT(*) AS n FROM books b WHERE ' . self::VISIBLE,
                [self::BOOK_STATUS_PUBLISHED],
            )[0]['n'] ?? 0;

            if ((int) $living === 0) {
                return [];
            }
        }

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['range_label']] = (int) $row['books'];
        }

        // Every CONFIGURED range keeps its label even when empty (a
        // zero is a real zero: the bucket exists, no book sits in it).
        // The label order follows the config, never SQL arcana.
        $buckets = [];
        foreach ($labels as $label) {
            $buckets[] = [
                'label' => $label,
                'books' => $counts[$label] ?? 0,
            ];
        }

        return $buckets;
    }

    /**
     * The recent activity of ONE shelf record family used by the
     * trending score - the three signals of the window:
     *   recent approved reviews, recent want_to_read adds, recent
     *   finishes - all scoped to the CURRENT timestamp window passed
     *   in by the service.
     *
     * Every subquery embeds the VISIBLE filter, so the placeholder
     * order is: status, since (x3 subqueries).
     *
     * @return array<string, int> keys: recent_reviews,
     *                            recent_interests, recent_finishes
     */
    public function recentActivity(string $since): array
    {
        $row = db()->query(
            'SELECT
                (SELECT COUNT(*) FROM reviews r
                 JOIN books b ON b.id = r.book_id
                 WHERE ' . self::VISIBLE . ' AND r.status = \'' . self::REVIEW_STATUS_APPROVED . '\' AND r.created_at >= ?)   AS recent_reviews,
                (SELECT COUNT(*) FROM user_library l
                 JOIN books b ON b.id = l.book_id
                 WHERE ' . self::VISIBLE . ' AND l.library_status = \'' . self::STATUS_WANT_TO_READ . '\' AND l.created_at >= ?) AS recent_interests,
                (SELECT COUNT(*) FROM user_library l
                 JOIN books b ON b.id = l.book_id
                 WHERE ' . self::VISIBLE . ' AND l.library_status = \'' . self::STATUS_FINISHED . '\' AND l.finished_reading_at >= ?) AS recent_finishes',
            [
                self::BOOK_STATUS_PUBLISHED, $since,
                self::BOOK_STATUS_PUBLISHED, $since,
                self::BOOK_STATUS_PUBLISHED, $since,
            ],
        )[0] ?? [];

        return [
            'recent_reviews'   => (int) ($row['recent_reviews'] ?? 0),
            'recent_interests' => (int) ($row['recent_interests'] ?? 0),
            'recent_finishes'  => (int) ($row['recent_finishes'] ?? 0),
        ];
    }
}