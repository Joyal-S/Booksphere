<?php

declare(strict_types=1);

namespace BookSphere\App\DTO;

/**
 * BookAnalytics
 *
 * The structured payload of the BOOK ANALYTICS module (Phase 12.2):
 * every number the catalogue-analytics page renders, computed by
 * BookAnalyticsService and consumed by the view - a controller never
 * touches a statistic directly, and the view never computes one.
 *
 * Immutable by construction (final readonly class) - exactly the
 * Phase 12.1 contract: the payload is built once per request and
 * `toArray()` is the one and only view contract (and the seam a
 * future cache would persist).
 *
 * Zeroes are always EXPLAINED by the surrounding shape:
 *
 *     - $empty = true        -> the catalogue holds NO visible
 *                               books; the page shows the guidance
 *                               empty state instead of a wall of zeros
 *     - $overview['averageRating'] = null (not 0) when no approved
 *                               review exists yet - the view shows an
 *                               en dash, never a fabricated 0/5
 *     - $rankings lists are empty when no book qualifies (e.g. the
 *                               highest-rated list needs at least
 *                               config('book_analytics.ratings.minimum_count')
 *                               approved reviews); the view renders
 *                               the "waiting for data" copy, not a
 *                               fake ranking
 */
final readonly class BookAnalytics
{
    /**
     * @param array{books: int, reviews: int, averageRating: float|null,
     *               distribution: array<int, int>, with_covers: int,
     *               without_covers: int, with_year: int,
     *               with_publisher: int, with_pages: int, imported: int} $overview
     * @param array<string, int>  $shelves
     * @param array<string, array<int, array<string, mixed>>>     $rankings
     *                                                             highest rated,
     *                                                             most reviewed /
     *                                                             wishlisted / read /
     *                                                             engaged, popular,
     *                                                             trending
     * @param array<string, array<string, mixed>>                 $metadata
     * @param array{recent: array<string, int>, window: array<int,
     *               array{key: string, label: string, reviews: int,
     *               finishes: int}>, older: array{reviews: int,
     *               finishes: int}, windowDays: int}            $activity
     */
    public function __construct(
        public readonly bool $empty,
        public readonly array $overview,
        public readonly array $shelves,
        public readonly array $rankings,
        public readonly array $metadata,
        public readonly array $activity,
        public readonly string $generatedAt,
    ) {}

    /**
     * The view contract: one flat array the template reads. Keeping
     * this the ONLY way the payload reaches the view means a later
     * cache layer can persist exactly this shape.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'empty'       => $this->empty,
            'overview'    => $this->overview,
            'shelves'     => $this->shelves,
            'rankings'    => $this->rankings,
            'metadata'    => $this->metadata,
            'activity'    => $this->activity,
            'generatedAt' => $this->generatedAt,
        ];
    }
}