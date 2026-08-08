<?php

declare(strict_types=1);

namespace BookSphere\App\Repositories;

/**
 * UserAnalyticsRepository
 *
 * The data layer of the USER ANALYTICS module (Phase 12.1): every
 * aggregate the statistics page shows comes from SQL here - the
 * module never ships the user's rows into PHP and counts them in a
 * loop (Task 13 - aggregation lives in the database, on the
 * user-scoped indexes).
 *
 * Sources of truth (Task 1 - the data audit, mirrored 1:1 in
 * docs/PHASE_12_1_USER_ANALYTICS.md):
 *
 *     - user_library       the personal library, one row per user
 *                          per book (UNIQUE (user_id, book_id) - the
 *                          database itself makes double counting
 *                          impossible). Statuses are the five
 *                          CHECK-constrained shelves; started_reading_at
 *                          / finished_reading_at provide the reading
 *                          timeline; updated_at moves on every read
 *                          write (the "active days" signal).
 *     - reviews            own reviews; only status = 'approved'
 *                          counts, the exact house rule the profile
 *                          page's rating activity already uses.
 *     - book_categories +
 *       categories         genre links via the book (many-to-many,
 *                          UNIQUE (book_id, category_id)).
 *     - book_authors +
 *       authors           author links via the book (many-to-many).
 *
 * Deliberately NOT counted (documented in the phase report):
 *     - the legacy `wishlist` table is the recommendation engine's
 *       personalization signal; the modern wishlist is the
 *       user_library 'want_to_read' shelf, so the analytics count
 *       the shelf (one source of truth, never both - Task 1).
 *     - author_follows / search_history / notifications / book_views
 *       have no metric in the Phase 12.1 list; they are mapped in
 *       the audit for later phases.
 *
 * Every query is user-scoped on the WHERE (user_id = ?) and every
 * statement is a prepared statement - one user can never read
 * another user's rows, and no literal is ever built from request
 * input here.
 *
 * Soft-deleted books: the analytics include the user's library
 * records exactly like the library shelves do (no deleted_at filter
 * - a book the user shelved stays in THEIR library view after an
 * admin soft-deletes it from the catalogue). Search/browse surfaces
 * filter deleted_at; personal surfaces don't. Numbers therefore
 * always agree with what the user sees on their shelves.
 */
final class UserAnalyticsRepository
{
    private const STATUS_FINISHED          = 'finished';
    private const STATUS_CURRENTLY_READING = 'currently_reading';
    private const STATUS_WANT_TO_READ      = 'want_to_read';
    private const STATUS_ON_HOLD           = 'on_hold';
    private const STATUS_DROPPED           = 'dropped';

    private const REVIEW_STATUS_APPROVED = 'approved';

    /**
     * The five canonical shelf statuses, ordered the way the
     * statistics page renders them.
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
     * Per-shelf counts of one user's library. One aggregation over
     * the user-scoped idx_user_library_user index - the total
     * shelved count is the SUM of these rows (every record carries
     * exactly one status), so no second query ever counts the
     * library again.
     *
     * @return array<string, int> status -> count (only statuses with
     *                                      rows appear; the service
     *                                      zeros the missing keys)
     */
    public function shelfCounts(int $userId): array
    {
        $counts = [];

        foreach (db()->query(
            'SELECT library_status AS status, COUNT(*) AS n
             FROM user_library
             WHERE user_id = ?
             GROUP BY library_status',
            [$userId],
        ) as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * The reading-day count: how many DISTINCT days the user's
     * library saw any write (a fresh row, a status change, progress,
     * a favorite - every write stamps updated_at, the same "active
     * day" signal the dashboard streak uses). NULL timestamps are
     * impossible on updated_at (NOT NULL DEFAULT), so the DISTINCT
     * date walk is exact.
     */
    public function activeDays(int $userId): int
    {
        $row = db()->query(
            'SELECT COUNT(DISTINCT substr(updated_at, 1, 10)) AS n
             FROM user_library
             WHERE user_id = ?',
            [$userId],
        )[0] ?? [];

        return (int) ($row['n'] ?? 0);
    }

    /**
     * One user's review totals over APPROVED reviews (the house rule
     * of ReviewRepository::userRatingStats).
     *
     * @return array{total: int, average: float|null}
     */
    public function reviewTotals(int $userId): array
    {
        $row = db()->query(
            'SELECT COUNT(r.id) AS total, AVG(r.rating) AS average
             FROM reviews r
             WHERE r.user_id = ? AND r.status = \'' . self::REVIEW_STATUS_APPROVED . '\'',
            [$userId],
        )[0] ?? [];

        $average = $row['average'] ?? null;

        return [
            'total'   => (int) ($row['total'] ?? 0),
            'average' => $average === null ? null : (float) $average,
        ];
    }

    /**
     * The rating histogram of one user's approved reviews: rating
     * (1..5) -> review count, only ratings with rows present (the
     * service completes the five buckets).
     *
     * @return array<int, int>
     */
    public function ratingDistribution(int $userId): array
    {
        $distribution = [];

        foreach (db()->query(
            'SELECT rating, COUNT(*) AS n
             FROM reviews r
             WHERE r.user_id = ? AND r.status = \'approved\'
             GROUP BY rating',
            [$userId],
        ) as $row) {
            $distribution[(int) $row['rating']] = (int) $row['n'];
        }

        return $distribution;
    }

    /**
     * The top genres of the books the user has FINISHED ("genres I
     * read"), most-read first.
     *
     * Calculation method (documented, Task 6): a book in several
     * genres counts ONCE per genre ("cover-label" counting - the
     * same physical book appears under every genre it carries). The
     * percentages are the share of the total genre memberships, so
     * a single book with two genres contributes one membership to
     * each. COUNT(DISTINCT l.book_id) guards every count against the
     * multi-genre join doubling a book within one genre.
     *
     * @return array<int, array{name: string, books: int}>
     */
    public function topGenres(int $userId, int $limit): array
    {
        $rows = db()->query(
            'SELECT c.name                                 AS name,
                    COUNT(DISTINCT l.book_id)              AS books
             FROM user_library l
             JOIN book_categories bc ON bc.book_id = l.book_id
             JOIN categories c       ON c.id = bc.category_id
             WHERE l.user_id = ? AND l.library_status = \'finished\'
             GROUP BY c.id, c.name
             ORDER BY books DESC, c.name COLLATE NOCASE ASC
             LIMIT ?',
            [$userId, $limit],
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
     * The total number of (finished book, genre) memberships - the
     * denominator of every genre percentage.
     */
    public function genreMemberships(int $userId): int
    {
        $row = db()->query(
            'SELECT COUNT(*) AS n
             FROM user_library l
             JOIN book_categories bc ON bc.book_id = l.book_id
             WHERE l.user_id = ? AND l.library_status = \'finished\'',
            [$userId],
        )[0] ?? [];

        return (int) ($row['n'] ?? 0);
    }

    /**
     * The top authors of the user's FINISHED books ("authors I
     * read"), most-read first. Same membership counting rule as the
     * genres: a co-authored book counts once per author, and
     * COUNT(DISTINCT book_id) keeps the junction join from ever
     * doubling one (book, author) pair.
     *
     * @return array<int, array{name: string, books: int}>
     */
    public function topAuthors(int $userId, int $limit): array
    {
        $rows = db()->query(
            'SELECT a.name                                 AS name,
                    COUNT(DISTINCT l.book_id)              AS books
             FROM user_library l
             JOIN book_authors ba ON ba.book_id = l.book_id
             JOIN authors a       ON a.id = ba.author_id
             WHERE l.user_id = ? AND l.library_status = \'finished\'
             GROUP BY a.id, a.name
             ORDER BY books DESC, a.name COLLATE NOCASE ASC
             LIMIT ?',
            [$userId, $limit],
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
     * How many DISTINCT genres the user's finished books cover - the
     * DISTINCT lives in SQL, so a book with several genres can never
     * inflate the unique count.
     */
    public function uniqueReadGenres(int $userId): int
    {
        $row = db()->query(
            'SELECT COUNT(DISTINCT c.id) AS n
             FROM user_library l
             JOIN book_categories bc ON bc.book_id = l.book_id
             JOIN categories c       ON c.id = bc.category_id
             WHERE l.user_id = ? AND l.library_status = \'finished\'',
            [$userId],
        )[0] ?? [];

        return (int) ($row['n'] ?? 0);
    }

    /**
     * How many DISTINCT authors wrote the user's finished books.
     * The DISTINCT lives in the SQL - the join can never duplicate
     * an author for the "unique authors" metric.
     */
    public function uniqueReadAuthors(int $userId): int
    {
        $row = db()->query(
            'SELECT COUNT(DISTINCT ba.author_id) AS n
             FROM user_library l
             JOIN book_authors ba ON ba.book_id = l.book_id
             WHERE l.user_id = ? AND l.library_status = \'finished\'',
            [$userId],
        )[0] ?? [];

        return (int) ($row['n'] ?? 0);
    }

    /**
     * The number of (finished book, author) memberships - the
     * denominator of the author percentages.
     */
    public function authorMemberships(int $userId): int
    {
        $row = db()->query(
            'SELECT COUNT(*) AS n
             FROM user_library l
             JOIN book_authors ba ON ba.book_id = l.book_id
             WHERE l.user_id = ? AND l.library_status = \'finished\'',
            [$userId],
        )[0] ?? [];

        return (int) ($row['n'] ?? 0);
    }

    /**
     * The user's REAL completion timeline: finished books per
     * calendar month (from finished_reading_at - the lifecycle stamp
     * the library service writes when a record becomes finished),
     * across all history. The service crops this into the trailing
     * window and reports what fell outside it - it NEVER invents
     * months that hold no data.
     *
     * @return array<string, int> 'YYYY-MM' -> count
     */
    public function monthlyCompletions(int $userId): array
    {
        return $this->monthlyMap(
            'SELECT substr(l.finished_reading_at, 1, 7) AS month, COUNT(*) AS n
             FROM user_library l
             WHERE l.user_id = ? AND l.library_status = \'finished\'
                   AND l.finished_reading_at IS NOT NULL
             GROUP BY month',
            $userId,
        );
    }

    /**
     * The user's review timeline: approved reviews per calendar
     * month (the review write stamp, never guessed).
     *
     * @return array<string, int> 'YYYY-MM' -> count
     */
    public function monthlyReviews(int $userId): array
    {
        return $this->monthlyMap(
            'SELECT substr(r.created_at, 1, 7) AS month, COUNT(*) AS n
             FROM reviews r
             WHERE r.user_id = ? AND r.status = \'approved\'
             GROUP BY month',
            $userId,
        );
    }

    /**
     * The recent activity timeline: the last LIMIT reading events of
     * the user across ALL sources - books finished, books started,
     * books rated (approved reviews) and books shelved for later -
     * merged into ONE ordered stream by their event timestamp.
     * Events carry no id (the page links through the book), so zero
     * internal database identifiers ever reach the view.
     *
     * @return array<int, array{type: string, book_title: string, at: string}>
     */
    public function recentEvents(int $userId, int $limit): array
    {
        return db()->query(
            'SELECT \'finished\' AS type, b.title AS book_title, l.finished_reading_at AS at
             FROM user_library l
             JOIN books b ON b.id = l.book_id
             WHERE l.user_id = ? AND l.library_status = \'finished\'
                   AND l.finished_reading_at IS NOT NULL
             UNION ALL
             SELECT \'started\', b.title, l.started_reading_at
             FROM user_library l
             JOIN books b ON b.id = l.book_id
             WHERE l.user_id = ? AND l.library_status = \'currently_reading\'
                   AND l.started_reading_at IS NOT NULL
             UNION ALL
             SELECT \'rated\', b.title, r.created_at
             FROM reviews r
             JOIN books b ON b.id = r.book_id
             WHERE r.user_id = ? AND r.status = \'approved\'
             UNION ALL
             SELECT \'shelved\', b.title, l.created_at
             FROM user_library l
             JOIN books b ON b.id = l.book_id
             WHERE l.user_id = ? AND l.library_status = \'want_to_read\'
             ORDER BY at DESC
             LIMIT ?',
            [$userId, $userId, $userId, $userId, $limit],
        );
    }

    /**
     * Run one month-GROUP BY aggregation and fold it into a
     * 'YYYY-MM' => count map. The SQL stays here (repository owns
     * the SQL), the map shape is shared by both monthly reads.
     *
     * @return array<string, int>
     */
    private function monthlyMap(string $sql, int $userId): array
    {
        $map = [];

        foreach (db()->query($sql, [$userId]) as $row) {
            $map[(string) $row['month']] = (int) $row['n'];
        }

        return $map;
    }
}