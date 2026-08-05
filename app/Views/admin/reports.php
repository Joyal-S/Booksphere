<?php

declare(strict_types=1);

/**
 * admin/reports.php
 *
 * The REVIEW MANAGEMENT console (Phase 7.5) at /admin/reviews - the
 * moderation FOUNDATION of the Reviews module. Every number and row
 * comes from the real review_reports / reviews tables via
 * ReviewService (reportStatistics / reportsByStatus / hiddenReviews);
 * nothing here is seeded or sampled.
 *
 * The page shows:
 *
 *     1. overview cards - total reports, per-status counts, per-
 *        reason distribution and the hidden-review count
 *     2. the queue, filtered by the ?status tab (pending |
 *        reviewed | dismissed | resolved) - each row carries the
 *        report context (reason, description, reporter, dates) and
 *        the review context (title, rating, reviewer, book), with
 *        the actions the status allows: Resolve / Dismiss on open
 *        reports, and Hide review on approved reviews
 *     3. the Hidden reviews tab: every hidden review with its
 *        book context and a Restore action
 *
 * Every action is a POST with a CSRF token (CsrfMiddleware). The
 * full workflow - approvals, appeals, AI analysis - is Phase 7.6;
 * this page is the foundation the brief scopes.
 */

$status = $status ?? 'pending';
$queue  = $queue ?? [];
$stats  = $stats ?? [];
$hidden = $hidden ?? [];

$statusCounts = [];
foreach ($stats['statuses'] ?? [] as $row) {
    $statusCounts[$row['status']] = (int) $row['count'];
}
$reasonCounts = [];
foreach ($stats['reasons'] ?? [] as $row) {
    $reasonCounts[$row['reason']] = (int) $row['count'];
}
$totalReports = (int) ($stats['total'] ?? 0);
$hiddenCount  = (int) ($stats['hidden'] ?? 0);

$tabs = ['pending' => 'Pending', 'reviewed' => 'Reviewed', 'dismissed' => 'Dismissed', 'resolved' => 'Resolved'];
?>
<div class="page-intro">
    <p class="eyebrow">Restricted area</p>
    <h1>Review Management</h1>
    <p class="lead">Community reports and review moderation &mdash; the Phase 7.5 foundation.</p>
</div>

<section class="dash-section" data-animate>
    <?php $section = ['eyebrow' => 'Moderation queue', 'title' => 'Overview', 'icon' => 'fa-flag']; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3 col-xl-2">
            <?php $stat = ['icon' => 'fa-flag', 'label' => 'Total reports', 'value' => $totalReports, 'tone' => 'primary']; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <?php $stat = ['icon' => 'fa-hourglass-half', 'label' => 'Pending', 'value' => $statusCounts['pending'] ?? 0, 'tone' => 'warning']; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <?php $stat = ['icon' => 'fa-magnifying-glass', 'label' => 'Reviewed', 'value' => $statusCounts['reviewed'] ?? 0, 'tone' => 'info']; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <?php $stat = ['icon' => 'fa-circle-check', 'label' => 'Resolved', 'value' => $statusCounts['resolved'] ?? 0, 'tone' => 'success']; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <?php $stat = ['icon' => 'fa-circle-xmark', 'label' => 'Dismissed', 'value' => $statusCounts['dismissed'] ?? 0, 'tone' => 'danger']; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <?php $stat = ['icon' => 'fa-eye-slash', 'label' => 'Hidden reviews', 'value' => $hiddenCount, 'tone' => 'secondary']; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
    </div>

    <?php if ($reasonCounts !== []): ?>
        <div class="card-base p-4 mb-4">
            <h3 class="section-title">Reports by reason</h3>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($reasonCounts as $reason => $count): ?>
                    <span class="badge text-bg-light">
                        <?= e($reason) ?> &middot; <?= (int) $count ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card-base p-4">
        <h3 class="section-title">The queue</h3>
        <ul class="nav nav-pills gap-2 mb-3" role="tablist" aria-label="Report status tabs">
            <?php foreach ($tabs as $key => $label): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $status === $key ? 'active' : '' ?>"
                       href="/admin/reviews?status=<?= e($key) ?>"
                       role="tab" aria-selected="<?= $status === $key ? 'true' : 'false' ?>">
                        <?= e($label) ?>
                        <span class="badge text-bg-<?= $status === $key ? 'light' : 'secondary' ?> ms-1">
                            <?= $statusCounts[$key] ?? 0 ?>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($queue === []): ?>
            <p class="text-muted mb-0">No <?= e(strtolower($tabs[$status] ?? $status)) ?> reports.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Review</th>
                            <th>Reason</th>
                            <th>Reported by</th>
                            <th>Description</th>
                            <th>When</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($queue as $report): ?>
                            <tr>
                                <td>
                                    <a href="/reviews/<?= (int) $report['review_id'] ?>">
                                        <?= e($report['review_title'] !== '' ? $report['review_title'] : 'Review #' . (int) $report['review_id']) ?>
                                    </a>
                                    <div class="text-muted small">
                                        <?= (int) $report['rating'] ?>/5 &middot; by <?= e($report['reviewer_name']) ?>
                                        &middot; <a class="text-decoration-none" href="/books/<?= (int) $report['book_id'] ?>"><?= e($report['book_title']) ?></a>
                                        <?php if ($report['review_status'] === 'hidden'): ?>
                                            <span class="badge text-bg-danger ms-1">hidden</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><span class="badge text-bg-light"><?= e($report['reason']) ?></span></td>
                                <td><?= e($report['reporter_name']) ?></td>
                                <td class="text-muted small">
                                    <?= e($report['description'] !== '' ? $report['description'] : '&mdash;') ?>
                                </td>
                                <td class="text-muted small"><?= e(format_review_date((string) $report['reported_at'])) ?></td>
                                <td class="text-end">
                                    <div class="d-flex gap-2 justify-content-end">
                                        <?php if ($status === 'pending'): ?>
                                            <form method="post" action="/admin/reports/<?= (int) $report['report_id'] ?>/resolve">
                                                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                <button class="btn btn-sm btn-outline-success" type="submit"
                                                        title="Mark this report as resolved">
                                                    <i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i>Resolve
                                                </button>
                                            </form>
                                            <form method="post" action="/admin/reports/<?= (int) $report['report_id'] ?>/dismiss">
                                                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                <button class="btn btn-sm btn-outline-secondary" type="submit"
                                                        title="Dismiss this report as unfounded">
                                                    <i class="fa-solid fa-circle-xmark me-1" aria-hidden="true"></i>Dismiss
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($report['review_status'] !== 'hidden'): ?>
                                            <form method="post" action="/admin/reviews/<?= (int) $report['review_id'] ?>/hide">
                                                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                <button class="btn btn-sm btn-outline-danger" type="submit"
                                                        title="Pull this review from the catalogue">
                                                    <i class="fa-solid fa-eye-slash me-1" aria-hidden="true"></i>Hide
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
        <?php endif; ?>
    </div>

    <div class="card-base p-4 mt-4">
        <h3 class="section-title">Hidden reviews</h3>
        <?php if ($hidden === []): ?>
            <p class="text-muted mb-0">No hidden reviews. Every approved review is live in the catalogue.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Review</th>
                            <th>Book</th>
                            <th>Author</th>
                            <th>Hidden since</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($hidden as $review): ?>
                            <tr>
                                <td>
                                    <a href="/reviews/<?= (int) $review['id'] ?>">
                                        <?= e($review['title'] !== '' ? $review['title'] : 'Review #' . (int) $review['id']) ?>
                                    </a>
                                    <div class="text-muted small"><?= (int) $review['rating'] ?>/5</div>
                                </td>
                                <td><a class="text-decoration-none" href="/books/<?= (int) $review['book_id'] ?>"><?= e($review['book_title']) ?></a></td>
                                <td><?= e($review['user_name']) ?></td>
                                <td class="text-muted small"><?= e(format_review_date((string) $review['updated_at'])) ?></td>
                                <td class="text-end">
                                    <form method="post" action="/admin/reviews/<?= (int) $review['id'] ?>/unhide">
                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                        <button class="btn btn-sm btn-outline-success" type="submit"
                                                title="Restore this review to the catalogue">
                                            <i class="fa-solid fa-eye me-1" aria-hidden="true"></i>Restore
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
