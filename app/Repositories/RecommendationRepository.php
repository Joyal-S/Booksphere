<?php

declare(strict_types=1);

namespace BookSphere\App\Repositories;

use BookSphere\App\Services\RecommendationScoring;

/**
 * RecommendationRepository
 *
 * The data-access layer of the recommendations module. EVERY SQL
 * query of the module lives here and here only - the strategies
 * (Phase 6.2) never see PDO or SQL text, they consume the results
 * of these methods and decide how to present them.
 *
 * Why it exists (same separation as the Book module):
 *     - The strategies decide WHAT a recommendation should look
 *       like; this repository decides HOW to read the data.
 *     - One home for the recommendation queries: a column change
 *       or a new index never touches a strategy class.
 *     - The three existing signal tables (reviews 0007, wishlist
 *       0008, recommendations 0009) are read through this single
 *       layer, so the whole module talks to the database in one
 *       consistent, prepared-statement-only way.
 *
 * Rules enforced by every read query:
 *     - deleted_at IS NULL: soft-deleted books never reach a
 *       recommendation (mirrors the Book module rule).
 *     - status = 'published': draft and archived books are never
 *       recommended (the Phase 6.2 brief explicitly excludes draft
 *       books).
 *     - prepared statements everywhere: ids, limits, cutoffs and
 *       even the SCORING WEIGHTS are bound as parameters - nothing
 *       user- or config-controlled is ever interpolated into SQL.
 *     - LIMIT is always a bound parameter.
 *
 * Aggregation: counts and averages are computed IN SQL with
 * correlated subqueries over the reviews / wishlist tables (they
 * use the existing idx_reviews_book / idx_wishlist_book indexes),
 * so there is no N+1 and no in-memory aggregation.
 *
 * Reuse instead of duplication:
 *     - Book metadata reads (a book by id, its authors, its
 *       categories) DELEGATE to BookRepository - the Book module
 *       already owns those queries, so they are not re-written
 *       here.
 *
 * What these methods are NOT:
 *     - They are plain data reads and aggregations. The ALGORITHM -
 *       which formula to apply, which context to resolve, what to
 *       explain - belongs to the strategies. This repository must
 *       stay free of business decisions.
 */
final class RecommendationRepository
{
    /** Only this publication status is eligible for recommendation. */
    private const RECOMMENDED_STATUS = 'published';

    /**
     * The book columns every recommendation shelf renders: the
     * whole row plus the aggregated author and category lists, so
     * book cards can be drawn without extra queries.
     */
    private const BOOK_SELECT = 'b.*,
            (SELECT GROUP_CONCAT(a.name, ", ")
             FROM book_authors ba
             JOIN authors a ON a.id = ba.author_id
             WHERE ba.book_id = b.id) AS authors_list,
            (SELECT GROUP_CONCAT(c.name, ", ")
             FROM book_categories bc
             JOIN categories c ON c.id = bc.category_id
             WHERE bc.book_id = b.id) AS categories_list';

    /** The WHERE clause every recommendation query starts from. */
    private const ACTIVE_WHERE = 'b.deleted_at IS NULL AND b.status = ?';

    /** All-time review count of one book (aggregated, indexed). */
    private const REVIEW_COUNT_SQL = '(SELECT COUNT(*) FROM reviews r WHERE r.book_id = b.id)';

    /** Review count of one book inside the trending window. */
    private const RECENT_REVIEW_COUNT_SQL = '(SELECT COUNT(*) FROM reviews r WHERE r.book_id = b.id AND r.created_at >= ?)';

    /** All-time wishlist-save count of one book (aggregated, indexed). */
    private const WISHLIST_COUNT_SQL = '(SELECT COUNT(*) FROM wishlist w WHERE w.book_id = b.id)';

    /** Wishlist-save count of one book inside the trending window. */
    private const RECENT_WISHLIST_COUNT_SQL = '(SELECT COUNT(*) FROM wishlist w WHERE w.book_id = b.id AND w.created_at >= ?)';

    public function __construct(
        /**
         * The Book module's repository, injected for reuse: book
         * existence, authors and categories are read through it
         * instead of re-writing the SQL here.
         */
        private readonly BookRepository $books,
    ) {}

    // -----------------------------------------------------------------
    // Existence checks and delegates (no duplicated SQL)
    // -----------------------------------------------------------------

    /**
     * Whether an active book exists.
     *
     * Input:  a book id
     * Output: true when the book exists and is not soft-deleted
     *
     * Business responsibility: lets the strategies answer "Book Not
     * Found" with a meaningful exception instead of an empty shelf.
     * Delegates to BookRepository::findById - the Book module owns
     * this query.
     */
    public function bookExists(int $bookId): bool
    {
        return $this->books->findById($bookId) !== null;
    }

    /**
     * The authors of one book.
     *
     * @return array<int, array<string, mixed>> Rows with id and name
     */
    public function authorsForBook(int $bookId): array
    {
        return $this->books->authorsFor($bookId);
    }

    /**
     * The categories of one book.
     *
     * @return array<int, array<string, mixed>> Rows with id and name
     */
    public function categoriesForBook(int $bookId): array
    {
        return $this->books->categoriesFor($bookId);
    }

    /**
     * Whether a category exists.
     *
     * Input:  a category id
     * Output: true when the category row exists
     *
     * Business responsibility: lets the service answer "Missing
     * Categories" with a meaningful exception before a strategy
     * runs.
     */
    public function categoryExists(int $categoryId): bool
    {
        return db()->query('SELECT id FROM categories WHERE id = ?', [$categoryId]) !== [];
    }

    /**
     * The name of one category (for page copy).
     */
    public function categoryName(int $categoryId): ?string
    {
        $rows = db()->query('SELECT name FROM categories WHERE id = ?', [$categoryId]);

        return isset($rows[0]['name']) ? (string) $rows[0]['name'] : null;
    }

    /**
     * Whether an author exists.
     *
     * Input:  an author id
     * Output: true when the author row exists
     *
     * Business responsibility: lets the service answer "Missing
     * Authors" with a meaningful exception before a strategy runs.
     */
    public function authorExists(int $authorId): bool
    {
        return db()->query('SELECT id FROM authors WHERE id = ?', [$authorId]) !== [];
    }

    /**
     * The number of active (non-deleted) books in the catalogue.
     *
     * Shown in the shelf note of the default recommendations page.
     */
    public function activeBookCount(): int
    {
        return (int) db()->query(
            'SELECT COUNT(*) AS count
             FROM books
             WHERE deleted_at IS NULL',
        )[0]['count'];
    }

    // -----------------------------------------------------------------
    // The six recommendation reads
    // -----------------------------------------------------------------

    /**
     * The most popular books, by weighted score.
     *
     * Input:  the maximum number of books to return
     * Output: book rows ordered by popularity_score DESC (each row
     *         carries review_count and wishlist_count)
     *
     * Business responsibility: one aggregation query - the inner
     * SELECT computes the review and wishlist counts once per book
     * (correlated subqueries over the indexed signal tables), the
     * outer SELECT applies the weighted formula of
     * RecommendationScoring (the weights are BOUND PARAMETERS) and
     * sorts. No PHP-side scoring, no N+1.
     */
    public function popularBooks(int $limit): array
    {
        return db()->query(
            'SELECT t.*,
                    ' . RecommendationScoring::popularitySql() . ' AS popularity_score
             FROM (
                 SELECT ' . self::BOOK_SELECT . ',
                        ' . self::REVIEW_COUNT_SQL . ' AS review_count,
                        ' . self::WISHLIST_COUNT_SQL . ' AS wishlist_count
                 FROM books b
                 WHERE ' . self::ACTIVE_WHERE . '
             ) t
             ORDER BY popularity_score DESC, t.average_rating DESC, t.id ASC
             LIMIT ?',
            [...RecommendationScoring::popularityParams(), self::RECOMMENDED_STATUS, $limit],
        );
    }

    /**
     * The highest-rated books with a minimum review count.
     *
     * Input:  the maximum number of books to return
     * Output: book rows ordered by average rating DESC, then review
     *         count DESC; only books with at least
     *         RecommendationScoring::MIN_REVIEWS_FOR_RATING reviews
     *
     * Business responsibility: the confidence threshold ("ignore
     * books with very few ratings") is applied in SQL so books
     * rated by one or two users never appear as "top rated".
     */
    public function highestRatedBooks(int $limit): array
    {
        return db()->query(
            'SELECT ' . self::BOOK_SELECT . ',
                    ' . self::REVIEW_COUNT_SQL . ' AS review_count,
                    (SELECT AVG(rating) FROM reviews r WHERE r.book_id = b.id) AS average_rating
             FROM books b
             WHERE ' . self::ACTIVE_WHERE . '
               AND ' . self::REVIEW_COUNT_SQL . ' >= ?
             ORDER BY average_rating DESC, review_count DESC, b.id ASC
             LIMIT ?',
            [self::RECOMMENDED_STATUS, RecommendationScoring::MIN_REVIEWS_FOR_RATING, $limit],
        );
    }

    /**
     * The most recently added books.
     *
     * Input:  the maximum number of books to return
     * Output: book rows, newest first
     *
     * Business responsibility: a pure freshness read - the created
     * date is the only signal, the limit is the caller's
     * configuration.
     */
    public function recentlyAddedBooks(int $limit): array
    {
        return db()->query(
            'SELECT ' . self::BOOK_SELECT . '
             FROM books b
             WHERE ' . self::ACTIVE_WHERE . '
             ORDER BY b.created_at DESC, b.id DESC
             LIMIT ?',
            [self::RECOMMENDED_STATUS, $limit],
        );
    }

    /**
     * The trending books: most review + wishlist activity in the
     * last RecommendationScoring::TRENDING_WINDOW_DAYS days.
     *
     * Input:  the maximum number of books to return
     * Output: book rows ordered by trending_score DESC (each row
     *         carries recent_review_count and recent_wishlist_count)
     *
     * Business responsibility: one aggregation query - the cutoff
     * timestamp and the weights are BOUND PARAMETERS; only books
     * with at least one recent signal make the shelf, so "trending"
     * never shows arbitrary books. Views are not part of the
     * formula yet (no views column exists - see RecommendationScoring).
     */
    public function trendingBooks(int $limit): array
    {
        $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - RecommendationScoring::TRENDING_WINDOW_DAYS * 86400);

        // Parameter order must follow the SQL TEXT order of the "?"
        // markers: the two SCORING placeholders (outer SELECT) come
        // first, then the inner query's two created_at cutoffs, the
        // status, and the limit.
        return db()->query(
            'SELECT t.*,
                    ' . RecommendationScoring::trendingSql() . ' AS trending_score
             FROM (
                 SELECT ' . self::BOOK_SELECT . ',
                        ' . self::RECENT_REVIEW_COUNT_SQL . ' AS recent_review_count,
                        ' . self::RECENT_WISHLIST_COUNT_SQL . ' AS recent_wishlist_count
                 FROM books b
                 WHERE ' . self::ACTIVE_WHERE . '
             ) t
             WHERE t.recent_review_count + t.recent_wishlist_count > 0
             ORDER BY trending_score DESC, t.id ASC
             LIMIT ?',
            [...RecommendationScoring::trendingParams(), $cutoff, $cutoff, self::RECOMMENDED_STATUS, $limit],
        );
    }

    /**
     * The books linked to ONE category.
     *
     * Input:  a category id, the maximum number of books, and an
     *         optional book id to exclude (the anchor book)
     * Output: book rows of that category, title A-Z
     *
     * Business responsibility: the EXISTS subquery keeps the filter
     * exact (a book belongs to the category or it does not) without
     * multiplying rows for multi-category books.
     */
    public function booksByCategory(int $categoryId, int $limit, ?int $excludeBookId = null): array
    {
        return db()->query(
            'SELECT ' . self::BOOK_SELECT . '
             FROM books b
             WHERE ' . self::ACTIVE_WHERE . '
               AND EXISTS (
                   SELECT 1
                   FROM book_categories bc
                   WHERE bc.book_id = b.id AND bc.category_id = ?
               )
               AND b.id != ?
             ORDER BY b.title ASC
             LIMIT ?',
            [self::RECOMMENDED_STATUS, $categoryId, $excludeBookId ?? -1, $limit],
        );
    }

    /**
     * The books linked to ANY of several categories.
     *
     * Input:  category ids, the maximum number of books, and an
     *         optional book id to exclude
     * Output: book rows belonging to at least one of the categories
     *
     * Business responsibility: multi-category support - an anchor
     * book can belong to several categories and the shelf draws from
     * all of them. The IN placeholders are built from the id count
     * (bounded, never user-controlled) and every id is a bound
     * parameter.
     *
     * @param array<int, int> $categoryIds
     */
    public function booksInCategories(array $categoryIds, int $limit, ?int $excludeBookId = null): array
    {
        return db()->query(
            'SELECT ' . self::BOOK_SELECT . '
             FROM books b
             WHERE ' . self::ACTIVE_WHERE . '
               AND EXISTS (
                   SELECT 1
                   FROM book_categories bc
                   WHERE bc.book_id = b.id AND bc.category_id IN (' . $this->placeholders($categoryIds) . ')
               )
               AND b.id != ?
             ORDER BY b.title ASC
             LIMIT ?',
            [self::RECOMMENDED_STATUS, ...$categoryIds, $excludeBookId ?? -1, $limit],
        );
    }

    /**
     * The books linked to ONE author.
     *
     * Input:  an author id, the maximum number of books, and an
     *         optional book id to exclude
     * Output: book rows by that author, title A-Z
     */
    public function booksByAuthor(int $authorId, int $limit, ?int $excludeBookId = null): array
    {
        return db()->query(
            'SELECT ' . self::BOOK_SELECT . '
             FROM books b
             WHERE ' . self::ACTIVE_WHERE . '
               AND EXISTS (
                   SELECT 1
                   FROM book_authors ba
                   WHERE ba.book_id = b.id AND ba.author_id = ?
               )
               AND b.id != ?
             ORDER BY b.title ASC
             LIMIT ?',
            [self::RECOMMENDED_STATUS, $authorId, $excludeBookId ?? -1, $limit],
        );
    }

    /**
     * The books linked to ANY of several authors.
     *
     * Input:  author ids, the maximum number of books, and an
     *         optional book id to exclude
     * Output: book rows by at least one of the authors
     *
     * Business responsibility: multi-author support - a book can be
     * written by several people and the shelf draws from all of
     * them, excluding the anchor book itself.
     *
     * @param array<int, int> $authorIds
     */
    public function booksInAuthors(array $authorIds, int $limit, ?int $excludeBookId = null): array
    {
        return db()->query(
            'SELECT ' . self::BOOK_SELECT . '
             FROM books b
             WHERE ' . self::ACTIVE_WHERE . '
               AND EXISTS (
                   SELECT 1
                   FROM book_authors ba
                   WHERE ba.book_id = b.id AND ba.author_id IN (' . $this->placeholders($authorIds) . ')
               )
               AND b.id != ?
             ORDER BY b.title ASC
             LIMIT ?',
            [self::RECOMMENDED_STATUS, ...$authorIds, $excludeBookId ?? -1, $limit],
        );
    }

    // -----------------------------------------------------------------
    // Phase 6.3: personalization data reads
    // -----------------------------------------------------------------

    /**
     * The book ids of one user's wishlist, most recently saved first.
     *
     * Input:  a user id
     * Output: the wishlist book ids (order = recency)
     *
     * Business responsibility: the wishlist is the primary personal
     * signal. Its books are (a) EXCLUDED from the personalized shelf
     * ("never recommend a book already in the wishlist") and (b) the
     * source of the wishlist-similarity factor.
     *
     * @return array<int, int>
     */
    public function wishlistBookIds(int $userId): array
    {
        return array_map(
            fn (array $row): int => (int) $row['book_id'],
            db()->query(
                'SELECT book_id
                 FROM wishlist
                 WHERE user_id = ?
                 ORDER BY created_at DESC',
                [$userId],
            ),
        );
    }

    /**
     * Toggle one book in the wishlist of one user (Phase 6.4
     * presentation support).
     *
     * Input:  a user id and a book id
     * Output: true when the book is NOW saved (the toggle added it),
     *         false when it was removed or the book cannot be saved
     *
     * Business responsibility: the wishlist quick action of the
     * recommendation cards. Only an active, published book can be
     * saved (draft / archived / deleted books never enter a
     * personal list). A second toggle removes the row - the
     * UNIQUE(user_id, book_id) rule keeps the table clean without
     * an existence check first. The recommendation pipeline reads
     * this table through wishlistBookIds(), so the toggle needs no
     * cache of its own.
     */
    public function toggleWishlist(int $userId, int $bookId): bool
    {
        $book = $this->books->findById($bookId);

        if ($book === null || ($book['status'] ?? '') !== 'published') {
            return false;
        }

        $existing = db()->query(
            'SELECT id FROM wishlist WHERE user_id = ? AND book_id = ?',
            [$userId, $bookId],
        );

        if ($existing !== []) {
            db()->execute(
                'DELETE FROM wishlist WHERE user_id = ? AND book_id = ?',
                [$userId, $bookId],
            );

            return false;
        }

        db()->execute(
            'INSERT INTO wishlist (user_id, book_id) VALUES (?, ?)',
            [$userId, $bookId],
        );

        return true;
    }

    /**
     * One user's ratings, as a book_id => rating map.
     *
     * Input:  a user id
     * Output: ['book id' => '1..5 rating']
     *
     * Business responsibility: ratings feed the favourites profile
     * and the rating-similarity factor. The map shape avoids a
     * look-up per book (no N+1).
     *
     * @return array<int, int>
     */
    public function ratedBooks(int $userId): array
    {
        $map = [];

        foreach (db()->query('SELECT book_id, rating FROM reviews WHERE user_id = ?', [$userId]) as $row) {
            $map[(int) $row['book_id']] = (int) $row['rating'];
        }

        return $map;
    }

    /**
     * The book ids one user has written a review for.
     *
     * Input:  a user id
     * Output: the reviewed book ids
     *
     * Business responsibility: reviews are the third signal source
     * for the favourites profile ("analyse categories and authors
     * from reviewed books").
     *
     * @return array<int, int>
     */
    public function reviewedBookIds(int $userId): array
    {
        return array_map(
            fn (array $row): int => (int) $row['book_id'],
            db()->query(
                'SELECT book_id
                 FROM reviews
                 WHERE user_id = ? AND review IS NOT NULL AND review != ?',
                [$userId, ''],
            ),
        );
    }

    /**
     * The most recently viewed book ids of one user.
     *
     * Input:  a user id and how many to return
     * Output: the view history, newest first
     *
     * Business responsibility: the "recently viewed" signal of the
     * brief. The candidate pool is "similar to what you viewed" -
     * the exact same book is never recommended again (the anchor
     * exclusion happens in the engine).
     *
     * @return array<int, int>
     */
    public function recentlyViewedBookIds(int $userId, int $limit): array
    {
        return array_map(
            fn (array $row): int => (int) $row['book_id'],
            db()->query(
                'SELECT book_id
                 FROM book_views
                 WHERE user_id = ?
                 ORDER BY viewed_at DESC, id DESC
                 LIMIT ?',
                [$userId, $limit],
            ),
        );
    }

    /**
     * Remember that a user viewed a book (upsert).
     *
     * Input:  the user id and the book id
     * Output: nothing
     *
     * Business responsibility: called by the Book module's show
     * page (one line, via RecommendationService::recordBookView).
     * Re-viewing a book refreshes its timestamp instead of adding a
     * duplicate row (UNIQUE(user_id, book_id) + ON CONFLICT).
     */
    public function recordBookView(int $userId, int $bookId): void
    {
        db()->execute(
            'INSERT INTO book_views (user_id, book_id, viewed_at)
             VALUES (?, ?, ?)
             ON CONFLICT(user_id, book_id) DO UPDATE SET viewed_at = excluded.viewed_at',
            [$userId, $bookId, gmdate('Y-m-d\TH:i:s\Z')],
        );
    }

    /**
     * The category links of several books, in one query.
     *
     * Input:  book ids (bounded by the candidate pool size)
     * Output: rows of (book_id, category_id, name)
     *
     * Business responsibility: the batch load that keeps the hybrid
     * scoring free of N+1 - every candidate's categories come from
     * this single IN (...) query, then the service groups them in
     * memory.
     *
     * @param array<int, int> $bookIds
     * @return array<int, array{book_id: int, category_id: int, name: string}>
     */
    public function categoriesForBooks(array $bookIds): array
    {
        if ($bookIds === []) {
            return [];
        }

        return db()->query(
            'SELECT bc.book_id, bc.category_id, c.name
             FROM book_categories bc
             JOIN categories c ON c.id = bc.category_id
             WHERE bc.book_id IN (' . $this->placeholders($bookIds) . ')',
            array_values($bookIds),
        );
    }

    /**
     * The author links of several books, in one query.
     *
     * Input:  book ids (bounded by the candidate pool size)
     * Output: rows of (book_id, author_id, name)
     *
     * Business responsibility: the author counterpart of
     * categoriesForBooks() - one batch query, no per-book look-ups.
     *
     * @param array<int, int> $bookIds
     * @return array<int, array{book_id: int, author_id: int, name: string}>
     */
    public function authorsForBooks(array $bookIds): array
    {
        if ($bookIds === []) {
            return [];
        }

        return db()->query(
            'SELECT ba.book_id, ba.author_id, a.name
             FROM book_authors ba
             JOIN authors a ON a.id = ba.author_id
             WHERE ba.book_id IN (' . $this->placeholders($bookIds) . ')',
            array_values($bookIds),
        );
    }

    /**
     * The names of several categories, as id => name.
     *
     * Input:  category ids
     * Output: ['id' => 'name'] for every id that exists
     *
     * Business responsibility: the favourites profile keeps its
     * ids; the names are looked up once (batch) for the explainable
     * reasons ("You enjoy Fantasy and Science Fiction books.").
     *
     * @param array<int, int> $categoryIds
     * @return array<int, string>
     */
    public function categoryNames(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        return array_map(
            'strval',
            array_column(
                db()->query(
                    'SELECT id, name FROM categories WHERE id IN (' . $this->placeholders($categoryIds) . ')',
                    array_values($categoryIds),
                ),
                'name',
                'id',
            ),
        );
    }

    /**
     * The names of several authors, as id => name.
     *
     * Input:  author ids
     * Output: ['id' => 'name'] for every id that exists
     *
     * Business responsibility: the author counterpart of
     * categoryNames(), for the same explainable reasons.
     *
     * @param array<int, int> $authorIds
     * @return array<int, string>
     */
    public function authorNames(array $authorIds): array
    {
        if ($authorIds === []) {
            return [];
        }

        return array_map(
            'strval',
            array_column(
                db()->query(
                    'SELECT id, name FROM authors WHERE id IN (' . $this->placeholders($authorIds) . ')',
                    array_values($authorIds),
                ),
                'name',
                'id',
            ),
        );
    }

    /**
     * The candidate pool of the hybrid engine: books matching ANY
     * personal factor, plus a popularity fallback for cold starts.
     *
     * Input:  the profile's favourite category/author ids, the
     *         category ids of the user's wishlist / highly rated /
     *         recently viewed books, the fallback popularity ids,
     *         and the maximum pool size
     * Output: candidate book rows, each carrying authors_list /
     *         categories_list, popularity_score and trending_score
     *
     * Business responsibility: ONE query selects the pool the hybrid
     * formula will score in PHP. A book is a candidate when it
     *
     *     - shares a category with a favourite category, or
     *     - is written by a favourite author, or
     *     - shares a category with a wishlist / highly rated /
     *       recently viewed book of the user, or
     *     - is one of the most popular books (the fallback, so a
     *       user with no signals still gets a shelf).
     *
     * The pool is ordered by popularity purely to bound its size -
     * the FINAL shelf order is the hybrid score computed by the
     * service. Every id list is a bound parameter (IN clauses built
     * from id counts, never user input). Drafts, deleted and
     * archived books are excluded here, so the engine never has to
     * re-check them.
     *
     * @param array<int, int> $favouriteCategoryIds
     * @param array<int, int> $favouriteAuthorIds
     * @param array<int, int> $wishlistCategoryIds
     * @param array<int, int> $ratingCategoryIds
     * @param array<int, int> $viewedCategoryIds
     * @param array<int, int> $fallbackBookIds
     */
    public function hybridCandidates(
        array $favouriteCategoryIds,
        array $favouriteAuthorIds,
        array $wishlistCategoryIds,
        array $ratingCategoryIds,
        array $viewedCategoryIds,
        array $fallbackBookIds,
        int $limit,
    ): array {
        // The union of every category signal, for one EXISTS clause
        // per signal group... actually a single OR chain over the
        // distinct sets is enough: one EXISTS per category set keeps
        // the query readable.
        $categorySets = [
            $favouriteCategoryIds,
            $wishlistCategoryIds,
            $ratingCategoryIds,
            $viewedCategoryIds,
        ];

        $where   = [];
        $params  = [];

        foreach ($categorySets as $categoryIds) {
            $categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));

            if ($categoryIds === []) {
                continue;
            }

            $where[]  = 'EXISTS (
                            SELECT 1
                            FROM book_categories bc
                            WHERE bc.book_id = b.id AND bc.category_id IN (' . $this->placeholders($categoryIds) . ')
                        )';
            array_push($params, ...$categoryIds);
        }

        $authorIds = array_values(array_unique(array_map('intval', $favouriteAuthorIds)));

        if ($authorIds !== []) {
            $where[]  = 'EXISTS (
                            SELECT 1
                            FROM book_authors ba
                            WHERE ba.book_id = b.id AND ba.author_id IN (' . $this->placeholders($authorIds) . ')
                        )';
            array_push($params, ...$authorIds);
        }

        $fallbackIds = array_values(array_unique(array_map('intval', $fallbackBookIds)));

        if ($fallbackIds !== []) {
            $where[] = 'b.id IN (' . $this->placeholders($fallbackIds) . ')';
            array_push($params, ...$fallbackIds);
        }

        if ($where === []) {
            // No personal signal and no fallback: nothing can be
            // recommended (the service treats this as an empty shelf).
            return [];
        }

        $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - RecommendationScoring::TRENDING_WINDOW_DAYS * 86400);

        // Parameter order follows the SQL TEXT order of the "?"
        // markers: the SCORING placeholders (outer SELECT) come
        // first, then the two trending cutoffs, the status, the
        // candidate-set ids and the limit.
        return db()->query(
            'SELECT t.*,
                    ' . RecommendationScoring::popularitySql() . ' AS popularity_score,
                    ' . RecommendationScoring::trendingSql() . ' AS trending_score
             FROM (
                 SELECT ' . self::BOOK_SELECT . ',
                        ' . self::REVIEW_COUNT_SQL . ' AS review_count,
                        ' . self::WISHLIST_COUNT_SQL . ' AS wishlist_count,
                        ' . self::RECENT_REVIEW_COUNT_SQL . ' AS recent_review_count,
                        ' . self::RECENT_WISHLIST_COUNT_SQL . ' AS recent_wishlist_count
                 FROM books b
                 WHERE ' . self::ACTIVE_WHERE . '
                   AND (' . implode(' OR ', $where) . ')
             ) t
             ORDER BY popularity_score DESC, t.id ASC
             LIMIT ?',
            [
                ...RecommendationScoring::popularityParams(),
                ...RecommendationScoring::trendingParams(),
                $cutoff,
                $cutoff,
                self::RECOMMENDED_STATUS,
                ...$params,
                $limit,
            ],
        );
    }

    // -----------------------------------------------------------------
    // Phase 6.5: monitoring reads (the admin metrics page)
    // -----------------------------------------------------------------

    /**
     * The signal totals behind the whole engine, as one row.
     *
     * Input:  nothing
     * Output: published_books (active catalogue size), reviews,
     *         wishlist (saves), book_views (tracked views) and
     *         average_rating (over every rating row)
     *
     * Business responsibility: the "data health" block of the admin
     * monitoring page - these five numbers tell an administrator at
     * a glance whether the engine has signal to work with, and how
     * much of the catalogue is actually active.
     *
     * @return array<string, int|float>
     */
    public function signalTotals(): array
    {
        $row = db()->query(
            'SELECT
                (SELECT COUNT(*) FROM books b WHERE ' . self::ACTIVE_WHERE . ') AS published_books,
                (SELECT COUNT(*) FROM reviews)  AS reviews,
                (SELECT COUNT(*) FROM wishlist) AS wishlist,
                (SELECT COUNT(*) FROM book_views) AS book_views,
                (SELECT ROUND(AVG(rating), 2) FROM reviews) AS average_rating',
            [self::RECOMMENDED_STATUS],
        );

        return $row[0] ?? [
            'published_books' => 0,
            'reviews'         => 0,
            'wishlist'        => 0,
            'book_views'      => 0,
            'average_rating'  => 0,
        ];
    }

    /**
     * The categories with the most community signal.
     *
     * Input:  how many categories to return
     * Output: rows of id, name, reviews, saves (wishlist rows) and
     *         signal (the sum), ordered by signal descending, then
     *         name ascending
     *
     * Business responsibility: the "what is the community interested
     * in" block of the admin page. One query joins the category
     * links with the two signal tables; COUNT(DISTINCT ...) keeps
     * the sums honest when a book belongs to several categories.
     *
     * @return array<int, array<string, mixed>>
     */
    public function topCategories(int $limit): array
    {
        return db()->query(
            'SELECT c.id,
                    c.name,
                    COUNT(DISTINCT r.id) AS reviews,
                    COUNT(DISTINCT w.id) AS saves,
                    (COUNT(DISTINCT r.id) + COUNT(DISTINCT w.id)) AS signal
             FROM categories c
             LEFT JOIN book_categories bc ON bc.category_id = c.id
             LEFT JOIN reviews r    ON r.book_id = bc.book_id
             LEFT JOIN wishlist w   ON w.book_id = bc.book_id
             GROUP BY c.id, c.name
             ORDER BY signal DESC, c.name ASC
             LIMIT ?',
            [$limit],
        );
    }

    /**
     * The authors with the most community signal.
     *
     * Input:  how many authors to return
     * Output: rows of id, name, reviews, saves and signal, ordered
     *         like topCategories()
     *
     * Business responsibility: the author counterpart of
     * topCategories() - the same query shape over the author links.
     *
     * @return array<int, array<string, mixed>>
     */
    public function topAuthors(int $limit): array
    {
        return db()->query(
            'SELECT a.id,
                    a.name,
                    COUNT(DISTINCT r.id) AS reviews,
                    COUNT(DISTINCT w.id) AS saves,
                    (COUNT(DISTINCT r.id) + COUNT(DISTINCT w.id)) AS signal
             FROM authors a
             LEFT JOIN book_authors ba ON ba.author_id = a.id
             LEFT JOIN reviews r    ON r.book_id = ba.book_id
             LEFT JOIN wishlist w   ON w.book_id = ba.book_id
             GROUP BY a.id, a.name
             ORDER BY signal DESC, a.name ASC
             LIMIT ?',
            [$limit],
        );
    }

    /**
     * One "?" per id, for a safe IN (...) clause.
     *
     * @param array<int, int> $ids
     */
    private function placeholders(array $ids): string
    {
        return implode(', ', array_fill(0, count($ids), '?'));
    }
}
