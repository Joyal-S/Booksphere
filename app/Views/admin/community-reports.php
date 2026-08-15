<?php

declare(strict_types=1);

/**
 * admin/community-reports.php
 *
 * Community Moderation Queue (Phase C5) at /admin/community/reports.
 * Shows paginated community_reports filtered by ?status tab.
 * Every moderation action is a POST with CSRF protection.
 */

$reports       = $reports       ?? [];
$total         = $total         ?? 0;
$page          = $page          ?? 1;
$pages         = $pages         ?? 1;
$currentStatus = $currentStatus ?? 'pending';
$pendingCount  = $pendingCount  ?? 0;
$statuses      = $statuses      ?? ['pending', 'reviewed', 'dismissed', 'resolved'];

<?php
$statusLabels = [
    'all'       => 'All Reports',
    'pending'   => 'Pending',
    'reviewed'  => 'Under Review',
    'dismissed' => 'Dismissed',
    'resolved'  => 'Resolved',
];
$statusTones = [
    'all'       => 'dark',
    'pending'   => 'warning',
    'reviewed'  => 'info',
    'dismissed' => 'secondary',
    'resolved'  => 'success',
];
$reportStats = $reportStats ?? [
    'pending'   => $pendingCount,
    'reviewed'  => 0,
    'dismissed' => 0,
    'resolved'  => 0,
    'total'     => $total,
];
?>
<div class="page-intro">
    <p class="eyebrow">Restricted area</p>
    <h1>Community Moderation</h1>
    <p class="lead">Review and action community reports — Phase C5 moderation queue.</p>
</div>

<!-- Flash Notifications -->
<?php if (session()->getFlash('success') !== null): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
        <i class="fa-solid fa-circle-check me-2" aria-hidden="true"></i><?= e(session()->getFlash('success')) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if (session()->getFlash('error') !== null): ?>
    <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
        <i class="fa-solid fa-circle-exclamation me-2" aria-hidden="true"></i><?= e(session()->getFlash('error')) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Overview stats -->
<section class="dash-section mb-4" data-animate>
    <?php $section = ['eyebrow' => 'Queue Summary', 'title' => 'Community Reports', 'icon' => 'fa-flag']; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <?php $stat = ['icon' => 'fa-hourglass-half', 'label' => 'Pending', 'value' => (int) ($reportStats['pending'] ?? 0), 'tone' => 'warning']; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
        <div class="col-6 col-md-3">
            <?php $stat = ['icon' => 'fa-magnifying-glass', 'label' => 'Under Review', 'value' => (int) ($reportStats['reviewed'] ?? 0), 'tone' => 'info']; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
        <div class="col-6 col-md-3">
            <?php $stat = ['icon' => 'fa-circle-check', 'label' => 'Resolved', 'value' => (int) ($reportStats['resolved'] ?? 0), 'tone' => 'success']; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
        <div class="col-6 col-md-3">
            <?php $stat = ['icon' => 'fa-flag', 'label' => 'Total Reports', 'value' => (int) ($reportStats['total'] ?? 0), 'tone' => 'primary']; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
    </div>
</section>

<!-- Status Tab Filter -->
<div class="d-flex gap-2 mb-4 flex-wrap" aria-label="Report Status Filter">
    <?php foreach ($statuses as $s): ?>
        <?php
        $isActive = ($currentStatus === $s);
        $sBadgeCount = match ($s) {
            'pending'   => (int) ($reportStats['pending'] ?? 0),
            'reviewed'  => (int) ($reportStats['reviewed'] ?? 0),
            'resolved'  => (int) ($reportStats['resolved'] ?? 0),
            'dismissed' => (int) ($reportStats['dismissed'] ?? 0),
            default     => (int) ($reportStats['total'] ?? 0),
        };
        ?>
        <a href="/admin/community/reports?status=<?= urlencode($s) ?>"
           class="btn btn-sm <?= $isActive ? 'btn-primary shadow-sm fw-bold' : 'btn-outline-secondary' ?> d-inline-flex align-items-center gap-1.5 px-3 py-1.5"
           <?= $isActive ? 'aria-current="page"' : '' ?>>
            <span><?= e($statusLabels[$s] ?? ucfirst($s)) ?></span>
            <?php if ($sBadgeCount > 0): ?>
                <span class="badge <?= $isActive ? 'bg-white text-primary' : 'bg-secondary-subtle text-secondary' ?> rounded-pill ms-1"><?= $sBadgeCount ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- Reports Table -->
<section class="dash-section" data-animate>
    <?php if (empty($reports)): ?>
        <?php $empty = ['icon' => 'fa-flag', 'title' => 'No ' . ($statusLabels[$currentStatus] ?? $currentStatus) . ' Reports', 'message' => 'There are no community reports with this status.']; ?>
        <?php require root_path('app/Views/components/empty-state.php'); ?>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="community-reports-table">
                <thead class="table-light">
                    <tr>
                        <th style="width:60px;">#</th>
                        <th>Type</th>
                        <th>Content Preview</th>
                        <th>Reporter</th>
                        <th>Reason</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reports as $report): ?>
                        <?php
                        $rid         = (int)    ($report['id']              ?? 0);
                        $contentType = (string) ($report['content_type']    ?? 'unknown');
                        $preview     = (string) ($report['content_preview'] ?? '(no preview)');
                        $reporter    = (string) ($report['reporter_name']   ?? 'Unknown');
                        $reason      = (string) ($report['reason']          ?? 'Other');
                        $rStatus     = (string) ($report['status']          ?? 'pending');
                        $createdAt   = (string) ($report['created_at']      ?? '');
                        $postId      = isset($report['post_id']) ? (int) $report['post_id'] : null;
                        $commentId   = isset($report['comment_id']) ? (int) $report['comment_id'] : null;
                        $tone        = $statusTones[$rStatus] ?? 'secondary';
                        $timeLabel   = function_exists('format_notification_time')
                            ? format_notification_time($createdAt)
                            : $createdAt;
                        ?>
                        <tr>
                            <td class="text-muted small"><?= $rid ?></td>
                            <td>
                                <?php if ($contentType === 'post'): ?>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                        <i class="fa-solid fa-comment-dots me-1" aria-hidden="true"></i> Post
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                                        <i class="fa-solid fa-comment me-1" aria-hidden="true"></i> Comment
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="text-truncate d-inline-block" style="max-width:260px;" title="<?= e($preview) ?>">
                                    <?= e(mb_strlen($preview) > 80 ? mb_substr($preview, 0, 80) . '…' : $preview) ?>
                                </span>
                            </td>
                            <td class="small text-muted"><?= e($reporter) ?></td>
                            <td>
                                <span class="badge bg-light text-dark border"><?= e($reason) ?></span>
                            </td>
                            <td class="small text-muted text-nowrap"><?= e($timeLabel) ?></td>
                            <td>
                                <span class="badge bg-<?= $tone ?>-subtle text-<?= $tone ?> border border-<?= $tone ?>-subtle">
                                    <?= e($statusLabels[$rStatus] ?? ucfirst($rStatus)) ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <div class="d-flex align-items-center justify-content-end gap-1 flex-wrap">
                                    <!-- Detail View -->
                                    <a href="/admin/community/reports/<?= $rid ?>"
                                       class="btn btn-sm btn-outline-secondary"
                                       title="View full report">
                                        <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                    </a>

                                    <?php if (in_array($rStatus, ['pending', 'reviewed'], true)): ?>
                                        <!-- Mark Resolved -->
                                        <form action="/admin/community/reports/<?= $rid ?>/resolve" method="POST" class="d-inline">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-outline-success"
                                                    title="Mark as resolved"
                                                    onclick="return confirm('Mark report #<?= $rid ?> as resolved?')">
                                                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                        <!-- Dismiss -->
                                        <form action="/admin/community/reports/<?= $rid ?>/dismiss" method="POST" class="d-inline">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-outline-secondary"
                                                    title="Dismiss report"
                                                    onclick="return confirm('Dismiss report #<?= $rid ?>?')">
                                                <i class="fa-solid fa-circle-xmark" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                        <!-- Mark Under Review -->
                                        <?php if ($rStatus === 'pending'): ?>
                                        <form action="/admin/community/reports/<?= $rid ?>/review" method="POST" class="d-inline">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-outline-info"
                                                    title="Mark as under review">
                                                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <!-- Hide / Restore Content -->
                                    <?php if ($postId !== null): ?>
                                        <form action="/admin/community/posts/<?= $postId ?>/hide" method="POST" class="d-inline">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                                    title="Hide post #<?= $postId ?>"
                                                    onclick="return confirm('Hide post #<?= $postId ?>? It will be removed from the community feed.')">
                                                <i class="fa-solid fa-eye-slash" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($commentId !== null): ?>
                                        <form action="/admin/community/comments/<?= $commentId ?>/hide" method="POST" class="d-inline">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                                    title="Hide comment #<?= $commentId ?>"
                                                    onclick="return confirm('Hide comment #<?= $commentId ?>?')">
                                                <i class="fa-solid fa-eye-slash" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($pages > 1): ?>
            <nav class="d-flex justify-content-center gap-2 mt-4" aria-label="Report queue pages">
                <?php for ($i = 1; $i <= $pages; $i++): ?>
                    <a href="/admin/community/reports?status=<?= urlencode($currentStatus) ?>&page=<?= $i ?>"
                       class="btn btn-sm <?= $i === $page ? 'btn-dark' : 'btn-outline-secondary' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
