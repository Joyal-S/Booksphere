<?php

declare(strict_types=1);

namespace BookSphere\App\Controllers;

use BookSphere\App\Core\Controller;
use BookSphere\App\Core\RateLimiter;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\DTO\RecommendationResult;
use BookSphere\App\Exceptions\RecommendationException;
use BookSphere\App\Policies\RecommendationPolicy;
use BookSphere\App\Presenters\RecommendationDashboardPresenter;
use BookSphere\App\Services\RecommendationService;

/**
 * RecommendationController
 *
 * The recommendations module (Phase 6.2: the six algorithms;
 * Phase 6.3: hybrid personalization; Phase 6.4: the dashboard).
 *
 * Every recommendations route renders the same template with the
 * RecommendationResult of the requested strategy:
 *
 *     - index()         -> /recommendations: the PERSONALIZED
 *                          dashboard (Phase 6.4) - the hero, the
 *                          eight sections and the strategy cards.
 *                          The dashboard data is composed by
 *                          RecommendationDashboardPresenter from
 *                          the EXISTING engine; the engine itself
 *                          is untouched.
 *     - refresh()       -> POST /recommendations/refresh: drop the
 *                          user's per-user cache and rebuild the
 *                          dashboard (the "Refresh Recommendations"
 *                          button of the hero)
 *     - toggleWishlist() -> POST /wishlist/toggle: the wishlist
 *                          quick action of the recommendation cards
 *                          (the full wishlist module is a later
 *                          phase; this is the one write the cards
 *                          need)
 *     - popular()       -> /recommendations/popular: PopularBooksStrategy
 *     - topRated()      -> /recommendations/top-rated:
 *                          HighestRatedStrategy (confidence-filtered
 *                          best-averaged shelf)
 *     - trending()      -> /recommendations/trending:
 *                          TrendingBooksStrategy (momentum over 30 days;
 *                          replaces the Phase 6.1 rating stand-in)
 *     - recent()        -> /recommendations/recent: RecentlyAddedStrategy
 *     - category()      -> /recommendations/category/{id}:
 *                          SameCategoryStrategy with an explicit category
 *     - show()          -> /recommendations/book/{id}: SameAuthorStrategy
 *                          ("more like this" for one anchor book)
 *
 * The strategy mapping is a Phase 6.1/6.2 decision, documented on
 * each action. The strategies themselves decide nothing about
 * routing: the service's ROUTES map owns the overview links, this
 * controller owns the route -> strategy wiring.
 *
 * Every action follows the same three steps:
 *
 *     1. authorize()      - the fine authorization gate
 *                           (RecommendationPolicy; the route table
 *                           already applied AuthMiddleware)
 *     2. ask the service   - a dedicated get*() method per shelf;
 *                           ids are validated there (meaningful
 *                           "category not found" / "book not found"
 *                           exceptions instead of silent empty pages)
 *     3. render()          - the page with the result, or a 404
 *                           when the request cannot be satisfied
 */
final class RecommendationController extends Controller
{
    public function __construct(
        private readonly RecommendationService $service,
        private readonly RecommendationPolicy $policy,
        private readonly ?RecommendationDashboardPresenter $dashboard = null,
        private readonly ?RateLimiter $limiter = null,
    ) {}

    /**
     * The overview page: the PERSONALIZED DASHBOARD (Phase 6.4).
     *
     * The view-model is composed by RecommendationDashboardPresenter
     * from the engine's existing entry points - the personalized
     * shelf (Phase 6.3), the profile accessor and the strategy
     * shelves - so the dashboard explains every recommendation
     * without re-implementing a single algorithm.
     */
    public function index(Request $request, array $params = []): void
    {
        $this->authorize();

        $result = $this->service->getPersonalizedRecommendations();

        $this->view(
            'recommendations.index',
            $this->viewData(
                title: 'Recommendations',
                lead: 'Personalized recommendations generated from your reading preferences and activity.',
                result: $result,
            ) + [
                'dashboard' => $this->dashboard?->compose($result),
            ],
        );
    }

    /**
     * Rebuild the personalized dashboard (the hero's refresh button).
     *
     * The engine's per-user cache is dropped explicitly - the next
     * /recommendations request recomputes the shelf from the latest
     * wishlist, rating, review and view signals.
     */
    public function refresh(Request $request, array $params = []): void
    {
        $this->authorize();
        $this->throttle('refresh');

        $userId = auth()?->id();

        if ($userId !== null) {
            $this->service->invalidatePersonalization($userId);
        }

        session()->flash('success', 'Your recommendations were refreshed from your latest activity.');
        Response::redirect('/recommendations');
    }

    /**
     * Toggle one book in the wishlist of the signed-in user.
     *
     * The recommendation cards post here with the book id. With
     * JavaScript the app.js fetches this endpoint and swaps the
     * button state in place (JSON answer); without JavaScript the
     * form submits normally and the page redirects back to the
     * dashboard with a flash message. The personalization cache is
     * invalidated either way, so the shelf always reflects the
     * change on the next render.
     */
    public function toggleWishlist(Request $request, array $params = []): void
    {
        $this->authorize();
        $this->throttle('wishlist_toggle');

        $userId = auth()?->id();
        $bookId = (int) $request->input('book_id');

        if ($userId === null || $bookId < 1) {
            Response::redirect('/recommendations');
        }

        $saved   = $this->service->toggleWishlist($userId, $bookId);
        $message = $saved
            ? 'Added to your wishlist.'
            : 'Removed from your wishlist.';

        if ($request->header('X-Requested-With') === 'fetch') {
            Response::json(['saved' => $saved, 'message' => $message]);

            return; // the JSON answer is the whole response
        }

        session()->flash('success', $message);
        Response::redirect('/recommendations');
    }

    /**
     * Popular picks across the whole catalogue.
     */
    public function popular(Request $request, array $params = []): void
    {
        $this->authorize();

        $this->render(
            title: 'Popular',
            lead: 'What everyone is reading - scored by ratings, wishlist saves and reviews.',
            run: fn (): RecommendationResult => $this->service->getPopularBooks(),
        );
    }

    /**
     * Top rated books (the confidence-filtered shelf).
     */
    public function topRated(Request $request, array $params = []): void
    {
        $this->authorize();

        $this->render(
            title: 'Top Rated',
            lead: 'The best-reviewed books, each with at least five reviews behind its average.',
            run: fn (): RecommendationResult => $this->service->getHighestRatedBooks(),
        );
    }

    /**
     * Trending books: the momentum shelf.
     */
    public function trending(Request $request, array $params = []): void
    {
        $this->authorize();

        $this->render(
            title: 'Trending',
            lead: 'Becoming popular right now - the most reviews and wishlist saves in the last 30 days.',
            run: fn (): RecommendationResult => $this->service->getTrendingBooks(),
        );
    }

    /**
     * Recently added books, newest first.
     */
    public function recent(Request $request, array $params = []): void
    {
        $this->authorize();

        $this->render(
            title: 'Recently Added',
            lead: 'Fresh arrivals as a discovery shelf.',
            run: fn (): RecommendationResult => $this->service->getRecentlyAddedBooks(),
        );
    }

    /**
     * Books in one explicit category.
     */
    public function category(Request $request, array $params = []): void
    {
        $this->authorize();

        $this->render(
            title: 'By Category',
            lead: 'Every book in this category, title A-Z.',
            run: fn (): RecommendationResult => $this->service->getBooksByCategory($this->idFrom($params)),
        );
    }

    /**
     * "More like this" for one anchor book (the same-author shelf).
     */
    public function show(Request $request, array $params = []): void
    {
        $this->authorize();

        $this->render(
            title: 'More Like This',
            lead: 'Other books by the authors of this one.',
            run: fn (): RecommendationResult => $this->service->getMoreLikeThis($this->idFrom($params)),
        );
    }

    /**
     * Run the strategy through the service and render the page.
     *
     * A RecommendationException (missing book, category or author)
     * becomes a 404 instead of a PHP crash - the module fails loudly
     * but politely.
     *
     * @param \Closure(): RecommendationResult $run
     */
    private function render(string $title, string $lead, \Closure $run): void
    {
        try {
            $result = $run();
        } catch (RecommendationException $exception) {
            Response::error(404, $exception->getMessage());
        }

        $this->view('recommendations.index', $this->viewData($title, $lead, $result));
    }

    /**
     * The route id, as a positive integer, or a request failure.
     */
    private function idFrom(array $params): int
    {
        $id = (int) ($params['id'] ?? 0);

        if ($id < 1) {
            Response::error(404, 'A valid id is required.');
        }

        return $id;
    }

    /**
     * The fine authorization gate: every action starts here.
     *
     * The route table already requires a login (AuthMiddleware);
     * the policy adds the module-level check the controllers can
     * evolve in later phases without touching the routes.
     */
    private function authorize(): void
    {
        if (!$this->policy->view()) {
            Response::redirect('/login');
        }
    }

    /**
     * The write-endpoint throttle (Phase 6.5 security step).
     *
     * Input:  the bucket name ('refresh' | 'wishlist_toggle')
     * Output: nothing (a request over the limit exits with HTTP 429)
     *
     * Business responsibility: the two dashboard writes are already
     * login- and CSRF-protected; the throttle caps how often ONE
     * session may perform them, so a single user can never flood
     * the endpoints. The limits live in
     * config/recommendations.php -> security.rate_limit; when the
     * limiter is not wired (tests) or no limit is configured, the
     * gate simply lets the request through.
     */
    private function throttle(string $bucket): void
    {
        $limits = (array) config('recommendations.security.rate_limit', []);
        $spec   = (array) ($limits[$bucket] ?? []);

        $limit  = (int) ($spec['limit'] ?? 0);
        $window = (int) ($spec['window_seconds'] ?? 0);

        if ($this->limiter === null || $limit < 1 || $window < 1) {
            return;
        }

        if (!$this->limiter->allow($bucket, $limit, $window)) {
            Response::error(429, 'Too many requests - please try again in a minute.');
        }
    }

    /**
     * The shared view payload of every recommendations page.
     */
    private function viewData(string $title, string $lead, ?RecommendationResult $result): array
    {
        return [
            'title'      => $title,
            'active'     => 'recommendations',
            'lead'       => $lead,
            'strategies' => $this->service->strategies(),
            'activeKey'  => $result?->strategyKey,
            'result'     => $result,
        ];
    }
}
