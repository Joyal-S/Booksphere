<?php

declare(strict_types=1);

namespace BookSphere\App\Controllers;

use BookSphere\App\Core\Controller;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Exceptions\ReviewException;
use BookSphere\App\Policies\ReviewPolicy;
use BookSphere\App\Services\RecommendationMetrics;
use BookSphere\App\Services\ReviewService;

/**
 * AdminController
 *
 * The administrator area (Phase 6.5: the recommendation monitoring
 * surface; Phase 7.3: the rating analytics surface; Phase 7.5: the
 * review-moderation foundation). Every route is protected by
 * AdminMiddleware, which only lets users with the "admin" role
 * through - this is the role-based authorization proof of the
 * module.
 *
 *     - index()       -> /admin: the administration landing page,
 *                        now with the live rating analytics
 *                        (overall average, distribution, the highest
 *                        and lowest rated books and the books that
 *                        have no reviews yet)
 *     - metrics()     -> /admin/recommendations: the read-only
 *                        health picture of the recommendation engine
 *                        (cache, config, data and score metrics from
 *                        RecommendationMetrics)
 *     - flushCache()  -> POST /admin/recommendations/cache/flush:
 *                        the write tool - drops every cached shelf
 *                        so the next dashboard visits rebuild from
 *                        the latest signals
 *     - reports()     -> /admin/reviews: the review-management
 *                        console - report statistics, the queue
 *                        (Pending / Reviewed / Dismissed /
 *                        Resolved tabs) and the hidden reviews
 *     - resolveReport() / dismissReport()
 *                    -> POST /admin/reports/{id}/resolve (dismiss):
 *                        move a report along its lifecycle
 *     - hideReview() / unhideReview()
 *                    -> POST /admin/reviews/{id}/hide (unhide):
 *                        pull a review from the catalogue or bring
 *                        it back
 *
 * The pages stay thin on purpose: the controller asks the
 * RecommendationMetrics / ReviewService services for one summary
 * array each and renders it. The services own the composition, the
 * repositories own the SQL, the middleware owns the authorization
 * and the ReviewPolicy is the fine per-action gate of the
 * moderation actions (defence in depth behind AdminMiddleware).
 */
final class AdminController extends Controller
{
    public function __construct(
        private readonly ?RecommendationMetrics $metrics = null,
        private readonly ?ReviewService $reviews = null,
        private readonly ?ReviewPolicy $policy = null,
    ) {}

    public function index(Request $request, array $params = []): void
    {
        $this->view('admin.index', [
            'title'        => 'Administration',
            'active'       => 'admin',
            'ratingAnalytics' => $this->reviews?->adminAnalytics() ?? [],
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

    // --- Phase 7.5: review management (moderation foundation) ------------

    /**
     * The review-management console (/admin/reviews): the report
     * statistics cards (total, per-status, per-reason, hidden
     * reviews), the moderation queue filtered by the ?status tab
     * (pending | reviewed | dismissed | resolved, pending by
     * default) and the hidden-reviews list. Foundation only - the
     * full workflow (approval, AI analysis, appeals) is Phase 7.6.
     */
    public function reports(Request $request, array $params = []): void
    {
        $status  = $request->input('status');
        $allowed = ['pending', 'reviewed', 'dismissed', 'resolved'];
        $status  = in_array($status, $allowed, true) ? $status : 'pending';

        $this->view('admin.reports', [
            'title'    => 'Review Management',
            'active'   => 'admin',
            'status'   => $status,
            'queue'    => $this->reviews?->reportsByStatus($status) ?? [],
            'stats'    => $this->reviews?->reportStatistics() ?? [],
            'hidden'   => $this->reviews?->hiddenReviews() ?? [],
        ]);
    }

    /**
     * Resolve a report: the admin judged the review and acted on
     * it (the report's own state, not the review's - the review is
     * hidden separately via hideReview()).
     */
    public function resolveReport(Request $request, array $params = []): void
    {
        $this->authorizeModeration('resolve reports');

        $this->setReportStatus((int) ($params['id'] ?? 0), 'resolved', 'Report resolved.');
        Response::redirect('/admin/reviews');
    }

    /**
     * Dismiss a report: the admin judged it unfounded.
     */
    public function dismissReport(Request $request, array $params = []): void
    {
        $this->authorizeModeration('dismiss reports');

        $this->setReportStatus((int) ($params['id'] ?? 0), 'dismissed', 'Report dismissed.');
        Response::redirect('/admin/reviews');
    }

    /**
     * Hide a review: pull it from the catalogue until the author
     * fixes it or the admin reconsiders. Book rating statistics are
     * recomputed, so the review's numbers leave every page with it.
     */
    public function hideReview(Request $request, array $params = []): void
    {
        $this->authorizeModeration('hide reviews');

        $this->hideOrUnhide((int) ($params['id'] ?? 0), true, 'Review hidden.');
        Response::redirect('/admin/reviews');
    }

    /**
     * Bring a hidden review back (status -> approved; the review
     * re-enters the averages and lists).
     */
    public function unhideReview(Request $request, array $params = []): void
    {
        $this->authorizeModeration('unhide reviews');

        $this->hideOrUnhide((int) ($params['id'] ?? 0), false, 'Review restored.');
        Response::redirect('/admin/reviews');
    }

    /**
     * The fine gate of every moderation action: AdminMiddleware
     * already keeps the route admin-only; the policy is the
     * defence-in-depth answer, exactly like the review module's
     * owner-or-admin gates.
     */
    private function authorizeModeration(string $action): void
    {
        if (!$this->policy?->canModerate()) {
            Response::error(403, 'You are not allowed to ' . $action . '.');
        }
    }

    /**
     * Apply a lifecycle status to a report, flashing the outcome.
     */
    private function setReportStatus(int $reportId, string $status, string $message): void
    {
        try {
            $this->reviews?->updateReportStatus($reportId, $status);
            session()->flash('success', $message);
        } catch (ReviewException $e) {
            Response::error(404, $e->getMessage());
        }
    }

    /**
     * Hide or restore a review, flashing the outcome.
     */
    private function hideOrUnhide(int $reviewId, bool $hidden, string $message): void
    {
        try {
            $this->reviews?->hideReview($reviewId, $hidden);
            session()->flash('success', $message);
        } catch (ReviewException $e) {
            Response::error(404, $e->getMessage());
        }
    }
}
