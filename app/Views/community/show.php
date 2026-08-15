<?php

declare(strict_types=1);

/**
 * community/show.php
 *
 * The COMMUNITY POST DETAIL page (Phase C4-C):
 * Displays full details of a single post, related book reference card,
 * like/unlike engagement controls, and full comment thread with creation, edit, and deletion capabilities.
 */

$post        = $post        ?? [];
$comments    = $comments    ?? [];
$canEdit     = $canEdit     ?? false;
$canDelete   = $canDelete   ?? false;
$hasLiked    = $hasLiked    ?? false;
$actorId     = $actorId     ?? 0;
$bookDetails = $bookDetails ?? null;

$postId       = (int) ($post['id'] ?? 0);
$authorName   = (string) ($post['author_name'] ?? 'Anonymous Reader');
$initial      = mb_strtoupper(mb_substr($authorName, 0, 1));
$timeAgo      = function_exists('format_notification_time')
    ? format_notification_time($post['created_at'] ?? '')
    : (string) ($post['created_at'] ?? '');
$hasBook      = isset($post['book_id']) && (int) $post['book_id'] > 0;
$bookTitle    = (string) ($post['book_title'] ?? 'Linked Book');
$isPostAuthor = $actorId > 0 && (int) ($post['user_id'] ?? 0) === $actorId;

$reportReasons = [
    'Spam',
    'Harassment',
    'Offensive Content',
    'False Information',
    'Duplicate',
    'Other',
];

?>
<div class="mb-3">
    <a href="/community" class="text-decoration-none text-muted small">
        <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i> Back to Community Feed
    </a>
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

<!-- Main Post Card -->
<article class="card-base p-4 p-md-5 mb-4">
    <!-- Author Header & Post Actions -->
    <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
        <div class="d-flex align-items-center gap-3">
            <a href="/community/user/<?= (int) ($post['user_id'] ?? 0) ?>" class="text-decoration-none" title="View posts by <?= e($authorName) ?>">
                <div class="d-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary fw-bold" style="width: 48px; height: 48px; font-size: 1.125rem;">
                    <?= e($initial) ?>
                </div>
            </a>
            <div>
                <?php $authorRep = (new \BookSphere\App\Models\CommunityReputation())->getUserReputation((int) ($post['user_id'] ?? 0)); ?>
                <h2 class="h6 mb-0 text-dark fw-bold d-flex align-items-center gap-1.5 flex-wrap">
                    <a href="/community/user/<?= (int) ($post['user_id'] ?? 0) ?>" class="text-decoration-none text-dark hover-primary">
                        <?= e($authorName) ?>
                    </a>
                    <?php if (!empty($authorRep['primary_badge'])): ?>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size: 0.6875rem;">
                            <i class="fa-solid <?= e($authorRep['primary_badge']['icon']) ?> me-1" aria-hidden="true"></i><?= e($authorRep['primary_badge']['name']) ?>
                        </span>
                    <?php endif; ?>
                </h2>
                <span class="text-muted small"><?= e($timeAgo) ?></span>
            </div>
        </div>

        <?php if ($canEdit || $canDelete): ?>
            <div class="d-flex align-items-center gap-2">
                <?php if ($canEdit): ?>
                    <a href="/community/post/<?= $postId ?>/edit" class="btn btn-sm btn-outline-secondary">
                        <i class="fa-solid fa-pen me-1" aria-hidden="true"></i> Edit
                    </a>
                <?php endif; ?>
                <?php if ($canDelete): ?>
                    <form action="/community/posts/<?= $postId ?>/delete" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this discussion?');">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-outline-danger">
                            <i class="fa-solid fa-trash me-1" aria-hidden="true"></i> Delete
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Title -->
    <h1 class="display-6 fw-bold mb-3"><?= e($post['title'] ?? '') ?></h1>

    <!-- Optional Compact Related Book Card -->
    <?php if ($hasBook): ?>
        <div class="mb-4 p-3 rounded bg-body-tertiary border border-subtle d-inline-flex align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center rounded bg-primary-subtle text-primary p-2" style="width: 40px; height: 50px;">
                <i class="fa-solid fa-book fa-lg" aria-hidden="true"></i>
            </div>
            <div>
                <div class="text-uppercase text-muted fw-bold small" style="font-size: 0.6875rem; letter-spacing: 0.05em;">RELATED BOOK</div>
                <div class="fw-bold text-dark text-truncate" style="max-width: 300px;"><?= e($bookTitle) ?></div>
                <?php if ($bookDetails && !empty($bookDetails['author'])): ?>
                    <div class="text-muted small"><?= e($bookDetails['author']) ?></div>
                <?php endif; ?>
            </div>
            <a href="/books/<?= (int) $post['book_id'] ?>" class="btn btn-sm btn-outline-primary ms-2">View Book</a>
        </div>
    <?php endif; ?>

    <!-- Post Body Content -->
    <div class="post-body text-secondary mb-4" style="font-size: 1.0625rem; line-height: 1.7; white-space: pre-wrap;"><?= e($post['body'] ?? '') ?></div>

    <!-- Engagement Controls & Counts -->
    <div class="d-flex align-items-center justify-content-between pt-3 border-top">
        <div class="d-flex align-items-center gap-3">
            <!-- Like / Unlike Button -->
            <?php if ($actorId > 0 && !$isPostAuthor): ?>
                <?php if ($hasLiked): ?>
                    <form action="/community/posts/<?= $postId ?>/unlike" method="POST" class="d-inline">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-outline-danger">
                            <i class="fa-solid fa-heart me-1" aria-hidden="true"></i> Liked
                        </button>
                    </form>
                <?php else: ?>
                    <form action="/community/posts/<?= $postId ?>/like" method="POST" class="d-inline">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                            <i class="fa-regular fa-heart me-1" aria-hidden="true"></i> Like
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <span class="text-muted small">
                <i class="fa-regular fa-heart me-1" aria-hidden="true"></i> <?= (int) ($post['like_count'] ?? 0) ?> Likes
            </span>
            <span class="text-muted small">
                <i class="fa-regular fa-comment me-1" aria-hidden="true"></i> <?= count($comments) ?> Comments
            </span>
        </div>

        <!-- Report Post Button (authenticated, non-author only) -->
        <?php if ($actorId > 0 && !$isPostAuthor): ?>
            <button type="button"
                    class="btn btn-sm btn-link text-muted text-decoration-none p-0 small"
                    data-bs-toggle="modal"
                    data-bs-target="#report-post-modal-<?= $postId ?>"
                    title="Report this discussion">
                <i class="fa-regular fa-flag me-1" aria-hidden="true"></i> Report
            </button>
        <?php endif; ?>
    </div>
</article>

<!-- Comments Section -->
<div class="card-base p-4 p-md-5">
    <div class="d-flex align-items-center justify-content-between border-bottom pb-3 mb-4">
        <h2 class="h6 text-uppercase fw-bold text-muted mb-0" style="letter-spacing: 0.05em; font-size: 0.75rem;">
            COMMENTS · <?= count($comments) ?>
        </h2>
    </div>

    <!-- Comment Creation Form / Sign-in Prompt -->
    <?php if ($actorId > 0): ?>
        <form action="/community/posts/<?= $postId ?>/comments" method="POST" class="mb-4">
            <?= csrf_field() ?>
            <div class="mb-2">
                <label for="comment_body" class="visually-hidden">Write a comment</label>
                <textarea id="comment_body"
                          name="body"
                          class="form-control"
                          rows="3"
                          placeholder="Write a comment..."
                          required
                          minlength="1"
                          maxlength="2000"></textarea>
            </div>
            <div class="d-flex align-items-center justify-content-between">
                <span class="text-muted small">Max 2,000 characters</span>
                <button type="submit" class="btn btn-primary btn-sm px-4">
                    <i class="fa-solid fa-paper-plane me-1" aria-hidden="true"></i> Post Comment
                </button>
            </div>
        </form>
    <?php else: ?>
        <div class="alert alert-light border mb-4 text-center text-muted small py-3">
            Please <a href="/login" class="fw-semibold text-primary text-decoration-none">sign in</a> to join the discussion and post comments.
        </div>
    <?php endif; ?>

    <!-- Comment List -->
    <?php if (empty($comments)): ?>
        <div class="text-center py-4 text-muted small">
            <i class="fa-regular fa-comments fa-2x mb-2 d-block opacity-50" aria-hidden="true"></i>
            No comments yet on this discussion. Be the first to share your thoughts!
        </div>
    <?php else: ?>
        <div class="d-flex flex-column gap-3">
            <?php foreach ($comments as $comment): ?>
                <?php
                $commentId     = (int) ($comment['id'] ?? 0);
                $commentAuthor = (string) ($comment['author_name'] ?? 'Reader');
                $commentInit   = mb_strtoupper(mb_substr($commentAuthor, 0, 1));
                $commentTime   = function_exists('format_notification_time')
                    ? format_notification_time($comment['created_at'] ?? '')
                    : (string) ($comment['created_at'] ?? '');
                $isCommentOwner = $actorId > 0 && (int) ($comment['user_id'] ?? 0) === $actorId;
                ?>
                <div class="p-3 rounded bg-body-tertiary border border-subtle">
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                        <div class="d-flex align-items-center gap-2">
                            <div class="d-flex align-items-center justify-content-center rounded-circle bg-secondary-subtle text-secondary fw-bold small" style="width: 32px; height: 32px; font-size: 0.8125rem;">
                                <?= e($commentInit) ?>
                            </div>
                            <div>
                                <span class="fw-semibold small text-dark d-block leading-none"><?= e($commentAuthor) ?></span>
                                <span class="text-muted small" style="font-size: 0.71875rem;"><?= e($commentTime) ?></span>
                            </div>
                        </div>

                        <!-- Comment Actions -->
                        <?php if ($isCommentOwner): ?>
                            <div class="d-flex align-items-center gap-2">
                                <button type="button" class="btn btn-link btn-sm text-muted p-0 text-decoration-none small"
                                        onclick="document.getElementById('comment-body-<?= $commentId ?>').classList.add('d-none'); document.getElementById('edit-comment-<?= $commentId ?>').classList.remove('d-none');">
                                    Edit
                                </button>
                                <span class="text-muted small">·</span>
                                <form action="/community/comments/<?= $commentId ?>/delete" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this comment?');">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-link btn-sm text-danger p-0 text-decoration-none small">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        <?php elseif ($actorId > 0): ?>
                            <!-- Report Comment (non-owner authenticated users) -->
                            <button type="button"
                                    class="btn btn-link btn-sm text-muted p-0 text-decoration-none small"
                                    data-bs-toggle="modal"
                                    data-bs-target="#report-comment-modal-<?= $commentId ?>"
                                    title="Report this comment">
                                <i class="fa-regular fa-flag me-1" aria-hidden="true"></i> Report
                            </button>
                        <?php endif; ?>
                    </div>

                    <!-- Comment Text Display -->
                    <div id="comment-body-<?= $commentId ?>" class="text-secondary small" style="line-height: 1.55; white-space: pre-wrap;"><?= e($comment['body'] ?? '') ?></div>

                    <!-- Inline Comment Edit Form (hidden by default) -->
                    <?php if ($isCommentOwner): ?>
                        <div id="edit-comment-<?= $commentId ?>" class="d-none mt-2">
                            <form action="/community/comments/<?= $commentId ?>/edit" method="POST">
                                <?= csrf_field() ?>
                                <input type="hidden" name="post_id" value="<?= $postId ?>">
                                <textarea name="body" class="form-control form-control-sm mb-2" rows="2" required minlength="1" maxlength="2000"><?= e($comment['body'] ?? '') ?></textarea>
                                <div class="d-flex justify-content-end gap-2">
                                    <button type="button" class="btn btn-sm btn-light border"
                                            onclick="document.getElementById('edit-comment-<?= $commentId ?>').classList.add('d-none'); document.getElementById('comment-body-<?= $commentId ?>').classList.remove('d-none');">
                                        Cancel
                                    </button>
                                    <button type="submit" class="btn btn-sm btn-primary">Save Changes</button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ================================================================
     REPORT POST MODAL
     Only rendered when user is authenticated and is not the author.
================================================================ -->
<?php if ($actorId > 0 && !$isPostAuthor): ?>
<div class="modal fade" id="report-post-modal-<?= $postId ?>" tabindex="-1"
     aria-labelledby="report-post-label-<?= $postId ?>" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form action="/community/posts/<?= $postId ?>/report" method="POST" class="modal-content">
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title" id="report-post-label-<?= $postId ?>">
                    <i class="fa-regular fa-flag me-2" aria-hidden="true"></i> Report Discussion
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">Select the reason that best describes why this discussion violates our community guidelines.</p>
                <div class="mb-3">
                    <?php foreach ($reportReasons as $reason): ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input"
                                   type="radio"
                                   name="reason"
                                   id="reason-post-<?= $postId ?>-<?= e(str_replace(' ', '-', strtolower($reason))) ?>"
                                   value="<?= e($reason) ?>"
                                   required
                                   <?= $reason === 'Spam' ? 'checked' : '' ?>>
                            <label class="form-check-label"
                                   for="reason-post-<?= $postId ?>-<?= e(str_replace(' ', '-', strtolower($reason))) ?>">
                                <?= e($reason) ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="mb-1">
                    <label for="report-post-desc-<?= $postId ?>" class="form-label small fw-semibold">Additional details <span class="text-muted fw-normal">(optional)</span></label>
                    <textarea id="report-post-desc-<?= $postId ?>"
                              name="description"
                              class="form-control form-control-sm"
                              rows="3"
                              maxlength="500"
                              placeholder="Briefly describe the issue..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-sm btn-danger">
                    <i class="fa-solid fa-flag me-1" aria-hidden="true"></i> Submit Report
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ================================================================
     REPORT COMMENT MODALS
     One modal per comment — only rendered for non-owners.
================================================================ -->
<?php if ($actorId > 0): ?>
    <?php foreach ($comments as $comment): ?>
        <?php
        $cId        = (int) ($comment['id'] ?? 0);
        $cOwner     = $actorId > 0 && (int) ($comment['user_id'] ?? 0) === $actorId;
        ?>
        <?php if (!$cOwner): ?>
        <div class="modal fade" id="report-comment-modal-<?= $cId ?>" tabindex="-1"
             aria-labelledby="report-comment-label-<?= $cId ?>" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form action="/community/comments/<?= $cId ?>/report" method="POST" class="modal-content">
                    <?= csrf_field() ?>
                    <input type="hidden" name="post_id" value="<?= $postId ?>">
                    <div class="modal-header">
                        <h5 class="modal-title" id="report-comment-label-<?= $cId ?>">
                            <i class="fa-regular fa-flag me-2" aria-hidden="true"></i> Report Comment
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small mb-3">Select the reason that best describes why this comment violates our community guidelines.</p>
                        <div class="mb-3">
                            <?php foreach ($reportReasons as $reason): ?>
                                <div class="form-check mb-2">
                                    <input class="form-check-input"
                                           type="radio"
                                           name="reason"
                                           id="reason-comment-<?= $cId ?>-<?= e(str_replace(' ', '-', strtolower($reason))) ?>"
                                           value="<?= e($reason) ?>"
                                           required
                                           <?= $reason === 'Spam' ? 'checked' : '' ?>>
                                    <label class="form-check-label"
                                           for="reason-comment-<?= $cId ?>-<?= e(str_replace(' ', '-', strtolower($reason))) ?>">
                                        <?= e($reason) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mb-1">
                            <label for="report-comment-desc-<?= $cId ?>" class="form-label small fw-semibold">Additional details <span class="text-muted fw-normal">(optional)</span></label>
                            <textarea id="report-comment-desc-<?= $cId ?>"
                                      name="description"
                                      class="form-control form-control-sm"
                                      rows="3"
                                      maxlength="500"
                                      placeholder="Briefly describe the issue..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-light border" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-danger">
                            <i class="fa-solid fa-flag me-1" aria-hidden="true"></i> Submit Report
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>
