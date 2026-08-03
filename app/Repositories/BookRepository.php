<?php

declare(strict_types=1);

namespace BookSphere\App\Repositories;

/**
 * BookRepository
 *
 * Purpose:
 *     The data-access layer of the Book module. Every SQL query
 *     that touches the books table (and its two many-to-many
 *     junction tables) lives here and here only.
 *
 * Why it exists:
 *     - Separates SQL from business logic. The BookService decides
 *       WHAT should happen; this repository decides HOW to talk to
 *       the database.
 *     - The Book model becomes a thin facade over this repository,
 *       so callers (BookService, BookController) keep one clean,
 *       predictable API.
 *     - One home for queries means changing a table column or
 *       adding an index never touches controllers or services.
 *
 * Dependencies:
 *     - db() helper (Core\Database singleton) - the shared PDO
 *       connection. All queries use prepared statements.
 *
 * How it fits inside MVC:
 *     Controller -> Service (business rules) -> Model (facade)
 *     -> Repository (SQL) -> PDO -> SQLite.
 *
 * Rules enforced by every read query:
 *     - deleted_at IS NULL: soft-deleted books never appear in
 *       the management screens again.
 *     - prepared statements everywhere: no user input ever ends
 *       up in the SQL text itself.
 *
 * The browse query (browse()) is the single read path behind
 * search, filters, sorting and pagination. The sort column is
 * interpolated into SQL, but only after passing TWO whitelists:
 * BookService maps the sort key to a safe column name and this
 * repository re-checks it against SORTABLE_COLUMNS - so it is
 * always one of a handful of hard-coded column names and never
 * attacker input.
 */
final class BookRepository
{
    /**
     * Find an active (non-deleted) book by primary key.
     *
     * @return array<string, mixed>|null The book row, or null
     */
    public function findById(int $id): ?array
    {
        $rows = db()->query(
            'SELECT *
             FROM books
             WHERE id = ? AND deleted_at IS NULL',
            [$id],
        );

        return $rows[0] ?? null;
    }

    /**
     * Find a book together with its authors and categories.
     *
     * Convenience for the show/edit pages: one call, three
     * queries. The relations are attached to the row as the
     * "authors" and "categories" keys.
     *
     * @return array<string, mixed>|null The book row with relations, or null
     */
    public function findWithRelations(int $id): ?array
    {
        $book = $this->findById($id);

        if ($book === null) {
            return null;
        }

        $book['authors']    = $this->authorsFor($id);
        $book['categories'] = $this->categoriesFor($id);

        return $book;
    }

    /**
     * Return the authors of one book.
     *
     * @return array<int, array<string, mixed>> Rows with id and name
     */
    public function authorsFor(int $bookId): array
    {
        return db()->query(
            'SELECT a.id, a.name
             FROM book_authors ba
             JOIN authors a ON a.id = ba.author_id
             WHERE ba.book_id = ?
             ORDER BY a.name ASC',
            [$bookId],
        );
    }

    /**
     * Return the categories of one book.
     *
     * @return array<int, array<string, mixed>> Rows with id and name
     */
    public function categoriesFor(int $bookId): array
    {
        return db()->query(
            'SELECT c.id, c.name
             FROM book_categories bc
             JOIN categories c ON c.id = bc.category_id
             WHERE bc.book_id = ?
             ORDER BY c.name ASC',
            [$bookId],
        );
    }

    /**
     * The browse SELECT columns: the whole book row plus the two
     * aggregated relation lists and the rating info every browse
     * screen shows. Defined once so no query ever repeats it.
     */
    private const SELECT_COLUMNS = 'b.*,
            (SELECT GROUP_CONCAT(a.name, ", ")
             FROM book_authors ba
             JOIN authors a ON a.id = ba.author_id
             WHERE ba.book_id = b.id) AS authors_list,
            (SELECT GROUP_CONCAT(c.name, ", ")
             FROM book_categories bc
             JOIN categories c ON c.id = bc.category_id
             WHERE bc.book_id = b.id) AS categories_list';

    /**
     * The columns the browse query is allowed to ORDER BY.
     *
     * The sort column is interpolated into the SQL text, so it is
     * NEVER taken from user input: BookService maps a whitelisted
     * sort key to this list, and this repository re-checks the
     * result (defence in depth). Anything else silently falls back
     * to created_at - the default sort.
     */
    private const SORTABLE_COLUMNS = [
        'title', 'created_at', 'updated_at', 'published_year', 'average_rating',
    ];

    /**
     * The columns exposed by distinct() for filter dropdowns. Like
     * SORTABLE_COLUMNS this is a hard whitelist: the column name is
     * interpolated into SQL, so it can only ever be one of these.
     */
    private const DISTINCT_COLUMNS = ['publisher'];

    /**
     * The single read query behind the whole browse experience.
     *
     * One method builds the WHERE clause (free-text search + every
     * filter), the ORDER BY (from the whitelisted sort spec) and
     * the LIMIT/OFFSET page slice - so search, filters, sorting
     * and pagination are one consistent, non-duplicated query.
     *
     * The caller (BookService) supplies SANITIZED options only:
     *
     *     q           - free-text term (searches title, subtitle,
     *                   isbn, publisher, description, language AND
     *                   the author/category names via EXISTS)
     *     status      - 'draft' | 'published' | 'archived' | null
     *     category_id - only books linked to this category
     *     author_id   - only books linked to this author
     *     publisher   - publisher LIKE match
     *     language    - exact language code
     *     year_from   - published_year >= n
     *     year_to     - published_year <= n
     *     min_rating  - average_rating >= n
     *     sort        - ['column' => ..., 'order' => 'ASC'|'DESC',
     *                    'nullsLast' => bool] (whitelisted, safe)
     *     perPage     - page size
     *     offset      - how many rows to skip
     *
     * @return array{items: array<int, array<string, mixed>>, total: int}
     *               The page rows plus the total match count
     */
    public function browse(array $options): array
    {
        [$whereSql, $params] = $this->whereParts($options);

        // 1. Count the total matches once (drives the pagination bar).
        $total = (int) db()->query(
            "SELECT COUNT(*) AS count FROM books b WHERE $whereSql",
            $params,
        )[0]['count'];

        // 2. Fetch ONLY the rows of the current page (LIMIT/OFFSET).
        //    The catalogue is never loaded into memory as a whole.
        $items = db()->query(
            'SELECT ' . self::SELECT_COLUMNS . '
             FROM books b
             WHERE ' . $whereSql . '
             ORDER BY ' . $this->orderSql($options['sort'] ?? []) . '
             LIMIT ? OFFSET ?',
            [...$params, $options['perPage'], $options['offset']],
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * The distinct values of one column (for filter dropdowns).
     *
     * Used to fill the publisher dropdown with the values that
     * actually exist in the catalogue. The column name is validated
     * against a whitelist (see DISTINCT_COLUMNS) before it is
     * interpolated.
     *
     * @return array<int, mixed>
     */
    public function distinct(string $column): array
    {
        if (!in_array($column, self::DISTINCT_COLUMNS, true)) {
            throw new \InvalidArgumentException('Column not whitelisted: ' . $column);
        }

        $rows = db()->query(
            "SELECT DISTINCT b.$column AS value
             FROM books b
             WHERE b.$column IS NOT NULL AND b.$column != '' AND b.deleted_at IS NULL
             ORDER BY b.$column ASC",
        );

        return array_column($rows, 'value');
    }

    /**
     * Build the WHERE clause for the browse query.
     *
     * Every condition is appended ONLY when the (already sanitized)
     * option has a real value, and every value is bound as a
     * prepared-statement parameter - never concatenated into SQL.
     *
     * Free-text search spans the book's own text columns plus the
     * author and category names through EXISTS subqueries. EXISTS
     * (instead of JOIN) is deliberate: a JOIN would multiply the
     * book rows for multi-author/multi-category books, breaking
     * COUNT and pagination.
     *
     * @return array{0: string, 1: array<int, mixed>} [where SQL, params]
     */
    private function whereParts(array $options): array
    {
        $where  = ['b.deleted_at IS NULL'];
        $params = [];

        if (!empty($options['status'])) {
            $where[]  = 'b.status = ?';
            $params[] = $options['status'];
        }

        if (!empty($options['q'])) {
            $like = '%' . $options['q'] . '%';

            $where[] = '(b.title LIKE ? OR b.subtitle LIKE ? OR b.isbn LIKE ?
                          OR b.publisher LIKE ? OR b.description LIKE ? OR b.language LIKE ?
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

            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
        }

        if (!empty($options['category_id'])) {
            $where[]  = 'EXISTS (
                             SELECT 1
                             FROM book_categories bc
                             WHERE bc.book_id = b.id AND bc.category_id = ?
                         )';
            $params[] = $options['category_id'];
        }

        if (!empty($options['author_id'])) {
            $where[]  = 'EXISTS (
                             SELECT 1
                             FROM book_authors ba
                             WHERE ba.book_id = b.id AND ba.author_id = ?
                         )';
            $params[] = $options['author_id'];
        }

        if (!empty($options['publisher'])) {
            // LIKE (not =) so a partially typed publisher still matches.
            $where[]  = 'b.publisher LIKE ?';
            $params[] = '%' . $options['publisher'] . '%';
        }

        if (!empty($options['language'])) {
            $where[]  = 'b.language = ?';
            $params[] = $options['language'];
        }

        if (($options['year_from'] ?? null) !== null) {
            $where[]  = 'b.published_year >= ?';
            $params[] = $options['year_from'];
        }

        if (($options['year_to'] ?? null) !== null) {
            $where[]  = 'b.published_year <= ?';
            $params[] = $options['year_to'];
        }

        if (($options['min_rating'] ?? null) !== null) {
            $where[]  = 'b.average_rating >= ?';
            $params[] = $options['min_rating'];
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * Build the ORDER BY clause from a whitelisted sort spec.
     *
     * The column is re-validated against SORTABLE_COLUMNS here even
     * though BookService already whitelisted it - if the two ever
     * drift apart, this check keeps user input out of the SQL.
     * A title tiebreaker keeps the page order stable across
     * pagination, and nullsLast pushes NULL years to the end of
     * the publication-year sorts.
     *
     * @param array{column: string, order: string, nullsLast: bool}|null $sort
     */
    private function orderSql(?array $sort): string
    {
        $column = in_array($sort['column'] ?? '', self::SORTABLE_COLUMNS, true)
            ? $sort['column']
            : 'created_at';
        $order   = ($sort['order'] ?? '') === 'DESC' ? 'DESC' : 'ASC';
        $nulls   = !empty($sort['nullsLast']) ? 'b.' . $column . ' IS NULL ASC, ' : '';

        return $nulls . 'b.' . $column . ' ' . $order . ', b.title ASC';
    }

    /**
     * Insert a new book and return its id.
     *
     * @param array<string, mixed> $data Normalized column values,
     *                                   including cover_image and isbn
     */
    public function create(array $data): int
    {
        db()->execute(
            'INSERT INTO books
                (title, subtitle, description, publisher, published_year,
                 language, page_count, cover_image, status, isbn)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['title'],
                $data['subtitle'],
                $data['description'],
                $data['publisher'],
                $data['published_year'],
                $data['language'],
                $data['page_count'],
                $data['cover_image'],
                $data['status'],
                $data['isbn'],
            ],
        );

        return (int) db()->lastInsertId();
    }

    /**
     * Update an active book.
     *
     * @param array<string, mixed> $data Normalized column values,
     *                                   including cover_image and isbn
     */
    public function update(int $id, array $data): bool
    {
        return db()->execute(
            'UPDATE books
             SET title = ?, subtitle = ?, description = ?, publisher = ?,
                 published_year = ?, language = ?, page_count = ?,
                 cover_image = ?, status = ?, isbn = ?, updated_at = ?
             WHERE id = ? AND deleted_at IS NULL',
            [
                $data['title'],
                $data['subtitle'],
                $data['description'],
                $data['publisher'],
                $data['published_year'],
                $data['language'],
                $data['page_count'],
                $data['cover_image'],
                $data['status'],
                $data['isbn'],
                $this->now(),
                $id,
            ],
        ) > 0;
    }

    /**
     * Soft delete a book: stamp deleted_at instead of removing the row.
     */
    public function softDelete(int $id): bool
    {
        return db()->execute(
            'UPDATE books
             SET deleted_at = ?, updated_at = ?
             WHERE id = ? AND deleted_at IS NULL',
            [$this->now(), $this->now(), $id],
        ) > 0;
    }

    /**
     * Replace the author links of a book.
     *
     * Delete-then-insert is simpler than diffing the old selection,
     * and the form always submits the full selection anyway.
     *
     * @param array<int, int> $authorIds
     */
    public function replaceAuthors(int $bookId, array $authorIds): void
    {
        db()->execute('DELETE FROM book_authors WHERE book_id = ?', [$bookId]);

        $statement = db()->prepare('INSERT OR IGNORE INTO book_authors (book_id, author_id) VALUES (?, ?)');
        foreach ($authorIds as $authorId) {
            $statement->execute([$bookId, $authorId]);
        }
    }

    /**
     * Replace the category links of a book.
     *
     * @param array<int, int> $categoryIds
     */
    public function replaceCategories(int $bookId, array $categoryIds): void
    {
        db()->execute('DELETE FROM book_categories WHERE book_id = ?', [$bookId]);

        $statement = db()->prepare('INSERT OR IGNORE INTO book_categories (book_id, category_id) VALUES (?, ?)');
        foreach ($categoryIds as $categoryId) {
            $statement->execute([$bookId, $categoryId]);
        }
    }

    /**
     * Whether an ISBN is already taken by another active book.
     *
     * @param int|null $exceptId The book being edited, so its own
     *                           unchanged ISBN does not count as taken.
     */
    public function isbnExists(string $isbn, ?int $exceptId = null): bool
    {
        $rows = db()->query(
            'SELECT id
             FROM books
             WHERE isbn = ? AND id != COALESCE(?, -1) AND deleted_at IS NULL',
            [$isbn, $exceptId],
        );

        return $rows !== [];
    }

    /**
     * Current UTC timestamp in the format the other columns use.
     */
    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
