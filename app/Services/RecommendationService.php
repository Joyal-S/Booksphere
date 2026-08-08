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
        private readonly ?NotificationDispatcher $dispatcher = null,
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
            // A cached shelf was stored under the limit of the FIRST
            // caller - re-apply THIS caller's limit (and recount the
            // total) so a shelf cached with 5 items can still serve
            // the book page's 6-item section, exactly like the
            // library-section restore path does.
            $items = $this->limitRecommendations((array) ($cached['items'] ?? []), $limit);
            $cached['items'] = $items;
            $cached['total'] = count($items);

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

        // Phase 9.3: the recommendation_ready ping - the shelf was
        // ACTUALLY generated right now (a fresh cache miss). A user
        // without personal signals got the honest cold-start pool
        // ("starting point", no "your picks" claim), so only a real
        // profile earns the "Your picks are ready" notification.
        if ($this->dispatcher !== null && $this->profileHasSignals($profile)) {
            $this->dispatcher->notify('recommendation_ready', [], $userId);
        }

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

        if (in_array('review_score', $matched, true)) {
            $parts[] = 'Highly rated by the community.';
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
     * Whether the profile carries ANY personal signal. The
     * recommendation_ready ping (Phase 9.3) is gated on this: a
     * profile-free user only ever got the honest cold-start pool, so
     * claiming "your picks are ready" would be a lie.
     */
    private function profileHasSignals(PersonalizationProfile $profile): bool
    {
        return $profile->favouriteCategories !== []
            || $profile->favouriteAuthors !== []
            || $profile->wishlistBookIds !== []
            || $profile->highlyRatedBookIds !== []
            || $profile->reviewedBookIds !== []
            || $profile->recentlyViewedBookIds !== [];
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
                'category'     => count(array_intersect($categoryIds[$id] ?? [], $profile->favouriteCategoryIds())),
                'author'       => count(array_intersect($authorIds[$id] ?? [], $profile->favouriteAuthorIds())),
                'wishlist'     => $wishlistOverlap + $viewedOverlap,
                'viewed'       => $viewedOverlap,
                'rating'       => count(array_intersect($categoryIds[$id] ?? [], $ratingCategoryIds)),
                'review_score' => $this->reviewScoreSignal($row),
                'trending'     => (float) ($row['trending_score'] ?? 0),
                'popularity'   => (float) ($row['popularity_score'] ?? 0),
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
     *         'viewed', 'rating', 'review_score', 'trending')
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

        if ((float) ($signals['review_score'] ?? 0) > 0) {
            $matched[] = 'review_score';
        }

        if ((float) ($signals['trending'] ?? 0) > 0) {
            $matched[] = 'trending';
        }

        return $matched;
    }

    /**
     * The review-score signal of one candidate book: its community
     * review quality, normalized to 0-1.
     *
     * Input:  a candidate row (carries the book's denormalized
     *         average_rating / ratings_count columns)
     * Output: average_rating / 5 when the book has at least one
     *         review, 0 otherwise
     *
     * Business responsibility: the Phase 7.6 review factor is
     * community signal only - a book with no approved reviews can
     * never earn the review_score weight, and the value is the
     * reviews-synced column the ReviewService maintains, never a
     * seeded sample.
     *
     * @param array<string, mixed> $row
     */
    private function reviewScoreSignal(array $row): float
    {
        $count   = (int) ($row['ratings_count'] ?? 0);
        $average = (float) ($row['average_rating'] ?? 0);

        if ($count <= 0 || $average <= 0) {
            return 0.0;
        }

        return min($average / RecommendationScoring::RATING_MAX, 1.0);
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
            . ' + reading history x ' . $weights['rating']
            . ' + review score x ' . $weights['review_score']
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

    // -----------------------------------------------------------------
    // Phase 8.5: Personal Library recommendations
    //
    // The library is now a first-class signal source. Every section
    // below is a weighted, EXPLAINABLE shelf: weights come from
    // RecommendationConfig (config/recommendations.php), every item
    // carries a reason the views can only print, books already in the
    // user's library or wishlist are never suggested again, shelves
    // are cached per user per section (PersonalizationCache, same TTL
    // and invalidation as the hybrid shelf), and every served shelf
    // is logged to recommendation_logs for the profile's accuracy
    // figure and the admin audit.
    // -----------------------------------------------------------------

    /**
     * The library shelf sections of Phase 8.5: key => label. The
     * section key is what libraryRecommendations() accepts, the
     * label is the shelf title the views render - the two can never
     * disagree because they share this one map.
     */
    public const LIBRARY_SECTIONS = [
        'because_library'      => 'Recommended from your library',
        'because_you_read'     => 'Because you read',
        'similar_favourites'   => 'Similar to your favourites',
        'continue_exploring'   => 'Continue exploring',
        'discover_new_authors' => 'Discover new authors',
        'hidden_gems'          => 'Hidden gems',
        'recently_popular'     => 'Recently popular',
        'fresh_arrivals'       => 'Fresh arrivals',
    ];

    /**
     * How many of a user's library records the engine loads as the
     * "never recommend my own books" exclusion set. Bounded: a huge
     * library still yields a bounded IN-list for the filter.
     */
    private const LIBRARY_EXCLUSION_LIMIT = 200;

    /**
     * The section catalogue, for the views and the tests.
     *
     * @return array<string, string>
     */
    public function librarySections(): array
    {
        return self::LIBRARY_SECTIONS;
    }

    /**
     * One library-derived recommendation shelf of one user.
     *
     * Input:  a user id, the section key (see librarySections()) and
     *         the shelf size
     * Output: a RecommendationResult whose items each carry a score
     *         (0-100), an explainable reason and a confidence label
     *
     * Sections:
     *
     *     because_library      all six weighted library factors
     *     because_you_read     reading history (finished books)
     *     similar_favourites   favourite categories + authors
     *     continue_exploring   want-to-read similarity
     *     discover_new_authors books matching your categories but by
     *                          authors you never kept
     *     hidden_gems          high rated, few reviews
     *     recently_popular     the trending shelf (community)
     *     fresh_arrivals       the newest additions (community)
     *
     * The personal sections follow the hybrid pipeline: bounded
     * candidate pool (one query), batch category/author links (no
     * N+1), weighted scoring from RecommendationConfig, exclusion of
     * the user's own library + wishlist books, sorting by score, and
     * a per-user per-section cache. Every shelf is logged to
     * recommendation_logs (signal = section key).
     *
     * A guest (no real user id) only ever receives the community
     * shelves; a user without a library gets the popularity fallback
     * pool (a cold-start shelf instead of an empty one).
     *
     * @throws RecommendationException When the section key is unknown
     */
    public function libraryRecommendations(int $userId, string $section = 'because_library', ?int $limit = null, array $ownLibrary = []): RecommendationResult
    {
        $limit = $limit ?? RecommendationConfig::sectionLimit('dashboard');

        if (!isset(self::LIBRARY_SECTIONS[$section])) {
            throw RecommendationException::unknownLibrarySection($section);
        }

        $label = self::LIBRARY_SECTIONS[$section];

        // Community shelves run for anyone.
        if ($section === 'recently_popular') {
            $result = $this->getTrendingBooks($limit);
            $result = RecommendationResult::fromBooks($section, $label, $result->items, 'The books gaining the most review and wishlist momentum in the last 30 days.');
            $this->logShelf($userId, $result->items, $section);

            return $result;
        }

        if ($section === 'fresh_arrivals') {
            $result = $this->getRecentlyAddedBooks($limit);
            $result = RecommendationResult::fromBooks($section, $label, $result->items, 'The newest books of the catalogue.');
            $this->logShelf($userId, $result->items, $section);

            return $result;
        }

        if ($userId < 1) {
            return RecommendationResult::placeholder($section, $label, 'Sign in and add books to your library to personalise this shelf.');
        }

        $cached = $this->cacheReadSection($userId, $section);

        if ($cached !== null) {
            $result = RecommendationResult::fromBooks(
                $section,
                $label,
                $this->limitRecommendations($cached['items'], $limit),
                (string) ($cached['note'] ?? ''),
            );

            return $result;
        }

        $items = $this->scoreLibraryShelf($userId, $section);

        // A caller that already fetched the user's library ids (one
        // library page composes five shelves) hands them in here so
        // this shelf does not re-read the table.
        $exclude = [
            ...($ownLibrary !== [] ? $ownLibrary : $this->repository->libraryBookIds($userId, self::LIBRARY_EXCLUSION_LIMIT)),
            ...$this->repository->wishlistBookIds($userId),
        ];

        $items = $this->filterRecommendations($items, $exclude);
        $items = $this->sortRecommendations($items);
        $items = $this->limitRecommendations($items, $limit);

        $result = RecommendationResult::fromBooks($section, $label, $items, $this->libraryNote($section));

        $this->cacheWriteSection($userId, $section, $this->storeResult($result));
        $this->logShelf($userId, $result->items, $section);

        return $result;
    }

    /**
     * The book-detail recommendation sections of one book.
     *
     * Input:  the anchor book id, the optional logged-in user id and
     *         the shelf size
     * Output: keyed sections, each an array of item arrays (with
     *         score + reason):
     *
     *     readers_also_enjoyed  "people who saved this also liked"
     *     same_author           more books by the anchor's authors
     *     same_category         books in the anchor's categories
     *     similar_rating        books with a close average rating
     *     similar_popularity    books with a close review count
     *     recommended_for_you   the user's personal shelf (logged-in
     *                           users only)
     *
     * Every section excludes the anchor book and deduplicates; the
     * community sections score on the shared 0-100 scale
     * (RecommendationScoring::collaborativeScore / ratingQuality).
     * The served items are logged to recommendation_logs with their
     * section key as the signal.
     *
     * @throws RecommendationException When the anchor book is missing
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function bookRecommendations(int $bookId, ?int $userId = null, ?int $limit = null): array
    {
        $limit  = $limit ?? RecommendationConfig::sectionLimit('book');
        $anchor = $this->repository->anchorBook($bookId);

        if ($anchor === null) {
            throw RecommendationException::bookNotFound($bookId);
        }

        $sections = [];

        // No book may appear twice across the page's sections: each
        // section is deduplicated against everything already served.
        // The personal shelf is served FIRST so it always keeps its
        // books - the most specific, personalized section must never
        // be emptied by the generic community shelves below it.
        $seen = [];
        $serve = function (array $rows) use (&$seen): array {
            $clean = [];

            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);

                if ($id < 1 || isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                $clean[]   = $row;
            }

            return $clean;
        };

        // Recommended for you: the personal shelf, minus the anchor.
        $sections['recommended_for_you'] = $serve($userId !== null && $userId > 0
            ? $this->filterRecommendations($this->getPersonalizedRecommendations($userId, $limit)->items, [$bookId])
            : []);

        // Readers also enjoyed: the collaborative shelf.
        $rows = $this->repository->coSavedBooks($bookId, $limit * 3);
        $sections['readers_also_enjoyed'] = $serve($this->limitRecommendations(
            $this->filterRecommendations($this->decorateCommunityItems($rows, 'Readers who saved this book also enjoyed it.'), [$bookId]),
            $limit,
        ));

        // Same author: one batched read across every author of the
        // anchor (a multi-author book used to run one query per
        // author) - the service dedupes and limits after.
        $authorIds = array_map(
            fn (array $author): int => (int) $author['id'],
            $this->repository->authorsForBook($bookId),
        );

        $rows = $this->repository->booksInAuthors($authorIds, $limit * 3, $bookId);
        $sections['same_author'] = $serve($this->limitRecommendations(
            $this->filterRecommendations($this->decorateCommunityItems($rows, 'By the same author as this book.'), [$bookId]),
            $limit,
        ));

        // Same category: one multi-category read.
        $categoryIds = array_map(
            fn (array $row): int => (int) $row['id'],
            $this->repository->categoriesForBook($bookId),
        );

        $rows = $this->repository->booksInCategories($categoryIds, $limit * 3, $bookId);
        $sections['same_category'] = $serve($this->limitRecommendations(
            $this->filterRecommendations($this->decorateCommunityItems($rows, 'Shares a category with this book.'), [$bookId]),
            $limit,
        ));

        // Similar by rating / popularity: the config-driven bands.
        $similarity = RecommendationConfig::similarity();

        $rows = $this->repository->booksSimilarByRating($anchor['average_rating'], $similarity['rating_band'], $limit * 3);
        $sections['similar_rating'] = $serve($this->limitRecommendations(
            $this->filterRecommendations($this->decorateCommunityItems($rows, 'Readers rate this book similarly.'), [$bookId]),
            $limit,
        ));

        $rows = $this->repository->booksSimilarByPopularity($anchor['ratings_count'], $similarity['popularity_factor'], $limit * 3);
        $sections['similar_popularity'] = $serve($this->limitRecommendations(
            $this->filterRecommendations($this->decorateCommunityItems($rows, 'A similar community favourite.'), [$bookId]),
            $limit,
        ));

        // The personal shelf was served first (the top dedupe
        // priority on the page) - the community sections above.
        $this->logSections($userId, $sections);

        return $sections;
    }

    /**
     * The library-page recommendation sections of one user.
     *
     * Input:  a user id and the shelf size
     * Output: keyed sections, each an array of item arrays:
     *
     *     because_in_library     the weighted library shelf
     *     people_also_saved      collaborative: what library
     *                            neighbours saved
     *     favourite_category     books in the user's top category
     *     favourite_author       books by the user's top author
     *     recently_discovered    what the community saved lately
     *
     * A user without a library gets empty sections (the library page
     * shows its empty states instead of fabricated shelves). Every
     * served item is logged with its section key.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function libraryPageRecommendations(int $userId, ?int $limit = null): array
    {
        $limit = $limit ?? RecommendationConfig::sectionLimit('library');

        // A user without a library gets honest empty sections: the
        // page renders its empty states instead of fabricated shelves
        // (libraryRecommendations() alone cold-starts with the
        // popularity fallback, which nothing on this page would want
        // for a library that does not exist).
        //
        // The exclusion set doubles as the "has a library" probe and
        // is then loaded ONCE for the whole page ("never recommend my
        // own books"): every shelf filters through the same ids, so
        // one library page costs one library read instead of one per
        // section.
        $ownLibrary = $this->repository->libraryBookIds($userId, self::LIBRARY_EXCLUSION_LIMIT);

        if ($ownLibrary === []) {
            return [
                'because_in_library'  => [],
                'people_also_saved'   => [],
                'favourite_category'  => [],
                'favourite_author'    => [],
                'recently_discovered' => [],
            ];
        }

        $sections = [];

        // Because this is in your library (logged by
        // libraryRecommendations() itself - not re-logged here).
        $sections['because_in_library'] = $this->libraryRecommendations($userId, 'because_library', $limit, $ownLibrary)->items;

        // People who saved this also liked: one collaborative query
        // over the user's whole library.
        $rows = $this->repository->coSavedForLibrary($userId, $limit * 3);
        $sections['people_also_saved'] = $this->excludeOwnLibrary($userId, $this->limitRecommendations(
            $this->decorateCommunityItems($rows, 'People who saved books from your library also liked this.'),
            $limit,
        ), $ownLibrary);

        // Favourite category / author shelves: the user's top
        // category and author (by books kept), minus their library.
        $topCategories = $this->repository->topLibraryCategories($userId, 1);

        if ($topCategories !== []) {
            $name = (string) $topCategories[0]['name'];
            $rows = $this->repository->booksInCategories([(int) $topCategories[0]['id']], $limit * 3);
            $sections['favourite_category'] = $this->excludeOwnLibrary($userId, $this->limitRecommendations(
                $this->decorateCommunityItems($rows, "Because you keep {$name} in your library."),
                $limit,
            ), $ownLibrary);
        } else {
            $sections['favourite_category'] = [];
        }

        $topAuthors = $this->repository->topLibraryAuthors($userId, 1);

        if ($topAuthors !== []) {
            $name = (string) $topAuthors[0]['name'];
            $rows = $this->repository->booksInAuthors([(int) $topAuthors[0]['id']], $limit * 3);
            $sections['favourite_author'] = $this->excludeOwnLibrary($userId, $this->limitRecommendations(
                $this->decorateCommunityItems($rows, "Because you keep books by {$name} in your library."),
                $limit,
            ), $ownLibrary);
        } else {
            $sections['favourite_author'] = [];
        }

        // Recently discovered: books the community saved inside the
        // discovery window.
        $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - RecommendationConfig::similarity()['discovery_window_days'] * 86400);
        $rows   = $this->repository->recentlyDiscoveredBooks($limit * 3, $cutoff);
        $sections['recently_discovered'] = $this->excludeOwnLibrary($userId, $this->limitRecommendations(
            $this->decorateCommunityItems($rows, 'Recently discovered by other readers.'),
            $limit,
        ), $ownLibrary);

        $this->logSections($userId, $sections, ['because_in_library']);

        return $sections;
    }

    /**
     * The profile-page recommendation insights of one user.
     *
     * Input:  a user id
     * Output:
     *
     *     categories    the user's top library categories (id, name,
     *                   kept) - the reading preferences
     *     authors       the user's top library authors (id, name,
     *                   kept)
     *     accuracy      recommended (logged recommendations inside
     *                   the window), acted (how many the user acted on
     *                   - saved / rated / reviewed - AT OR AFTER the
     *                   recommendation was served, the strict
     *                   attribution rule), percent (0-100 or null when
     *                   nothing was recommended yet)
     *     influencing   the favourite + finished books that shaped
     *                   the shelves (title, cover, categories)
     *     logs          the recent logged recommendations (bounded)
     *
     * Everything is a read over the library and recommendation_logs -
     * the page never writes through here.
     *
     * @return array<string, mixed>
     */
    public function profileRecommendationInsights(int $userId): array
    {
        $limit = RecommendationConfig::sectionLimit('profile');

        $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - RecommendationConfig::accuracyWindowDays() * 86400);
        $logs   = $this->repository->recommendationLogs($userId, $cutoff, $limit * 2);

        $recommended = count($logs);
        $acted       = 0;

        foreach ($logs as $row) {
            if ((int) ($row['in_library'] ?? 0) === 1
                || (int) ($row['rated'] ?? 0) === 1
                || (int) ($row['saved'] ?? 0) === 1
            ) {
                $acted++;
            }
        }

        return [
            'categories'  => $this->repository->topLibraryCategories($userId, $limit),
            'authors'     => $this->repository->topLibraryAuthors($userId, $limit),
            'accuracy'    => [
                'recommended' => $recommended,
                'acted'       => $acted,
                'percent'     => $recommended > 0 ? (int) round($acted / $recommended * 100) : null,
            ],
            'influencing' => $this->repository->libraryProfileBooks($userId, $limit),
            'logs'        => array_slice($logs, 0, $limit),
        ];
    }

    /**
     * Append the serve-log of one shelf to recommendation_logs.
     *
     * Input:  a user id, the served items and the signal (section)
     *         key that produced them
     * Output: nothing
     *
     * Business responsibility: the audit trail behind the profile's
     * Recommendation Accuracy figure. A guest is never logged; the
     * per-user retention (config) is enforced after every write. A
     * failing log write (e.g. a database without the 0019 migration)
     * degrades to a warning - the shelf itself is never lost.
     *
     * @param array<int, array<string, mixed>> $items
     */
    /**
     * Whether the user's personalized shelf is currently served from
     * the cache - i.e. a call to getPersonalizedRecommendations()
     * would NOT recompute it.
     *
     * Callers that log the shelf as "served" ask this BEFORE their
     * read: on a cache hit nothing is logged (the rows were already
     * logged when they were first generated), so the recommendation
     * audit trail records every GENERATION once and repeated page
     * renders never inflate it (the dashboard's
     * 'dashboard_recommended' signal uses this gate).
     */
    public function personalizedShelfIsCached(int $userId): bool
    {
        return $userId > 0 && $this->cacheRead($userId) !== null;
    }

    /**
     * Append one recommendation log entry per served book.
     *
     * @param array<int, array<string, mixed>> $items The served items
     * @param string                           $signal The section key
     */
    public function logRecommendations(int $userId, array $items, string $signal): void
    {
        if ($userId < 1 || $items === []) {
            return;
        }

        $entries = [];

        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? $item['book_id'] ?? 0);

            if ($id < 1) {
                continue;
            }

            $entries[] = [
                'book_id' => $id,
                'reason'  => (string) ($item['reason'] ?? ''),
                'score'   => (float) ($item['score'] ?? 0),
                'signal'  => $signal,
            ];
        }

        try {
            $this->repository->logRecommendations($userId, $entries);
            $this->repository->pruneRecommendationLogs($userId, RecommendationConfig::logRetention());
        } catch (\Throwable $exception) {
            $this->logger?->warning('Recommendation log write skipped.', [
                'error' => $exception->getMessage(),
                'user_id' => $userId,
            ]);
        }
    }

    /**
     * Log one shelf under its section key.
     *
     * @param array<int, array<string, mixed>> $items
     */
    private function logShelf(int $userId, array $items, string $signal): void
    {
        $this->logRecommendations($userId, $items, $signal);
    }

    /**
     * Log every non-empty section of a keyed sections payload under
     * its own signal key.
     *
     * A guest (null user id) has no audit trail and is a quiet no-op -
     * bookRecommendations() runs for anonymous visitors too.
     *
     * @param array<string, array<int, array<string, mixed>>> $sections
     * @param array<int, string>                              $skip Signals never logged (already logged elsewhere)
     */
    private function logSections(?int $userId, array $sections, array $skip = []): void
    {
        if ($userId === null) {
            return;
        }

        foreach ($sections as $signal => $items) {
            if (in_array($signal, $skip, true) || $items === []) {
                continue;
            }

            $this->logRecommendations($userId, $items, $signal);
        }
    }

    /**
     * The weighted library shelf of one user, before exclusion and
     * sorting.
     *
     * Input:  a user id and the section key
     * Output: scored item arrays (score 0-100, reason, confidence,
     *         matched) for every candidate that scored above zero
     *
     * Pipeline: one bounded candidate query per section
     * (hybridCandidates reuses the library-derived category sets),
     * batch category/author links (two IN queries - no N+1), the
     * weighted libraryScore from RecommendationConfig, the section's
     * own explainable reason and confidence.
     *
     * @return array<int, array<string, mixed>>
     */
    private function scoreLibraryShelf(int $userId, string $section): array
    {
        $signals = $this->librarySignalIds($userId);

        $favouriteCategoryIds = $this->categoryIdsOf($signals['favourite']);
        $finishedCategoryIds  = $this->categoryIdsOf($signals['finished']);
        $wantToReadCategoryIds = $this->categoryIdsOf($signals['want_to_read']);
        $favouriteAuthorIds   = $this->authorIdsOf($signals['favourite']);

        $fallbackIds = array_map(
            fn (array $row): int => (int) $row['id'],
            $this->repository->popularBooks((int) config('recommendations.candidates.popularity_fallback', 10)),
        );

        $poolLimit = (int) config('recommendations.candidates.pool_limit', 50);

        // Section -> candidate pool + which factors are active.
        switch ($section) {
            case 'because_library':
                $pool = $this->repository->hybridCandidates(
                    $favouriteCategoryIds,
                    $favouriteAuthorIds,
                    $wantToReadCategoryIds,
                    $finishedCategoryIds,
                    [],
                    $fallbackIds,
                    $poolLimit,
                );
                $factorSets = [
                    'favourite_category' => $favouriteCategoryIds,
                    'favourite_author'   => $favouriteAuthorIds,
                    'reading_history'    => $finishedCategoryIds,
                    'want_to_read'       => $wantToReadCategoryIds,
                ];
                break;

            case 'because_you_read':
                $pool = $this->repository->hybridCandidates([], [], [], $finishedCategoryIds, [], $fallbackIds, $poolLimit);
                $factorSets = ['reading_history' => $finishedCategoryIds];
                break;

            case 'similar_favourites':
                $pool = $this->repository->hybridCandidates(
                    $favouriteCategoryIds,
                    $favouriteAuthorIds,
                    [],
                    [],
                    [],
                    $fallbackIds,
                    $poolLimit,
                );
                $factorSets = [
                    'favourite_category' => $favouriteCategoryIds,
                    'favourite_author'   => $favouriteAuthorIds,
                ];
                break;

            case 'continue_exploring':
                $pool = $this->repository->hybridCandidates([], [], $wantToReadCategoryIds, [], [], $fallbackIds, $poolLimit);
                $factorSets = ['want_to_read' => $wantToReadCategoryIds];
                break;

            case 'discover_new_authors':
                $discoverCategories = array_values(array_unique([...$favouriteCategoryIds, ...$finishedCategoryIds]));
                $pool = $this->repository->hybridCandidates($discoverCategories, [], [], [], [], $fallbackIds, $poolLimit);
                $factorSets = ['favourite_category' => $discoverCategories];
                break;

            case 'hidden_gems':
                $gems = RecommendationConfig::hiddenGems();
                $pool = $this->repository->hiddenGemBooks($gems['min_rating'], $gems['max_reviews'], $poolLimit);
                $factorSets = [];
                break;

            default:
                $pool = [];
                $factorSets = [];
        }

        $candidateIds = array_map(fn (array $row): int => (int) $row['id'], $pool);
        $categoryIds  = $this->idsPerBook($this->repository->categoriesForBooks($candidateIds));
        $authorIds    = $this->idsPerBook($this->repository->authorsForBooks($candidateIds));

        // Discover-new-authors: the authors the user already keeps.
        $knownAuthorIds = $section === 'discover_new_authors'
            ? $this->distinctIds($this->repository->authorsForBooks($signals['all']))
            : [];

        $categoryNames = $this->repository->categoryNames(array_values(array_unique(array_merge([], ...array_values($factorSets)))));
        $authorNames   = $this->repository->authorNames(array_values(array_unique($this->authorIdsOf($signals['favourite']))));

        $items = [];

        foreach ($pool as $row) {
            $id = (int) $row['id'];

            // Hidden gems have no personal factor - the section IS
            // the filter; the score reflects community quality.
            $signalsForBook = [];

            foreach ($factorSets as $factor => $ids) {
                $base = str_ends_with($factor, 'author')
                    ? ($authorIds[$id] ?? [])
                    : ($categoryIds[$id] ?? []);

                $signalsForBook[$factor] = count(array_intersect($base, $ids));
            }

            // Discover-new-authors: only books by authors the user
            // never kept survive the candidate filter.
            if ($section === 'discover_new_authors' && array_intersect($authorIds[$id] ?? [], $knownAuthorIds) !== []) {
                continue;
            }

            $signalsForBook['rating']     = $this->reviewScoreSignal($row);
            $signalsForBook['popularity'] = (float) ($row['popularity_score'] ?? 0);

            $score = RecommendationScoring::libraryScore($signalsForBook);

            if ($score <= 0) {
                continue;
            }

            $matched = [];

            foreach (['favourite_category', 'favourite_author', 'reading_history', 'want_to_read'] as $factor) {
                if ((int) ($signalsForBook[$factor] ?? 0) > 0) {
                    $matched[] = $factor;
                }
            }

            if ((float) ($signalsForBook['rating'] ?? 0) > 0) {
                $matched[] = 'rating';
            }

            $items[] = [
                ...$row,
                'score'      => round($score, 1),
                'reason'     => $this->libraryReason($section, $matched, $signalsForBook, $factorSets, $categoryIds[$id] ?? [], $authorIds[$id] ?? [], $categoryNames, $authorNames),
                'confidence' => $this->libraryConfidence($score, $matched),
                'matched'    => $matched,
            ];
        }

        return $items;
    }

    /**
     * The explainable reason of one library-shelf item.
     *
     * Input:  the section, the matched factors, the raw signals, the
     *         factor sets, the book's category/author ids and the
     *         name maps
     * Output: a short human-readable explanation ("Recommended
     *         because you like Self Help.", "Similar to books you
     *         finished.")
     *
     * Business responsibility: the EXPLAINABLE part of Phase 8.5 -
     * the reason names the actual category/author that fired (from
     * the batch name maps, never a per-book query) and stays honest
     * for every section.
     *
     * @param array<int, string>              $matched
     * @param array<string, int|float>        $signals
     * @param array<string, array<int, int>>  $factorSets
     * @param array<int, int>                 $bookCategoryIds
     * @param array<int, int>                 $bookAuthorIds
     * @param array<int, string>              $categoryNames
     * @param array<int, string>              $authorNames
     */
    private function libraryReason(
        string $section,
        array $matched,
        array $signals,
        array $factorSets,
        array $bookCategoryIds,
        array $bookAuthorIds,
        array $categoryNames,
        array $authorNames,
    ): string {
        $parts = [];

        if (in_array('favourite_category', $matched, true)) {
            $shared = array_values(array_intersect($bookCategoryIds, $factorSets['favourite_category'] ?? []));
            $name   = isset($shared[0]) ? (string) ($categoryNames[(int) $shared[0]] ?? '') : '';

            $parts[] = $name !== ''
                ? "Recommended because you like {$name} books."
                : 'Recommended because you like books in a category you read.';
        }

        if (in_array('favourite_author', $matched, true)) {
            $shared = array_values(array_intersect($bookAuthorIds, $factorSets['favourite_author'] ?? []));
            $name   = isset($shared[0]) ? (string) ($authorNames[(int) $shared[0]] ?? '') : '';

            $parts[] = $name !== ''
                ? "Because you favourite books by {$name}."
                : 'Because you favourite this author.';
        }

        if (in_array('reading_history', $matched, true)) {
            $parts[] = 'Similar to books you finished.';
        }

        if (in_array('want_to_read', $matched, true)) {
            $parts[] = 'Similar to books on your want-to-read shelf.';
        }

        if (in_array('rating', $matched, true)) {
            $parts[] = 'Highly rated by the community.';
        }

        if ($parts !== []) {
            return implode(' ', array_slice($parts, 0, 2));
        }

        return $section === 'hidden_gems'
            ? 'A hidden gem - highly rated with few reviews.'
            : 'A community favourite - a starting point for your library.';
    }

    /**
     * The confidence label of a library-shelf item.
     *
     * Input:  the score and the matched personal factors
     * Output: 'high' | 'medium' | 'low' (thresholds from config)
     *
     * Business logic: mirrors confidenceFor() but counts the LIBRARY
     * factor keys - a book earns 'high' only with a strong score AND
     * at least one personal library match, so community-only
     * popularity can never claim high confidence.
     *
     * @param array<int, string> $matched
     */
    private function libraryConfidence(float $score, array $matched): string
    {
        $thresholds = (array) config('recommendations.confidence', ['high' => 60, 'medium' => 30]);
        $personal   = count(array_intersect($matched, ['favourite_category', 'favourite_author', 'reading_history', 'want_to_read']));

        if ($score >= (float) ($thresholds['high'] ?? 60) && $personal >= 1) {
            return 'high';
        }

        if ($score >= (float) ($thresholds['medium'] ?? 30)) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * The library signal ids of one user, grouped by shelf.
     *
     * @return array{favourite: array<int, int>, finished: array<int, int>, want_to_read: array<int, int>, all: array<int, int>}
     */
    private function librarySignalIds(int $userId): array
    {
        $cap = (int) config('recommendations.candidates.signal_book_cap', 20);

        return [
            'favourite'    => $this->repository->favouriteBookIds($userId, $cap),
            'finished'     => $this->repository->finishedBookIds($userId, $cap),
            'want_to_read' => $this->repository->wantToReadBookIds($userId, $cap),
            'all'          => $this->repository->libraryBookIds($userId, $cap * 2),
        ];
    }

    /**
     * The distinct author ids of a set of books (batch loaded once).
     *
     * @param array<int, int> $bookIds
     * @return array<int, int>
     */
    private function authorIdsOf(array $bookIds): array
    {
        return $this->distinctIds($this->repository->authorsForBooks($bookIds));
    }

    /**
     * Turn a community shelf (co-saved / shared / similar rows) into
     * items on the shared 0-100 scale with an explainable reason.
     *
     * Input:  the repository rows and the reason every item shows
     * Output: item arrays (score + reason + confidence + matched)
     *
     * Business responsibility: collaborative shelves score with
     * RecommendationScoring::collaborativeScore (the co-save count
     * normalized), every other community row scores with the rating
     * quality - both documented in the scoring home, never magic
     * numbers inside this service.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function decorateCommunityItems(array $rows, string $reason): array
    {
        $items = [];

        foreach ($rows as $row) {
            $count = (int) ($row['saved_count'] ?? $row['shared_count'] ?? $row['discovery_count'] ?? 0);

            if ($count > 0) {
                $score = RecommendationScoring::collaborativeScore($count);
            } else {
                $score = (int) round(
                    RecommendationScoring::ratingQuality((float) ($row['average_rating'] ?? 0), (int) ($row['ratings_count'] ?? 0)) * 100,
                );
            }

            $items[] = [
                ...$row,
                'score'      => $score,
                'reason'     => $reason,
                'confidence' => 'medium',
                'matched'    => ['collaborative'],
            ];
        }

        return $items;
    }

    /**
     * Drop every item whose book is already in the user's library
     * ("never recommend a book the user already keeps").
     *
     * A caller that already fetched the user's library book ids
     * (e.g. libraryPageRecommendations() composes several shelves
     * per request) passes them in $ownLibrary - otherwise the set
     * is fetched here. Sharing the set keeps one library page at
     * ONE libraryBookIds() query instead of one per shelf.
     *
     * @param array<int, array<string, mixed>> $items
     * @param array<int, int>                  $ownLibrary Pre-fetched
     *                                                    exclusion ids
     * @return array<int, array<string, mixed>>
     */
    private function excludeOwnLibrary(int $userId, array $items, array $ownLibrary = []): array
    {
        $exclude = $ownLibrary !== []
            ? $ownLibrary
            : $this->repository->libraryBookIds($userId, self::LIBRARY_EXCLUSION_LIMIT);

        return $this->filterRecommendations($items, $exclude);
    }

    /**
     * The run note of a library shelf (the formula, with the real
     * configured weights).
     */
    private function libraryNote(string $section): string
    {
        if ($section === 'hidden_gems') {
            $gems = RecommendationConfig::hiddenGems();

            return "Hidden gems: at least {$gems['min_rating']} average rating with at most {$gems['max_reviews']} reviews.";
        }

        $weights = RecommendationScoring::libraryWeights();

        return 'Library personalization: favourite categories x ' . $weights['favourite_category']
            . ' + favourite authors x ' . $weights['favourite_author']
            . ' + reading history x ' . $weights['reading_history']
            . ' + want-to-read similarity x ' . $weights['want_to_read']
            . ' + review score x ' . $weights['rating']
            . ' + popularity x ' . $weights['popularity']
            . ' (of 100) - personalised from your library, favourites, reading history and want-to-read shelf.';
    }

    /**
     * Read the cached library-section shelf of one user, or null on
     * a miss (the Phase 8.5 sibling of cacheRead()).
     *
     * @return array<string, mixed>|null
     */
    private function cacheReadSection(int $userId, string $section): ?array
    {
        try {
            return $this->cache?->getSection($userId, $section);
        } catch (\Throwable $exception) {
            $this->cacheWarning('read-section', $exception);

            return null;
        }
    }

    /**
     * Store the library-section shelf of one user, best-effort (the
     * Phase 8.5 sibling of cacheWrite()).
     */
    private function cacheWriteSection(int $userId, string $section, array $payload): void
    {
        try {
            $this->cache?->putSection($userId, $section, $payload);
        } catch (\Throwable $exception) {
            $this->cacheWarning('write-section', $exception);
        }
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
