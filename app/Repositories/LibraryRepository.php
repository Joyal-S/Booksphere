<?php

declare(strict_types=1);

namespace BookSphere\App\Repositories;

/**
 * LibraryRepository
 *
 * The data-access layer of the Wishlist & Personal Reading Library
 * module (Phase 8.1, extended by Phase 8.2 and Phase 8.3). Every SQL
 * query that touches the library module's two tables - user_library
 * and user_preferences - lives here and here only - prepared
 * statements everywhere (the db() helper binds parameters; no value
 * ever lands in the SQL text), explicit column lists, and the shelf
 * buckets expressed as this repository's single status spelling so a
 * future status can never leave a stray literal behind.
 *
 * Responsibilities:
 *
 *     - CRUD: create / update / delete / find
 *     - Reads: findByUser / findByBook (the user's single record for
 *       a book) / exists
 *     - Shelf scopes: favorites / wishlist / currentlyReading /
 *       finished - the status buckets the service and the Phase 8.5
 *       recommendation hooks read. continueReading() is the Phase 8.3
 *       name of the currently-reading shelf (the dashboard resume
 *       cards read it), currentlyReading() delegates to it.
 *     - Aggregates: statistics() (the per-user library overview) and
 *       preferredGenres() (the genres a user's library favours - a
 *       recommendation hook read).
 *     - Phase 8.3 dashboard reads: the generic filter() / countFiltered() /
 *       paginate() over the shared WHERE builder (search by title /
 *       publisher / language / author / category, shelf / category /
 *       author / rating / favourite / recency filters, the SORTS
 *       ordering map - the backend of the library dashboard grid),
 *       readingSummary() (the favourite-genre / favourite-author /
 *       average-rating-given / average-progress summaries),
 *       readingStreak() (the current / longest consecutive-day streak
 *       of library activity) and filterOptions() (the dropdown vocab).
 *     - Preferences: preference() / savePreferences() - the one-row
 *       user_preferences table (library_sort / library_view) the
 *       dashboard persists and reads between visits.
 *
 * Rules inherited from the schema (migration 0017):
 *     - UNIQUE (user_id, book_id): one record per user per book.
 *     - ON DELETE CASCADE: user or book deletion removes the rows.
 *     - CHECK constraints: the five statuses and the 0-100 progress
 *       range are enforced by the database too - the last line of
 *       defence behind the request validation and the service.
 *
 * The rows returned carry the book display columns (title, cover)
 * joined in, so every library list is renderable without an extra
 * query per row (the same approach as ReviewRepository::SELECT).
 *
 * Dependencies:
 *     - db() helper (Core\Database singleton) - the shared PDO
 *       connection, exactly like ReviewRepository and BookRepository.
 *
 * How it fits inside MVC:
 *     Controller -> Service (business rules) -> UserLibrary model
 *     (facade) -> LibraryRepository (SQL) -> PDO -> SQLite.
 */
final class LibraryRepository
{
    /**
     * The library_status spellings of the five shelves (single home here
     * for every SQL string of the data layer; the SERVICE owns the
     * display labels).
     */
    private const STATUS_WANT_TO_READ     = 'want_to_read';
    private const STATUS_CURRENTLY_READING = 'currently_reading';
    private const STATUS_FINISHED         = 'finished';
    private const STATUS_ON_HOLD          = 'on_hold';
    private const STATUS_DROPPED          = 'dropped';

    /**
     * The library_sort spellings of the dashboard grid (Phase 8.3) -
     * the ONLY ORDER BY fragments the data layer will ever build. The
     * keys are the sort ids the UI submits; an unknown id (tampered
     * request, stale bookmark) falls back to the default. Every key
     * sorts within the user's records only, and the id tie-break
     * keeps every ordering deterministic.
     *
     * The display labels live in LibraryService::SORTS - the SQL
     * fragments live here, exactly like the status spellings above.
     */
    private const SORTS = [
        'newest_added'     => 'l.created_at DESC, l.id DESC',
        'oldest_added'     => 'l.created_at ASC, l.id ASC',
        'recently_updated' => 'l.updated_at DESC, l.id DESC',
        'title_asc'        => 'b.title COLLATE NOCASE ASC, l.id DESC',
        'title_desc'       => 'b.title COLLATE NOCASE DESC, l.id DESC',
        'highest_rated'    => 'b.average_rating DESC, b.ratings_count DESC, l.id DESC',
        'lowest_rated'     => 'b.average_rating ASC, l.id DESC',
        'progress'         => 'l.progress_percentage DESC, l.id DESC',
        // "Most reviewed" counts the platform's OWN approved reviews,
        // not the external ratings_count the highest/lowest-rated
        // sorts use: a book reviewed by many readers on BookSphere
        // outranks a popular-but-unreviewed title here.
        'most_reviewed'    => '(SELECT COUNT(*) FROM reviews r
                               WHERE r.book_id = b.id AND r.status = \'approved\') DESC, l.id DESC',
        // NOTE: 'most_recommended' has no static fragment here on purpose
        // - see orderFor(), which swaps in the recommendation-aware
        // ordering (the engine's suggested book ids first) whenever a
        // recommendation set is available, so the default below is only
        // the shared best-effort fallback.
        'most_recommended' => 'b.ratings_count DESC, l.id DESC',
    ];

    /**
     * The user_preferences columns the repository may write (the
     * service validates the VALUES; the repository only filters the
     * KEYS so a stray column can never be injected).
     */
    private const PREFERENCE_COLUMNS = ['library_sort', 'library_view'];

    /**
     * The base SELECT of every library read: the full library record
     * plus the book display columns every shelf / card needs - the
     * title, the cover, the rating, and the author / category lists
     * (the same GROUP_CONCAT aggregates the Book module uses) - all
     * fetched without an N+1 lookup per row.
     */
    private const SELECT = 'l.*,
        b.title            AS book_title,
        b.cover_image      AS book_cover,
        b.average_rating   AS book_average_rating,
        b.ratings_count    AS book_ratings_count,
        (SELECT COUNT(*) FROM reviews r
         WHERE r.book_id = b.id AND r.status = \'approved\') AS book_review_count,
        (SELECT GROUP_CONCAT(a.name, ", ")
         FROM book_authors ba
         JOIN authors a ON a.id = ba.author_id
         WHERE ba.book_id = b.id) AS book_authors,
        (SELECT GROUP_CONCAT(c.name, ", ")
         FROM book_categories bc
         JOIN categories c ON c.id = bc.category_id
         WHERE bc.book_id = b.id) AS book_categories';

    /**
     * Insert a new library record and return its id.
     *
     * @param array<string, mixed> $data Normalized column values:
     *                                   user_id, book_id, library_status,
     *                                   is_favorite, progress_percentage,
     *                                   started_reading_at, finished_reading_at
     */
    public function create(array $data): int
    {
        db()->execute(
            'INSERT INTO user_library
                (user_id, book_id, library_status, is_favorite, progress_percentage,
                 started_reading_at, finished_reading_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['user_id'],
                $data['book_id'],
                $data['library_status'],
                $data['is_favorite'],
                $data['progress_percentage'],
                $data['started_reading_at'] ?? null,
                $data['finished_reading_at'] ?? null,
                $this->now(),
                $this->now(),
            ],
        );

        return (int) db()->lastInsertId();
    }

    /**
     * Update a library record's mutable fields. Only the keys present
     * in $data are written (a partial update), the timestamps that
     * carry meaning stay untouched - the finished_reading_at /
     * started_reading_at lifecycle is the SERVICE's decision, never
     * guessed here.
     *
     * @param array<string, mixed> $data Subset of the mutable columns:
     *                                   library_status, is_favorite,
     *                                   progress_percentage,
     *                                   started_reading_at,
     *                                   finished_reading_at.
     *                                   An explicit null means "write NULL"
     *                                   (handled via SQL), so flipping a
     *                                   timestamp to empty is possible.
     */
    public function update(int $id, array $data): bool
    {
        $assignments = [];
        $params      = [];

        foreach (['library_status', 'is_favorite', 'progress_percentage', 'started_reading_at', 'finished_reading_at'] as $column) {
            if (array_key_exists($column, $data)) {
                $assignments[] = "{$column} = ?";
                $params[]      = $data[$column];
            }
        }

        // An update with nothing to write is a no-op (callers never
        // hit this - the service always passes at least one field).
        if ($assignments === []) {
            return false;
        }

        $params[] = $this->now();
        $params[] = $id;

        return db()->execute(
            'UPDATE user_library
             SET ' . implode(', ', $assignments) . ', updated_at = ?
             WHERE id = ?',
            $params,
        ) > 0;
    }

    /**
     * Hard delete a library record (library rows are small and their
     * loss is harmless - no soft delete).
     */
    public function delete(int $id): bool
    {
        return db()->execute('DELETE FROM user_library WHERE id = ?', [$id]) > 0;
    }

    /**
     * Find a single library record by primary key.
     *
     * @return array<string, mixed>|null The library row (with the book
     *                                   title and cover attached), or null
     */
    public function find(int $id): ?array
    {
        $rows = db()->query(
            'SELECT ' . self::SELECT . '
             FROM user_library l
             JOIN books b ON b.id = l.book_id
             WHERE l.id = ?',
            [$id],
        );

        return $rows[0] ?? null;
    }

    /**
     * Every library record of one user, newest first ("My Library").
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByUser(int $userId, int $limit = 50): array
    {
        return db()->query(
            'SELECT ' . self::SELECT . '
             FROM user_library l
             JOIN books b ON b.id = l.book_id
             WHERE l.user_id = ?
             ORDER BY l.updated_at DESC, l.id DESC
             LIMIT ?',
            [$userId, $limit],
        );
    }

    /**
     * The user's single library record for ONE book (the record that
     * status / progress / favourite operations act on).
     *
     * The UNIQUE (user_id, book_id) index guarantees at most one row,
     * so the first hit is the answer.
     *
     * @return array<string, mixed>|null The library row, or null when
     *                                   the user has no record for it
     */
    public function findByBook(int $userId, int $bookId): ?array
    {
        $rows = db()->query(
            'SELECT ' . self::SELECT . '
             FROM user_library l
             JOIN books b ON b.id = l.book_id
             WHERE l.user_id = ? AND l.book_id = ?',
            [$userId, $bookId],
        );

        return $rows[0] ?? null;
    }

    /**
     * Whether a user already has a record for the book.
     *
     * This is the service's duplicate-prevention read; the UNIQUE
     * (user_id, book_id) index is the last line of defence should two
     * requests ever race past it.
     */
    public function exists(int $userId, int $bookId): bool
    {
        $rows = db()->query(
            'SELECT id
             FROM user_library
             WHERE user_id = ? AND book_id = ?',
            [$userId, $bookId],
        );

        return $rows !== [];
    }

    /**
     * One status shelf of the user, newest first. This is the generic
     * bucket behind the category pages: the dedicated wishlist /
     * currentlyReading / finished scopes stay as their own named
     * queries (each with its own documentation and ordering), so a
     * caller that needs "the on_hold shelf" or "the dropped shelf"
     * can ask without a new method.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByStatus(int $userId, string $status, int $limit = 50): array
    {
        return db()->query(
            'SELECT ' . self::SELECT . '
             FROM user_library l
             JOIN books b ON b.id = l.book_id
             WHERE l.user_id = ? AND l.library_status = ?
             ORDER BY l.updated_at DESC, l.id DESC
             LIMIT ?',
            [$userId, $status, $limit],
        );
    }

    /**
     * Search a user's own library by the book title, publisher or
     * language (Phase 8.3: the two catalogue columns joined the earlier
     * title / author / category search) - the "simple" search the
     * service exposes. The query never leaves the user's records, and
     * the EXISTS subqueries keep a multi-author / multi-category book
     * from multiplying into duplicate rows (same approach as
     * BookRepository::whereParts). The dashboard grid's own search
     * (the combined filter() builder) matches the SAME columns, so the
     * two surfaces can never drift apart.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(int $userId, string $query, int $limit = 50): array
    {
        [$where, $params] = $this->filterClause($userId, ['q' => $query]);

        return db()->query(
            'SELECT ' . self::SELECT . '
             FROM user_library l
             JOIN books b ON b.id = l.book_id'
            . $where
            . ' ORDER BY l.updated_at DESC, l.id DESC
               LIMIT ?',
            array_merge($params, [$limit]),
        );
    }

    /**
     * The user's favourite books (scope: favorites) - newest first.
     * Favourites work independently of the status: a finished book may
     * also be a favourite.
     *
     * @return array<int, array<string, mixed>>
     */
    public function favorites(int $userId, int $limit = 50): array
    {
        return db()->query(
            'SELECT ' . self::SELECT . '
             FROM user_library l
             JOIN books b ON b.id = l.book_id
             WHERE l.user_id = ? AND l.is_favorite = 1
             ORDER BY l.updated_at DESC, l.id DESC
             LIMIT ?',
            [$userId, $limit],
        );
    }

    /**
     * The user's "want to read" shelf (scope: wishlist) - the modern
     * wishlist, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function wishlist(int $userId, int $limit = 50): array
    {
        return db()->query(
            'SELECT ' . self::SELECT . '
             FROM user_library l
             JOIN books b ON b.id = l.book_id
             WHERE l.user_id = ? AND l.library_status = \'' . self::STATUS_WANT_TO_READ . '\'
             ORDER BY l.updated_at DESC, l.id DESC
             LIMIT ?',
            [$userId, $limit],
        );
    }

    /**
     * The user's "currently reading" shelf (scope: currentlyReading),
     * newest first. Phase 8.3: this is the data layer's canonical
     * reading shelf under its resume-dashboard name; the dedicated
     * currentlyReading() scope stays as a delegate so both callers
     * (the Phase 8.2 dashboard shelf and the Phase 8.3 dashboard)
     * read one query.
     *
     * @return array<int, array<string, mixed>>
     */
    public function continueReading(int $userId, int $limit = 12): array
    {
        return db()->query(
            'SELECT ' . self::SELECT . '
             FROM user_library l
             JOIN books b ON b.id = l.book_id
             WHERE l.user_id = ? AND l.library_status = \'' . self::STATUS_CURRENTLY_READING . '\'
             ORDER BY l.updated_at DESC, l.id DESC
             LIMIT ?',
            [$userId, $limit],
        );
    }

    /**
     * The user's "currently reading" shelf (scope: currentlyReading),
     * newest first - the alias of continueReading() under the Phase
     * 8.2 name the earlier dashboard reads.
     *
     * @return array<int, array<string, mixed>>
     */
    public function currentlyReading(int $userId, int $limit = 50): array
    {
        return $this->continueReading($userId, $limit);
    }

    /**
     * The user's finished books (scope: finished), most recently
     * finished first. This ordering is the natural reading-history
     * order, so readingHistory() and completedBooks() - the Phase 8.5
     * recommendation hooks - read straight from this shelf.
     *
     * @return array<int, array<string, mixed>>
     */
    public function finished(int $userId, int $limit = 50): array
    {
        return db()->query(
            'SELECT ' . self::SELECT . '
             FROM user_library l
             JOIN books b ON b.id = l.book_id
             WHERE l.user_id = ? AND l.library_status = \'' . self::STATUS_FINISHED . '\'
             ORDER BY l.finished_reading_at DESC, l.id DESC
             LIMIT ?',
            [$userId, $limit],
        );
    }

    /**
     * A page of the dashboard book grid (Phase 8.3): the generic
     * combined read behind every grid view. One query answers "which
     * rows for these filters, this sort, this page" through the
     * shared WHERE builder (filterClause) and the SORTS ordering map.
     *
     * Recognized $filters keys (each optional, each already type-
     * normalized by the service; unknown keys are ignored):
     *
     *     - q                -> title / publisher / language / author /
     *                           category LIKE search
     *     - status           -> one of the five shelves
     *     - category         -> a category id (EXISTS on the junction)
     *     - author           -> an author id (EXISTS on the junction)
     *     - rating           -> minimum book.average_rating (1-5)
     *     - favorite         -> is_favorite = 1
     *     - recently_added   -> created during the current month
     *     - recently_updated -> updated during the last 30 days
     *
     * $recommended is the OPTIONAL engine suggestion set (book ids)
     * consumed by the 'most_recommended' sort: the suggested books
     * sort first, everything else follows by ratings count. Without a
     * set (or without the engine) the sort degrades to its static
     * fallback - never an error.
     *
     * @param array<int, int> $recommended Recommended book ids
     * @return array<int, array<string, mixed>>
     */
    public function filter(int $userId, array $filters = [], string $sort = 'newest_added', int $offset = 0, int $limit = 50, array $recommended = []): array
    {
        [$where, $params] = $this->filterClause($userId, $filters);
        [$order, $orderParams] = $this->orderFor($sort, $recommended);

        // The page bounds stay defensive here (an extreme page number
        // in the URL is harmless either way).
        $offset = max(0, $offset);
        $limit  = max(1, min(100, $limit));

        return db()->query(
            'SELECT ' . self::SELECT . '
             FROM user_library l
             JOIN books b ON b.id = l.book_id'
            . $where
            . ' ORDER BY ' . $order
            . ' LIMIT ? OFFSET ?',
            array_merge($params, $orderParams, [$limit, $offset]),
        );
    }

    /**
     * The ORDER BY fragment of a filter() call: the static SORTS map
     * entry, or - for 'most_recommended' with a non-empty suggestion
     * set - a parameterized CASE that ranks the engine's suggested
     * book ids first. The ids travel as prepared IN placeholders, so
     * the fragment stays closed even though it is built dynamically.
     *
     * @param array<int, int> $recommended Recommended book ids
     * @return array{0: string, 1: array<int, int>} The ORDER BY
     *                                              fragment and its
     *                                              parameters
     */
    private function orderFor(string $sort, array $recommended): array
    {
        $ids = [];

        if ($sort === 'most_recommended') {
            foreach ($recommended as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }

            if ($ids !== []) {
                $placeholders = implode(', ', array_fill(0, count($ids), '?'));

                return [
                    'CASE WHEN b.id IN (' . $placeholders . ') THEN 0 ELSE 1 END ASC, b.ratings_count DESC, l.id DESC',
                    array_values($ids),
                ];
            }
        }

        return [self::SORTS[$sort] ?? self::SORTS['newest_added'], []];
    }

    /**
     * The total row count behind a filter set (the pagination
     * denominator of the Phase 8.3 grid). Shares every WHERE clause
     * with filter() so the pages it counts and the rows it returns
     * can never disagree.
     */
    public function countFiltered(int $userId, array $filters = []): int
    {
        [$where, $params] = $this->filterClause($userId, $filters);

        return (int) db()->query(
            'SELECT COUNT(*) AS count
             FROM user_library l
             JOIN books b ON b.id = l.book_id'
            . $where,
            $params,
        )[0]['count'];
    }

    /**
     * The combined pagination answer of the dashboard grid: the total,
     * the page bounds, and the page of rows - one call, everything a
     * rendered grid (and its pagination bar) needs. Page arithmetic is
     * clamped: page 0 and page 999 both land on a real page.
     *
     * @return array<string, mixed> Keys: items, total, page, pages,
     *                              per_page, has_prev, has_next
     */
    public function paginate(int $userId, array $filters = [], string $sort = 'newest_added', int $page = 1, int $perPage = 12, array $recommended = []): array
    {
        $perPage = max(1, min(50, $perPage));
        $page    = max(1, $page);

        $total = $this->countFiltered($userId, $filters);
        $pages = max(1, (int) ceil($total / $perPage));
        $page  = min($page, $pages);

        $items = $this->filter($userId, $filters, $sort, ($page - 1) * $perPage, $perPage, $recommended);

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $perPage,
            'has_prev' => $page > 1,
            'has_next' => $page < $pages,
        ];
    }

    // --- Phase 8.4: bulk actions, collections, recent activity --------

    /**
     * Normalize the raw ids of a bulk request into a de-duplicated
     * list of positive integers - every bulk operation's shared gate,
     * so a tampered payload can never smuggle a negative id, a
     * non-numeric string or a duplicate into a prepared statement.
     *
     * @param array<int|string> $ids
     * @return array<int, int>
     */
    private function normalizeIds(array $ids): array
    {
        $clean = [];

        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $clean[$id] = $id;
            }
        }

        return array_values($clean);
    }

    /**
     * The user-scoped IN-clause fragment and parameters shared by the
     * bulk write methods. The ids are always passed through
     * normalizeIds() first, so the placeholders are bounded by the
     * caller's own list - a user can only ever target their own rows
     * (the user_id guard), one record per id. The fragment targets
     * the bare user_library table (no alias - the bulk statements are
     * UPDATE/DELETE, which carry no joins).
     *
     * @param array<int, int> $ids
     * @return array{0: string, 1: array<int, int>} "user_id = ? AND
     *                                              id IN (...)" and the
     *                                              [user_id, ...ids]
     *                                              parameters
     */
    private function ownedIdsClause(int $userId, array $ids): array
    {
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        return [
            'user_id = ? AND id IN (' . $placeholders . ')',
            array_merge([$userId], $ids),
        ];
    }

    /**
     * Move several of the user's library records to one shelf (a bulk
     * status update). The supplied owner ids are never trusted: every
     * record is re-gated by the user_id guard, so a foreign record id
     * in the payload is simply skipped.
     *
     * @param array<int|string> $ids Record ids (normalized here)
     * @return int The number of records actually moved
     */
    public function bulkStatus(int $userId, array $ids, string $status): int
    {
        $ids = $this->normalizeIds($ids);

        if ($ids === []) {
            return 0;
        }

        [$where, $params] = $this->ownedIdsClause($userId, $ids);

        return db()->execute(
            'UPDATE user_library
             SET library_status = ?, updated_at = ?
             WHERE ' . $where,
            array_merge([$status, $this->now()], $params),
        );
    }

    /**
     * Mark or un-mark several of the user's books as favourites (a
     * bulk favourite toggle). Independent of the status, exactly like
     * the single-record toggle.
     *
     * @param array<int|string> $ids Record ids (normalized here)
     * @param bool $favorite The value to set (true = favourite)
     * @return int The number of records actually updated
     */
    public function bulkFavorite(int $userId, array $ids, bool $favorite): int
    {
        $ids = $this->normalizeIds($ids);

        if ($ids === []) {
            return 0;
        }

        [$where, $params] = $this->ownedIdsClause($userId, $ids);

        return db()->execute(
            'UPDATE user_library
             SET is_favorite = ?, updated_at = ?
             WHERE ' . $where,
            array_merge([$favorite ? 1 : 0, $this->now()], $params),
        );
    }

    /**
     * Remove several of the user's library records (a bulk delete). A
     * foreign record id in the payload is skipped by the user_id
     * guard, so the destructive action can never touch another user's
     * rows.
     *
     * @param array<int|string> $ids Record ids (normalized here)
     * @return int The number of records actually removed
     */
    public function bulkDelete(int $userId, array $ids): int
    {
        $ids = $this->normalizeIds($ids);

        if ($ids === []) {
            return 0;
        }

        [$where, $params] = $this->ownedIdsClause($userId, $ids);

        return db()->execute(
            'DELETE FROM user_library
             WHERE ' . $where,
            $params,
        );
    }

    /**
     * The collection statistics of the user's library (Phase 8.4): for
     * every collection - "all", the five status shelves, and
     * "favorites" - the book count, the mean book rating and the last
     * activity timestamp. One UNION ALL aggregation, every bucket
     * keyed by its collection id, so the collections rail can paint
     * its occupancy numbers in one read.
     *
     * @return array<string, array<string, mixed>> Collection id -> keys:
     *                                              count, average_rating,
     *                                              last_updated
     */
    public function collectionStatistics(int $userId): array
    {
        $rows = db()->query(
            'SELECT \'all\'      AS collection, COUNT(*) AS count, COALESCE(AVG(b.average_rating), 0) AS average_rating, MAX(l.updated_at) AS last_updated
             FROM user_library l
             JOIN books b ON b.id = l.book_id
             WHERE l.user_id = ?
             UNION ALL
             SELECT l.library_status AS collection, COUNT(*) AS count, COALESCE(AVG(b.average_rating), 0) AS average_rating, MAX(l.updated_at) AS last_updated
             FROM user_library l
             JOIN books b ON b.id = l.book_id
             WHERE l.user_id = ?
             GROUP BY l.library_status
             UNION ALL
             SELECT \'favorites\' AS collection, COUNT(*) AS count, COALESCE(AVG(b.average_rating), 0) AS average_rating, MAX(l.updated_at) AS last_updated
             FROM user_library l
             JOIN books b ON b.id = l.book_id
             WHERE l.user_id = ? AND l.is_favorite = 1',
            [$userId, $userId, $userId],
        );

        // Every collection id is GUARANTEED to be present (an empty
        // shelf reads count 0 / rating 0.0 / no stamp) - the same
        // defaulted-map contract statusCounts() keeps, so the
        // collections rail never has to guard a missing key.
        $collections = [
            'all'               => ['count' => 0, 'average_rating' => 0.0, 'last_updated' => null],
            self::STATUS_WANT_TO_READ      => ['count' => 0, 'average_rating' => 0.0, 'last_updated' => null],
            self::STATUS_CURRENTLY_READING => ['count' => 0, 'average_rating' => 0.0, 'last_updated' => null],
            self::STATUS_FINISHED          => ['count' => 0, 'average_rating' => 0.0, 'last_updated' => null],
            self::STATUS_ON_HOLD           => ['count' => 0, 'average_rating' => 0.0, 'last_updated' => null],
            self::STATUS_DROPPED           => ['count' => 0, 'average_rating' => 0.0, 'last_updated' => null],
            'favorites'         => ['count' => 0, 'average_rating' => 0.0, 'last_updated' => null],
        ];

        foreach ($rows as $row) {
            $collections[$row['collection']] = [
                'count'          => (int) $row['count'],
                'average_rating' => round((float) $row['average_rating'], 1),
                'last_updated'   => $row['last_updated'],
            ];
        }

        return $collections;
    }

    /**
     * The user's most recently added books (the Phase 8.4 "recently
     * added" reads of the dashboard / profile) - the newest_added
     * sort, capped. The dedicated name documents the intent and keeps
     * the callers free of sort spellings.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentlyAdded(int $userId, int $limit = 12): array
    {
        return $this->filter($userId, [], 'newest_added', 0, $limit);
    }

    /**
     * The user's most recently updated books (the Phase 8.4 "recently
     * updated" strip) - the recently_updated sort, capped.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentlyUpdated(int $userId, int $limit = 12): array
    {
        return $this->filter($userId, [], 'recently_updated', 0, $limit);
    }

    /**
     * The distinct filter vocabulary of the user's own library (Phase
     * 8.3): the category and author ids+names found in the books the
     * user keeps, alphabetically ordered - the dropdown options of the
     * dashboard filter bar. Empty arrays when the library is empty.
     *
     * @return array<string, array<int, array<string, mixed>>> Keys:
     *                                                          categories,
     *                                                          authors (rows
     *                                                          with id, name)
     */
    public function filterOptions(int $userId): array
    {
        $categories = db()->query(
            'SELECT DISTINCT c.id, c.name
             FROM user_library l
             JOIN book_categories bc ON bc.book_id = l.book_id
             JOIN categories c       ON c.id = bc.category_id
             WHERE l.user_id = ?
             ORDER BY c.name ASC',
            [$userId],
        );

        $authors = db()->query(
            'SELECT DISTINCT a.id, a.name
             FROM user_library l
             JOIN book_authors ba ON ba.book_id = l.book_id
             JOIN authors a       ON a.id = ba.author_id
             WHERE l.user_id = ?
             ORDER BY a.name ASC',
            [$userId],
        );

        return ['categories' => $categories, 'authors' => $authors];
    }

    /**
     * The shared WHERE builder of every filtered library read (the
     * dashboard grid, the paginator, the count, and the simple
     * search). Every recognized filter becomes a prepared parameter;
     * unknown keys are silently ignored. The LIKE wildcards are placed
     * on a value the caller passed (the service trims it), never on
     * the SQL text.
     *
     * @return array{0: string, 1: array<int, mixed>} The WHERE clause
     *                                                (starts with
     *                                                " WHERE ...") and
     *                                                its parameters
     */
    private function filterClause(int $userId, array $filters = []): array
    {
        $clauses = ['l.user_id = ?'];
        $params  = [$userId];

        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $like = '%' . $query . '%';

            $clauses[] = '(b.title LIKE ?
                           OR b.description LIKE ?
                           OR b.publisher LIKE ?
                           OR b.language LIKE ?
                           OR EXISTS (
                               SELECT 1
                               FROM book_authors ba
                               JOIN authors a ON a.id = ba.author_id
                               WHERE ba.book_id = b.id AND a.name LIKE ?
                           )
                           OR EXISTS (
                               SELECT 1
                               FROM book_categories bc
                               JOIN categories c ON c.id = bc.category_id
                               WHERE bc.book_id = b.id AND c.name LIKE ?
                           ))';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, [self::STATUS_WANT_TO_READ, self::STATUS_CURRENTLY_READING, self::STATUS_FINISHED, self::STATUS_ON_HOLD, self::STATUS_DROPPED], true)) {
            $clauses[] = 'l.library_status = ?';
            $params[]  = $status;
        }

        $category = (int) ($filters['category'] ?? 0);
        if ($category > 0) {
            $clauses[] = 'EXISTS (
                SELECT 1
                FROM book_categories bc
                WHERE bc.book_id = b.id AND bc.category_id = ?
            )';
            $params[] = $category;
        }

        $author = (int) ($filters['author'] ?? 0);
        if ($author > 0) {
            $clauses[] = 'EXISTS (
                SELECT 1
                FROM book_authors ba
                WHERE ba.book_id = b.id AND ba.author_id = ?
            )';
            $params[] = $author;
        }

        $rating = (int) ($filters['rating'] ?? 0);
        if ($rating >= 1 && $rating <= 5) {
            $clauses[] = 'b.average_rating >= ?';
            $params[]  = $rating;
        }

        if (!empty($filters['favorite'])) {
            $clauses[] = 'l.is_favorite = 1';
        }

        if (!empty($filters['recently_added'])) {
            // The timestamps are UTC ISO8601, which compares
            // lexicographically like chronologically; date('now',
            // 'start of month') is UTC too, so a plain string
            // comparison is a "this month" test.
            $clauses[] = "l.created_at >= date('now', 'start of month')";
        }

        if (!empty($filters['recently_updated'])) {
            $clauses[] = "l.updated_at >= date('now', '-30 days')";
        }

        return [' WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
     * The per-user library overview: how many books are on each
     * shelf, how many are favourites, the average reading progress
     * of the started books, the books added during the current
     * calendar month and the total record count. One aggregate query
     * over the status buckets (the backend behind the library
     * dashboard counters and the /library/statistics page).
     *
     * The stored timestamps are the UTC ISO8601 format
     * ('YYYY-MM-DDTHH:MM:SSZ'), which compares lexicographically the
     * same way it compares chronologically, so "created this month"
     * is a plain string comparison against the first of the month.
     *
     * @return array<string, mixed> Keys: total, favorites,
     *                              statuses (status -> count),
     *                              average_progress (current/finished,
     *                              float|null), started, finished,
     *                              added_this_month
     */
    public function statistics(int $userId): array
    {
        $totals = db()->query(
            'SELECT COUNT(*)                                                AS total,
                    SUM(CASE WHEN is_favorite = 1 THEN 1 ELSE 0 END)        AS favorites,
                    SUM(CASE WHEN created_at >= date(\'now\', \'start of month\') THEN 1 ELSE 0 END) AS added_this_month,
                    COALESCE(AVG(CASE WHEN progress_percentage > 0 THEN progress_percentage END), 0) AS average_progress
             FROM user_library
             WHERE user_id = ?',
            [$userId],
        )[0];

        $statuses = db()->query(
            'SELECT library_status, COUNT(*) AS count
             FROM user_library
             WHERE user_id = ?
             GROUP BY library_status',
            [$userId],
        );

        $byStatus = [];

        foreach ($statuses as $row) {
            $byStatus[$row['library_status']] = (int) $row['count'];
        }

        return [
            'total'            => (int) ($totals['total'] ?? 0),
            'favorites'        => (int) ($totals['favorites'] ?? 0),
            'average_progress' => (float) ($totals['average_progress'] ?? 0),
            'added_this_month' => (int) ($totals['added_this_month'] ?? 0),
            'started'          => (int) ($byStatus[self::STATUS_CURRENTLY_READING] ?? 0),
            'finished'         => (int) ($byStatus[self::STATUS_FINISHED] ?? 0),
            'statuses'         => $byStatus,
        ];
    }

    /**
     * The genre preferences of one user's library (the Phase 8.5
     * preferredGenres() hook): the categories shared by the books the
     * user keeps, most-kept first. One aggregation across the
     * book_categories junction - the SQL library rows never touch
     * categories directly.
     *
     * @return array<int, array<string, mixed>> Rows with id, name,
     *                                          count (books on the
     *                                          shelves of that category)
     */
    public function preferredGenres(int $userId, int $limit = 5): array
    {
        return db()->query(
            'SELECT c.id,
                    c.name,
                    COUNT(DISTINCT l.book_id) AS count
             FROM user_library l
             JOIN book_categories bc ON bc.book_id = l.book_id
             JOIN categories c       ON c.id = bc.category_id
             WHERE l.user_id = ?
             GROUP BY c.id
             ORDER BY count DESC, c.name ASC
             LIMIT ?',
            [$userId, $limit],
        );
    }

    /**
     * The one-composed-call payload of the library dashboard (Phase
     * 8.3): the overview statistics, the reading summary and the
     * reading streak - every number the page header, the stat row and
     * the summary section show, so the page makes a single aggregated
     * read instead of three (the same composition pattern the reviews
     * dashboard introduced).
     *
     * @return array<string, mixed> Keys: statistics, summary, streak
     */
    public function dashboard(int $userId): array
    {
        return [
            'statistics' => $this->statistics($userId),
            'summary'    => $this->readingSummary($userId),
            'streak'     => $this->readingStreak($userId),
        ];
    }

    /**
     * The reading summary statistics of the dashboard (Phase 8.3):
     *
     *     - favourite_genre       the category the user keeps the most
     *     - favourite_author      the author the user keeps the most
     *     - average_rating_given  the mean rating of the user's
     *                             approved reviews (0 when none)
     *     - average_progress      the mean progress of the started
     *                             books (reads through statistics())
     *     - finished              the finished count (same read)
     *
     * Each aggregate is its own small query (none of them touches the
     * other, and they are all user-scoped).
     *
     * @return array<string, mixed>
     */
    public function readingSummary(int $userId): array
    {
        $stats = $this->statistics($userId);

        $genre = $this->preferredGenres($userId, 1)[0] ?? null;

        $author = db()->query(
            'SELECT a.name,
                    COUNT(DISTINCT l.book_id) AS count
             FROM user_library l
             JOIN book_authors ba ON ba.book_id = l.book_id
             JOIN authors a       ON a.id = ba.author_id
             WHERE l.user_id = ?
             GROUP BY a.id
             ORDER BY count DESC, a.name ASC
             LIMIT 1',
            [$userId],
        )[0] ?? null;

        $rating = db()->query(
            'SELECT COALESCE(AVG(rating), 0) AS average
             FROM reviews
             WHERE user_id = ? AND status = \'approved\'',
            [$userId],
        )[0]['average'] ?? 0;

        return [
            'favourite_genre'      => $genre['name'] ?? null,
            'favourite_author'     => $author['name'] ?? null,
            'average_rating_given' => round((float) $rating, 1),
            'average_progress'     => round((float) $stats['average_progress'], 1),
            'finished'             => (int) $stats['finished'],
        ];
    }

    /**
     * The reading streak of the dashboard (Phase 8.3): how many
     * consecutive days the user's library saw activity, both the
     * current run (counting backwards from today or - never breaking
     * a streak that is still alive - yesterday) and the longest run
     * ever. A day with any library write (create / status / progress
     * / favourite thus updates updated_at) counts as an active day.
     *
     * The brief's "reading streak" lands as a REAL library-activity
     * count here (the analytics module of Phase 8.4 will later
     * broaden it); the dashboard renders it as the current streak
     * only.
     *
     * @return array<string, int> Keys: current, longest
     */
    public function readingStreak(int $userId): array
    {
        $rows = db()->query(
            'SELECT DISTINCT substr(updated_at, 1, 10) AS day
             FROM user_library
             WHERE user_id = ?
             ORDER BY day DESC
             LIMIT 400',
            [$userId],
        );

        $days = array_column($rows, 'day');

        if ($days === []) {
            return ['current' => 0, 'longest' => 0];
        }

        // Longest run: walk the descending dates and count each run of
        // consecutive days.
        $longest = 0;
        $run     = 0;
        $prev    = null;

        foreach ($days as $day) {
            $run = ($prev !== null && $this->dayDelta($prev, $day) === 1) ? $run + 1 : 1;
            $longest = max($longest, $run);
            $prev = $day;
        }

        // Current run: count consecutive days ending today or - when
        // today is not an active day yet - yesterday (a streak that
        // started yesterday is still alive).
        $today = gmdate('Y-m-d');
        $current = 0;
        $last = $days[0];

        if ($last === $today || $last === $this->dayBefore($today)) {
            $current = 1;
            $cursor  = $this->dayBefore($last);

            for ($i = 1, $count = count($days); $i < $count; $i++) {
                if ($days[$i] === $cursor) {
                    $current++;
                    $cursor = $this->dayBefore($days[$i]);
                } else {
                    break;
                }
            }
        }

        return ['current' => $current, 'longest' => $longest];
    }

    /**
     * The user_preferences read of the dashboard (Phase 8.3): one
     * preference value of the user's row, or the fallback when the
     * row (or the value) is missing. The two known keys are
     * library_sort and library_view; anything else returns the
     * fallback.
     */
    public function preference(int $userId, string $key, ?string $default = null): ?string
    {
        $row = db()->query(
            'SELECT library_sort, library_view
             FROM user_preferences
             WHERE user_id = ?',
            [$userId],
        )[0] ?? [];

        $values = [
            'library_sort' => $row['library_sort'] ?? null,
            'library_view' => $row['library_view'] ?? null,
        ];

        return $values[$key] ?? $default;
    }

    /**
     * The user_preferences write of the dashboard (Phase 8.3): merge
     * the given values into the user's row (a one-row-per-user table,
     * user_id is the primary key) and upsert it. Only the two
     * preference columns are ever written - an unknown key is
     * silently dropped, and the VALUES are the caller's responsibility
     * (the service validates them against its allowlists before this
     * is reached; the library_view CHECK constraint is the last line
     * of defence).
     *
     * @param array<string, mixed> $values library_sort / library_view
     */
    public function savePreferences(int $userId, array $values): void
    {
        $values = array_intersect_key($values, array_flip(self::PREFERENCE_COLUMNS));

        if ($values === []) {
            return;
        }

        $sort = (string) ($values['library_sort'] ?? $this->preference($userId, 'library_sort') ?? 'newest_added');
        $view = (string) ($values['library_view'] ?? $this->preference($userId, 'library_view') ?? 'grid');

        db()->execute(
            'INSERT INTO user_preferences (user_id, library_sort, library_view, updated_at)
             VALUES (?, ?, ?, ?)
             ON CONFLICT (user_id) DO UPDATE SET
                 library_sort = excluded.library_sort,
                 library_view = excluded.library_view,
                 updated_at   = excluded.updated_at',
            [$userId, $sort, $view, $this->now()],
        );
    }

    /**
     * The current UTC timestamp in the database's stored format.
     */
    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    /**
     * The number of calendar days between two 'Y-m-d' strings (a
     * positive value when $later follows $earlier).
     */
    private function dayDelta(string $later, string $earlier): int
    {
        return (int) floor((strtotime($later) - strtotime($earlier)) / 86400);
    }

    /**
     * The calendar day before a 'Y-m-d' string.
     */
    private function dayBefore(string $day): string
    {
        return gmdate('Y-m-d', strtotime('-1 day', strtotime($day)));
    }
}