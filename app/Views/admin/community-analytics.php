<?php

declare(strict_types=1);

/**
 * admin/community-analytics.php
 *
 * Community Analytics Dashboard View (Phase C8-D).
 * Accessible at /admin/analytics/community.
 * Restricted to administrators via AdminMiddleware.
 */

$range        = $range        ?? '30d';
$validRanges  = $validRanges  ?? ['7d', '30d', '90d', 'all'];
$analytics    = $analytics    ?? [];

$posts           = (int)   ($analytics['posts']           ?? 0);
$comments        = (int)   ($analytics['comments']        ?? 0);
$likes           = (int)   ($analytics['likes']           ?? 0);
$reports         = (int)   ($analytics['reports']         ?? 0);
$activeUsers     = (int)   ($analytics['activeUsers']     ?? 0);
$topBooks        = (array) ($analytics['topBooks']        ?? []);
$topPosts        = (array) ($analytics['topPosts']        ?? []);
$reportsByReason = (array) ($analytics['reportsByReason'] ?? []);
$moderationStats = (array) ($analytics['moderationStats'] ?? []);
$dailyActivity   = (array) ($analytics['dailyActivity']   ?? []);

$rangeLabels = [
    '7d'  => 'Last 7 Days',
    '30d' => 'Last 30 Days',
    '90d' => 'Last 90 Days',
    'all' => 'All Time',
];

$activeRangeLabel = $rangeLabels[$range] ?? 'Last 30 Days';

// Find maximum daily post count for scaling activity visualization
$maxDailyPosts = 1;
foreach ($dailyActivity as $day) {
    $c = (int) ($day['post_count'] ?? 0);
    if ($c > $maxDailyPosts) {
        $maxDailyPosts = $c;
    }
}
?>
<div class="page-intro mb-4">
    <p class="eyebrow text-uppercase fw-semibold text-primary mb-1" style="letter-spacing: 0.05em; font-size: 0.8125rem;">ADMINISTRATIVE ANALYTICS</p>
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
        <div>
            <h1 class="h3 fw-bold text-dark mb-1">
                <i class="fa-solid fa-chart-line text-primary me-2" aria-hidden="true"></i>Community Analytics
            </h1>
            <p class="text-muted small mb-0">Overview of community growth, member engagement, discussion topics, and moderation activity.</p>
        </div>

        <!-- Time Range Selector -->
        <div class="d-flex align-items-center gap-1.5 bg-body-tertiary p-1.5 rounded-pill border" role="group" aria-label="Time Range Options">
            <?php foreach ($validRanges as $r): ?>
                <?php $isActive = ($range === $r); ?>
                <a href="/admin/analytics/community?range=<?= urlencode($r) ?>"
                   class="btn btn-sm <?= $isActive ? 'btn-primary shadow-sm fw-bold' : 'btn-ghost text-muted' ?> rounded-pill px-3 py-1"
                   <?= $isActive ? 'aria-current="page"' : '' ?>>
                    <?= e($rangeLabels[$r] ?? strtoupper($r)) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Flash Notifications -->
<?php if (session()->getFlash('error') !== null): ?>
    <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
        <i class="fa-solid fa-circle-exclamation me-2" aria-hidden="true"></i><?= e(session()->getFlash('error')) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- 1. KPI Overview Cards -->
<section class="dash-section mb-4" data-animate>
    <?php $section = ['eyebrow' => 'Platform Signals', 'title' => 'Community Key Metrics (' . e($activeRangeLabel) . ')', 'icon' => 'fa-gauge-high']; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>

    <div class="row g-3 g-xl-4 mb-4">
        <div class="col-6 col-md-4 col-xl-2.4">
            <?php $stat = ['icon' => 'fa-comments', 'label' => 'Total Posts', 'value' => number_format($posts), 'tone' => 'primary']; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
        <div class="col-6 col-md-4 col-xl-2.4">
            <?php $stat = ['icon' => 'fa-comment-dots', 'label' => 'Total Comments', 'value' => number_format($comments), 'tone' => 'info']; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
        <div class="col-6 col-md-4 col-xl-2.4">
            <?php $stat = ['icon' => 'fa-heart', 'label' => 'Total Likes', 'value' => number_format($likes), 'tone' => 'danger']; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
        <div class="col-6 col-md-4 col-xl-2.4">
            <?php $stat = ['icon' => 'fa-user-group', 'label' => 'Active Members', 'value' => number_format($activeUsers), 'tone' => 'success']; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
        <div class="col-6 col-md-4 col-xl-2.4">
            <?php $stat = ['icon' => 'fa-flag', 'label' => 'Total Reports', 'value' => number_format($reports), 'tone' => 'warning']; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
    </div>
</section>

<!-- 2. Daily Post Activity Trend -->
<section class="card-base p-4 mb-4" data-animate>
    <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-3">
        <div>
            <h2 class="h5 fw-bold mb-0">
                <i class="fa-solid fa-chart-bar text-primary me-2" aria-hidden="true"></i>Discussion Post Activity Trend
            </h2>
            <span class="text-muted small">Daily discussion posts created over recent intervals</span>
        </div>
        <span class="badge bg-secondary-subtle text-secondary small"><?= e($activeRangeLabel) ?></span>
    </div>

    <?php if (empty($dailyActivity)): ?>
        <div class="text-center py-4 text-muted">
            <i class="fa-solid fa-chart-simple fs-2 text-tertiary mb-2" aria-hidden="true"></i>
            <p class="mb-0 small">No community posts created during this time range.</p>
        </div>
    <?php else: ?>
        <div class="d-flex align-items-end gap-2 pt-3 pb-2 px-2 overflow-x-auto" style="min-height: 160px;" aria-label="Discussion Activity Chart">
            <?php foreach ($dailyActivity as $day): ?>
                <?php
                $dStr   = (string) ($day['date_str'] ?? '');
                $dCount = (int) ($day['post_count'] ?? 0);
                $heightPct = max(8, min(100, (int) round(($dCount / $maxDailyPosts) * 100)));
                ?>
                <div class="d-flex flex-column align-items-center flex-grow-1" style="min-width: 36px;">
                    <span class="badge bg-primary-subtle text-primary mb-1 small fw-bold" style="font-size: 0.7rem;"><?= $dCount ?></span>
                    <div class="w-100 bg-primary rounded-top opacity-75 hover-opacity-100 transition"
                         style="height: <?= $heightPct ?>px;"
                         title="<?= e($dStr) ?>: <?= $dCount ?> post(s)"></div>
                    <span class="text-muted mt-2 text-nowrap" style="font-size: 0.6875rem; font-family: monospace;">
                        <?= date('M d', strtotime($dStr)) ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- 3. Two Column: Top Discussed Books & Top Engaged Discussions -->
<div class="row g-4 mb-4">
    <!-- Left Column: Top Books -->
    <div class="col-lg-6">
        <div class="card-base p-4 h-100">
            <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-3">
                <h2 class="h5 fw-bold mb-0">
                    <i class="fa-solid fa-book-bookmark text-primary me-2" aria-hidden="true"></i>Top Discussed Books
                </h2>
                <span class="badge bg-primary-subtle text-primary small">Top 5</span>
            </div>

            <?php if (empty($topBooks)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="fa-solid fa-book-open fs-2 text-tertiary mb-2" aria-hidden="true"></i>
                    <p class="mb-0 small">No book discussion activity recorded for this period.</p>
                </div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($topBooks as $idx => $b): ?>
                        <?php
                        $bId      = (int) $b['id'];
                        $bTitle   = (string) $b['title'];
                        $discs    = (int) ($b['discussion_count'] ?? 0);
                        $comms    = (int) ($b['comment_count'] ?? 0);
                        $bLikes   = (int) ($b['like_count'] ?? 0);
                        ?>
                        <div class="list-group-item d-flex align-items-center justify-content-between py-3 px-0 border-subtle">
                            <div class="d-flex align-items-center gap-3">
                                <span class="badge bg-secondary-subtle text-secondary fw-bold rounded-circle" style="width:28px; height:28px; display:inline-flex; align-items:center; justify-content:center;">
                                    <?= $idx + 1 ?>
                                </span>
                                <div>
                                    <a href="/community/book/<?= $bId ?>" target="_blank" class="fw-bold text-dark text-decoration-none hover-primary">
                                        <?= e($bTitle) ?>
                                    </a>
                                    <div class="text-muted small">
                                        <i class="fa-solid fa-comments me-1" aria-hidden="true"></i> <?= $discs ?> discussion<?= $discs === 1 ? '' : 's' ?>
                                        <span class="mx-1">•</span>
                                        <i class="fa-solid fa-comment me-1" aria-hidden="true"></i> <?= $comms ?> comment<?= $comms === 1 ? '' : 's' ?>
                                    </div>
                                </div>
                            </div>
                            <a href="/community/book/<?= $bId ?>" class="btn btn-outline-primary btn-sm rounded-pill">
                                View Hub
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right Column: Top Discussions -->
    <div class="col-lg-6">
        <div class="card-base p-4 h-100">
            <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-3">
                <h2 class="h5 fw-bold mb-0">
                    <i class="fa-solid fa-fire text-danger me-2" aria-hidden="true"></i>Top Engaged Discussions
                </h2>
                <span class="badge bg-danger-subtle text-danger small">Top 5</span>
            </div>

            <?php if (empty($topPosts)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="fa-solid fa-comments fs-2 text-tertiary mb-2" aria-hidden="true"></i>
                    <p class="mb-0 small">No active discussions found for this period.</p>
                </div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($topPosts as $p): ?>
                        <?php
                        $pId     = (int) $p['id'];
                        $pTitle  = (string) $p['title'];
                        $author  = (string) ($p['author_name'] ?? 'Member');
                        $bookTag = (string) ($p['book_title'] ?? '');
                        $pLikes  = (int) ($p['like_count'] ?? 0);
                        $pComms  = (int) ($p['comment_count'] ?? 0);
                        ?>
                        <div class="list-group-item py-3 px-0 border-subtle">
                            <div class="d-flex align-items-start justify-content-between gap-2">
                                <div>
                                    <a href="/community/post/<?= $pId ?>" target="_blank" class="fw-bold text-dark text-decoration-none hover-primary d-block mb-1">
                                        <?= e($pTitle) ?>
                                    </a>
                                    <div class="text-muted small">
                                        By <strong><?= e($author) ?></strong>
                                        <?php if ($bookTag !== ''): ?>
                                            <span class="mx-1">•</span> <i class="fa-solid fa-book me-1" aria-hidden="true"></i> <?= e($bookTag) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-2 flex-shrink-0">
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                                        <i class="fa-solid fa-heart me-1" aria-hidden="true"></i> <?= $pLikes ?>
                                    </span>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                        <i class="fa-solid fa-comment me-1" aria-hidden="true"></i> <?= $pComms ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 4. Moderation Overview & Report Reasons -->
<section class="card-base p-4 mb-4" data-animate>
    <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-3">
        <div>
            <h2 class="h5 fw-bold mb-0">
                <i class="fa-solid fa-shield-halved text-warning me-2" aria-hidden="true"></i>Moderation Analytics
            </h2>
            <span class="text-muted small">Community report status counts and reason distributions</span>
        </div>
        <a href="/admin/community/reports" class="btn btn-outline-secondary btn-sm">
            Open Moderation Queue <i class="fa-solid fa-arrow-right ms-1" aria-hidden="true"></i>
        </a>
    </div>

    <div class="row g-4">
        <!-- Moderation Status Cards -->
        <div class="col-md-6">
            <h3 class="h6 fw-bold text-uppercase text-muted mb-3" style="font-size: 0.75rem; letter-spacing: 0.05em;">Reports by Status</h3>
            <div class="row g-2">
                <div class="col-6">
                    <div class="p-3 rounded bg-warning-subtle border border-warning-subtle text-center">
                        <span class="d-block h4 mb-0 fw-bold text-warning-emphasis"><?= (int) ($moderationStats['pending'] ?? 0) ?></span>
                        <span class="text-muted small text-uppercase" style="font-size: 0.6875rem;">Pending</span>
                    </div>
                </div>
                <div class="col-6">
                    <div class="p-3 rounded bg-info-subtle border border-info-subtle text-center">
                        <span class="d-block h4 mb-0 fw-bold text-info-emphasis"><?= (int) ($moderationStats['reviewed'] ?? 0) ?></span>
                        <span class="text-muted small text-uppercase" style="font-size: 0.6875rem;">Under Review</span>
                    </div>
                </div>
                <div class="col-6">
                    <div class="p-3 rounded bg-success-subtle border border-success-subtle text-center">
                        <span class="d-block h4 mb-0 fw-bold text-success-emphasis"><?= (int) ($moderationStats['resolved'] ?? 0) ?></span>
                        <span class="text-muted small text-uppercase" style="font-size: 0.6875rem;">Resolved</span>
                    </div>
                </div>
                <div class="col-6">
                    <div class="p-3 rounded bg-secondary-subtle border border-secondary-subtle text-center">
                        <span class="d-block h4 mb-0 fw-bold text-secondary-emphasis"><?= (int) ($moderationStats['dismissed'] ?? 0) ?></span>
                        <span class="text-muted small text-uppercase" style="font-size: 0.6875rem;">Dismissed</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Reports by Reason -->
        <div class="col-md-6">
            <h3 class="h6 fw-bold text-uppercase text-muted mb-3" style="font-size: 0.75rem; letter-spacing: 0.05em;">Reports by Reason</h3>
            <?php if (empty($reportsByReason)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="fa-solid fa-check-circle fs-2 text-success mb-2" aria-hidden="true"></i>
                    <p class="mb-0 small">No reports submitted during this period.</p>
                </div>
            <?php else: ?>
                <div class="d-flex flex-column gap-2">
                    <?php foreach ($reportsByReason as $rRow): ?>
                        <?php
                        $reasonLabel = (string) ($rRow['reason'] ?? 'Other');
                        $rCnt        = (int) ($rRow['count'] ?? 0);
                        $rPct        = $reports > 0 ? (int) round(($rCnt / $reports) * 100) : 0;
                        ?>
                        <div>
                            <div class="d-flex align-items-center justify-content-between small mb-1">
                                <span class="fw-semibold text-dark"><?= e($reasonLabel) ?></span>
                                <span class="text-muted"><?= $rCnt ?> (<?= $rPct ?>%)</span>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar bg-warning" role="progressbar" style="width: <?= $rPct ?>%;" aria-valuenow="<?= $rPct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
