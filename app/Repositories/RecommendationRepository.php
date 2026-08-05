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

    /**
     * The per-call INSERT cap of logRecommendations(): a shelf is
     * bounded by its section limit anyway, this is the safety valve
     * that keeps one (tampered) call from flooding the table. The
     * per-user RETENTION is a separate rule - the config's
     * retention_per_user, applied by pruneRecommendationLogs().
     */
    private const MAX_LOG_BATCH = 100;

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

    /**
     * The anchor snapshot the book-detail sections need: id, title,
     * average rating and ratings count of one book - or null when
     * the book is missing or soft-deleted.
     *
     * @return array<string, mixed>|null
     */
    public function anchorBook(int $bookId): ?array
    {
        $book = $this->books->findById($bookId);

        if ($book === null) {
            return null;
        }

        return [
            'id'             => (int) $book['id'],
            'title'          => (string) ($book['title'] ?? ''),
            'average_rating' => (float) ($book['average_rating'] ?? 0),
            'ratings_count'  => (int) ($book['ratings_count'] ?? 0),
        ];
    }

    // -----------------------------------------------------------------
    // Phase 8.5: Personal Library signal reads
    // -----------------------------------------------------------------

    /**
     * The book ids of one user's library, newest first.
     *
     * Input:  a user id, an optional status filter and a cap
     * Output: the library book ids (all shelves, or one shelf when
     *         $status is given)
     *
     * Business responsibility: the raw library signal of Phase 8.5.
     * The library is the modern wishlist (Phase 8.1) and every shelf
     * of it feeds the engine: favourites, finished books and
     * want-to-read books all produce their own weighted factor.
     * The user's own library books are ALWAYS excluded from their
     * recommendation shelves - a book already in the library is never
     * suggested again.
     *
     * @return array<int, int>
     */
    public function libraryBookIds(int $userId, int $limit, ?string $status = null): array
    {
        $statusClause = $status !== null ? ' AND ul.library_status = ?' : '';
        $params       = $status !== null ? [$userId, $status, $limit] : [$userId, $limit];

        return array_map(
            fn (array $row): int => (int) $row['book_id'],
            db()->query(
                'SELECT ul.book_id
                 FROM user_library ul
                 WHERE ul.user_id = ?' . $statusClause . '
                 ORDER BY ul.created_at DESC, ul.id DESC
                 LIMIT ?',
                $params,
            ),
        );
    }

    /**
     * The user's starred books (is_favorite = 1) - the strongest
     * affinity signal of the library.
     *
     * @return array<int, int>
     */
    public function favouriteBookIds(int $userId, int $limit): array
    {
        return array_map(
            fn (array $row): int => (int) $row['book_id'],
            db()->query(
                'SELECT ul.book_id
                 FROM user_library ul
                 WHERE ul.user_id = ? AND ul.is_favorite = 1
                 ORDER BY ul.updated_at DESC, ul.id DESC
                 LIMIT ?',
                [$userId, $limit],
            ),
        );
    }

    /**
     * The user's finished books, most recently finished first - the
     * reading-history signal.
     *
     * @return array<int, int>
     */
    public function finishedBookIds(int $userId, int $limit): array
    {
        return array_map(
            fn (array $row): int => (int) $row['book_id'],
            db()->query(
                'SELECT ul.book_id
                 FROM user_library ul
                 WHERE ul.user_id = ? AND ul.library_status = ?
                 ORDER BY ul.finished_reading_at DESC, ul.id DESC
                 LIMIT ?',
                [$userId, 'finished', $limit],
            ),
        );
    }

    /**
     * The user's want-to-read books, most recently added first - the
     * "continue exploring" signal.
     *
     * @return array<int, int>
     */
    public function wantToReadBookIds(int $userId, int $limit): array
    {
        return array_map(
            fn (array $row): int => (int) $row['book_id'],
            db()->query(
                'SELECT ul.book_id
                 FROM user_library ul
                 WHERE ul.user_id = ? AND ul.library_status = ?
                 ORDER BY ul.created_at DESC, ul.id DESC
                 LIMIT ?',
                [$userId, 'want_to_read', $limit],
            ),
        );
    }

    /**
     * The user's top categories by books kept - the library
     * "favourite categories" of the profile and the library page.
     *
     * Input:  a user id and how many categories to return
     * Output: rows of id, name, kept (how many library books belong
     *         to the category), ordered by kept descending
     *
     * @return array<int, array<string, mixed>>
     */
    public function topLibraryCategories(int $userId, int $limit): array
    {
        return db()->query(
            'SELECT c.id, c.name, COUNT(*) AS kept
             FROM user_library ul
             JOIN book_categories bc ON bc.book_id = ul.book_id
             JOIN categories c       ON c.id = bc.category_id
             WHERE ul.user_id = ?
             GROUP BY c.id, c.name
             ORDER BY kept DESC, c.name ASC
             LIMIT ?',
            [$userId, $limit],
        );
    }

    /**
     * The user's top authors by books kept - the author counterpart
     * of topLibraryCategories().
     *
     * @return array<int, array<string, mixed>>
     */
    public function topLibraryAuthors(int $userId, int $limit): array
    {
        return db()->query(
            'SELECT a.id, a.name, COUNT(*) AS kept
             FROM user_library ul
             JOIN book_authors ba ON ba.book_id = ul.book_id
             JOIN authors a       ON a.id = ba.author_id
             WHERE ul.user_id = ?
             GROUP BY a.id, a.name
             ORDER BY kept DESC, a.name ASC
             LIMIT ?',
            [$userId, $limit],
        );
    }

    /**
     * "People who saved this also liked": the books other users saved
     * alongside the anchor book, ordered by how many of them did so.
     *
     * Input:  the anchor book id and the maximum number of books
     * Output: book rows ordered by saved_count DESC (each row carries
     *         the number of other users who saved it)
     *
     * Business responsibility: the collaborative signal of Phase 8.5 -
     * one query joins the library of every user who saved the anchor
     * with their other saved books and groups by book. The anchor
     * itself is excluded; only active, published books are returned.
     *
     * @return array<int, array<string, mixed>>
     */
    public function coSavedBooks(int $bookId, int $limit): array
    {
        return db()->query(
            'SELECT t.*, co.saved_count
             FROM (
                 SELECT ul2.book_id, COUNT(*) AS saved_count
                 FROM user_library ul1
                 JOIN user_library ul2 ON ul2.user_id = ul1.user_id AND ul2.book_id != ?
                 WHERE ul1.book_id = ?
                 GROUP BY ul2.book_id
             ) co
             JOIN (
                 SELECT ' . self::BOOK_SELECT . '
                 FROM books b
                 WHERE ' . self::ACTIVE_WHERE . '
             ) t ON t.id = co.book_id
             ORDER BY co.saved_count DESC, t.average_rating DESC, t.id ASC
             LIMIT ?',
            [$bookId, $bookId, self::RECOMMENDED_STATUS, $limit],
        );
    }

    /**
     * "People who saved this also liked" for the WHOLE library of one
     * user: the books other users saved alongside any of the user's
     * books, the user's own books excluded.
     *
     * Input:  a user id and the maximum number of books
     * Output: book rows ordered by shared_count DESC (how many of the
     *         user's library neighbours saved the book)
     *
     * Business responsibility: the library-page collaborative shelf.
     * One query: every user who shares at least one library book with
     * the user contributes their other books; the more neighbours
     * saved a book, the higher it ranks.
     *
     * @return array<int, array<string, mixed>>
     */
    public function coSavedForLibrary(int $userId, int $limit): array
    {
        return db()->query(
            'SELECT t.*, co.shared_count
             FROM (
                 SELECT ul.book_id, COUNT(DISTINCT ul.user_id) AS shared_count
                 FROM user_library ul
                 WHERE ul.user_id IN (
                       SELECT DISTINCT ul2.user_id
                       FROM user_library ul2
                       WHERE ul2.book_id IN (SELECT book_id FROM user_library WHERE user_id = ?)
                         AND ul2.user_id != ?
                 )
                   AND ul.book_id NOT IN (SELECT book_id FROM user_library WHERE user_id = ?)
                 GROUP BY ul.book_id
             ) co
             JOIN (
                 SELECT ' . self::BOOK_SELECT . '
                 FROM books b
                 WHERE ' . self::ACTIVE_WHERE . '
             ) t ON t.id = co.book_id
             ORDER BY co.shared_count DESC, t.average_rating DESC, t.id ASC
             LIMIT ?',
            [$userId, $userId, $userId, self::RECOMMENDED_STATUS, $limit],
        );
    }

    /**
     * The books the community has been discovering recently: saved to
     * libraries inside the discovery window, most-saved first.
     *
     * Input:  the maximum number of books and the window cutoff
     * Output: book rows ordered by discovery_count DESC (each row
     *         carries how many saves it gathered in the window)
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentlyDiscoveredBooks(int $limit, string $cutoff): array
    {
        return db()->query(
            'SELECT t.*, d.discovery_count
             FROM (
                 SELECT ul.book_id, COUNT(*) AS discovery_count
                 FROM user_library ul
                 WHERE ul.created_at >= ?
                 GROUP BY ul.book_id
             ) d
             JOIN (
                 SELECT ' . self::BOOK_SELECT . '
                 FROM books b
                 WHERE ' . self::ACTIVE_WHERE . '
             ) t ON t.id = d.book_id
             ORDER BY d.discovery_count DESC, t.average_rating DESC, t.id ASC
             LIMIT ?',
            [$cutoff, self::RECOMMENDED_STATUS, $limit],
        );
    }

    /**
     * The Hidden Gems shelf: books with few reviews but a high
     * average rating (the denormalized columns ReviewService keeps
     * in sync with the approved reviews).
     *
     * Input:  the filter (min rating, max review count) and the limit
     * Output: book rows, best-rated first (ties: fewer reviews first)
     *
     * @return array<int, array<string, mixed>>
     */
    public function hiddenGemBooks(float $minRating, int $maxReviews, int $limit): array
    {
        return db()->query(
            'SELECT ' . self::BOOK_SELECT . '
             FROM books b
             WHERE ' . self::ACTIVE_WHERE . '
               AND b.average_rating >= ?
               AND b.ratings_count <= ?
             ORDER BY b.average_rating DESC, b.ratings_count ASC, b.id ASC
             LIMIT ?',
            [self::RECOMMENDED_STATUS, $minRating, $maxReviews, $limit],
        );
    }

    /**
     * The books whose average rating sits inside a band around the
     * anchor's rating - the book page's "similar by rating" shelf.
     *
     * Input:  the anchor rating, the band width and the limit
     * Output: book rows ordered by the rating gap ascending (each row
     *         carries rating_gap)
     *
     * @return array<int, array<string, mixed>>
     */
    public function booksSimilarByRating(float $anchorRating, float $band, int $limit): array
    {
        return db()->query(
            'SELECT ' . self::BOOK_SELECT . ',
                    ABS(b.average_rating - ?) AS rating_gap
             FROM books b
             WHERE ' . self::ACTIVE_WHERE . '
               AND b.average_rating BETWEEN ? - ? AND ? + ?
             ORDER BY rating_gap ASC, b.ratings_count DESC, b.id ASC
             LIMIT ?',
            [$anchorRating, self::RECOMMENDED_STATUS, $anchorRating, $band, $anchorRating, $band, $limit],
        );
    }

    /**
     * The books whose popularity (ratings count) sits inside a band
     * around the anchor's - the book page's "similar by popularity"
     * shelf.
     *
     * Input:  the anchor count, the band (a fraction of the count)
     *         and the limit
     * Output: book rows ordered by the count gap ascending (each row
     *         carries count_gap)
     *
     * @return array<int, array<string, mixed>>
     */
    public function booksSimilarByPopularity(int $anchorCount, float $factor, int $limit): array
    {
        $band = max(1, (int) round($anchorCount * $factor));

        return db()->query(
            'SELECT ' . self::BOOK_SELECT . ',
                    ABS(b.ratings_count - ?) AS count_gap
             FROM books b
             WHERE ' . self::ACTIVE_WHERE . '
               AND b.ratings_count BETWEEN ? AND ?
             ORDER BY count_gap ASC, b.average_rating DESC, b.id ASC
             LIMIT ?',
            [$anchorCount, self::RECOMMENDED_STATUS, max(0, $anchorCount - $band), $anchorCount + $band, $limit],
        );
    }

    /**
     * The library books that most shaped the recommendations - the
     * favourites and the finished books of one user, with their
     * titles and categories - the "Books Influencing
     * Recommendations" block of the profile page.
     *
     * Input:  a user id and how many books to return
     * Output: rows of book_id, title, cover_image, average_rating,
     *         ratings_count, library_status, is_favorite,
     *         categories_list, favourites first, then most recently
     *         updated
     *
     * @return array<int, array<string, mixed>>
     */
    public function libraryProfileBooks(int $userId, int $limit): array
    {
        return db()->query(
            'SELECT ul.book_id,
                    b.title,
                    b.cover_image,
                    b.average_rating,
                    b.ratings_count,
                    ul.library_status,
                    ul.is_favorite,
                    (SELECT GROUP_CONCAT(c.name, ", ")
                     FROM book_categories bc
                     JOIN categories c ON c.id = bc.category_id
                     WHERE bc.book_id = b.id) AS categories_list
             FROM user_library ul
             JOIN books b ON b.id = ul.book_id
             WHERE ul.user_id = ?
               AND (ul.is_favorite = 1 OR ul.library_status = ?)
             ORDER BY ul.is_favorite DESC, ul.updated_at DESC, ul.id DESC
             LIMIT ?',
            [$userId, 'finished', $limit],
        );
    }

    // -----------------------------------------------------------------
    // Phase 8.5: recommendation_logs (the audit + accuracy trail)
    // -----------------------------------------------------------------

    /**
     * Append one recommendation log entry per served book.
     *
     * Input:  the user id and the entries (each: book_id, reason,
     *         score, signal)
     * Output: nothing (one multi-row INSERT in a transaction)
     *
     * Business responsibility: the audit trail of Phase 8.5. Every
     * book a library-derived shelf serves is recorded - the caller
     * decides WHEN a shelf is worth logging (each section method logs
     * its own result), this method only persists. The rows power the
     * profile's Recommendation Accuracy figure.
     *
     * @param array<int, array<string, mixed>> $entries
     */
    public function logRecommendations(int $userId, array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $rows = array_slice($entries, 0, self::MAX_LOG_BATCH);

        $sql = 'INSERT INTO recommendation_logs (user_id, book_id, reason, score, signal)
                VALUES ';

        $placeholders = [];
        $params       = [];

        foreach ($rows as $entry) {
            $placeholders[] = '(?, ?, ?, ?, ?)';
            array_push($params, $userId, (int) $entry['book_id'], (string) $entry['reason'], (float) $entry['score'], (string) $entry['signal']);
        }

        db()->execute($sql . implode(', ', $placeholders), $params);
    }

    /**
     * Prune a user's logs to the newest rows.
     *
     * Input:  a user id and how many rows to keep
     * Output: nothing
     *
     * Business responsibility: the retention rule of the logs table -
     * the table stays bounded per user (config
     * recommendations.library.logs.retention_per_user) without a
     * background job. Called by the service after every log write.
     */
    public function pruneRecommendationLogs(int $userId, int $keep): void
    {
        if ($keep < 1) {
            return;
        }

        db()->execute(
            'DELETE FROM recommendation_logs
             WHERE user_id = ?
               AND id NOT IN (
                   SELECT id FROM recommendation_logs
                   WHERE user_id = ?
                   ORDER BY generated_at DESC, id DESC
                   LIMIT ?
               )',
            [$userId, $userId, $keep],
        );
    }

    /**
     * The recent recommendation logs of one user inside the accuracy
     * window, each annotated with whether the user acted on the book
     * (library record, rating, or wishlist save).
     *
     * Input:  a user id, the window cutoff and the limit
     * Output: rows of book_id, title, cover_image, reason, score,
     *         signal, generated_at, in_library, rated, saved
     *
     * Business responsibility: the single read behind the profile's
     * "Recommendation Accuracy" figure - three EXISTS subqueries
     * annotate each logged book with the user's actions, so the
     * service computes the accuracy from one row set (no N+1).
     *
     * @return array<int, array<string, mixed>>
     */
    public function recommendationLogs(int $userId, string $cutoff, int $limit): array
    {
        return db()->query(
            'SELECT l.book_id,
                    b.title,
                    b.cover_image,
                    l.reason,
                    l.score,
                    l.signal,
                    l.generated_at,
                    EXISTS(SELECT 1 FROM user_library ul
                           WHERE ul.user_id = l.user_id AND ul.book_id = l.book_id) AS in_library,
                    EXISTS(SELECT 1 FROM reviews r
                           WHERE r.user_id = l.user_id AND r.book_id = l.book_id) AS rated,
                    EXISTS(SELECT 1 FROM wishlist w
                           WHERE w.user_id = l.user_id AND w.book_id = l.book_id) AS saved
             FROM recommendation_logs l
             JOIN books b ON b.id = l.book_id
             WHERE l.user_id = ? AND l.generated_at >= ?
             ORDER BY l.generated_at DESC, l.id DESC
             LIMIT ?',
            [$userId, $cutoff, $limit],
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
