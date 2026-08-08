<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\Repositories\RecommendationRepository;

/**
 * AdminAnalyticsService
 *
 * The coordination layer of the ADMIN ANALYTICS DASHBOARD
 * (Phase 12.4). The dashboard composes the three analytics modules
 * that already exist - it computes NOTHING itself:
 *
 *     - 12.1 (User Analytics): the community rating picture already
 *       lives in ReviewService::adminAnalytics(), which the
 *       AdminController has carried since Phase 7.3 - this service
 *       does not touch it.
 *     - 12.2 (Book Analytics): BookAnalyticsService::build() - the
 *       catalogue overview, shelves, rankings and metadata DTO, one
 *       request away.
 *     - 12.3 (Recommendation Analytics): the recommendation_logs
 *       aggregates (RecommendationRepository::logTotals() and
 *       friends) plus the engine health picture of
 *       RecommendationMetrics::summary().
 *
 * The view contract is one array, exactly like every other analytics
 * surface: the service owns the composition, the repositories own
 * the SQL, the controller stays a thin render call and the template
 * never computes a statistic.
 */
final class AdminAnalyticsService
{
    /** How many surfaces / books the dashboard's small lists show. */
    private const SIGNAL_LIMIT = 8;
    private const BOOK_LIMIT   = 5;

    public function __construct(
        private readonly BookAnalyticsService $books,
        private readonly RecommendationRepository $recommendations,
        private readonly RecommendationMetrics $metrics,
    ) {}

    /**
     * Build the complete dashboard payload.
     *
     * Output (the one array the view reads):
     *
     *     'books'          -> the full 12.2 payload
     *                         (BookAnalytics::toArray())
     *     'recommendation' -> the 12.3 activity block: the log
     *                         totals, the per-surface breakdown, the
     *                         most-recommended books and the slept
     *                         books
     *     'engine'         -> the 12.3 health block
     *                         (RecommendationMetrics::summary())
     *     'generatedAt'    -> when the dashboard was built (UTC)
     *
     * The Phase 12.5 report re-uses the SAME assembly, optionally
     * scoped: when $since is an ISO date, the log totals and the
     * per-surface counts are recomputed with the identical groupings
     * but a single generated_at >= $since filter (logTotalsSince /
     * logCountsBySignalSince) - the "all time" dashboard numbers and
     * the ranged ones can therefore never diverge in logic. The
     * top/slept lists stay all-time on purpose: they are qualified by
     * "repeatedly suggested", a rule that ranges have no meaning for.
     *
     * @param  string|null $since inclusive ISO date, or null for all-time
     * @return array<string, mixed>
     */
    public function dashboard(?string $since = null): array
    {
        $logs = $since === null
            ? $this->recommendations->logTotals()
            : $this->recommendations->logTotalsSince($since);

        return [
            'books'          => $this->books->build()->toArray(),
            'recommendation' => [
                'totals' => $logs,
                'signals' => $since === null
                    ? $this->recommendations->logCountsBySignal(self::SIGNAL_LIMIT)
                    : $this->recommendations->logCountsBySignalSince($since, self::SIGNAL_LIMIT),
                'top'    => $this->recommendations->topRecommendedBooks(self::BOOK_LIMIT),
                'slept'  => $this->recommendations->sleptBooks(self::BOOK_LIMIT),
            ],
            'engine'       => $this->metrics->summary(),
            'generatedAt'  => gmdate('c'),
        ];
    }

    /**
     * The raw recommendation_logs of a report range, newest first -
     * the CSV export source of the admin report. The controller only
     * streams the rows; the range and the column mapping stay here.
     *
     * @param  string $since inclusive ISO date lower bound
     * @param  string $until inclusive ISO date upper bound
     * @param  int    $limit hard cap for the stream
     * @return array<int, array{user: string, title: string, signal: string, score: float|null, reason: string, generated_at: string}>
     */
    public function recommendationLogsForRange(string $since, string $until, int $limit = 5000): array
    {
        return $this->recommendations->logsForRange($since, $until, $limit);
    }
}