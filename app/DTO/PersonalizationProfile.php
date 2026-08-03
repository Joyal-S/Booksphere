<?php

declare(strict_types=1);

namespace BookSphere\App\DTO;

/**
 * PersonalizationProfile
 *
 * The per-user picture the hybrid engine derives its scores from
 * (Phase 6.3). One immutable snapshot per user, built by
 * RecommendationService::buildProfile() from the user's wishlist,
 * ratings, reviews and recent views - and cached so the expensive
 * queries run at most once per cache lifetime.
 *
 * What it holds:
 *     - favouriteCategories / favouriteAuthors -> the user's top
 *       categories and authors, weighted from the three signal
 *       sources (wishlist saves weigh most, then high ratings, then
 *       reviews). A poorly rated book (<= profile.ignore_rating)
 *       contributes NOTHING - "ignore books rated very poorly".
 *     - wishlistBookIds          -> books already saved: excluded
 *                                   from recommendations, and their
 *                                   categories drive the wishlist
 *                                   similarity factor
 *     - highlyRatedBookIds       -> books rated >= min_favourite_rating:
 *                                   the rating-similarity factor
 *     - reviewedBookIds          -> books with a written review:
 *                                   analysed for favourites
 *     - recentlyViewedBookIds    -> the last viewed books (most
 *                                   recent first), for the "similar
 *                                   to recently viewed" factor
 *
 * Immutability is deliberate (same rule as RecommendationContext):
 * the profile travels from the cache to the scoring pipeline without
 * ever being mutated on the way.
 */
final readonly class PersonalizationProfile
{
    /**
     * @param array<int, array{name: string, weight: int}> $favouriteCategories
     * @param array<int, array{name: string, weight: int}> $favouriteAuthors
     * @param array<int, int> $wishlistBookIds
     * @param array<int, int> $highlyRatedBookIds
     * @param array<int, int> $reviewedBookIds
     * @param array<int, int> $recentlyViewedBookIds
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $favouriteCategories,
        public readonly array $favouriteAuthors,
        public readonly array $wishlistBookIds,
        public readonly array $highlyRatedBookIds,
        public readonly array $reviewedBookIds,
        public readonly array $recentlyViewedBookIds,
        public readonly string $builtAt,
    ) {}

    /**
     * The ids of the favourite categories (the keys of the array).
     *
     * @return array<int, int>
     */
    public function favouriteCategoryIds(): array
    {
        return array_keys($this->favouriteCategories);
    }

    /**
     * The ids of the favourite authors (the keys of the array).
     *
     * @return array<int, int>
     */
    public function favouriteAuthorIds(): array
    {
        return array_keys($this->favouriteAuthors);
    }
}
