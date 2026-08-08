<?php

declare(strict_types=1);

namespace BookSphere\App\Controllers;

use BookSphere\App\Core\Controller;
use BookSphere\App\Core\Request;
use BookSphere\App\Services\BookAnalyticsService;

/**
 * BookAnalyticsController
 *
 * The presentation layer of the BOOK ANALYTICS module (Phase 12.2).
 * The page answers ONE question - "what do the numbers of the whole
 * catalogue say?" - so it is a single read-only action:
 *
 *     GET /book-analytics  ->  the catalogue-analytics page
 *
 * The route carries the AuthMiddleware (like every signed-in page,
 * including the Phase 12.1 personal page). The payload is purely
 * derived from catalogue rows that every signed-in user already sees
 * in browse, library and review surfaces, so the page gates on
 * login, never on an admin role.
 *
 * The page is fully server-rendered (no-JS friendly): analytics are
 * a read-only snapshot, so the module adds no AJAX surface.
 *
 * Every calculation lives in BookAnalyticsService::build(); this
 * controller only hands the payload to the view.
 */
final class BookAnalyticsController extends Controller
{
    public function __construct(
        private readonly BookAnalyticsService $service,
    ) {}

    public function index(Request $request, array $params = []): void
    {
        $this->view('book_analytics.index', [
            'active'   => 'book-analytics',
            'title'    => 'Book Analytics',
            'analytics' => $this->service->build()->toArray(),
        ]);
    }
}