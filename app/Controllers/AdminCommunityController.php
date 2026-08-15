<?php

declare(strict_types=1);

namespace BookSphere\App\Controllers;

use BookSphere\App\Core\Controller;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Exceptions\CommunityException;
use BookSphere\App\Policies\CommunityPolicy;
use BookSphere\App\Services\CommunityService;

/**
 * AdminCommunityController
 *
 * Community moderation dashboard (Phase C5).
 * Every route is protected by AdminMiddleware and CsrfMiddleware (writes).
 *
 * Routes:
 *   GET  /admin/community/reports            → queue()   paginated queue filtered by ?status
 *   GET  /admin/community/reports/{id}       → detail()  full report detail + context
 *   POST /admin/community/reports/{id}/resolve  → resolve()  set status = resolved
 *   POST /admin/community/reports/{id}/dismiss  → dismiss()  set status = dismissed
 *   POST /admin/community/posts/{id}/hide       → hidePost()
 *   POST /admin/community/posts/{id}/unhide     → unhidePost()
 *   POST /admin/community/comments/{id}/hide    → hideComment()
 *   POST /admin/community/comments/{id}/unhide  → unhideComment()
 */
final class AdminCommunityController extends Controller
{
    public function __construct(
        private readonly CommunityService $service,
        private readonly CommunityPolicy  $policy,
    ) {}

    // ===================================================================
    // MODERATION QUEUE
    // ===================================================================

    /**
     * GET /admin/community/reports
     * Paginated moderation queue filtered by ?status (pending|reviewed|dismissed|resolved).
     */
    public function queue(Request $request, array $params = []): void
    {
        $status  = (string) $request->input('status', 'pending');
        $page    = max(1, (int) $request->input('page', 1));
        $perPage = 25;

        $validStatuses = array_merge(['all'], CommunityService::REPORT_STATUSES);
        if (!in_array($status, $validStatuses, true)) {
            $status = 'pending';
        }

        $pageData     = $this->service->listReports($page, $perPage, $status);
        $pendingCount = $this->service->pendingReportCount();
        $reportStats  = $this->service->getReportStatistics();

        $this->view('admin.community-reports', [
            'title'         => 'Community Moderation Queue',
            'active'        => 'admin-community',
            'reports'       => $pageData['items'],
            'total'         => (int) $pageData['total'],
            'page'          => (int) $pageData['page'],
            'pages'         => (int) $pageData['pages'],
            'perPage'       => $perPage,
            'currentStatus' => $status,
            'pendingCount'  => $pendingCount,
            'reportStats'   => $reportStats,
            'statuses'      => $validStatuses,
            'reasons'       => CommunityService::REPORT_REASONS,
        ]);
    }

    /**
     * GET /admin/community/reports/{id}
     * Full report detail page with complete content context and moderation actions.
     */
    public function detail(Request $request, array $params = []): void
    {
        $reportId = (int) ($params['id'] ?? 0);

        try {
            $report = $this->service->getReportWithContext($reportId);
        } catch (CommunityException $e) {
            session()->flash('error', $e->getMessage());
            Response::redirect('/admin/community/reports');
            return;
        }

        $this->view('admin.community-report-detail', [
            'title'  => 'Report #' . $reportId . ' — Community Moderation',
            'active' => 'admin-community',
            'report' => $report,
        ]);
    }

    // ===================================================================
    // REPORT STATUS ACTIONS
    // ===================================================================

    /**
     * POST /admin/community/reports/{id}/resolve
     */
    public function resolve(Request $request, array $params = []): void
    {
        $this->updateReportStatus($params, 'resolved');
    }

    /**
     * POST /admin/community/reports/{id}/dismiss
     */
    public function dismiss(Request $request, array $params = []): void
    {
        $this->updateReportStatus($params, 'dismissed');
    }

    /**
     * POST /admin/community/reports/{id}/review
     */
    public function markReviewed(Request $request, array $params = []): void
    {
        $this->updateReportStatus($params, 'reviewed');
    }

    // ===================================================================
    // CONTENT HIDE / UNHIDE ACTIONS
    // ===================================================================

    /**
     * POST /admin/community/posts/{id}/hide
     */
    public function hidePost(Request $request, array $params = []): void
    {
        $postId  = (int) ($params['id'] ?? 0);
        $actorId = (int) auth()->id();

        try {
            $this->service->hidePost($actorId, $postId);
            session()->flash('success', "Post #{$postId} has been hidden.");
        } catch (CommunityException $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->redirectBack($request, '/admin/community/reports');
    }

    /**
     * POST /admin/community/posts/{id}/unhide
     */
    public function unhidePost(Request $request, array $params = []): void
    {
        $postId  = (int) ($params['id'] ?? 0);
        $actorId = (int) auth()->id();

        try {
            $this->service->unhidePost($actorId, $postId);
            session()->flash('success', "Post #{$postId} is now visible again.");
        } catch (CommunityException $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->redirectBack($request, '/admin/community/reports');
    }

    /**
     * POST /admin/community/comments/{id}/hide
     */
    public function hideComment(Request $request, array $params = []): void
    {
        $commentId = (int) ($params['id'] ?? 0);
        $actorId   = (int) auth()->id();

        try {
            $this->service->hideComment($actorId, $commentId);
            session()->flash('success', "Comment #{$commentId} has been hidden.");
        } catch (CommunityException $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->redirectBack($request, '/admin/community/reports');
    }

    /**
     * POST /admin/community/comments/{id}/unhide
     */
    public function unhideComment(Request $request, array $params = []): void
    {
        $commentId = (int) ($params['id'] ?? 0);
        $actorId   = (int) auth()->id();

        try {
            $this->service->unhideComment($actorId, $commentId);
            session()->flash('success', "Comment #{$commentId} is now visible again.");
        } catch (CommunityException $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->redirectBack($request, '/admin/community/reports');
    }

    // ===================================================================
    // COMMUNITY ANALYTICS
    // ===================================================================

    /**
     * GET /admin/analytics/community
     * Focused Community Analytics dashboard (Phase C8-D).
     */
    public function analytics(Request $request, array $params = []): void
    {
        $range       = (string) $request->input('range', '30d');
        $validRanges = ['7d', '30d', '90d', 'all'];

        if (!in_array($range, $validRanges, true)) {
            $range = '30d';
        }

        $analytics = $this->service->getCommunityAnalytics($range);

        $this->view('admin.community-analytics', [
            'title'       => 'Community Analytics',
            'active'      => 'admin-community-analytics',
            'range'       => $range,
            'validRanges' => $validRanges,
            'analytics'   => $analytics,
        ]);
    }

    // ===================================================================
    // PRIVATE HELPERS
    // ===================================================================

    private function updateReportStatus(array $params, string $status): void
    {
        $reportId = (int) ($params['id'] ?? 0);
        $actorId  = (int) auth()->id();

        try {
            $this->service->moderateReport($actorId, $reportId, $status);
            $label = ucfirst($status);
            session()->flash('success', "Report #{$reportId} marked as {$label}.");
        } catch (CommunityException $e) {
            session()->flash('error', $e->getMessage());
        }

        Response::redirect('/admin/community/reports');
    }

    private function redirectBack(Request $request, string $fallback = '/'): void
    {
        $referer = (string) $request->header('Referer');
        $target  = ($referer !== '' && str_starts_with($referer, '/'))
            ? $referer
            : $fallback;

        Response::redirect($target);
    }
}
