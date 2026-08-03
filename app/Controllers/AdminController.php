<?php

declare(strict_types=1);

namespace BookSphere\App\Controllers;

use BookSphere\App\Core\Controller;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Services\RecommendationMetrics;

/**
 * AdminController
 *
 * The administrator area (Phase 6.5: the recommendation monitoring
 * surface). Every route is protected by AdminMiddleware, which only
 * lets users with the "admin" role through - this is the role-based
 * authorization proof of the module.
 *
 *     - index()       -> /admin: the administration landing page
 *     - metrics()     -> /admin/recommendations: the read-only
 *                        health picture of the recommendation engine
 *                        (cache, config, data and score metrics from
 *                        RecommendationMetrics)
 *     - flushCache()  -> POST /admin/recommendations/cache/flush:
 *                        the write tool - drops every cached shelf
 *                        so the next dashboard visits rebuild from
 *                        the latest signals
 *
 * The metrics page stays thin on purpose: the controller asks the
 * RecommendationMetrics service for one summary array and renders
 * it. The service owns the composition, the repository owns the
 * SQL, the middleware owns the authorization.
 */
final class AdminController extends Controller
{
    public function __construct(
        private readonly ?RecommendationMetrics $metrics = null,
    ) {}

    public function index(Request $request, array $params = []): void
    {
        $this->view('admin.index', [
            'title'  => 'Administration',
            'active' => 'admin',
        ]);
    }

    /**
     * The recommendation engine monitoring page.
     *
     * The one page where an administrator can SEE the engine
     * working: how the cache behaves (files, stale entries,
     * writability), which configuration values are live, how much
     * signal the data holds and what the average scores look like.
     * Read-only - the only write action is the explicit flush
     * button on the same page.
     */
    public function metrics(Request $request, array $params = []): void
    {
        $this->view('admin.recommendations', [
            'title'   => 'Recommendation Engine',
            'active'  => 'admin',
            'metrics' => $this->metrics?->summary() ?? [],
        ]);
    }

    /**
     * The cache flush tool: drop every cached personalized shelf.
     *
     * POST-only and CSRF-protected (the route table adds
     * CsrfMiddleware). Flushing is safe: the next dashboard visit
     * simply rebuilds a shelf from the current signals - this is
     * the administrative counterpart of the user-facing refresh
     * button, applied to all users at once.
     */
    public function flushCache(Request $request, array $params = []): void
    {
        $this->metrics?->flushCache();

        session()->flash('success', 'The recommendation cache was flushed - every user\'s next dashboard visit rebuilds from the latest signals.');
        Response::redirect('/admin/recommendations');
    }
}
