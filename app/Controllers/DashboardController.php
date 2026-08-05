<?php

declare(strict_types=1);

namespace BookSphere\App\Controllers;

use BookSphere\App\Core\Controller;
use BookSphere\App\Core\Request;
use BookSphere\App\Services\LibraryService;
use BookSphere\App\Services\RecommendationService;
use BookSphere\App\Services\ReviewService;

/**
 * DashboardController
 *
 * Renders the logged-in user's dashboard (the root of the
 * application). Phase 7.3: the Top Rated Books shelf is no longer
 * placeholder data - it is computed by the Reviews module's
 * ReviewService (real aggregation over approved reviews, newest
 * first with rating ties) and only presented here.
 *
 * Phase 7.4: the review sections are real too - the Recent Reviews
 * shelf (latest community reviews), the Highest Rated Reviews shelf
 * and the signed-in user's latest review - all rendered with the
 * existing compact review card design.
 *
 * Phase 7.6: the dashboard asks for ONE composed payload
 * (ReviewService::dashboardStatistics()) instead of four separate
 * reads: the Top Rated shelf, the Recently Reviewed shelf, the
 * Community Favourite Books (most reviewed), the recent highest
 * rated community reviews, the user's latest review and the user's
 * highest rated book.
 *
 * Phase 8.2: the dashboard gains the personal library's "Continue
 * Reading" shelf - the books the user is currently reading, newest
 * activity first (LibraryService::currentlyReading() through the
 * SAME shared instance the library module uses, so the shelf can
 * never disagree with the /library page). The controller stays thin:
 * it only asks the service and hands the rows to the view.
 *
 * The route is protected by AuthMiddleware, so the greeting can
 * safely read the logged-in user from the session.
 */
final class DashboardController extends Controller
{
    public function __construct(
        private readonly ?ReviewService $reviews = null,
        private readonly ?LibraryService $library = null,
        // Phase 8.5: the SHARED RecommendationService - the dashboard's
        // "Recommended for you", "Because you read" and "Trending"
        // shelves are real engine output now, replacing the last
        // placeholder cards.
        private readonly ?RecommendationService $recommendations = null,
    ) {}

    public function index(Request $request, array $params = []): void
    {
        $userId = auth()?->id();

        // Phase 7.6: one composed payload per user - the shelves, the
        // community lists and the personal cards all come from the
        // Reviews module in a single call.
        $stats = $userId !== null && $this->reviews !== null
            ? $this->reviews->dashboardStatistics((int) $userId)
            : [];

        // Phase 8.2: the "Continue Reading" shelf - the user's
        // currently-reading books sorted by last updated (the
        // repository's natural order), or an empty shelf when the
        // library module is not wired (tests / standalone controller).
        $continueReading = $userId !== null && $this->library !== null
            ? $this->library->currentlyReading((int) $userId)
            : [];

        // Phase 8.4: the personal library surfaces of the dashboard -
        // the recently-added shelf, the favourites shelf, the real
        // Library Overview numbers and the Smart Collections quick
        // access - all read through the SAME shared LibraryService.
        $recentlyAdded   = $userId !== null && $this->library !== null
            ? $this->library->recentlyAdded((int) $userId, 4)
            : [];
        $favouriteBooks  = $userId !== null && $this->library !== null
            ? $this->library->favoriteBooks((int) $userId, 4)
            : [];
        $libraryCounts   = $userId !== null && $this->library !== null
            ? $this->library->statusCounts((int) $userId)
            : [];
        $collections     = $userId !== null && $this->library !== null
            ? $this->library->collectionStatistics((int) $userId)
            : [];

        // Phase 8.5: the real recommendation shelves - the personal
        // hybrid shelf ("Recommended for you"), the library-derived
        // "Because you read" shelf and the community trending shelf.
        // Each degrades to an empty shelf when the engine is not
        // wired (tests / standalone controller).
        //
        // The personalized shelf is logged as 'dashboard_recommended'
        // ONLY when it was freshly GENERATED (a cache hit skips the
        // log - the rows were already recorded at generation time).
        // 'because_you_read' is logged by libraryRecommendations()
        // itself on generation, so it is not re-logged here - repeated
        // dashboard renders must never inflate recommendation_logs
        // (the profile's Recommendation Accuracy reads one log row per
        // real generation).
        $recommendedWasCached = $userId !== null && $this->recommendations !== null
            ? $this->recommendations->personalizedShelfIsCached((int) $userId)
            : true;
        $recommendedForYou = $userId !== null && $this->recommendations !== null
            ? $this->recommendations->getPersonalizedRecommendations((int) $userId, 5)->items
            : [];
        $becauseYouRead = $userId !== null && $this->recommendations !== null
            ? $this->recommendations->libraryRecommendations((int) $userId, 'because_you_read', 5)->items
            : [];
        $trendingBooks = $userId !== null && $this->recommendations !== null
            ? $this->recommendations->getTrendingBooks(5)->items
            : [];

        if ($userId !== null && $this->recommendations !== null && !$recommendedWasCached) {
            $this->recommendations->logRecommendations((int) $userId, $recommendedForYou, 'dashboard_recommended');
        }

        $this->view('dashboard.index', [
            'title'                => 'Dashboard',
            'active'               => 'dashboard',
            'topRated'             => $stats['topRated'] ?? [],
            'latestReviews'        => $stats['recentlyReviewed'] ?? [],
            'communityFavourites'  => $stats['communityFavourites'] ?? [],
            'highestRatedReviews'  => $stats['recentCommunityReviews'] ?? [],
            'myLatestReview'       => $stats['myLatestReview'] ?? null,
            'myHighestRatedBook'   => $stats['myHighestRatedBook'] ?? null,
            'continueReading'      => $continueReading,
            'recentlyAdded'        => $recentlyAdded,
            'favouriteBooks'       => $favouriteBooks,
            'libraryCounts'        => $libraryCounts,
            'collections'          => $collections,
            'statusLabels'         => LibraryService::STATUSES,
            'recommendedForYou'    => $recommendedForYou,
            'becauseYouRead'       => $becauseYouRead,
            'trendingBooks'        => $trendingBooks,
        ]);
    }
}
