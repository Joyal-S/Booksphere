<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\Core\Logger;
use BookSphere\App\DTO\PersonalizationProfile;
use BookSphere\App\DTO\PersonalizedRecommendationItem;
use BookSphere\App\DTO\RecommendationContext;
use BookSphere\App\DTO\RecommendationResult;
use BookSphere\App\Exceptions\RecommendationException;
use BookSphere\App\Repositories\RecommendationRepository;

/**
 * RecommendationService
 *
 * The central orchestrator of the recommendations module - the
 * single entry point the controller talks to. It plays the same
 * role for recommendations that BookService plays for books.
 *
 * Responsibilities (Phase 6.2):
 *
 *     - CHOOSE the strategy: resolve a strategy key through the
 *       RecommendationFactory ("popular", "rating", "category",
 *       "recent", "author", "trending")
 *     - VALIDATE the request: confirm the chosen strategy can run
 *       with the given RecommendationContext (its supports()
 *       contract); a mismatch fails loudly instead of returning
 *       garbage
 *     - EXECUTE the strategy: hand the context over and get the
 *       RecommendationResult back
 *     - RETURN the DTO: one well-defined result shape, whatever
 *       strategy produced it
 *     - EXPOSE the six algorithms: getPopularBooks(),
 *       getHighestRatedBooks(), getRecentlyAddedBooks(),
 *       getBooksByCategory(), getBooksByAuthor(),
 *       getTrendingBooks() - thin, typed fronts for the controller
 *     - COMPOSE the overview: getRecommendations() builds the merged
 *       default shelf for /recommendations (Popular + Top Rated +
 *       Recently Added, no duplicates), ready for the other
 *       strategies in later phases
 *     - VALIDATE ids: category and author ids from the URL are
 *       checked against the catalogue here (delegating to the
 *       repository), so the controller never sees a bad id
 *
 * Responsibilities (Phase 6.3 - hybrid personalization):
 *
 *     - getPersonalizedRecommendations() builds ONE profile per user
 *       (favourite categories/authors from wishlist + ratings +
 *       reviews, plus recently viewed books), scores a bounded
 *       candidate pool with the weighted hybrid formula of
 *       config/recommendations.php, explains every book with a
 *       reason, and caches the result per user (30 minutes,
 *       PersonalizationCache)
 *     - calculateHybridScore() / getRecommendationReason() /
 *       filterRecommendations() / sortRecommendations() /
 *       limitRecommendations() are the small, single-responsibility
 *       pipeline steps of that shelf
 *     - invalidatePersonalization() drops a user's cache when their
 *       wishlist / rating / review signals change
 *     - recordBookView() lets the Book module feed the
 *       "recently viewed" signal (one line in BookController::show)
 *
 * Responsibilities (Phase 6.5 - production readiness):
 *
 *     - flushPersonalization() drops EVERY user's cache, called by
 *       the Book module after a catalogue write (create/update/soft
 *       delete) - shelves depend on the catalogue, so a catalogue
 *       change invalidates everyone
 *     - every cache access goes through cacheRead() / cacheWrite() /
 *       cacheWarning(): a broken cache degrades to an uncached run
 *       with a logged warning instead of a 500 (the cache is an
 *       optimization, never a dependency)
 *     - the engine's scores are normalized to 0-100 in
 *       RecommendationScoring (popularityPercent / trendingPercent),
 *       so every number the UI shows shares one scale
 *
 * What this service deliberately does NOT do:
 *     - no SQL (that is the repository's job)
 *     - no scoring math (that is RecommendationScoring's job)
 *     - no strategy decisions (that is the factory's job)
 *     - no rendering (that is the controller's job)
 *
 * Because every strategy is executed through this one funnel, later
 * features - caching, auditing, persisting results into the
 * recommendations table, hybrid strategies - can be added here
 * without touching a single strategy class.
 *
 * Dependency injection: the factory and the repository are injected
 * (routes/web.php wires it with all six strategies and the
 * repository). The service never constructs anything itself, which
 * keeps it trivially testable. The repository is needed here only
 * for catalogue id validation (categoryExists/authorExists) - all
 * strategy data still flows through the factory.
 */
final class RecommendationService
{
    /**
     * The page each strategy is exposed by, for the overview cards.
     *
     * This mapping is a presentation concern and lives here so the
     * overview page and the routes can never disagree.
     *
     * 'rating'  -> /recommendations/top-rated  (the confidence-
     *              filtered best-averaged shelf)
     * 'trending'-> /recommendations/trending   (the momentum shelf;
     *              replaces the Phase 6.1 rating stand-in)
     * 'category'-> /recommendations/category/{id} (explicit category)
     * 'author'  -> /books (book-anchored "more like this", reached
     *              from a book's page)
     */
    public const ROUTES = [
        'popular'  => '/recommendations/popular',
        'rating'   => '/recommendations/top-rated',
        'trending' => '/recommendations/trending',
        'category' => '/recommendations/category/{id}',
        'recent'   => '/recommendations/recent',
        'author'   => '/books',
    ];

    public const DEFAULT_LIMIT = 10;

    /** The strategy key of the personalized shelf. */
    public const PERSONAL_KEY = 'personal';

    public function __construct(
        private readonly RecommendationFactory $factory,
        private readonly RecommendationRepository $repository,
        private readonly ?PersonalizationCache $cache = null,
        private readonly ?Logger $logger = null,
    ) {}

    /**
     * Run the strategy behind a key with a validated context.
     *
     * Pipeline: choose (factory) -> validate (supports) -> execute
     * (strategy) -> return (DTO).
     *
     * @throws RecommendationException On an unknown strategy key or
     *                                 a context the strategy cannot run with
     */
    public function recommend(string $strategyKey, RecommendationContext $context): RecommendationResult
    {
        $strategy = $this->factory->make($strategyKey);

        if (!$strategy->supports($context)) {
            throw RecommendationException::unsupportedContext($strategyKey);
        }

        return $strategy->recommend($context);
    }

    /**
     * The merged default shelf: Popular + Top Rated + Recently
     * Added, deduplicated by book id, returned as one strategy
     * result (the overview page renders it as a single shelf).
     *
     * @throws RecommendationException When a constituent strategy
     *                                 cannot run (should never
     *                                 happen - all three are
     *                                 context-free)
     */
    public function getRecommendations(): RecommendationResult
    {
        $limit   = self::DEFAULT_LIMIT;
        $context = new RecommendationContext(limit: $limit);

        $shelves = [
            $this->recommend('popular', $context),
            $this->recommend('rating', $context),
            $this->recommend('recent', $context),
        ];

        $merged = [];
        $seen   = [];

        foreach ($shelves as $shelf) {
            foreach ($shelf->items as $item) {
                $id = (int) $item['id'];

                if (isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                $merged[]  = $item;
            }
        }

        return RecommendationResult::fromBooks(
            'popular',
            'Recommended for you',
            $merged,
            'A blend of the most popular, the best rated and the newest books, with duplicates removed.',
        );
    }

    /**
     * The "Popular" shelf: the community's most active books.
     *
     * @throws RecommendationException On a context the strategy
     *                                 cannot run with (never for
     *                                 this context-free shelf)
     */
    public function getPopularBooks(int $limit = self::DEFAULT_LIMIT): RecommendationResult
    {
        return $this->recommend('popular', new RecommendationContext(limit: $limit));
    }

    /**
     * The "Top Rated" shelf: best average rating with a minimum
     * number of reviews (confidence rule).
     *
     * @throws RecommendationException See getPopularBooks()
     */
    public function getHighestRatedBooks(int $limit = self::DEFAULT_LIMIT): RecommendationResult
    {
        return $this->recommend('rating', new RecommendationContext(limit: $limit));
    }

    /**
     * The "Recently Added" shelf: the newest arrivals.
     *
     * @throws RecommendationException See getPopularBooks()
     */
    public function getRecentlyAddedBooks(int $limit = self::DEFAULT_LIMIT): RecommendationResult
    {
        return $this->recommend('recent', new RecommendationContext(limit: $limit));
    }

    /**
     * The "Trending" shelf: books gaining momentum in the last
     * 30 days.
     *
     * @throws RecommendationException See getPopularBooks()
     */
    public function getTrendingBooks(int $limit = self::DEFAULT_LIMIT): RecommendationResult
    {
        return $this->recommend('trending', new RecommendationContext(limit: $limit));
    }

    /**
     * The "By Category" shelf for an explicit category id.
     *
     * @throws RecommendationException When the category does not
     *                                 exist
     */
    public function getBooksByCategory(int $categoryId, int $limit = self::DEFAULT_LIMIT): RecommendationResult
    {
        $this->assertCategoryExists($categoryId);

        return $this->recommend('category', new RecommendationContext(categoryId: $categoryId, limit: $limit));
    }

    /**
     * The "By Author" shelf for an explicit author id.
     *
     * @throws RecommendationException When the author does not
     *                                 exist
     */
    public function getBooksByAuthor(int $authorId, int $limit = self::DEFAULT_LIMIT): RecommendationResult
    {
        $this->assertAuthorExists($authorId);

        return $this->recommend('author', new RecommendationContext(authorId: $authorId, limit: $limit));
    }

    /**
     * The book-anchored "More Like This" shelf: other books by the
     * authors of one anchor book, the anchor itself excluded.
     *
     * @throws RecommendationException When the anchor book is
     *                                 missing or has no authors
     */
    public function getMoreLikeThis(int $bookId, int $limit = self::DEFAULT_LIMIT): RecommendationResult
    {
        return $this->recommend('author', new RecommendationContext(bookId: $bookId, limit: $limit));
    }

    // -----------------------------------------------------------------
    // Phase 6.3: hybrid personalization
    // -----------------------------------------------------------------

    /**
     * The personalization profile snapshot of one user (Phase 6.4
     * presentation support).
     *
     * Input:  a user id
     * Output: the same PersonalizationProfile the hybrid engine
     *         builds for its scoring - favourite categories/authors,
     *         wishlist / highly rated / reviewed / recently viewed
     *         ids
     *
     * Business responsibility: the recommendation dashboard needs
     * the profile to explain the sections ("Because you follow...",
     * "Trending near your interests", the insights strip). This
     * read-only accessor REUSES buildProfile() - the profile is
     * derived exactly the same way the engine derives it, so the
     * dashboard can never disagree with the algorithm. No scoring,
     * filtering or caching behaviour is touched.
     */
    public function profileFor(int $userId): PersonalizationProfile
    {
        return $this->buildProfile($userId);
    }

    /**
     * Toggle one book in the wishlist of one user (Phase 6.4
     * presentation support).
     *
     * Input:  a user id and a book id
     * Output: whether the book is NOW saved in the wishlist
     *         (true = just saved, false = just removed / invalid)
     *
     * Business responsibility: the wishlist quick action of the
     * recommendation cards. A book is only saved when it exists and
     * is published; re-toggling removes it (UNIQUE(user_id,
     * book_id)). Because the wishlist is a personalization signal,
     * the user's cached shelf is invalidated so the next dashboard
     * reflects the change immediately. A guest (no real user id)
     * can never write to a wishlist.
     */
    public function toggleWishlist(int $userId, int $bookId): bool
    {
        if ($userId < 1) {
            return false;
        }

        $saved = $this->repository->toggleWishlist($userId, $bookId);

        $this->invalidatePersonalization($userId);

        return $saved;
    }

    /**
     * The personalized shelf of one user (the Phase 6.3 deliverable).
     *
     * Input:  the user id (defaults to the logged-in user) and the
     *         requested shelf size
     * Output: a RecommendationResult with books unique to that user,
     *         each carrying a score (0-100), an explainable reason
     *         and a confidence label
     *
     * Pipeline (every step is its own small method):
     *
     *     1. cache      - the per-user result is served from
     *                     PersonalizationCache when fresh (30
     *                     minutes), so the pipeline below runs at
     *                     most once per cache lifetime
     *     2. buildProfile()   - favourite categories/authors from
     *                     wishlist + ratings + reviews, plus recent
     *                     views (all batch queries, no N+1)
     *     3. scoreCandidates() - the bounded candidate pool
     *                     (hybridCandidates) scored with the
     *                     weighted formula (calculateHybridScore)
     *     4. filterRecommendations() - wishlist books, duplicates
     *                     and junk ids removed
     *     5. sortRecommendations()   - hybrid score first, trending
     *                     as the tiebreak, then popularity, then id
     *     6. limitRecommendations()  - the shelf size
     *     7. cache      - the fresh result is stored for the next
     *                     request
     *
     * A user without any signal gets the popularity-fallback pool -
     * a sensible cold start instead of an empty page. Guests fall
     * back to the community shelves (getRecommendations()).
     */
    public function getPersonalizedRecommendations(?int $userId = null, int $limit = self::DEFAULT_LIMIT): RecommendationResult
    {
        $userId ??= auth()?->id();

        if ($userId === null || $userId < 1) {
            return $this->getRecommendations();
        }

        $cached = $this->cacheRead($userId);

        if ($cached !== null) {
            return $this->restoreResult($cached);
        }

        $profile    = $this->buildProfile($userId);
        $candidates = $this->scoreCandidates($profile, $limit);

        $items = array_map(
            fn (PersonalizedRecommendationItem $item): array => $item->toArray(),
            $candidates,
        );

        // Exclusion rules (the brief's list, enforced once): wishlist
        // books, the exact recently-viewed books ("do not recommend
        // the same book"), duplicates and junk ids.
        $items = $this->filterRecommendations(
            $items,
            [...$profile->wishlistBookIds, ...$profile->recentlyViewedBookIds],
        );
        $items = $this->sortRecommendations($items);
        $items = $this->limitRecommendations($items, $limit);

        $result = RecommendationResult::fromBooks(
            self::PERSONAL_KEY,
            'Recommended for you',
            $items,
            $this->personalNote(),
        );

        $this->cacheWrite($userId, $this->storeResult($result));

        return $result;
    }

    /**
     * The weighted hybrid score of one book, 0-100.
     *
     * Input:  the factor signals of the book (see
     *         RecommendationScoring::hybridScore())
     * Output: the score, from the weights of
     *         config/recommendations.php
     *
     * Business responsibility: a thin front over the scoring engine -
     * the formula itself lives in RecommendationScoring so the tests
     * can compare the pipeline against the pure mirror.
     *
     * @param array<string, int|float> $signals
     */
    public function calculateHybridScore(array $signals): float
    {
        return RecommendationScoring::hybridScore($signals);
    }

    /**
     * The explainable reason of one recommendation.
     *
     * Input:  the matched factors of the book, the user's profile and
     *         the raw factor signals (for the wishlist-vs-recently-
     *         viewed phrasing)
     * Output: a short human-readable explanation ("You enjoy Fantasy
     *         and Science Fiction books. Similar to books in your
     *         wishlist.")
     *
     * Business responsibility: the EXPLAINABLE part of the engine -
     * the reason is composed here as data and travels inside the
     * DTO; a view can only print it, never invent it. At most two
     * sentences keep the card readable; a book from the fallback
     * pool honestly says it is a starting point.
     *
     * @param array<int, string>            $matched
     * @param array<string, int|float>      $signals
     */
    public function getRecommendationReason(array $matched, PersonalizationProfile $profile, array $signals = []): string
    {
        $parts = [];

        if (in_array('category', $matched, true)) {
            $names = array_slice(
                array_map(fn (array $favourite): string => $favourite['name'], $profile->favouriteCategories),
                0,
                2,
            );
            $parts[] = 'You enjoy ' . implode(' and ', $names) . ' books.';
        }

        if (in_array('author', $matched, true)) {
            $names = array_slice(
                array_map(fn (array $favourite): string => $favourite['name'], $profile->favouriteAuthors),
                0,
                2,
            );
            $parts[] = 'Because you follow ' . implode(' and ', $names) . '.';
        }

        if (in_array('wishlist', $matched, true)) {
            $viewed   = (int) ($signals['viewed'] ?? 0);
            $wishlist = (int) ($signals['wishlist'] ?? 0) - $viewed;

            if ($viewed > 0 && $wishlist <= 0) {
                $parts[] = 'Similar to books you recently viewed.';
            } elseif ($viewed > 0) {
                $parts[] = 'Similar to books in your wishlist or recently viewed.';
            } else {
                $parts[] = 'Similar to books in your wishlist.';
            }
        }

        if (in_array('rating', $matched, true)) {
            $parts[] = 'Popular among readers of your highly rated books.';
        }

        if (in_array('trending', $matched, true)) {
            $parts[] = 'Gaining momentum this month.';
        }

        if ($parts !== []) {
            return implode(' ', array_slice($parts, 0, 2));
        }

        return 'A community favourite - a starting point for your profile.';
    }

    /**
     * Remove everything a user must never be recommended.
     *
     * Input:  the scored items and the ids to exclude (wishlist
     *         books; the "current book" is passed the same way)
     * Output: the items without excluded ids and without duplicates
     *
     * Business responsibility: the exclusion rules of the brief are
     * enforced HERE, once, for every personal shelf:
     *
     *     - books already in the wishlist
     *     - duplicate recommendations (first occurrence wins)
     *     - junk ids (defence in depth)
     *
     * Deleted / draft / archived books never reach this method: the
     * candidate query already excludes them. "Books already read"
     * is not supported yet - there is no read-tracking table (a
     * Phase 6.4 future improvement).
     *
     * @param array<int, array<string, mixed>> $items
     * @param array<int, int>                  $excludeIds
     * @return array<int, array<string, mixed>>
     */
    public function filterRecommendations(array $items, array $excludeIds = []): array
    {
        $excluded = array_fill_keys(array_map('intval', $excludeIds), true);

        $seen = [];
        $kept = [];

        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);

            if ($id < 1 || isset($excluded[$id]) || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $kept[]    = $item;
        }

        return $kept;
    }

    /**
     * Order the shelf by relevance.
     *
     * Input:  the filtered items
     * Output: the same items, hybrid score descending
     *
     * Business responsibility: the score is the primary key. The
     * brief asks to "prefer trending books when scores are similar",
     * so the trending score is the first tiebreak, then popularity
     * (still only as a tiebreak), then id for a deterministic order.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    public function sortRecommendations(array $items): array
    {
        usort($items, function (array $a, array $b): int {
            $score = (float) ($b['score'] ?? 0) <=> (float) ($a['score'] ?? 0);

            if ($score !== 0) {
                return $score;
            }

            $trending = (float) ($b['trending_score'] ?? 0) <=> (float) ($a['trending_score'] ?? 0);

            if ($trending !== 0) {
                return $trending;
            }

            $popularity = (float) ($b['popularity_score'] ?? 0) <=> (float) ($a['popularity_score'] ?? 0);

            if ($popularity !== 0) {
                return $popularity;
            }

            return (int) $a['id'] <=> (int) $b['id'];
        });

        return $items;
    }

    /**
     * Cut the shelf down to size.
     *
     * Input:  the sorted items and the maximum number to keep
     * Output: at most that many items, in the same order
     *
     * Business responsibility: the final step of the pipeline - the
     * engine never hands the view more than the caller asked for.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    public function limitRecommendations(array $items, int $limit): array
    {
        return array_slice($items, 0, max(0, $limit));
    }

    /**
     * Drop the cached shelf of one user.
     *
     * Input:  a user id
     * Output: nothing
     *
     * Business responsibility: the explicit invalidation hook of the
     * brief - the future wishlist / rating / review write-controllers
     * call this the moment a signal changes, so the next shelf
     * reflects the new signal instead of the cached one.
     */
    public function invalidatePersonalization(int $userId): void
    {
        try {
            $this->cache?->invalidate($userId);
        } catch (\Throwable $exception) {
            $this->cacheWarning('invalidate', $exception);
        }
    }

    /**
     * Drop EVERY user's cached shelf (Phase 6.5).
     *
     * Input:  nothing
     * Output: nothing
     *
     * Business responsibility: the catalogue-wide invalidation hook.
     * A created, updated or deleted book can change the shelves of
     * EVERY user (popularity, trending, recent, category and author
     * signals all depend on the catalogue), so the Book module calls
     * this once after each catalogue write instead of walking every
     * user id. The per-user invalidation stays the right tool for
     * single-user signal changes; this is the all-at-once cousin.
     */
    public function flushPersonalization(): void
    {
        try {
            $this->cache?->flush();
        } catch (\Throwable $exception) {
            $this->cacheWarning('flush', $exception);
        }
    }

    /**
     * Feed the "recently viewed" signal.
     *
     * Input:  a user id and a book id
     * Output: nothing
     *
     * Business responsibility: called by the Book module's show page
     * so the hybrid engine can recommend "similar to what you
     * viewed". Bad ids are ignored, and a view does NOT invalidate
     * the cache (views change too often; the 30-minute TTL is the
     * right freshness for them).
     */
    public function recordBookView(int $userId, int $bookId): void
    {
        if ($userId < 1 || $bookId < 1) {
            return;
        }

        $this->repository->recordBookView($userId, $bookId);
    }

    /**
     * Build the per-user profile from the three signal sources.
     *
     * Input:  a user id
     * Output: a PersonalizationProfile snapshot (favourite
     *         categories/authors + wishlist/highly-rated/reviewed/
     *         recently-viewed book ids)
     *
     * Business logic (weights from config/recommendations.php):
     *
     *     - every wishlist book     weighs wishlist_weight
     *     - every book rated >= min_favourite_rating weighs
     *       high_rating_weight
     *     - every reviewed book weighs review_weight, UNLESS it was
     *       rated <= ignore_rating - poorly rated books contribute
     *       nothing ("ignore books rated very poorly")
     *     - the weights are summed per category and per author; the
     *       top favourite_categories / favourite_authors are kept
     *
     * Performance: the signal ids are read with three indexed
     * queries, their category/author links with TWO batch queries
     * (IN clauses) - there is no per-book look-up, so the profile
     * stays at O(books + links), not O(books^2).
     */
    private function buildProfile(int $userId): PersonalizationProfile
    {
        $profileConfig = (array) config('recommendations.profile', []);
        $wishlistWeight  = (int) ($profileConfig['wishlist_weight'] ?? 3);
        $highRatingWeight = (int) ($profileConfig['high_rating_weight'] ?? 2);
        $reviewWeight    = (int) ($profileConfig['review_weight'] ?? 1);
        $minFavourite    = (int) ($profileConfig['min_favourite_rating'] ?? 4);
        $ignoreRating    = (int) ($profileConfig['ignore_rating'] ?? 2);
        $categoryLimit   = (int) ($profileConfig['favourite_categories'] ?? 5);
        $authorLimit     = (int) ($profileConfig['favourite_authors'] ?? 5);

        $wishlistIds = $this->repository->wishlistBookIds($userId);
        $ratings     = $this->repository->ratedBooks($userId);
        $reviewedIds = $this->repository->reviewedBookIds($userId);

        $highlyRatedIds = array_values(array_filter(
            array_keys($ratings),
            fn (int $bookId): bool => $ratings[$bookId] >= $minFavourite,
        ));

        $reviewedForProfile = array_values(array_filter(
            $reviewedIds,
            fn (int $bookId): bool => ($ratings[$bookId] ?? 0) > $ignoreRating,
        ));

        // Signal weight per book: a wishlist save + high rating +
        // review can all apply to the same book (the weights sum).
        $bookWeights = [];

        foreach ($wishlistIds as $bookId) {
            $bookWeights[$bookId] = ($bookWeights[$bookId] ?? 0) + $wishlistWeight;
        }

        foreach ($highlyRatedIds as $bookId) {
            $bookWeights[$bookId] = ($bookWeights[$bookId] ?? 0) + $highRatingWeight;
        }

        foreach ($reviewedForProfile as $bookId) {
            $bookWeights[$bookId] = ($bookWeights[$bookId] ?? 0) + $reviewWeight;
        }

        $favouriteCategories = $this->favourites(
            $this->repository->categoriesForBooks(array_keys($bookWeights)),
            $bookWeights,
            $categoryLimit,
        );

        $favouriteAuthors = $this->favourites(
            $this->repository->authorsForBooks(array_keys($bookWeights)),
            $bookWeights,
            $authorLimit,
        );

        $viewCap = (int) (config('recommendations.candidates.signal_book_cap', 20));

        return new PersonalizationProfile(
            userId:                $userId,
            favouriteCategories:   $favouriteCategories,
            favouriteAuthors:      $favouriteAuthors,
            wishlistBookIds:       array_values(array_unique($wishlistIds)),
            highlyRatedBookIds:    $highlyRatedIds,
            reviewedBookIds:       $reviewedForProfile,
            recentlyViewedBookIds: $this->repository->recentlyViewedBookIds($userId, $viewCap),
            builtAt:               gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    /**
     * Aggregate link rows into the top-N favourite ids with names.
     *
     * Input:  the batch-loaded category/author link rows, the per-
     *         book signal weights, and how many favourites to keep
     * Output: ['id' => ['name' => ..., 'weight' => ...]] ordered by
     *         weight descending, then name ascending
     *
     * Business logic: each link row contributes its book's signal
     * weight to the linked category/author; the highest weighted
     * ids are kept. Ties are broken alphabetically by name, so two
     * runs over the same data always produce the same favourites
     * (stable shelves for the cache tests). The result keys are the
     * ids (see PersonalizationProfile::favouriteCategoryIds()).
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, int>                  $bookWeights
     * @return array<int, array{name: string, weight: int}>
     */
    private function favourites(array $rows, array $bookWeights, int $limit): array
    {
        $weights = [];

        foreach ($rows as $row) {
            $id = (int) ($row['category_id'] ?? $row['author_id']);

            $weights[$id] = ($weights[$id] ?? 0) + ($bookWeights[(int) $row['book_id']] ?? 0);
        }

        $isCategory = isset($rows[0]['category_id']);

        $names = $isCategory
            ? $this->repository->categoryNames(array_keys($weights))
            : $this->repository->authorNames(array_keys($weights));

        uasort($weights, static function (int $a, int $b) use ($names): int {
            if ($a !== $b) {
                return $b <=> $a;
            }

            return strcasecmp((string) ($names[$a] ?? ''), (string) ($names[$b] ?? ''));
        });

        $favourites = [];

        foreach (array_slice(array_keys($weights), 0, $limit) as $id) {
            $favourites[(int) $id] = [
                'name'   => (string) ($names[$id] ?? 'Unknown'),
                'weight' => $weights[$id],
            ];
        }

        return $favourites;
    }

    /**
     * Score the candidate pool of one profile.
     *
     * Input:  the user's profile and the requested shelf size
     * Output: PersonalizedRecommendationItem DTOs, unsorted
     *
     * Business logic:
     *
     *     1. The candidate pool comes from one repository query
     *        (hybridCandidates): every book matching a favourite
     *        category/author or similar to the wishlist/highly
     *        rated/recently viewed books, plus the popularity
     *        fallback. The pool is bounded (config) and carries
     *        popularity_score and trending_score per row.
     *     2. The candidates' category and author links are batch
     *        loaded (two IN queries) - no N+1.
     *     3. Every candidate is scored with the weighted formula;
     *        the books with a zero score (possible only via a
     *        fallback book with no popularity) are dropped.
     *     4. Every survivor gets its explainable reason and its
     *        confidence label.
     *
     * @return array<int, PersonalizedRecommendationItem>
     */
    private function scoreCandidates(PersonalizationProfile $profile, int $limit): array
    {
        $candidateConfig = (array) config('recommendations.candidates', []);
        $signalCap  = (int) ($candidateConfig['signal_book_cap'] ?? 20);
        $fallbackN  = (int) ($candidateConfig['popularity_fallback'] ?? 10);
        $poolLimit  = (int) ($candidateConfig['pool_limit'] ?? 50);

        $wishlistIds = array_slice($profile->wishlistBookIds, 0, $signalCap);
        $ratedIds    = array_slice($profile->highlyRatedBookIds, 0, $signalCap);
        $viewedIds   = array_slice($profile->recentlyViewedBookIds, 0, $signalCap);

        $wishlistCategoryIds = $this->categoryIdsOf($wishlistIds);
        $ratingCategoryIds   = $this->categoryIdsOf($ratedIds);
        $viewedCategoryIds   = $this->categoryIdsOf($viewedIds);

        $fallbackIds = array_map(
            fn (array $row): int => (int) $row['id'],
            $this->repository->popularBooks($fallbackN),
        );

        $rows = $this->repository->hybridCandidates(
            $profile->favouriteCategoryIds(),
            $profile->favouriteAuthorIds(),
            $wishlistCategoryIds,
            $ratingCategoryIds,
            $viewedCategoryIds,
            $fallbackIds,
            $poolLimit,
        );

        $candidateIds = array_map(fn (array $row): int => (int) $row['id'], $rows);
        $categoryIds  = $this->idsPerBook($this->repository->categoriesForBooks($candidateIds));
        $authorIds    = $this->idsPerBook($this->repository->authorsForBooks($candidateIds));

        $items = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];

            // "Recently viewed" rides the wishlist-similarity factor:
            // viewed books express the same "I am interested" signal
            // as wishlist saves, so their shared categories feed the
            // same 15-point budget (the cap makes a strong wishlist
            // match still dominate a weak view-only one).
            $wishlistOverlap = count(array_intersect($categoryIds[$id] ?? [], $wishlistCategoryIds));
            $viewedOverlap   = count(array_intersect($categoryIds[$id] ?? [], $viewedCategoryIds));

            $signals = [
                'category'   => count(array_intersect($categoryIds[$id] ?? [], $profile->favouriteCategoryIds())),
                'author'     => count(array_intersect($authorIds[$id] ?? [], $profile->favouriteAuthorIds())),
                'wishlist'   => $wishlistOverlap + $viewedOverlap,
                'viewed'     => $viewedOverlap,
                'rating'     => count(array_intersect($categoryIds[$id] ?? [], $ratingCategoryIds)),
                'trending'   => (float) ($row['trending_score'] ?? 0),
                'popularity' => (float) ($row['popularity_score'] ?? 0),
            ];

            $score = $this->calculateHybridScore($signals);

            if ($score <= 0) {
                continue;
            }

            $matched = $this->matchedFactors($signals);

            $items[] = new PersonalizedRecommendationItem(
                book:       $row,
                score:      $score,
                reason:     $this->getRecommendationReason($matched, $profile, $signals),
                confidence: $this->confidenceFor($score, $matched),
                matched:    $matched,
            );
        }

        return $items;
    }

    /**
     * The factor keys that actually fired for one book.
     *
     * Input:  the factor signals of the book
     * Output: the matched keys ('category', 'author', 'wishlist',
     *         'viewed', 'rating', 'trending')
     *
     * Business responsibility: the machine-readable half of the
     * explanation, and the basis of the confidence label. The pure
     * popularity bonus is deliberately NOT a matched factor - it is
     * a small tiebreak, not a reason ("popularity should never
     * dominate personalization").
     *
     * @param array<string, int|float> $signals
     * @return array<int, string>
     */
    private function matchedFactors(array $signals): array
    {
        $matched = [];

        foreach (['category', 'author', 'wishlist', 'viewed', 'rating'] as $factor) {
            if ((int) ($signals[$factor] ?? 0) > 0) {
                $matched[] = $factor;
            }
        }

        if ((float) ($signals['trending'] ?? 0) > 0) {
            $matched[] = 'trending';
        }

        return $matched;
    }

    /**
     * The confidence label of one recommendation.
     *
     * Input:  the score and the matched personal factors
     * Output: 'high' | 'medium' | 'low' (thresholds from config)
     *
     * Business logic: high requires both a strong score AND at least
     * two personal factors - a book can score well on popularity
     * alone, but the engine only claims high confidence when the
     * match is truly personal.
     *
     * @param array<int, string> $matched
     */
    private function confidenceFor(float $score, array $matched): string
    {
        $thresholds = (array) config('recommendations.confidence', ['high' => 60, 'medium' => 30]);
        $personal   = count(array_intersect($matched, ['category', 'author', 'wishlist', 'viewed', 'rating']));

        if ($score >= (float) ($thresholds['high'] ?? 60) && $personal >= 2) {
            return 'high';
        }

        if ($score >= (float) ($thresholds['medium'] ?? 30)) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * The category ids of a set of books (batch loaded once).
     *
     * @param array<int, int> $bookIds
     * @return array<int, int>
     */
    private function categoryIdsOf(array $bookIds): array
    {
        return $this->distinctIds($this->repository->categoriesForBooks($bookIds));
    }

    /**
     * Group link rows by book id: book => set of linked ids.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<int, int>>
     */
    private function idsPerBook(array $rows): array
    {
        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row['book_id']][] = (int) ($row['category_id'] ?? $row['author_id']);
        }

        return $map;
    }

    /**
     * The distinct linked ids of link rows (category OR author rows).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, int>
     */
    private function distinctIds(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $ids[] = (int) ($row['category_id'] ?? $row['author_id']);
        }

        return array_values(array_unique($ids));
    }

    /**
     * The note shown above the personalized shelf: the formula, with
     * the real configured weights.
     */
    private function personalNote(): string
    {
        $weights = RecommendationScoring::hybridWeights();

        return 'Hybrid personalization: category matches x ' . $weights['category']
            . ' + author matches x ' . $weights['author']
            . ' + wishlist similarity x ' . $weights['wishlist']
            . ' + rating similarity x ' . $weights['rating']
            . ' + trending x ' . $weights['trending']
            . ' + popularity x ' . $weights['popularity']
            . ' (of 100) - personalised from your wishlist, ratings, reviews and recent views.';
    }

    /**
     * Rebuild a RecommendationResult from a cached payload.
     *
     * @param array<string, mixed> $payload
     */
    private function restoreResult(array $payload): RecommendationResult
    {
        return new RecommendationResult(
            strategyKey:   (string) ($payload['strategyKey'] ?? self::PERSONAL_KEY),
            strategyLabel: (string) ($payload['strategyLabel'] ?? 'Recommended for you'),
            items:         is_array($payload['items'] ?? null) ? $payload['items'] : [],
            total:         (int) ($payload['total'] ?? 0),
            note:          (string) ($payload['note'] ?? ''),
            generatedAt:   (string) ($payload['generatedAt'] ?? gmdate('Y-m-d\TH:i:s\Z')),
        );
    }

    /**
     * Serialize a RecommendationResult for the cache.
     *
     * @return array<string, mixed>
     */
    private function storeResult(RecommendationResult $result): array
    {
        return [
            'strategyKey'   => $result->strategyKey,
            'strategyLabel' => $result->strategyLabel,
            'items'         => $result->items,
            'total'         => $result->total,
            'note'          => $result->note,
            'generatedAt'   => $result->generatedAt,
        ];
    }

    // -----------------------------------------------------------------
    // Phase 6.5: cache access with graceful degradation
    // -----------------------------------------------------------------

    /**
     * Read the cached shelf of one user, or null on a miss.
     *
     * Input:  a user id
     * Output: the cached payload array, or null when the cache is
     *         disabled, cold, stale - OR broken
     *
     * Business responsibility: the cache is an optimization, never a
     * dependency. A file that cannot be read (permissions, disk
     * trouble, a corrupt payload) must not turn the dashboard into a
     * 500 - it is treated as a miss, the engine recomputes the shelf,
     * and the failure is logged for the administrator. The shelf is
     * always worth one rebuild.
     *
     * @return array<string, mixed>|null
     */
    private function cacheRead(int $userId): ?array
    {
        try {
            return $this->cache?->get($userId);
        } catch (\Throwable $exception) {
            $this->cacheWarning('read', $exception);

            return null;
        }
    }

    /**
     * Store the shelf of one user, best-effort.
     *
     * Input:  the user id and the serializable payload
     * Output: nothing (a failed write is logged, never fatal)
     *
     * Business responsibility: the counterpart of cacheRead(). If
     * the cache cannot be written, the just-computed shelf is still
     * returned to the user - the next request simply rebuilds it.
     */
    private function cacheWrite(int $userId, array $payload): void
    {
        try {
            $this->cache?->put($userId, $payload);
        } catch (\Throwable $exception) {
            $this->cacheWarning('write', $exception);
        }
    }

    /**
     * Log one cache failure once, without stopping the request.
     *
     * Input:  the failing operation and the thrown exception
     * Output: nothing
     *
     * Business responsibility: the single reporting path for cache
     * trouble. The warning level (not error) reflects that the
     * engine degrades gracefully - nobody sees a broken page, the
     * log just explains why the cache was skipped.
     */
    private function cacheWarning(string $operation, \Throwable $exception): void
    {
        $this->logger?->warning('Recommendation cache unavailable (' . $operation . '); serving an uncached run.', [
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Validate a category id against the catalogue before the
     * strategy runs - a bad id must never reach the strategy.
     *
     * @throws RecommendationException When the category is missing
     */
    private function assertCategoryExists(int $categoryId): void
    {
        $this->repository->categoryExists($categoryId)
            || throw RecommendationException::categoryNotFound($categoryId);
    }

    /**
     * Validate an author id against the catalogue before the
     * strategy runs - a bad id must never reach the strategy.
     *
     * @throws RecommendationException When the author is missing
     */
    private function assertAuthorExists(int $authorId): void
    {
        $this->repository->authorExists($authorId)
            || throw RecommendationException::authorNotFound($authorId);
    }

    /**
     * The metadata of every registered strategy, for the overview
     * page (the strategy cards).
     *
     * @return array<int, array{key: string, label: string, description: string, icon: string, url: string}>
     */
    public function strategies(): array
    {
        $items = [];

        foreach ($this->factory->all() as $strategy) {
            $key     = $strategy->key();
            $items[] = [
                'key'         => $key,
                'label'       => $strategy->label(),
                'description' => $strategy->description(),
                'icon'        => $strategy->icon(),
                'url'         => self::ROUTES[$key] ?? '/books',
            ];
        }

        return $items;
    }
}
