<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\Repositories\RecommendationRepository;

/**
 * RecommendationMetrics (Phase 6.5)
 *
 * The read-only health picture of the recommendation engine, for the
 * administrator-only monitoring page (/admin/recommendations).
 *
 * Why it exists:
 *     The engine produces no dashboard of its own - a developer or
 *     viva examiner wants to SEE that the algorithms, the cache and
 *     the signal data are healthy. This service composes the four
 *     read-only blocks of that picture:
 *
 *         1. cache   - files, bytes, stale entries, cached users and
 *                      writability (PersonalizationCache::stats())
 *         2. config  - the active tuning values of the engine, read
 *                      straight from config/recommendations.php
 *                      (so the page always shows what the engine is
 *                      really running with)
 *         3. data    - signal totals and the top categories/authors
 *                      by community activity (repository aggregates)
 *         4. scores  - the average popularity and trending scores of
 *                      the active catalogue, raw AND normalized to
 *                      the shared 0-100 scale (RecommendationScoring)
 *
 * What it deliberately does NOT do:
 *     - no SQL (the repository owns every query; this service only
 *       composes method calls and config reads)
 *     - no writes (the flush button is a separate action that calls
 *       PersonalizationCache::flush() through the same cache object)
 *     - no authorization (the route carries AdminMiddleware; this
 *       service trusts its caller)
 *
 * The page is a monitoring snapshot: every value is derived from the
 * live database and the live cache directory at call time.
 */
final class RecommendationMetrics
{
    /** How many top categories / authors the page shows. */
    private const TOP_N = 5;

    /** How many catalogue rows the score averages sample. */
    private const SAMPLE_SIZE = 500;

    public function __construct(
        private readonly RecommendationRepository $repository,
        private readonly ?PersonalizationCache $cache = null,
    ) {}

    /**
     * The full metrics payload of the admin page.
     *
     * Input:  nothing
     * Output: one array with the 'cache', 'config', 'data' and
     *         'scores' blocks, plus a 'generatedAt' timestamp
     *
     * Business responsibility: the single entry point the controller
     * calls - the view renders exactly this array, so the page and
     * the service can never drift apart.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'cache'       => $this->cacheStats(),
            'config'      => $this->configSnapshot(),
            'data'        => $this->dataStats(),
            'scores'      => $this->scoreStats(),
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Drop every cached shelf (the admin flush tool).
     *
     * Input:  nothing
     * Output: whether the cache was disabled (true = nothing to do)
     *
     * Business responsibility: the write half of the monitoring
     * page. Flushing the per-user shelves forces the next dashboard
     * visits to rebuild from the latest signals - the administrative
     * counterpart of the user-facing "Refresh recommendations"
     * button.
     */
    public function flushCache(): void
    {
        $this->cache?->flush();
    }

    /**
     * The cache block of the payload.
     *
     * @return array<string, mixed>
     */
    private function cacheStats(): array
    {
        return $this->cache?->stats() ?? [
            'enabled'   => false,
            'files'     => 0,
            'bytes'     => 0,
            'stale'     => 0,
            'users'     => [],
            'newest'    => null,
            'oldest'    => null,
            'writable'  => false,
            'directory' => '',
            'ttl'       => 0,
        ];
    }

    /**
     * The config block: every tunable the engine is running with.
     *
     * @return array<string, mixed>
     */
    private function configSnapshot(): array
    {
        return [
            'hybrid_weights' => RecommendationScoring::hybridWeights(),
            'profile'        => (array) config('recommendations.profile', []),
            'candidates'     => (array) config('recommendations.candidates', []),
            'confidence'     => (array) config('recommendations.confidence', []),
            'security'       => (array) config('recommendations.security', []),
        ];
    }

    /**
     * The data block: signal totals and the top signal categories /
     * authors.
     *
     * @return array<string, mixed>
     */
    private function dataStats(): array
    {
        return [
            'totals'       => $this->repository->signalTotals(),
            'topCategories' => $this->repository->topCategories(self::TOP_N),
            'topAuthors'   => $this->repository->topAuthors(self::TOP_N),
        ];
    }

    /**
     * The score block: average raw AND 0-100-normalized popularity
     * and trending scores over the active catalogue.
     *
     * The samples come from the engine's own shelves (popularBooks
     * / trendingBooks ordered by their raw scores); the averages are
     * computed here, and the normalization belongs to
     * RecommendationScoring - the ONE class that owns every score.
     *
     * @return array<string, mixed>
     */
    private function scoreStats(): array
    {
        $popularity = $this->repository->popularBooks(self::SAMPLE_SIZE);
        $trending   = $this->repository->trendingBooks(self::SAMPLE_SIZE);

        $popularityRaw = array_map('floatval', array_column($popularity, 'popularity_score'));
        $trendingRaw   = array_map('floatval', array_column($trending, 'trending_score'));

        $average = static fn (array $values): float => $values === []
            ? 0.0
            : array_sum($values) / count($values);

        $avgPopularity = $average($popularityRaw);
        $avgTrending   = $average($trendingRaw);

        return [
            'popularity' => [
                'raw'     => round($avgPopularity, 2),
                'percent' => RecommendationScoring::popularityPercent($avgPopularity),
            ],
            'trending' => [
                'raw'     => round($avgTrending, 2),
                'percent' => RecommendationScoring::trendingPercent($avgTrending),
            ],
            'sampleSize' => count($popularityRaw),
        ];
    }
}
