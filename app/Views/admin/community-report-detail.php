<?php

declare(strict_types=1);

/**
 * admin/community-report-detail.php
 *
 * Full detail view for a single community report (Phase C5).
 * Accessible at GET /admin/community/reports/{id}.
 * Shows reporter info, full content, reason, status, and moderation actions.
 */

$report = $report ?? [];

$rid         = (int)    ($report['id']              ?? 0);
$contentType = (string) ($report['content_type']    ?? 'unknown');
$reporter    = (string) ($report['reporter_name']   ?? 'Unknown');
$reason      = (string) ($report['reason']          ?? 'Other');
$description = (string) ($report['description']     ?? '');
$rStatus     = (string) ($report['status']          ?? 'pending');
$createdAt   = (string) ($report['created_at']      ?? '');
$updatedAt   = (string) ($report['updated_at']      ?? '');
$postTitle   = (string) ($report['post_title']      ?? '');
$postBody    = (string) ($report['post_body']        ?? '');
$postStatus  = (string) ($report['post_status']     ?? '');
$commentBody = (string) ($report['comment_body']    ?? '');
$commentStatus = (string) ($report['comment_status'] ?? '');
$contentAuthor = (string) ($report['content_author'] ?? 'Unknown');
$postId      = isset($report['post_id'])    ? (int) $report['post_id']    : null;
$commentId   = isset($report['comment_id']) ? (int) $report['comment_id'] : null;

$statusLabels = [
    'pending'   => 'Pending',
    'reviewed'  => 'Under Review',
    'dismissed' => 'Dismissed',
    'resolved'  => 'Resolved',
];
$statusTones = [
    'pending'   => 'warning',
    'reviewed'  => 'info',
    'dismissed' => 'secondary',
    'resolved'  => 'success',
];
$contentTone   = $statusTones[$rStatus] ?? 'secondary';
$contentLabel  = $statusLabels[$rStatus] ?? ucfirst($rStatus);

$timeLabel = function_exists('format_notification_time')
    ? format_notification_time($createdAt)
    : $createdAt;
?>
<div class="mb-3">
    <a href="/admin/community/reports" class="text-decoration-none text-muted small">
        <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i> Back to Moderation Queue
    </a>
</div>

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

<div class="row g-4">
    <!-- Left: Content Card -->
    <div class="col-lg-8">
        <div class="card-base p-4 mb-4">
            <div class="d-flex align-items-center gap-2 mb-3">
                <span class="badge bg-<?= $contentType === 'post' ? 'primary' : 'secondary' ?>-subtle text-<?= $contentType === 'post' ? 'primary' : 'secondary' ?> border border-<?= $contentType === 'post' ? 'primary' : 'secondary' ?>-subtle">
                    <i class="fa-solid <?= $contentType === 'post' ? 'fa-comment-dots' : 'fa-comment' ?> me-1" aria-hidden="true"></i>
                    <?= ucfirst($contentType) ?>
                </span>
                <span class="badge bg-<?= $contentTone ?>-subtle text-<?= $contentTone ?> border border-<?= $contentTone ?>-subtle">
                    <?= e($contentLabel) ?>
                </span>
                <?php if (($contentType === 'post' && $postStatus === 'hidden') || ($contentType === 'comment' && $commentStatus === 'hidden')): ?>
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                        <i class="fa-solid fa-eye-slash me-1" aria-hidden="true"></i> Hidden
                    </span>
                <?php endif; ?>
            </div>

            <?php if ($contentType === 'post'): ?>
                <h2 class="h5 fw-bold mb-2"><?= e($postTitle) ?></h2>
                <div class="text-secondary mb-3" style="white-space: pre-wrap; font-size: 0.95rem; line-height: 1.65;"><?= e($postBody) ?></div>
                <?php if ($postId !== null): ?>
                    <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
                        <a href="/community/post/<?= $postId ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                            <i class="fa-solid fa-arrow-up-right-from-square me-1" aria-hidden="true"></i> View Discussion Page
                        </a>
                        <?php if (!empty($report['book_id'])): ?>
                            <a href="/community/book/<?= (int) $report['book_id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                <i class="fa-solid fa-book me-1" aria-hidden="true"></i> Book Discussion Hub: <?= e($report['book_title'] ?? 'Book') ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php elseif ($contentType === 'comment'): ?>
                <?php if (!empty($report['comment_post_title'])): ?>
                    <div class="mb-3 p-3 rounded bg-body-tertiary border">
                        <span class="text-uppercase text-muted fw-bold small d-block mb-1" style="font-size: 0.6875rem; letter-spacing: 0.05em;">PARENT DISCUSSION</span>
                        <a href="/community/post/<?= (int) ($report['comment_post_id'] ?? 0) ?>" target="_blank" class="fw-bold text-dark text-decoration-none hover-primary">
                            <?= e($report['comment_post_title']) ?>
                        </a>
                    </div>
                <?php endif; ?>
                <div class="text-secondary mb-3" style="white-space: pre-wrap; font-size: 0.95rem; line-height: 1.65;"><?= e($commentBody) ?></div>
                <?php if (!empty($report['book_id'])): ?>
                    <div class="mb-2">
                        <a href="/community/book/<?= (int) $report['book_id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                            <i class="fa-solid fa-book me-1" aria-hidden="true"></i> Book Discussion Hub: <?= e($report['book_title'] ?? 'Book') ?>
                        </a>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <p class="text-muted">Content not available.</p>
            <?php endif; ?>

            <div class="mt-3 pt-3 border-top text-muted small">
                <i class="fa-solid fa-user me-1" aria-hidden="true"></i> Author: <strong><?= e($contentAuthor) ?></strong>
            </div>
        </div>

        <!-- Report Details Card -->
        <div class="card-base p-4">
            <h3 class="h6 fw-bold text-uppercase text-muted mb-3" style="letter-spacing: 0.05em; font-size: 0.75rem;">Report Details</h3>
            <dl class="row mb-0" style="row-gap: 0.5rem;">
                <dt class="col-sm-4 text-muted fw-normal small">Report ID</dt>
                <dd class="col-sm-8 small mb-0">#<?= $rid ?></dd>

                <dt class="col-sm-4 text-muted fw-normal small">Reporter</dt>
                <dd class="col-sm-8 small mb-0"><?= e($reporter) ?></dd>

                <dt class="col-sm-4 text-muted fw-normal small">Reason</dt>
                <dd class="col-sm-8 mb-0">
                    <span class="badge bg-light text-dark border"><?= e($reason) ?></span>
                </dd>

                <?php if ($description !== ''): ?>
                <dt class="col-sm-4 text-muted fw-normal small">Description</dt>
                <dd class="col-sm-8 small mb-0" style="white-space: pre-wrap;"><?= e($description) ?></dd>
                <?php endif; ?>

                <dt class="col-sm-4 text-muted fw-normal small">Reported</dt>
                <dd class="col-sm-8 small mb-0"><?= e($timeLabel) ?></dd>

                <dt class="col-sm-4 text-muted fw-normal small">Last updated</dt>
                <dd class="col-sm-8 small mb-0"><?= e($updatedAt) ?></dd>
            </dl>
        </div>
    </div>

    <!-- Right: Action Panel -->
    <div class="col-lg-4">
        <div class="card-base p-4 mb-4">
            <h3 class="h6 fw-bold text-uppercase text-muted mb-3" style="letter-spacing: 0.05em; font-size: 0.75rem;">Moderation Actions</h3>

            <div class="d-grid gap-2">
                <?php if (in_array($rStatus, ['pending', 'reviewed'], true)): ?>
                    <!-- Mark Resolved -->
                    <form action="/admin/community/reports/<?= $rid ?>/resolve" method="POST">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-success w-100"
                                onclick="return confirm('Mark report #<?= $rid ?> as resolved?')">
                            <i class="fa-solid fa-circle-check me-2" aria-hidden="true"></i> Resolve Report
                        </button>
                    </form>
                    <!-- Dismiss -->
                    <form action="/admin/community/reports/<?= $rid ?>/dismiss" method="POST">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-outline-secondary w-100"
                                onclick="return confirm('Dismiss report #<?= $rid ?>?')">
                            <i class="fa-solid fa-circle-xmark me-2" aria-hidden="true"></i> Dismiss Report
                        </button>
                    </form>
                    <!-- Mark Under Review -->
                    <?php if ($rStatus === 'pending'): ?>
                    <form action="/admin/community/reports/<?= $rid ?>/review" method="POST">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-outline-info w-100">
                            <i class="fa-solid fa-magnifying-glass me-2" aria-hidden="true"></i> Mark Under Review
                        </button>
                    </form>
                    <?php endif; ?>
                    <hr class="my-2">
                <?php endif; ?>

                <!-- Content Actions -->
                <?php if ($contentType === 'post' && $postId !== null): ?>
                    <?php if ($postStatus !== 'hidden'): ?>
                        <form action="/admin/community/posts/<?= $postId ?>/hide" method="POST">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-outline-danger w-100"
                                    onclick="return confirm('Hide post #<?= $postId ?>? It will be removed from the community feed.')">
                                <i class="fa-solid fa-eye-slash me-2" aria-hidden="true"></i> Hide Post
                            </button>
                        </form>
                    <?php else: ?>
                        <form action="/admin/community/posts/<?= $postId ?>/unhide" method="POST">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-outline-success w-100">
                                <i class="fa-solid fa-eye me-2" aria-hidden="true"></i> Restore Post
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($contentType === 'comment' && $commentId !== null): ?>
                    <?php if ($commentStatus !== 'hidden'): ?>
                        <form action="/admin/community/comments/<?= $commentId ?>/hide" method="POST">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-outline-danger w-100"
                                    onclick="return confirm('Hide comment #<?= $commentId ?>?')">
                                <i class="fa-solid fa-eye-slash me-2" aria-hidden="true"></i> Hide Comment
                            </button>
                        </form>
                    <?php else: ?>
                        <form action="/admin/community/comments/<?= $commentId ?>/unhide" method="POST">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-outline-success w-100">
                                <i class="fa-solid fa-eye me-2" aria-hidden="true"></i> Restore Comment
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Current Status Card -->
        <div class="card-base p-4">
            <h3 class="h6 fw-bold text-uppercase text-muted mb-3" style="letter-spacing: 0.05em; font-size: 0.75rem;">Report Status</h3>
            <span class="badge bg-<?= $contentTone ?>-subtle text-<?= $contentTone ?> border border-<?= $contentTone ?>-subtle px-3 py-2 fs-6">
                <?= e($contentLabel) ?>
            </span>
        </div>
    </div>
</div>
