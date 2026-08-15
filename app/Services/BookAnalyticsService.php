<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\DTO\BookAnalytics;
use BookSphere\App\Repositories\BookAnalyticsRepository;

/**
 * BookAnalyticsService
 *
 * The business logic of the BOOK ANALYTICS module (Phase 12.2).
 * Everything the catalogue-analytics page needs is composed here -
 * the controller stays thin (it renders the view), the repository
 * stays a pure SQL layer, and every calculation, rounding decision
 * and label lives in this one class.
 *
 * Facts the service guarantees (the "no fiction" contract of the
 * module, mirrored from Phase 12.1):
 *
 *     - EVERY number derives from REAL rows: books (visible only),
 *       reviews (APPROVED only - the house rule), user_library (the
 *       modern shelves, UNIQUE (user_id, book_id) so no count can
 *       ever double a user) and the metadata joins. Nothing is ever
 *       guessed: a metric with no row is a contextual zero or null.
 *     - "Visible book" is ONE rule everywhere in the module: status
 *       'published' AND deleted_at IS NULL - the same scope the
 *       recommendation engine uses, so every surface agrees on the
 *       size of the catalogue.
 *     - The legacy `wishlist` table, book_views, recommendations,
 *       notifications, follows and search history are never sources
 *       (documented in the repository's class docblock).
 *     - The ranking formulas below are DOCUMENTED here and retunable
 *       only through config/book_analytics.php (the weights and the
 *       normalizers); they are never hardcoded in a view.
 *
 * The popularity formula (a score in [0, 1], higher = more popular):
 *
 *     score = ratingWeight * (rating / ratingDivisor)
 *           + reviewWeight * min(reviews / reviewNormalizer, 1)
 *           + interestWeight * min(interests / interestNormalizer, 1)
 *
 * with rating = the book's REAL average (approved reviews only).
 * Every component is first normalized to [0, 1] and then weighted,
 * so a single 5-star book cannot shoot to the top of the catalogue
 * by itself - the interest and review counts hold it back.
 *
 * The trending formula (the same shape, over the trailing window):
 *
 *     score = reviewWeight * min(recentReviews / n, 1)
 *           + interestWeight * min(recentInterests / n, 1)
 *           + readingWeight * min(recentFinishes / n, 1)
 *
 * with the recent counts scoped to the last
 * config('book_analytics.trending.window_days') days.
 *
 * The ranking sort: score DESC, then title (case-insensitive) - the
 * same deterministic tie-break the repository uses everywhere, so a
 * user never sees a list reshuffle between requests.
 */
final class BookAnalyticsService
{
    public function __construct(
        private readonly BookAnalyticsRepository $repository,
        private readonly array $config,
    ) {}

    private ?BookAnalytics $cachedDto = null;

    /**
     * Clear instance cache (for testing/resets).
     */
    public function clearCache(): void
    {
        $this->cachedDto = null;
    }

    /**
     * Build the complete catalogue-analytics payload.
     */
    public function build(): BookAnalytics
    {
        if ($this->cachedDto !== null) {
            return $this->cachedDto;
        }

        $overview   = $this->repository->overview();
        $pages      = (int) ($overview['books'] ?? 0);
        $distribution = $this->completeDistribution($this->repository->ratingDistribution());

        $limits = (array) ($this->config['limits'] ?? []);
        $ratings = (array) ($this->config['ratings'] ?? []);

        $shelves = $this->repository->shelfTotals();

        $highestLimit     = (int) ($limits['highest_rated'] ?? 10);
        $minimumCount     = (int) ($ratings['minimum_count'] ?? 5);
        $reviewsLimit     = (int) ($limits['most_reviewed'] ?? 10);
        $wishlistLimit    = (int) ($limits['most_wishlisted'] ?? 10);
        $readLimit        = (int) ($limits['most_read'] ?? 10);
        $engagedLimit     = (int) ($limits['most_engaged'] ?? 10);
        $popularLimit     = (int) ($limits['popular'] ?? 10);
        $trendingLimit    = (int) ($limits['trending'] ?? 10);
        $genresLimit      = (int) ($limits['genres'] ?? 12);
        $authorsLimit     = (int) ($limits['authors'] ?? 12);
        $publishersLimit  = (int) ($limits['publishers'] ?? 10);
        $languagesLimit   = (int) ($limits['languages'] ?? 10);
        $yearsLimit       = (int) ($limits['years'] ?? 12);

        $metadata = [
            'genres'     => [
                'unique' => $this->repository->genreCount(),
                'size'   => $this->repository->genresByCatalogue($genresLimit),
                'reading' => $this->repository->genresByReading($genresLimit),
            ],
            'authors'    => [
                'unique'  => $this->repository->authorCount(),
                'size'    => $this->repository->authorsByCatalogue($authorsLimit),
                'reading' => $this->repository->authorsRead($authorsLimit),
            ],
            'publishers' => $this->repository->publishers($publishersLimit),
            'languages'  => $this->repository->languages($languagesLimit),
            'years'      => $this->repository->years($yearsLimit),
            'pageRanges' => $this->repository->pageRanges(
                (array) ($this->config['page_ranges'] ?? []),
            ),
        ];

        $activity = $this->activity((int) (($this->config['activity'] ?? [])['months'] ?? 12));

        return $this->cachedDto = new BookAnalytics(
            empty: $pages === 0,
            overview: [
                'books'          => $pages,
                'reviews'        => $this->repository->totalApprovedReviews(),
                'averageRating'  => $this->repository->globalAverageRating(),
                'distribution'   => $distribution,
                'with_covers'    => (int) ($overview['with_covers'] ?? 0),
                'without_covers' => (int) ($overview['without_covers'] ?? 0),
                'with_year'      => (int) ($overview['with_year'] ?? 0),
                'with_publisher' => (int) ($overview['with_publisher'] ?? 0),
                'with_pages'     => (int) ($overview['with_pages'] ?? 0),
                'imported'       => (int) ($overview['imported'] ?? 0),
            ],
            shelves: $shelves,
            rankings: [
                'highestRated'   => $this->repository->highestRated($highestLimit, $minimumCount),
                'mostReviewed'   => $this->repository->mostReviewed($reviewsLimit),
                'mostWishlisted' => $this->repository->mostWishlisted($wishlistLimit),
                'mostRead'       => $this->repository->mostRead($readLimit),
                'mostEngaged'    => $this->repository->mostEngaged($engagedLimit),
                'popular'        => $this->rankPopularity($popularLimit),
                'trending'       => $this->rankTrending($trendingLimit),
            ],
            metadata: $metadata,
            activity: $activity,
            generatedAt: gmdate('c'),
        );
    }

    /**
     * The popularity ranking: score every book with any signal, take
     * the configured top-N, ties broken by title. The formula is the
     * one documented in the class docblock - weights and normalizers
     * come from config and are never local constants here.
     *
     * @return array<int, array{id: int, title: string, cover: string|null, score: float}>
     */
    private function rankPopularity(int $limit): array
    {
        $popularity = (array) ($this->config['popularity'] ?? []);

        $ratingWeight    = (float) ($popularity['rating_weight'] ?? 0.4);
        $reviewWeight    = (float) ($popularity['review_weight'] ?? 0.3);
        $interestWeight  = (float) ($popularity['interest_weight'] ?? 0.3);
        $ratingDivisor   = max(1.0, (float) ($popularity['rating_divisor'] ?? 5.0));
        $reviewNormalizer = max(1, (int) ($popularity['review_normalizer'] ?? 10));
        $interestNormalizer = max(1, (int) ($popularity['interest_normalizer'] ?? 10));

        $rows = $this->repository->popularitySignals();

        return array_slice(
            $this->sortRows($rows, static fn (array $row): float =>
                $ratingWeight * ($row['average'] / $ratingDivisor)
                + $reviewWeight * min((float) $row['reviews'] / $reviewNormalizer, 1.0)
                + $interestWeight * min((float) $row['interests'] / $interestNormalizer, 1.0),
            ),
            0,
            max(0, $limit),
        );
    }

    /**
     * The trending ranking: the same weighting idea over the trailing
     * window (recent approved reviews + recent wishlist adds + recent
     * finishes). The window itself comes from
     * config('book_analytics.trending.window_days').
     *
     * @return array<int, array{id: int, title: string, cover: string|null, score: float}>
     */
    private function rankTrending(int $limit): array
    {
        $trending = (array) ($this->config['trending'] ?? []);

        $windowDays      = max(1, (int) ($trending['window_days'] ?? 30));
        $reviewWeight    = (float) ($trending['review_weight'] ?? 0.4);
        $interestWeight  = (float) ($trending['interest_weight'] ?? 0.3);
        $readingWeight   = (float) ($trending['reading_weight'] ?? 0.3);
        $reviewNormalizer  = max(1, (int) ($trending['review_normalizer'] ?? 5));
        $interestNormalizer = max(1, (int) ($trending['interest_normalizer'] ?? 5));
        $readingNormalizer  = max(1, (int) ($trending['reading_normalizer'] ?? 5));

        $since = gmdate('c', time() - $windowDays * 86400);
        $rows  = $this->repository->trendingSignals($since);

        return array_slice(
            $this->sortRows($rows, static fn (array $row): float =>
                $reviewWeight * min((float) $row['reviews'] / $reviewNormalizer, 1.0)
                + $interestWeight * min((float) $row['interests'] / $interestNormalizer, 1.0)
                + $readingWeight * min((float) $row['finishes'] / $readingNormalizer, 1.0),
            ),
            0,
            max(0, $limit),
        );
    }

    /**
     * Sort the signal rows by score DESC with the deterministic title
     * tie-break, and attach the score to each row. The computation
     * stays in the service (the documented formula); the sort is
     * stable and case-insensitive exactly like the SQL rankings.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param callable(array<string, mixed>): float $score
     *
     * @return array<int, array<string, mixed>>
     */
    private function sortRows(array $rows, callable $score): array
    {
        $scored = [];

        foreach ($rows as $row) {
            // The ranked row carries ONLY the view contract of the
            // formula lists: id, title, cover and the score - the raw
            // signal keys never leak into the payload (a downstream
            // view reading average/count must not misread a score row).
            $scored[] = [
                'id'          => (int) $row['id'],
                'title'       => (string) $row['title'],
                'author_name' => (string) ($row['author_name'] ?? ''),
                'cover'       => $row['cover'],
                'score'       => round($score($row), 4),
            ];
        }

        usort($scored, static function (array $a, array $b): int {
            if ($a['score'] === $b['score']) {
                return strcasecmp((string) $a['title'], (string) $b['title']);
            }

            return $b['score'] <=> $a['score'];
        });

        return $scored;
    }

    /**
     * Complete the rating histogram with every bucket 1..5 present -
     * an absent rating means zero reviews of that star count, and the
     * view must see it as 0, not as a missing bar.
     *
     * @param array<int, int> $distribution rating (1..5) -> count
     *
     * @return array<int, int>
     */
    private function completeDistribution(array $distribution): array
    {
        $completed = [];

        foreach (range(1, 5) as $rating) {
            $completed[$rating] = $distribution[$rating] ?? 0;
        }

        return $completed;
    }

    /**
     * The trailing month activity: the last $months calendar months,
     * newest first, each carrying the catalogue's REAL review and
     * finish counts of that month (zeros where nothing happened - a
     * window row without data is a REAL zero, never an invented
     * activity). The window start crops the monthly maps; everything
     * older is reported by the caller as 'older' counts.
     *
     * @return array{recent: array{reviews: int, interests: int, finishes: int},
     *               window: array<int, array{key: string, label: string, reviews: int, finishes: int}>,
     *               older: array{reviews: int, finishes: int}}
     */
    private function activity(int $months): array
    {
        $reviewsMap = $this->repository->monthlyReviews();
        $finishesMap = $this->repository->monthlyFinished();

        $window = [];
        $count  = max(1, min($months, 60));
        $start  = gmdate('Y-m', strtotime('-' . $count . ' months'));

        for ($i = $count - 1; $i >= 0; $i--) {
            $key   = gmdate('Y-m', strtotime("-{$i} months"));
            $window[] = [
                'key'      => $key,
                'label'    => gmdate('M y', strtotime("-{$i} months")),
                'reviews'  => $reviewsMap[$key] ?? 0,
                'finishes' => $finishesMap[$key] ?? 0,
            ];
        }

        $olderReviews  = $this->countBefore($reviewsMap, $start);
        $olderFinishes = $this->countBefore($finishesMap, $start);

        $trending = (array) ($this->config['trending'] ?? []);
        $windowDays = max(1, (int) ($trending['window_days'] ?? 30));
        $since = gmdate('c', time() - $windowDays * 86400);

        return [
            'windowDays' => $windowDays,
            'recent'     => $this->repository->recentActivity($since),
            'window'     => $window,
            'older'      => [
                'reviews'  => $olderReviews,
                'finishes' => $olderFinishes,
            ],
        ];
    }

    /**
     * How many all-time events fall OUTSIDE the trailing window
     * (still real data - the view notes it, never drops history).
     *
     * @param array<string, int> $map
     */
    private function countBefore(array $map, string $windowStart): int
    {
        $older = 0;

        foreach ($map as $key => $count) {
            if ($key < $windowStart) {
                $older += $count;
            }
        }

        return $older;
    }
}