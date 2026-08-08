<?php

declare(strict_types=1);

namespace BookSphere\App\DTO;

/**
 * UserAnalytics
 *
 * The structured payload of the USER ANALYTICS module (Phase 12.1):
 * every number the /analytics page renders, computed by
 * UserAnalyticsService and consumed by the view - a controller never
 * touches a statistic directly, and the view never computes one.
 *
 * Immutable by construction (final readonly class): a payload can
 * only be built once, so a partial or mutated snapshot can never
 * leak between requests. `toArray()` is the view contract and the
 * serialization seam a Phase 13 cache would persist - the class
 * carries the exact shape a cached blob would restore, which is why
 * the service may one day hydrate it from cache without the
 * repository.
 *
 * Zeroes are always EXPLAINED by the surrounding shape:
 *
 *     - $empty = true        -> the user has no shelves AND no
 *                               reviews; the page shows the guidance
 *                               empty state instead of bare zeros
 *     - $summary counts hold subtitles (e.g. "shelved so far") on
 *       the view, so a new-but-partial user never reads a bare 0
 *       without context
 *     - $reviews['average'] = null   (not 0) when the user has no
 *                               approved review yet - the view shows
 *                               an en dash, never a fabricated 0/5
 */
final readonly class UserAnalytics
{
    /**
     * @param array<string, int|float|null> $summary       shelved, reading,
     *                                                      wishlist, completed,
     *                                                      completionRate,
     *                                                      activeDays, reviews,
     *                                                      averageRating|null
     * @param array<string, int>            $shelf         status => count for the
     *                                                      five canonical shelves
     * @param array{unique: int, rows: array<int, array{name: string, books: int, percent: float}>} $genres
     * @param array{unique: int, rows: array<int, array{name: string, books: int, percent: float}>} $authors
     * @param array{total: int, average: float|null, favourite: int|null,
     *               distribution: array<int, int>}      $reviews
     * @param array{months: array<int, array{key: string, label: string, completed: int, rated: int}>,
     *               older: array{completed: int, rated: int},
     *               recent: array<int, array{type: string, label: string, book_title: string, at: string}>} $activity
     */
    public function __construct(
        public readonly bool $empty,
        public readonly array $summary,
        public readonly array $shelf,
        public readonly array $genres,
        public readonly array $authors,
        public readonly array $reviews,
        public readonly array $activity,
        public readonly string $generatedAt,
    ) {}

    /**
     * The view contract: one flat array the template reads. Keeping
     * this method the ONLY way the payload reaches the view means a
     * later cache layer can persist exactly this shape.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'empty'       => $this->empty,
            'summary'     => $this->summary,
            'shelf'       => $this->shelf,
            'genres'      => $this->genres,
            'authors'     => $this->authors,
            'reviews'     => $this->reviews,
            'activity'    => $this->activity,
            'generatedAt' => $this->generatedAt,
        ];
    }
}