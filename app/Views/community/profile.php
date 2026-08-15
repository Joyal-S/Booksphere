<?php

declare(strict_types=1);

/**
 * community/profile.php
 *
 * Public Community Profile Page (Phase C6-A).
 * Displays a member's public community identity, statistics, discussions, and comments.
 * Strictly public information only — no email, password, or moderation data exposed.
 */

$profileUser  = $profileUser  ?? [];
$stats        = $stats        ?? [];
$posts        = $posts        ?? [];
$comments     = $comments     ?? [];
$postTotal    = $postTotal    ?? 0;
$postPage     = $postPage     ?? 1;
$postPages    = $postPages    ?? 1;
$commentTotal = $commentTotal ?? 0;
$commentPage  = $commentPage  ?? 1;
$commentPages = $commentPages ?? 1;
$currentTab   = $currentTab   ?? 'discussions';
$isOwnProfile = $isOwnProfile ?? false;

$userId      = (int) ($profileUser['id'] ?? 0);
$fullName    = (string) ($profileUser['full_name'] ?? 'Community Member');
$initial     = (string) ($profileUser['initial'] ?? 'M');
$memberSince = (string) ($profileUser['member_since'] ?? 'Member');

$postCount      = (int) ($stats['posts'] ?? 0);
$commentCount   = (int) ($stats['comments'] ?? 0);
$followerCount  = (int) ($stats['followers'] ?? $stats['follower_count'] ?? 0);
$followingCount = (int) ($stats['following'] ?? $stats['following_count'] ?? 0);
$isFollowing    = (bool) ($stats['is_following'] ?? false);
?>
<div class="mb-3">
    <a href="/community" class="text-decoration-none text-muted small">
        <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i> Back to Community Feed
    </a>
</div>

<!-- Community Profile Header Card -->
<section class="card-base p-4 p-md-5 mb-4">
    <div class="d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary fw-bold flex-shrink-0"
                 style="width: 64px; height: 64px; font-size: 1.5rem;">
                <?= e($initial) ?>
            </div>
            <div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <h1 class="h4 mb-0 fw-bold text-dark"><?= e($fullName) ?></h1>
                    <?php if ($isOwnProfile): ?>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                            <i class="fa-solid fa-user-check me-1" aria-hidden="true"></i> Your Community Profile
                        </span>
                    <?php elseif (auth_check()): ?>
                        <?php if ($isFollowing): ?>
                            <form action="/community/user/<?= $userId ?>/unfollow" method="POST" class="d-inline">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                                    <i class="fa-solid fa-user-minus me-1" aria-hidden="true"></i> Unfollow
                                </button>
                            </form>
                        <?php else: ?>
                            <form action="/community/user/<?= $userId ?>/follow" method="POST" class="d-inline">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-primary btn-sm rounded-pill px-3">
                                    <i class="fa-solid fa-user-plus me-1" aria-hidden="true"></i> Follow
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="text-muted small mt-1">
                    <i class="fa-regular fa-calendar me-1" aria-hidden="true"></i> Joined <?= e($memberSince) ?>
                </div>
            </div>
        </div>

        <!-- Activity Stats -->
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <a href="/community/user/<?= $userId ?>/followers" class="text-decoration-none px-3 py-2 rounded bg-body-tertiary border border-subtle text-center">
                <span class="d-block h5 mb-0 fw-bold text-primary"><?= $followerCount ?></span>
                <span class="text-muted small text-uppercase" style="font-size: 0.6875rem; letter-spacing: 0.05em;">Followers</span>
            </a>
            <a href="/community/user/<?= $userId ?>/following" class="text-decoration-none px-3 py-2 rounded bg-body-tertiary border border-subtle text-center">
                <span class="d-block h5 mb-0 fw-bold text-primary"><?= $followingCount ?></span>
                <span class="text-muted small text-uppercase" style="font-size: 0.6875rem; letter-spacing: 0.05em;">Following</span>
            </a>
            <div class="px-3 py-2 rounded bg-body-tertiary border border-subtle text-center">
                <span class="d-block h5 mb-0 fw-bold text-primary"><?= (int) ($reputation['score'] ?? 0) ?></span>
                <span class="text-muted small text-uppercase" style="font-size: 0.6875rem; letter-spacing: 0.05em;">Reputation</span>
            </div>
            <div class="px-3 py-2 rounded bg-body-tertiary border border-subtle text-center">
                <span class="d-block h5 mb-0 fw-bold text-primary"><?= $postCount ?></span>
                <span class="text-muted small text-uppercase" style="font-size: 0.6875rem; letter-spacing: 0.05em;">Discussions</span>
            </div>
            <div class="px-3 py-2 rounded bg-body-tertiary border border-subtle text-center">
                <span class="d-block h5 mb-0 fw-bold text-primary"><?= $commentCount ?></span>
                <span class="text-muted small text-uppercase" style="font-size: 0.6875rem; letter-spacing: 0.05em;">Comments</span>
            </div>
        </div>
    </div>
</section>

<!-- Community Reputation & Badges Section -->
<?php
$repScore  = (int) ($reputation['score'] ?? 0);
$repBadges = $reputation['badges'] ?? [];
?>
<div class="card-base p-4 mb-4">
    <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3 border-bottom pb-3 mb-3">
        <div>
            <div class="text-uppercase text-primary fw-semibold small tracking-wide mb-1" style="font-size: 0.75rem; letter-spacing: 0.05em;">
                <i class="fa-solid fa-medal me-1" aria-hidden="true"></i> Community Reputation
            </div>
            <h2 class="h5 mb-0 fw-bold text-dark">
                <?= number_format($repScore) ?> <span class="text-muted fs-6 fw-normal">reputation points</span>
            </h2>
        </div>
        <div>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle fs-6 px-3 py-2">
                <i class="fa-solid fa-star me-1" aria-hidden="true"></i> Level <?= (int) floor($repScore / 50) + 1 ?> Member
            </span>
        </div>
    </div>

    <h3 class="h6 fw-bold text-muted text-uppercase mb-3" style="font-size: 0.75rem; letter-spacing: 0.05em;">
        Earned Badges (<?= count($repBadges) ?>)
    </h3>

    <?php if (empty($repBadges)): ?>
        <p class="text-muted small mb-0">No community badges earned yet. Participate in discussions to unlock badges!</p>
    <?php else: ?>
        <div class="row g-2">
            <?php foreach ($repBadges as $badge): ?>
                <div class="col-12 col-sm-6 col-md-4">
                    <div class="d-flex align-items-center gap-2 p-2.5 rounded bg-body-tertiary border">
                        <div class="d-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary flex-shrink-0"
                             style="width: 36px; height: 36px; font-size: 1rem;"
                             aria-label="<?= e($badge['name']) ?> badge icon">
                            <i class="fa-solid <?= e($badge['icon'] ?? 'fa-award') ?>" aria-hidden="true"></i>
                        </div>
                        <div>
                            <div class="fw-semibold text-dark small mb-0"><?= e($badge['name']) ?></div>
                            <div class="text-muted" style="font-size: 0.725rem;"><?= e($badge['description']) ?></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Activity Tabs -->
<div class="d-flex align-items-center gap-2 border-bottom mb-4 pb-2">
    <a href="/community/user/<?= $userId ?>?tab=discussions"
       class="btn btn-sm <?= $currentTab === 'discussions' ? 'btn-primary' : 'btn-light border' ?>">
        <i class="fa-solid fa-comments me-1" aria-hidden="true"></i> Discussions (<?= $postTotal ?>)
    </a>
    <a href="/community/user/<?= $userId ?>?tab=comments"
       class="btn btn-sm <?= $currentTab === 'comments' ? 'btn-primary' : 'btn-light border' ?>">
        <i class="fa-solid fa-comment-dots me-1" aria-hidden="true"></i> Comments (<?= $commentTotal ?>)
    </a>
</div>

<!-- Tab Content: DISCUSSIONS -->
<?php if ($currentTab === 'discussions'): ?>
    <?php if (empty($posts)): ?>
        <?php $empty = ['icon' => 'fa-comments', 'title' => 'No Discussions Yet', 'message' => e($fullName) . ' has not started any community discussions yet.']; ?>
        <?php require root_path('app/Views/components/empty-state.php'); ?>
    <?php else: ?>
        <div class="d-flex flex-column gap-3 mb-4">
            <?php foreach ($posts as $post): ?>
                <?php
                $timeAgo = function_exists('format_notification_time')
                    ? format_notification_time($post['created_at'] ?? '')
                    : (string) ($post['created_at'] ?? '');
                $hasBook   = isset($post['book_id']) && (int) $post['book_id'] > 0;
                $bookTitle = (string) ($post['book_title'] ?? 'Linked Book');
                ?>
                <article class="card-base p-4 transition-all">
                    <div class="d-flex align-items-start gap-3">
                        <div class="flex-grow-1 min-w-0">
                            <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                                <span class="text-muted small"><?= e($timeAgo) ?></span>
                            </div>

                            <h2 class="h5 mb-2">
                                <a href="/community/post/<?= (int) $post['id'] ?>" class="text-decoration-none text-dark hover-primary fw-semibold">
                                    <?= e($post['title'] ?? '') ?>
                                </a>
                            </h2>

                            <p class="text-secondary mb-3 text-break" style="line-height: 1.55;">
                                <?= e(mb_strimwidth((string) ($post['body'] ?? ''), 0, 280, '...')) ?>
                            </p>

                            <?php if ($hasBook): ?>
                                <div class="mb-3">
                                    <a href="/books/<?= (int) $post['book_id'] ?>" class="d-inline-flex align-items-center gap-2 px-3 py-1-5 rounded bg-body-tertiary text-decoration-none border text-reset hover-border-primary transition-all">
                                        <i class="fa-solid fa-book text-primary" aria-hidden="true"></i>
                                        <span class="small fw-medium text-truncate" style="max-width: 320px;"><?= e($bookTitle) ?></span>
                                    </a>
                                </div>
                            <?php endif; ?>

                            <div class="d-flex align-items-center gap-4 text-muted small pt-1">
                                <span title="Likes">
                                    <i class="fa-regular fa-heart me-1" aria-hidden="true"></i>
                                    <span><?= (int) ($post['like_count'] ?? 0) ?></span>
                                </span>
                                <span title="Comments">
                                    <i class="fa-regular fa-comment me-1" aria-hidden="true"></i>
                                    <span><?= (int) ($post['comment_count'] ?? 0) ?></span>
                                </span>
                            </div>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <!-- Discussions Pagination -->
        <?php if ($postPages > 1): ?>
            <nav class="d-flex justify-content-center gap-2 mt-4" aria-label="Discussion pages">
                <?php for ($i = 1; $i <= $postPages; $i++): ?>
                    <a href="/community/user/<?= $userId ?>?tab=discussions&page=<?= $i ?>"
                       class="btn btn-sm <?= $i === $postPage ? 'btn-dark' : 'btn-outline-secondary' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>

<!-- Tab Content: COMMENTS -->
<?php else: ?>
    <?php if (empty($comments)): ?>
        <?php $empty = ['icon' => 'fa-comment-dots', 'title' => 'No Comments Yet', 'message' => e($fullName) . ' has not posted any community comments yet.']; ?>
        <?php require root_path('app/Views/components/empty-state.php'); ?>
    <?php else: ?>
        <div class="d-flex flex-column gap-3 mb-4">
            <?php foreach ($comments as $comment): ?>
                <?php
                $commentId  = (int) ($comment['id'] ?? 0);
                $postId     = (int) ($comment['post_id'] ?? 0);
                $postTitle  = (string) ($comment['post_title'] ?? 'Discussion');
                $hasBook    = isset($comment['book_id']) && (int) $comment['book_id'] > 0;
                $bookTitle  = (string) ($comment['book_title'] ?? 'Linked Book');
                $commentTime= function_exists('format_notification_time')
                    ? format_notification_time($comment['created_at'] ?? '')
                    : (string) ($comment['created_at'] ?? '');
                ?>
                <article class="card-base p-4 transition-all border border-subtle">
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                        <div class="small">
                            <span class="text-muted">Commented on</span>
                            <a href="/community/post/<?= $postId ?>" class="fw-semibold text-dark text-decoration-none hover-primary">
                                <?= e($postTitle) ?>
                            </a>
                        </div>
                        <span class="text-muted small" style="font-size: 0.75rem;"><?= e($commentTime) ?></span>
                    </div>

                    <div class="text-secondary small mb-2" style="line-height: 1.55; white-space: pre-wrap;"><?= e($comment['body'] ?? '') ?></div>

                    <?php if ($hasBook): ?>
                        <div class="mt-2">
                            <a href="/books/<?= (int) $comment['book_id'] ?>" class="d-inline-flex align-items-center gap-1-5 px-2-5 py-1 rounded bg-body-tertiary text-decoration-none border text-reset small">
                                <i class="fa-solid fa-book text-primary" aria-hidden="true"></i>
                                <span class="text-truncate" style="max-width: 280px; font-size: 0.8125rem;"><?= e($bookTitle) ?></span>
                            </a>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>

        <!-- Comments Pagination -->
        <?php if ($commentPages > 1): ?>
            <nav class="d-flex justify-content-center gap-2 mt-4" aria-label="Comment pages">
                <?php for ($i = 1; $i <= $commentPages; $i++): ?>
                    <a href="/community/user/<?= $userId ?>?tab=comments&comment_page=<?= $i ?>"
                       class="btn btn-sm <?= $i === $commentPage ? 'btn-dark' : 'btn-outline-secondary' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
