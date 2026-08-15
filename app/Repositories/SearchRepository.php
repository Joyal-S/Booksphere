<?php

declare(strict_types=1);

namespace BookSphere\App\Repositories;

use BookSphere\App\DTO\SearchQuerySpec;

/**
 * SearchRepository
 *
 * The data-access layer of the global search module (Phase 11.2).
 * EVERY SQL query the module runs lives here and here only - the
 * same role BookRepository plays for the book module.
 *
 * Contract (the Phase 11.1 architecture, Task 1):
 *     - prepared statements everywhere; every condition bound
 *     - column / sort tokens come from hard whitelists
 *     - module-owned reads via the existing db() PDO connection
 *     - deliberately NO business rules (scoring, filter semantics);
 *       those live in the service/builder
 *
 * Each searchXxx() accepts a SearchQuerySpec and returns a page of
 * the entity's own row shape PLUS the total match count - one COUNT
 * + one LIMIT/OFFSET per scope, the exact strategy of browse().
 *
 * Multi-word terms: every word must match somewhere (AND), so
 * "harry potter" finds volumes carrying both words. LIKE travels
 * the normal SQLite path and is case-insensitive for ASCII, exactly
 * like the browse module.
 */
final class SearchRepository
{
    /** The books columns the search hit needs to render a card. */
    private const BOOK_COLUMNS = 'b.id, b.title, b.subtitle, b.description, b.publisher,
             b.language, b.isbn, b.published_year, b.page_count,
             b.cover_image, b.average_rating, b.ratings_count, b.status,
             (SELECT GROUP_CONCAT(a.name, ", ")
              FROM book_authors ba
              JOIN authors a ON a.id = ba.author_id
              WHERE ba.book_id = b.id) AS authors_list,
             (SELECT c.name
              FROM book_categories bc
              JOIN categories c ON c.id = bc.category_id
              WHERE bc.book_id = b.id LIMIT 1) AS category_name';

    /**
     * Search the books catalogue.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function searchBooks(SearchQuerySpec $query): array
    {
        [$where, $params] = $this->bookWhere($query, 'b');
        [$filterWhere, $filterParams] = $this->bookFilters($query, 'b');

        $where  = array_merge(['b.deleted_at IS NULL'], $where, $filterWhere);
        $params = array_merge(array_values($params), $filterParams);

        $total = (int) db()->query(
            'SELECT COUNT(*) AS count FROM books b WHERE ' . implode(' AND ', $where),
            $params,
        )[0]['count'];

        $items = $total === 0 ? [] : db()->query(
            'SELECT ' . self::BOOK_COLUMNS . '
             FROM books b
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY b.title ASC
             LIMIT ? OFFSET ?',
            $this->pageParams($params, $query, $total),
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Search authors by name.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function searchAuthors(SearchQuerySpec $query): array
    {
        [$where, $params] = $this->wordWhere($query, 'a.name');

        $where  = array_merge(['1 = 1'], $where);
        $params = array_values($params);

        $total = (int) db()->query(
            'SELECT COUNT(*) AS count FROM authors a WHERE ' . implode(' AND ', $where)
                . ' AND a.name != \'\'',
            $params,
        )[0]['count'];

        $items = $total === 0 ? [] : db()->query(
            'SELECT a.id, a.name,
                    (SELECT COUNT(DISTINCT ba.book_id) FROM book_authors ba JOIN books b ON b.id = ba.book_id AND b.deleted_at IS NULL WHERE ba.author_id = a.id) AS book_count,
                    (SELECT AVG(r.rating) FROM reviews r JOIN books b ON b.id = r.book_id AND b.deleted_at IS NULL JOIN book_authors ba ON ba.book_id = b.id WHERE ba.author_id = a.id AND r.status = \'approved\') AS average_rating
             FROM authors a
             WHERE ' . implode(' AND ', $where) . ' AND a.name != \'\'
             ORDER BY a.name ASC
             LIMIT ? OFFSET ?',
            $this->pageParams($params, $query, $total),
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Search categories by name or slug.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function searchCategories(SearchQuerySpec $query): array
    {
        [$where, $params] = $this->categoryWhere($query);

        $total = (int) db()->query(
            'SELECT COUNT(*) AS count FROM categories c WHERE ' . implode(' AND ', $where),
            $params,
        )[0]['count'];

        $items = $total === 0 ? [] : db()->query(
            'SELECT c.id, c.name, c.slug,
                    (SELECT COUNT(DISTINCT bc.book_id) FROM book_categories bc JOIN books b ON b.id = bc.book_id AND b.deleted_at IS NULL WHERE bc.category_id = c.id) AS book_count
             FROM categories c
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY c.name ASC
             LIMIT ? OFFSET ?',
            $this->pageParams($params, $query, $total),
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Search publishers (the distinct publisher values of the books
     * table - the same  source the browse filter dropdown uses).
     *
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function searchPublishers(SearchQuerySpec $query): array
    {
        $where  = ["b.publisher IS NOT NULL AND b.publisher != '' AND b.deleted_at IS NULL"];
        $params = [];

        if ($query->hasQuery()) {
            $patches = [];
            foreach ($query->words as $word) {
                $patches[] = 'b.publisher LIKE ?';
                $params[]  = '%' . $word . '%';
            }
            $where[] = '(' . implode(' AND ', $patches) . ')';
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) db()->query(
            'SELECT COUNT(DISTINCT b.publisher) AS count FROM books b WHERE ' . $whereSql,
            $params,
        )[0]['count'];

        $items = $total === 0 ? [] : db()->query(
            'SELECT DISTINCT b.publisher AS name,
                    (SELECT COUNT(*) FROM books b2 WHERE b2.publisher = b.publisher AND b2.deleted_at IS NULL) AS book_count
             FROM books b
             WHERE ' . $whereSql . '
             ORDER BY b.publisher ASC
             LIMIT ? OFFSET ?',
            $this->pageParams($params, $query, $total),
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Search reviews by title or body, joined to the book so a
     * review hit can render like a book result (the reviewed
     * volume).
     *
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function searchReviews(SearchQuerySpec $query): array
    {
        [$where, $params] = $this->reviewWhere($query);

        $where = array_merge(["r.status = 'approved'"], $where);

        $total = (int) db()->query(
            'SELECT COUNT(*) AS count
             FROM reviews r
             JOIN books b ON b.id = r.book_id AND b.deleted_at IS NULL
             WHERE ' . implode(' AND ', $where),
            array_values($params),
        )[0]['count'];

        $items = $total === 0 ? [] : db()->query(
            'SELECT r.id, r.review, r.rating, r.created_at, b.id AS book_id, b.title AS book_title,
                    (SELECT u.full_name FROM users u WHERE u.id = r.user_id) AS reviewer_name
             FROM reviews r
             JOIN books b ON b.id = r.book_id AND b.deleted_at IS NULL
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY r.created_at DESC
             LIMIT ? OFFSET ?',
            $this->pageParams(array_values($params), $query, $total),
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Suggest book titles (Phase 11.4): a LEAN read for the type-ahead
     * pool - only the id/title/subtitle a suggestion row needs, never
     * the full BOOK_COLUMNS card payload. Matching is the SAME
     * every-word-must-match rule as the full search (anyWordWhere over
     * title + subtitle), deterministically ordered, capped to the spec
     * page size. No COUNT: suggestions never paginate.
     *
     * @return array<int, array<string, mixed>>
     */
    public function suggestBooks(SearchQuerySpec $query): array
    {
        [$where, $params] = $this->anyWordWhere($query, ['b.title', 'b.subtitle']);

        $where = array_merge(['b.deleted_at IS NULL'], $where);
        $params[] = $query->limit();

        return db()->query(
            'SELECT b.id, b.title, b.subtitle
             FROM books b
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY b.title ASC
             LIMIT ?',
            $params,
        );
    }

    /**
     * Suggest author names (Phase 11.4) - wordWhere on a.name, capped.
     *
     * @return array<int, array<string, mixed>>
     */
    public function suggestAuthors(SearchQuerySpec $query): array
    {
        [$where, $params] = $this->wordWhere($query, 'a.name');

        $where = array_merge(['1 = 1', "a.name != ''"], $where);
        $params[] = $query->limit();

        return db()->query(
            'SELECT a.id, a.name
             FROM authors a
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY a.name ASC
             LIMIT ?',
            $params,
        );
    }

    /**
     * Suggest categories (Phase 11.4) - categoryWhere, name or slug.
     *
     * @return array<int, array<string, mixed>>
     */
    public function suggestCategories(SearchQuerySpec $query): array
    {
        [$where, $params] = $this->categoryWhere($query);

        $params[] = $query->limit();

        return db()->query(
            'SELECT c.id, c.name, c.slug
             FROM categories c
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY c.name ASC
             LIMIT ?',
            $params,
        );
    }

    /**
     * Suggest publishers (Phase 11.4) - the DISTINCT live publisher
     * values, the same source the search/browse publisher dropdowns
     * use.
     *
     * @return array<int, array<string, mixed>>
     */
    public function suggestPublishers(SearchQuerySpec $query): array
    {
        $where  = ["b.publisher IS NOT NULL AND b.publisher != '' AND b.deleted_at IS NULL"];
        $params = [];

        if ($query->hasQuery()) {
            $patches = [];
            foreach ($query->words as $word) {
                $patches[] = 'b.publisher LIKE ?';
                $params[]  = '%' . $word . '%';
            }
            $where[] = '(' . implode(' AND ', $patches) . ')';
        }

        $params[] = $query->limit();

        return db()->query(
            'SELECT DISTINCT b.publisher AS name
             FROM books b
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY b.publisher ASC
             LIMIT ?',
            $params,
        );
    }

    /**
     * The books WHERE clause: the term ANDed over the book's own
     * columns plus the author/category names via EXISTS (never a
     * multiplying JOIN - the browse module's proven rule).
     *
     * @return array{0: array<int, string>, 1: array<int, mixed>}
     */
    private function bookWhere(SearchQuerySpec $query, string $alias): array
    {
        $where  = [];
        $params = [];

        if (!$query->hasQuery()) {
            return [$where, $params];
        }

        $patches = [];

        foreach ($query->words as $word) {
            $like = '%' . $word . '%';

            $patches[] = "$alias.title LIKE ?
                       OR $alias.subtitle LIKE ?
                       OR $alias.isbn LIKE ?
                       OR $alias.publisher LIKE ?
                       OR $alias.description LIKE ?
                       OR EXISTS (
                           SELECT 1
                           FROM book_authors ba
                           JOIN authors a ON a.id = ba.author_id
                           WHERE ba.book_id = $alias.id AND a.name LIKE ?
                       )
                       OR EXISTS (
                           SELECT 1
                           FROM book_categories bc
                           JOIN categories c ON c.id = bc.category_id
                           WHERE bc.book_id = $alias.id AND c.name LIKE ?
                       )";

            array_push($params, $like, $like, $like, $like, $like, $like, $like);
        }

        $where[] = '(' . implode(') AND (', $patches) . ')';

        return [$where, $params];
    }

    /**
     * A bare word LIKE on one entity's column (authors: a.name,
     * reviews: r.body), AND across words.
     *
     * @return array{0: array<int, string>, 1: array<int, mixed>}
     */
    private function wordWhere(SearchQuerySpec $query, string $column): array
    {
        $where  = [];
        $params = [];

        if (!$query->hasQuery()) {
            return [$where, $params];
        }

        $patches = [];

        foreach ($query->words as $word) {
            $like      = '%' . $word . '%';
            $patches[] = "$column LIKE ?";
            $params[]  = $like;
        }

        $where[] = '(' . implode(' AND ', $patches) . ')';

        return [$where, $params];
    }

    /**
     * The suggestion variant of wordWhere (Phase 11.4): a term matched
     * against ANY of several columns (book title OR subtitle), AND
     * across words - the same LIKE semantics as the full search,
     * without the book search's author/category EXISTS expansion.
     *
     * @param array<int, string> $columns fully-qualified columns
     * @return array{0: array<int, string>, 1: array<int, mixed>}
     */
    private function anyWordWhere(SearchQuerySpec $query, array $columns): array
    {
        $where  = [];
        $params = [];

        if (!$query->hasQuery()) {
            return [$where, $params];
        }

        $patches = [];

        foreach ($query->words as $word) {
            $clauses = [];

            foreach ($columns as $column) {
                $clauses[] = "$column LIKE ?";
                $params[]  = '%' . $word . '%';
            }

            $patches[] = '(' . implode(' OR ', $clauses) . ')';
        }

        $where[] = '(' . implode(' AND ', $patches) . ')';

        return [$where, $params];
    }

    /**
     * The category WHERE clause (name OR slug, AND across words).
     *
     * @return array{0: array<int, string>, 1: array<int, mixed>}
     */
    private function categoryWhere(SearchQuerySpec $query): array
    {
        $where  = [];
        $params = [];

        if (!$query->hasQuery()) {
            return [$where, $params];
        }

        $patches = [];

        foreach ($query->words as $word) {
            $like      = '%' . $word . '%';
            $patches[] = "(c.name LIKE ? OR c.slug LIKE ?)";
            $params[]  = $like;
            $params[]  = $like;
        }

        $where[] = '(' . implode(' AND ', $patches) . ')';

        return [$where, $params];
    }

    /**
     * The review WHERE clause (title OR body, AND across words),
     * column-qualified because the reviews read joins books.
     *
     * @return array{0: array<int, string>, 1: array<int, mixed>}
     */
    private function reviewWhere(SearchQuerySpec $query): array
    {
        $where  = [];
        $params = [];

        if (!$query->hasQuery()) {
            return [$where, $params];
        }

        $patches = [];

        foreach ($query->words as $word) {
            $like      = '%' . $word . '%';
            $patches[] = '(r.title LIKE ? OR r.review LIKE ?)';
            $params[]  = $like;
            $params[]  = $like;
        }

        $where[] = '(' . implode(' AND ', $patches) . ')';

        return [$where, $params];
    }

    /**
     * The books filter WHERE clauses (Phase 11.3): each active,
     * whitelisted filter becomes one bound condition, ANDed with the
     * free-text term. Author/category filters walk the SAME EXISTS
     * route as the term search (never a multiplying JOIN), matching
     * the browse module's filter contract slot-for-slot:
     *
     *     status       b.status = ?            (exact storage value)
     *     language     b.language = ?          (exact code)
     *     min_rating   b.average_rating >= ?
     *     year_from    b.published_year >= ?
     *     year_to      b.published_year <= ?
     *     category_id  EXISTS linked category id
     *     author_id    EXISTS linked author id
     *     publisher    b.publisher LIKE ?
     *
     * Every value arrived through SearchQueryRequest already -
     * nothing here is interpolated, only bound.
     *
     * @return array{0: array<int, string>, 1: array<int, mixed>}
     */
    private function bookFilters(SearchQuerySpec $query, string $alias): array
    {
        $where  = [];
        $params = [];

        $filters = $query->filters;

        if (isset($filters['status'])) {
            if ($filters['status'] !== 'all') {
                $where[]  = "$alias.status = ?";
                $params[] = (string) $filters['status'];
            }
        } else {
            $where[]  = "$alias.status = ?";
            $params[] = 'published';
        }

        if (isset($filters['language'])) {
            $where[]  = "$alias.language = ?";
            $params[] = (string) $filters['language'];
        }

        if (isset($filters['min_rating'])) {
            $where[]  = "$alias.average_rating >= ?";
            $params[] = (float) $filters['min_rating'];
        }

        if (isset($filters['year_from'])) {
            $where[]  = "$alias.published_year >= ?";
            $params[] = (int) $filters['year_from'];
        }

        if (isset($filters['year_to'])) {
            $where[]  = "$alias.published_year <= ?";
            $params[] = (int) $filters['year_to'];
        }

        if (isset($filters['category_id'])) {
            $where[] = "EXISTS (
                SELECT 1
                FROM book_categories bc
                WHERE bc.book_id = $alias.id AND bc.category_id = ?
            )";
            $params[] = (int) $filters['category_id'];
        }

        if (isset($filters['author_id'])) {
            $where[] = "EXISTS (
                SELECT 1
                FROM book_authors ba
                WHERE ba.book_id = $alias.id AND ba.author_id = ?
            )";
            $params[] = (int) $filters['author_id'];
        }

        if (isset($filters['publisher'])) {
            $where[]  = "$alias.publisher LIKE ?";
            $params[] = '%' . (string) $filters['publisher'] . '%';
        }

        return [$where, $params];
    }

    /**
     * The filter toolbar vocabulary: the dropdowns of the Phase 11.3
     * filter bar. Categories and publishers are the DISTINCT values
     * of the live catalogue (the same source the browse module's
     * dropdowns use) so a filter can only offer values that exist;
     * authors are every author linked to a live book. The status /
     * language / rating vocabularies live in config/search.php (the
     * request gate whitelists from the SAME maps).
     *
     * @return array{categories: array<int, array{id: int, name: string}>, authors: array<int, array{id: int, name: string}>, publishers: array<int, string>}
     */
    public function filterOptions(): array
    {
        $categories = db()->query(
            'SELECT DISTINCT c.id, c.name
             FROM categories c
             JOIN book_categories bc ON bc.category_id = c.id
             JOIN books b ON b.id = bc.book_id AND b.deleted_at IS NULL
             ORDER BY c.name ASC',
        );

        $authors = db()->query(
            'SELECT DISTINCT a.id, a.name
             FROM authors a
             JOIN book_authors ba ON ba.author_id = a.id
             JOIN books b ON b.id = ba.book_id AND b.deleted_at IS NULL
             ORDER BY a.name ASC',
        );

        $publishers = db()->query(
            "SELECT DISTINCT b.publisher AS value
             FROM books b
             WHERE b.publisher IS NOT NULL AND b.publisher != '' AND b.deleted_at IS NULL
             ORDER BY b.publisher ASC",
        );

        return [
            'categories' => array_map(
                static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name']],
                $categories,
            ),
            'authors'    => array_map(
                static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name']],
                $authors,
            ),
            'publishers' => array_column($publishers, 'value'),
        ];
    }

    /**
     * Search history (Phase 11.5).
     *
     * One row per ONE (user_id, query, scope, filters) key, so a
     * repeated search is an UPSERT, not a duplicate row: the unique
     * index on that key turns the second run into an UPDATE of
     * last_used_at plus a count bump (which is what makes the
     * "prevent duplicate consecutive entries" requirement structural).
     * All reads are owner-scoped by user_id - the API takes the
     * authenticated user id explicitly, never derives it.
     */

    /**
     * Record (or re-record) one search for a user as an UPSERT.
     *
     * The UNIQUE(user_id, query, scope, filters) index decides the
     * branch: a brand-new key INSERTs (count = 1), a repeat UPDATEs
     * last_used_at and bumps count, so a duplicate can never appear
     * - not even back-to-back - by schema, not by code.
     */
    public function upsertHistory(
        int $userId,
        string $query,
        string $scope,
        string $filters,
        string $now,
    ): void {
        db()->execute(
            'INSERT INTO search_history
                 (user_id, query, scope, filters, created_at, last_used_at, count)
             VALUES (?, ?, ?, ?, ?, ?, 1)
             ON CONFLICT (user_id, query, scope, filters)
             DO UPDATE SET
                 last_used_at = excluded.last_used_at,
                 count        = count + 1',
            [$userId, $query, $scope, $filters, $now, $now],
        );
    }

    /**
     * The most recent history rows of one user, newest use first.
     * The (user_id, last_used_at) index serves this ordering.
     *
     * @return array<int, array{id: int, user_id: int, query: string, scope: string, filters: string, created_at: string, last_used_at: string, count: int}>
     */
    public function historyRows(int $userId, int $limit): array
    {
        return db()->query(
            'SELECT id, user_id, query, scope, filters, created_at, last_used_at, count
             FROM search_history
             WHERE user_id = ?
             ORDER BY last_used_at DESC, id DESC
             LIMIT ?',
            [$userId, $limit],
        );
    }

    /**
     * Remove a user's rows older than the TTL cutoff (the migration
     * ships an index built for exactly this scan).
     *
     * @return int The number of expired rows removed
     */
    public function pruneHistory(int $userId, string $cutoff): int
    {
        return db()->execute(
            'DELETE FROM search_history
             WHERE user_id = ? AND last_used_at < ?',
            [$userId, $cutoff],
        );
    }

    /**
     * Enforce the per-user storage cap: keep only the newest rows,
     * drop the older surplus (newest-first by last use, id as the
     * tie-breaker).
     *
     * @return int The number of rows dropped
     */
    public function capHistory(int $userId, int $limit): int
    {
        return db()->execute(
            'DELETE FROM search_history
             WHERE user_id = ?
               AND id NOT IN (
                 SELECT id
                 FROM search_history
                 WHERE user_id = ?
                 ORDER BY last_used_at DESC, id DESC
                 LIMIT ?
               )',
            [$userId, $userId, $limit],
        );
    }

    /**
     * Delete ONE row, owner-scoped: a row id that does not belong to
     * the given user removes nothing. True when a row was removed.
     */
    public function deleteHistoryEntry(int $id, int $userId): bool
    {
        return db()->execute(
            'DELETE FROM search_history WHERE id = ? AND user_id = ?',
            [$id, $userId],
        ) > 0;
    }

    /**
     * Delete every row of one user (the user's own clear-all).
     *
     * @return int The number of rows removed
     */
    public function clearHistory(int $userId): int
    {
        return db()->execute(
            'DELETE FROM search_history WHERE user_id = ?',
            [$userId],
        );
    }

    /**
     * The bind-values for the LIMIT/OFFSET tail of a paged query.
     *
     * The OFFSET is CLAMPED here, after the COUNT: a requested page
     * beyond the last one slides to the last real page of rows
     * (offset = total - limit, never negative), exactly like the
     * browse module's page clamping. The user asked for page 999;
     * the repository reads the last full page, and the formatter
     * reflects the clamped page number in the result.
     *
     * @param array<int, mixed> $params
     * @return array<int, mixed>
     */
    private function pageParams(array $params, SearchQuerySpec $query, int $total): array
    {
        $limit  = $query->limit();
        $offset = max(0, $query->offset() <= 0 ? 0 : min($query->offset(), $total - $limit));

        $params[] = $limit;
        $params[] = $offset;

        return $params;
    }
}