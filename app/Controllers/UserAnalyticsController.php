<?php

declare(strict_types=1);

namespace BookSphere\App\Controllers;

use BookSphere\App\Core\Controller;
use BookSphere\App\Core\Request;
use BookSphere\App\Services\UserAnalyticsService;

/**
 * UserAnalyticsController
 *
 * The presentation layer of the USER ANALYTICS module (Phase 12.1).
 * The page answers ONE question - "what do the numbers of my own
 * reading say?" - so it is a single read-only action:
 *
 *     GET /analytics  ->  the personal statistics page
 *
 * The route carries the AuthMiddleware, and the user id comes from
 * the authenticated SESSION only (auth()->id()) - a user id from
 * the client is structurally impossible here: the route takes no
 * parameters and the request body is never consulted. Users can
 * therefore only ever see their OWN analytics (Task 14).
 *
 * The page is fully server-rendered (no-JS friendly): analytics are
 * a read-only snapshot, so the module adds no AJAX surface - the
 * application's JSON endpoints exist for live-updating widgets, and
 * nothing here changes.
 *
 * Every calculation lives in UserAnalyticsService::build(); this
 * controller only hands the payload to the view.
 */
final class UserAnalyticsController extends Controller
{
    public function __construct(
        private readonly UserAnalyticsService $service,
    ) {}

    public function show(Request $request, array $params = []): void
    {
        $this->view('analytics.show', [
            'active'    => 'analytics',
            'title'     => 'My Analytics',
            'analytics' => $this->service->build((int) auth()->id())->toArray(),
        ]);
    }

    /**
     * GET /analytics/report - the print-only personal READING REPORT
     * (Phase 12.5). One payload, portrait layout, no chrome: the same
     * numbers the /analytics page shows, rendered for paper or PDF.
     * The view itself carries the generatedAt stamp so every printed
     * copy says when it was produced.
     */
    public function report(Request $request, array $params = []): void
    {
        $this->view('analytics.report', [
            'active'      => 'analytics-report',
            'title'       => 'My Reading Report',
            'bodyClass'   => 'report-print',
            'analytics'   => $this->service->build((int) auth()->id())->toArray(),
            'generatedAt' => gmdate('Y-m-d H:i') . ' UTC',
        ]);
    }
}