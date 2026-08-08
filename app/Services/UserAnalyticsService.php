<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\DTO\UserAnalytics;
use BookSphere\App\Repositories\UserAnalyticsRepository;

/**
 * UserAnalyticsService
 *
 * The business logic of the USER ANALYTICS module (Phase 12.1).
 * Everything the statistics page needs is composed here - the
 * controller stays thin (it reads auth()->id() and renders the
 * view), the repository stays a pure SQL layer, and every
 * calculation, rounding decision and label lives in this one class.
 *
 * Facts the service guarantees (Task 4-9):
 *
 *     - Every metric is derived from REAL rows in user_library /
 *       reviews (Task 1 audit). Nothing is ever guessed: if the
 *       user has no row for a metric, the metric is a contextual
 *       zero or null - never a fabricated number.
 *     - "Finished" is the library's own lifecycle status; "wishlist"
 *       is the want_to_read shelf (the modern wishlist); "reading"
 *       is currently_reading. The five CHECK-constrained statuses
 *       are the ONLY shelves that exist.
 *     - Review metrics count APPROVED reviews only - the exact
 *       house rule of the profile page (ReviewRepository
 *       ::userRatingStats), so no page can ever disagree with
 *       another on "how many reviews did I write".
 *     - Genre/author percentages are membership shares (see
 *       UserAnalyticsRepository) - multi-genre and co-authored
 *       books count once per label, explicitly documented there.
 *
 * Caching seam (Task 15): build() is the single entry point, its
 * result is a serializable DTO (toArray()), and every read it makes
 * is scoped to one user id. A Phase 13 cache therefore needs just a
 * wrapper around build() keyed by user id + a stamped freshness
 * check - no repository or service change. The module does NOT
 * cache yet.
 */
final class UserAnalyticsService
{
    private const STATUS_FINISHED          = 'finished';
    private const STATUS_CURRENTLY_READING = 'currently_reading';
    private const STATUS_WANT_TO_READ      = 'want_to_read';
    private const STATUS_ON_HOLD           = 'on_hold';
    private const STATUS_DROPPED           = 'dropped';

    public function __construct(
        private readonly UserAnalyticsRepository $repository,
        private readonly array $config,
    ) {}

    /**
     * Build the complete analytics payload of one user.
     *
     * @param int $userId The authenticated session user id - never a
     *                    request parameter (the controller owns that
     *                    rule; the service refuses nothing further
     *                    because the id is already the session's).
     */
    public function build(int $userId): UserAnalytics
    {
        $counts   = $this->repository->shelfCounts($userId);
        $shelf    = $this->completeShelf($counts);
        $shelved  = array_sum($shelf);
        $finished = $shelf[self::STATUS_FINISHED];

        $reviews = $this->repository->reviewTotals($userId);
        $rating  = $reviews['total'] > 0 ? $reviews['average'] : null;

        $genresLimit  = (int) ($this->config['limits']['genres'] ?? 5);
        $authorsLimit = (int) ($this->config['limits']['authors'] ?? 5);

        $genres = $this->topList(
            $this->repository->topGenres($userId, $genresLimit),
            $this->repository->genreMemberships($userId),
        );
        $authors = $this->topList(
            $this->repository->topAuthors($userId, $authorsLimit),
            $this->repository->authorMemberships($userId),
        );

        $distribution = $this->repository->ratingDistribution($userId);
        $months       = (int) ($this->config['activity']['months'] ?? 12);
        $recent       = (int) ($this->config['activity']['recent'] ?? 10);

        $completedMap = $this->repository->monthlyCompletions($userId);
        $ratedMap     = $this->repository->monthlyReviews($userId);

        $empty = $shelved === 0 && $reviews['total'] === 0;

        return new UserAnalytics(
            empty: $empty,
            summary: [
                'shelved'        => $shelved,
                'reading'        => $shelf[self::STATUS_CURRENTLY_READING],
                'wishlist'       => $shelf[self::STATUS_WANT_TO_READ],
                'completed'      => $finished,
                'completionRate' => $shelved > 0 ? round($finished / $shelved * 100, 1) : 0.0,
                'activeDays'     => $this->repository->activeDays($userId),
                'reviews'        => $reviews['total'],
                'averageRating'  => $rating === null ? null : round($rating, 1),
            ],
            shelf: $shelf,
            genres: [
                'unique' => $this->repository->uniqueReadGenres($userId),
                'rows'   => $genres,
            ],
            authors: [
                'unique' => $this->repository->uniqueReadAuthors($userId),
                'rows'   => $authors,
            ],
            reviews: [
                'total'        => $reviews['total'],
                'average'      => $rating === null ? null : round($rating, 1),
                'favourite'    => $this->favouriteRating($distribution),
                'distribution' => $this->completeDistribution($distribution),
            ],
            activity: [
                'months' => $this->monthWindow($months, $completedMap, $ratedMap),
                'older'  => [
                    'completed' => $this->olderCount($completedMap, $months),
                    'rated'     => $this->olderCount($ratedMap, $months),
                ],
                'recent' => $this->decorateEvents(
                    $this->repository->recentEvents($userId, $recent),
                ),
            ],
            generatedAt: gmdate('c'),
        );
    }

    /**
     * Fold the repository's sparse status map into the five canonical
     * shelves with explicit zeroes - the view always sees every
     * shelf, so a missing row can never masquerade as a missing
     * feature.
     *
     * @param array<string, int> $counts
     *
     * @return array<string, int>
     */
    private function completeShelf(array $counts): array
    {
        $shelf = [];

        foreach ($this->repository->shelfStatuses() as $status) {
            $shelf[$status] = $counts[$status] ?? 0;
        }

        return $shelf;
    }

    /**
     * Attach the membership share to each top list row (rounded to
     * one decimal, 0 when the user has no finished books). The
     * denominator comes from the repository's membership query - the
     * percentage is never computed from the truncated top list.
     *
     * @param array<int, array{name: string, books: int}> $rows
     *
     * @return array<int, array{name: string, books: int, percent: float}>
     */
    private function topList(array $rows, int $memberships): array
    {
        return array_map(
            static fn (array $row): array => $row + [
                'percent' => $memberships > 0
                    ? round($row['books'] / $memberships * 100, 1)
                    : 0.0,
            ],
            $rows,
        );
    }

    /**
     * The rating the user hands out most often; ties resolve to the
     * higher rating so the answer is always deterministic. Null when
     * the user never rated.
     *
     * @param array<int, int> $distribution
     */
    private function favouriteRating(array $distribution): ?int
    {
        $favourite = null;
        $best      = 0;

        foreach ($distribution as $rating => $count) {
            if ($count >= $best && $count > 0) {
                $favourite = $rating;
                $best      = $count;
            }
        }

        return $favourite;
    }

    /**
     * Complete the rating histogram with every bucket 1..5 present -
     * again, an absent rating means zero reviews of that star count,
     * and the view must see it as 0, not as a missing bar.
     *
     * @param array<int, int> $distribution
     *
     * @return array<int, int> rating (1..5) -> count
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
     * The trailing month window: the LAST $months calendar months,
     * newest first, each carrying the real completion and rating
     * counts of that month (zeros where the user was inactive - a
     * window row without data is a REAL zero, never an invention of
     * an activity the user never did). Months outside the window are
     * collapsed into the 'older' counts by the caller.
     *
     * @param array<string, int> $completedMap
     * @param array<string, int> $ratedMap
     *
     * @return array<int, array{key: string, label: string, completed: int, rated: int}>
     */
    private function monthWindow(int $months, array $completedMap, array $ratedMap): array
    {
        $window = [];
        $count  = max(1, min($months, 60));

        for ($i = $count - 1; $i >= 0; $i--) {
            $key   = gmdate('Y-m', strtotime("-{$i} months"));
            $label = gmdate('M y', strtotime("-{$i} months"));

            $window[] = [
                'key'       => $key,
                'label'     => $label,
                'completed' => $completedMap[$key] ?? 0,
                'rated'     => $ratedMap[$key] ?? 0,
            ];
        }

        return $window;
    }

    /**
     * How many of the user's all-time events fall OUTSIDE the trailing
     * window (still real data - the view notes it, it never drops
     * history silently).
     *
     * @param array<string, int> $map
     */
    private function olderCount(array $map, int $months): int
    {
        $windowStart = gmdate('Y-m', strtotime('-' . max(1, $months) . ' months'));
        $older       = 0;

        foreach ($map as $key => $count) {
            if ($key < $windowStart) {
                $older += $count;
            }
        }

        return $older;
    }

    /**
     * Decorate the raw event rows into what the view renders: a
     * friendly action label per event type, the ISO timestamp intact
     * (escaped by the view) plus a human label for display. The
     * event types are the four the repository emits; anything else
     * is impossible (the repository is the only writer of the query).
     *
     * @param array<int, array{type: string, book_title: string, at: string}> $events
     *
     * @return array<int, array{type: string, label: string, book_title: string, at: string}>
     */
    private function decorateEvents(array $events): array
    {
        $labels = [
            'finished' => 'Finished reading',
            'started'  => 'Started reading',
            'rated'    => 'Rated',
            'shelved'  => 'Added to wishlist',
        ];

        return array_map(
            static fn (array $event): array => $event + [
                'label' => $labels[$event['type']] ?? 'Activity',
            ],
            $events,
        );
    }
}