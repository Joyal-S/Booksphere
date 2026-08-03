<?php

declare(strict_types=1);

namespace BookSphere\App\Presenters;

use BookSphere\App\DTO\PersonalizationProfile;
use BookSphere\App\DTO\RecommendationResult;
use BookSphere\App\Exceptions\RecommendationException;
use BookSphere\App\Models\Category;
use BookSphere\App\Repositories\BookRepository;
use BookSphere\App\Repositories\RecommendationRepository;
use BookSphere\App\Services\RecommendationService;

/**
 * RecommendationDashboardPresenter (Phase 6.4)
 *
 * The VIEW-MODEL of the recommendation dashboard. A presenter is the
 * presentation half of the MVC triangle: it collects the data the
 * dashboard sections need from the EXISTING engine and shapes it for
 * the templates - it never decides what is recommended and never
 * re-implements an algorithm.
 *
 * Every section composes only existing engine entry points:
 *
 *     1. Hero           - greeting + quality score derived from the
 *                         personalized shelf (getPersonalizedRecommendations)
 *     2. Recommended    - the personalized shelf itself (the engine's
 *                         Phase 6.3 hybrid result, unchanged)
 *     3. Because Liked  - anchors = the user's highly rated books
 *                         (profile), similar picks = getMoreLikeThis()
 *     4. Because Follow - the profile's favourite authors, their books
 *                         via getBooksByAuthor(), newest first
 *     5. Trending near  - getTrendingBooks(), narrowed to the user's
 *                         favourite categories (interest filter only,
 *                         the scoring is the engine's)
 *     6. Recently added - getRecentlyAddedBooks(), interest-matched
 *                         first, honest newest fallback afterwards
 *     7. New genres     - categories that co-occur on the user's own
 *                         recommended shelf but are not favourites yet
 *                         (an Apriori-lite, "similar readers" signal)
 *     8. Insights       - the profile + the shelf, summarized as stats
 *
 * The presenter owns no SQL (the repositories own that), no scoring
 * (the engine owns that) and no markup (the partials own that). It
 * is the single place that decides WHICH section gets WHAT data, so
 * the view stays readable and the engine stays untouched.
 *
 * Cold start: a user without signals still gets the engine's
 * popularity fallback shelf; sections that would be empty are left
 * out of the payload (the view renders exactly what is present), and
 * the hero flags the empty profile so the page can invite the user
 * to browse.
 *
 * Reusability: injected with the same service/repository instances
 * the routes already wire, so it is trivially testable and can be
 * reused by any future page (e.g. a wishlist dashboard).
 */
final class RecommendationDashboardPresenter
{
    /** Section 2: the personalized shelf size. */
    public const SHELF_SIZE = 8;

    /** Section 3: similar books per "because you liked" anchor. */
    public const LIKE_SIZE = 4;

    /** Section 3: how many anchor books are shown. */
    public const MAX_ANCHORS = 3;

    /** Section 4: how many favourite authors are shown. */
    public const FOLLOW_AUTHORS = 3;

    /** Section 4: newest books kept per followed author. */
    public const FOLLOW_BOOKS_PER_AUTHOR = 2;

    /** Section 5: books on the trending-near-interests shelf. */
    public const TRENDING_SIZE = 4;

    /** Section 6: books on the recently-added shelf. */
    public const RECENT_SIZE = 4;

    /** Section 7: how many new genres are suggested. */
    public const GENRE_SUGGESTIONS = 4;

    /** The rating that makes a book an explicit "you liked" anchor. */
    public const LIKED_MIN_RATING = 4;

    public function __construct(
        private readonly RecommendationService $service,
        private readonly RecommendationRepository $repository,
        private readonly BookRepository $books,
        private readonly Category $categories,
    ) {}

    /**
     * Compose the full dashboard payload.
     *
     * Input:  the already-computed personalized shelf (optional; when
     *         omitted, the shelf is fetched through the engine - the
     *         per-user cache makes the second call cheap)
     * Output: the view-model array every dashboard partial consumes
     *
     * @return array<string, mixed>
     */
    public function compose(?RecommendationResult $personal = null): array
    {
        $userId    = auth()?->id() ?? 0;
        $personal ??= $this->service->getPersonalizedRecommendations($userId, self::SHELF_SIZE);
        $profile   = $userId > 0 ? $this->service->profileFor($userId) : null;

        // Books the user already owns the signal for are never shown
        // again on any shelf (wishlist saves and recent views), so a
        // recommendation always feels new.
        $excluded = $this->excludedIds($userId);

        // Phase 6.5: the sections are deduplicated AGAINST EACH OTHER
        // (not only inside themselves) - a book the engine already
        // placed on the main shelf must never reappear on "Because
        // you liked" or "Trending near your interests". The first
        // section wins; the others simply skip the id.
        $sections = $this->dedupeSections([
            'recommended' => $this->recommended($personal),
            'becauseLiked'=> $this->becauseLiked($profile, $excluded),
            'follow'      => $this->follow($profile, $excluded),
            'trending'    => $this->trendingNearInterests($profile, $excluded),
            'recent'      => $this->recentlyAdded($profile, $excluded),
            'genres'      => $this->exploreGenres($profile, $personal),
        ]);

        $insights = $this->insights($profile, $personal, $sections);

        return [
            'userId'      => $userId,
            'hasSignals'  => $profile !== null
                && ($profile->favouriteCategories !== [] || $profile->favouriteAuthors !== []
                    || $profile->wishlistBookIds !== [] || $profile->highlyRatedBookIds !== []
                    || $profile->reviewedBookIds !== [] || $profile->recentlyViewedBookIds !== []),
            'quality'     => $this->quality($personal),
            'updatedAgo'  => $this->ago($personal->generatedAt),
            'wishlistIds' => array_map('intval', $this->repository->wishlistBookIds($userId)),
            ...$sections,
            'insights'    => $insights,
        ];
    }

    // -----------------------------------------------------------------
    // Section 1: hero quality score
    // -----------------------------------------------------------------

    /**
     * The hero's match accuracy: the average hybrid score of the
     * personalized shelf, rounded to a whole percent. The label is
     * the confidence TONE the hero ring renders as (high/medium/low);
     * the aria-label in the hero builds the human sentence from the
     * score and this tone.
     *
     * @return array{score: int, label: string, generatedAt: string}
     */
    private function quality(RecommendationResult $personal): array
    {
        $scores = array_map('floatval', array_column($personal->items, 'score'));
        $score  = $scores === [] ? 0 : (int) round(array_sum($scores) / count($scores));

        return [
            'score'       => $score,
            'label'       => $score >= 80 ? 'high' : ($score >= 60 ? 'medium' : 'low'),
            'generatedAt' => $this->formatTimestamp($personal->generatedAt),
        ];
    }

    // -----------------------------------------------------------------
    // Section 2: the personalized shelf (unchanged engine output)
    // -----------------------------------------------------------------

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int, note: string, generatedAt: string, updatedAgo: string}
     */
    private function recommended(RecommendationResult $personal): array
    {
        return [
            'items'       => $personal->items,
            'total'       => $personal->total,
            'note'        => $personal->note,
            'generatedAt' => $this->formatTimestamp($personal->generatedAt),
            'updatedAgo'  => $this->ago($personal->generatedAt),
        ];
    }

    // -----------------------------------------------------------------
    // Section 3: because you liked...
    // -----------------------------------------------------------------

    /**
     * Grouped recommendations anchored on books the user LIKED.
     *
     * The strongest "liked" signal is a rating of 4+ stars; when the
     * user has none yet, the wishlist stands in ("saved for later").
     * Each anchor yields the engine's "more like this" shelf, the
     * anchor itself excluded, wishlist/views excluded, capped at
     * LIKE_SIZE books per anchor and MAX_ANCHORS anchors.
     *
     * @return array<int, array{anchor: array<string, mixed>, items: array<int, array<string, mixed>>}>
     */
    private function becauseLiked(?PersonalizationProfile $profile, array $excluded): array
    {
        if ($profile === null) {
            return [];
        }

        $anchorIds = array_values($profile->highlyRatedBookIds);

        if ($anchorIds === []) {
            $anchorIds = array_slice($profile->wishlistBookIds, 0, self::MAX_ANCHORS);
        }

        $blocks = [];

        foreach (array_slice(array_unique($anchorIds), 0, self::MAX_ANCHORS) as $anchorId) {
            $anchor = $this->books->findById((int) $anchorId);

            if ($anchor === null) {
                continue;
            }

            $anchor['authors_list'] = implode(', ', array_map(
                fn (array $author): string => (string) $author['name'],
                $this->repository->authorsForBook((int) $anchorId),
            ));

            try {
                $similar = $this->service->getMoreLikeThis((int) $anchorId, self::LIKE_SIZE + self::LIKE_SIZE);
            } catch (RecommendationException) {
                continue; // the anchor has no authors: nothing to group
            }

            $items = array_slice($this->stripExcluded($similar->items, $excluded), 0, self::LIKE_SIZE);

            if ($items === []) {
                continue;
            }

            $blocks[] = ['anchor' => $anchor, 'items' => $items];
        }

        return $blocks;
    }

    // -----------------------------------------------------------------
    // Section 4: because you follow
    // -----------------------------------------------------------------

    /**
     * The newest releases of the authors the user follows.
     *
     * For each favourite author the engine's by-author shelf is read
     * and re-ordered newest-first in the presenter (freshness is a
     * presentation choice; the engine keeps its own ordering). Every
     * pick carries the brief's exact reason: "New release from an
     * author you follow." - the engine's generic "By the same
     * author" line stays available as a secondary explanation.
     *
     * @return array<int, array<string, mixed>>
     */
    private function follow(?PersonalizationProfile $profile, array $excluded): array
    {
        if ($profile === null || $profile->favouriteAuthors === []) {
            return [];
        }

        $shelf = [];

        foreach (array_slice($profile->favouriteAuthors, 0, self::FOLLOW_AUTHORS, true) as $authorId => $meta) {
            try {
                $result = $this->service->getBooksByAuthor((int) $authorId, self::FOLLOW_BOOKS_PER_AUTHOR + 2);
            } catch (RecommendationException) {
                continue;
            }

            $items = $this->stripExcluded($result->items, $excluded);

            usort($items, static fn (array $a, array $b): int =>
                strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

            foreach (array_slice($items, 0, self::FOLLOW_BOOKS_PER_AUTHOR) as $item) {
                $item['reason'] = 'New release from an author you follow.';
                $item['author'] = (string) ($meta['name'] ?? '');
                $shelf[]        = $item;
            }
        }

        return array_slice($shelf, 0, self::FOLLOW_AUTHORS * self::FOLLOW_BOOKS_PER_AUTHOR);
    }

    // -----------------------------------------------------------------
    // Section 5: trending near your interests
    // -----------------------------------------------------------------

    /**
     * Books gaining momentum INSIDE the user's favourite categories.
     *
     * The engine's trending shelf is global; this section narrows it
     * with a pure interest filter: only books whose category list
     * contains a favourite category survive. The momentum scoring is
     * untouched - this is selection for the page, not re-scoring.
     *
     * @return array<int, array<string, mixed>>
     */
    private function trendingNearInterests(?PersonalizationProfile $profile, array $excluded): array
    {
        $names = $this->categoryNames($profile);

        if ($names === []) {
            return [];
        }

        $shelf = [];

        foreach ($this->stripExcluded($this->service->getTrendingBooks(self::TRENDING_SIZE * 4)->items, $excluded) as $item) {
            $match = $this->matchingCategory($item, $names);

            if ($match === null) {
                continue;
            }

            $item['reason'] = 'Trending in ' . $match . ' - near your interests.';
            $shelf[]        = $item;

            if (count($shelf) >= self::TRENDING_SIZE) {
                break;
            }
        }

        return $shelf;
    }

    // -----------------------------------------------------------------
    // Section 6: recently added
    // -----------------------------------------------------------------

    /**
     * The newest books matching the user's interests.
     *
     * Interest-matched arrivals go first (brief: "newest books
     * matching the user's interests"); when the community is quiet
     * and fewer than RECENT_SIZE match, the shelf tops up with the
     * plain newest arrivals - always honest, never fabricated, and
     * each card explains which of the two it is.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentlyAdded(?PersonalizationProfile $profile, array $excluded): array
    {
        $catalogueNames = array_values($this->categoryNames($profile));
        $authorNames    = array_values(array_map(
            fn (array $author): string => (string) $author['name'],
            $profile?->favouriteAuthors ?? [],
        ));

        $matched = [];
        $others  = [];

        foreach ($this->stripExcluded($this->service->getRecentlyAddedBooks(self::RECENT_SIZE * 4)->items, $excluded) as $item) {
            if ($this->matchesInterests($item, $catalogueNames, $authorNames)) {
                $matched[] = $item;
            } else {
                $others[] = $item;
            }
        }

        $shelf = [];

        foreach (array_slice($matched, 0, self::RECENT_SIZE) as $item) {
            $item['reason'] = 'Newest matching your interests.';
            $shelf[]        = $item;
        }

        foreach (array_slice($others, 0, self::RECENT_SIZE - count($shelf)) as $item) {
            $item['reason'] = 'Recently added to the catalogue.';
            $shelf[]        = $item;
        }

        return $shelf;
    }

    // -----------------------------------------------------------------
    // Section 7: explore new genres
    // -----------------------------------------------------------------

    /**
     * Categories the user rarely reads but may enjoy.
     *
     * "Based on similar users" without a dedicated collaborative
     * table: the categories that co-occur on the books of the user's
     * own recommended shelf (books the engine picked because readers
     * with similar interests enjoyed them) and are NOT yet favourites
     * are the most promising next genres. This is association
     * counting (Apriori-lite) over the engine's own output - no new
     * SQL, no new scoring.
     *
     * @return array<int, array{id: int, name: string, seen: int}>
     */
    private function exploreGenres(?PersonalizationProfile $profile, RecommendationResult $personal): array
    {
        $favouriteIds = $profile?->favouriteCategoryIds() ?? [];

        $nameToId = [];
        $catalogue = [];

        foreach ($this->categories->all() as $category) {
            $nameToId[strtolower((string) $category['name'])] = (int) $category['id'];
            $catalogue[(int) $category['id']] = (string) $category['name'];
        }

        $seen = [];

        foreach ($personal->items as $item) {
            foreach ($this->categoryNamesOf($item) as $name) {
                $id = $nameToId[strtolower($name)] ?? null;

                if ($id === null) {
                    continue;
                }

                $seen[$id] = ($seen[$id] ?? 0) + 1;
            }
        }

        foreach ($favouriteIds as $id) {
            unset($seen[$id]);
        }

        arsort($seen);

        $suggested = [];

        foreach (array_slice(array_keys($seen), 0, self::GENRE_SUGGESTIONS) as $id) {
            $suggested[] = [
                'id'   => (int) $id,
                'name' => $catalogue[(int) $id] ?? 'Category',
                'seen' => $seen[$id],
            ];
        }

        return $suggested;
    }

    // -----------------------------------------------------------------
    // Section 8: recommendation insights
    // -----------------------------------------------------------------

    /**
     * The six insights of the dashboard: favourite category, favourite
     * author, confidence, books analysed, recommendations generated
     * and the last update time. Every value is derived from the
     * profile and the shelf - nothing is invented.
     *
     * @param array<string, mixed> $sections
     * @return array<int, array{icon: string, label: string, value: string, tone: string, trend: string}>
     */
    private function insights(
        ?PersonalizationProfile $profile,
        RecommendationResult $personal,
        array $sections,
    ): array {
        $average = $this->averageScore($personal);
        $confidence = $average >= 60 ? 'High' : ($average >= 30 ? 'Medium' : 'Low');

        $signalCount = $profile === null ? 0 : count(array_unique([
            ...$profile->wishlistBookIds,
            ...$profile->highlyRatedBookIds,
            ...$profile->reviewedBookIds,
            ...$profile->recentlyViewedBookIds,
        ]));

        $generated = $personal->total
            + array_sum(array_map(
                static fn (array $block): int => count($block['items']),
                $sections['becauseLiked'],
            ))
            + count($sections['follow'])
            + count($sections['trending'])
            + count($sections['recent']);

        $favourite = static fn (array $favourites): string => isset($favourites[0]['name'])
            ? (string) $favourites[0]['name']
            : 'Not enough data yet';

        return [
            ['icon' => 'fa-tags',   'label' => 'Favourite Category',     'value' => $favourite($profile?->favouriteCategories ?? []), 'tone' => 'primary', 'trend' => 'Top by weight'],
            ['icon' => 'fa-user-pen', 'label' => 'Favourite Author',     'value' => $favourite($profile?->favouriteAuthors ?? []),     'tone' => 'success', 'trend' => 'Top by weight'],
            ['icon' => 'fa-gauge-high', 'label' => 'Recommendation Confidence', 'value' => $confidence . ' (' . $average . '%)',       'tone' => 'info',    'trend' => 'Average match score'],
            ['icon' => 'fa-magnifying-glass-chart', 'label' => 'Books Analysed', 'value' => (string) $signalCount,                    'tone' => 'warning', 'trend' => 'From your activity'],
            ['icon' => 'fa-wand-magic-sparkles', 'label' => 'Recommendations Generated', 'value' => (string) $generated,               'tone' => 'danger',  'trend' => 'On this page'],
            ['icon' => 'fa-clock', 'label' => 'Last Recommendation Update', 'value' => $this->formatTimestamp($personal->generatedAt), 'tone' => 'primary', 'trend' => 'Cached per user'],
        ];
    }

    // -----------------------------------------------------------------
    // Small, single-responsibility helpers
    // -----------------------------------------------------------------

    /**
     * The ids never recommended again: the user's wishlist saves and
     * the recently viewed books (the engine already excludes them
     * from the personal shelf; this keeps the OTHER sections honest
     * too).
     *
     * @return array<int, int>
     */
    private function excludedIds(int $userId): array
    {
        if ($userId < 1) {
            return [];
        }

        return array_values(array_unique([
            ...$this->repository->wishlistBookIds($userId),
            ...$this->repository->recentlyViewedBookIds($userId, 20),
        ]));
    }

    /**
     * Remove the excluded ids from a shelf.
     *
     * @param array<int, array<string, mixed>> $items
     * @param array<int, int>                  $excluded
     * @return array<int, array<string, mixed>>
     */
    private function stripExcluded(array $items, array $excluded): array
    {
        if ($excluded === []) {
            return $items;
        }

        $blocked = array_fill_keys($excluded, true);

        return array_values(array_filter(
            $items,
            static fn (array $item): bool => !isset($blocked[(int) ($item['id'] ?? 0)]),
        ));
    }

    /**
     * The names of the profile's favourite categories.
     *
     * @return array<int, string>
     */
    private function categoryNames(?PersonalizationProfile $profile): array
    {
        if ($profile === null) {
            return [];
        }

        return array_map(
            static fn (array $category): string => (string) $category['name'],
            $profile->favouriteCategories,
        );
    }

    /**
     * The first favourite category that appears on a book, if any.
     */
    private function matchingCategory(array $item, array $favouriteNames): ?string
    {
        foreach ($favouriteNames as $name) {
            if ($this->bookInCategory($item, $name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Whether a book row belongs to a category, by exact token match
     * on the comma-separated categories_list (a partial fallback
     * catches names like "Science Fiction" on "Science Fiction &
     * Fantasy").
     */
    private function bookInCategory(array $item, string $name): bool
    {
        $tokens = array_map('trim', explode(',', (string) ($item['categories_list'] ?? '')));

        if (in_array($name, $tokens, true)) {
            return true;
        }

        return str_contains((string) ($item['categories_list'] ?? ''), $name);
    }

    /**
     * Whether a book matches the user's interest signals: a favourite
     * category or a favourite author on it.
     *
     * @param array<int, string> $catalogueNames
     * @param array<int, string> $authorNames
     */
    private function matchesInterests(array $item, array $catalogueNames, array $authorNames): bool
    {
        foreach ($catalogueNames as $name) {
            if ($this->bookInCategory($item, $name)) {
                return true;
            }
        }

        foreach ($authorNames as $name) {
            if (str_contains((string) ($item['authors_list'] ?? ''), $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The category names on one book row.
     *
     * @return array<int, string>
     */
    private function categoryNamesOf(array $item): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string) ($item['categories_list'] ?? '')),
        )));
    }

    /**
     * The average hybrid score of a shelf, rounded to a whole percent.
     */
    private function averageScore(RecommendationResult $result): int
    {
        $scores = array_map('floatval', array_column($result->items, 'score'));

        return $scores === [] ? 0 : (int) round(array_sum($scores) / count($scores));
    }

    /**
     * Format an engine timestamp (UTC ISO-8601) for display in the
     * user's local timezone.
     */
    private function formatTimestamp(string $iso): string
    {
        $date = new \DateTimeImmutable($iso);

        return $date->format('j M Y, g:i A');
    }

    // -----------------------------------------------------------------
    // Phase 6.5: freshness and cross-section deduplication
    // -----------------------------------------------------------------

    /**
     * The freshness phrase of the dashboard ("Updated X minutes ago").
     *
     * Input:  the engine timestamp (UTC ISO-8601) of the personalized
     *         shelf
     * Output: a short human phrase: "Updated just now" under one
     *         minute, then minutes, then hours, then days; a shelf
     *         older than a week falls back to the full formatted
     *         timestamp
     *
     * Business responsibility: the freshness requirement of the
     * Phase 6.5 brief - the user must be able to tell whether the
     * page holds this minute's signals or a cached snapshot. The
     * hero and the section headers render this phrase beside the
     * exact timestamp. A corrupt timestamp degrades to "just now"
     * (the shelf itself is still served).
     */
    private function ago(string $iso): string
    {
        try {
            $seconds = max(0, time() - (new \DateTimeImmutable($iso))->getTimestamp());
        } catch (\Exception) {
            $seconds = 0;
        }

        $minutes = (int) floor($seconds / 60);
        $hours   = (int) floor($minutes / 60);
        $days    = (int) floor($hours / 24);

        return match (true) {
            $minutes < 1            => 'Updated just now',
            $minutes < 60           => 'Updated ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' ago',
            $hours < 24             => 'Updated ' . $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago',
            $days < 7               => 'Updated ' . $days . ' day' . ($days === 1 ? '' : 's') . ' ago',
            default                 => 'Updated ' . $this->formatTimestamp($iso),
        };
    }

    /**
     * Remove books that already appeared in an earlier section.
     *
     * Input:  the composed sections (recommended, becauseLiked,
     *         follow, trending, recent, genres)
     * Output: the same sections, with every id that appeared in an
     *         EARLIER section removed from the later ones; an empty
     *         "because you liked" block is dropped entirely
     *
     * Business responsibility: the engine deduplicates INSIDE each
     * shelf; this pass deduplicates ACROSS the dashboard sections.
     * The main shelf always wins (it is the user's most relevant
     * picks); the exploratory sections fill with fresh books. The
     * genres section is untouched - it lists categories, not books.
     *
     * @param array<string, mixed> $sections
     * @return array<string, mixed>
     */
    private function dedupeSections(array $sections): array
    {
        $seen = [];

        $sections['recommended']['items'] = $this->withoutSeen($sections['recommended']['items'] ?? [], $seen);
        // The section's total must always agree with its items.
        $sections['recommended']['total'] = count($sections['recommended']['items']);

        $blocks = [];

        foreach ($sections['becauseLiked'] as $block) {
            $items = $this->withoutSeen($block['items'], $seen);

            if ($items !== []) {
                $blocks[] = ['anchor' => $block['anchor'], 'items' => $items];
            }
        }

        $sections['becauseLiked'] = $blocks;
        $sections['follow']       = $this->withoutSeen($sections['follow'], $seen);
        $sections['trending']     = $this->withoutSeen($sections['trending'], $seen);
        $sections['recent']       = $this->withoutSeen($sections['recent'], $seen);

        return $sections;
    }

    /**
     * Keep only the books whose id has not been seen yet, marking
     * each survivor as seen.
     *
     * @param array<int, array<string, mixed>> $items
     * @param array<int, true>                 $seen The id -> true map, mutated
     * @return array<int, array<string, mixed>>
     */
    private function withoutSeen(array $items, array &$seen): array
    {
        $kept = [];

        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);

            if ($id < 1 || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $kept[]    = $item;
        }

        return $kept;
    }
}
