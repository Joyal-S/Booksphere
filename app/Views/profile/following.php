<?php

declare(strict_types=1);

/**
 * profile/following.php
 *
 * "Authors I follow" (GET /profile/following, Phase 9.2): the signed-
 * in user's followed authors, newest first, read through the SHARED
 * FollowService instance the author page uses - so the list and the
 * author pages can never disagree about the state.
 *
 * Each card is the same author surface as the directory (name, the
 * "N books by this author" counter) plus the compact Unfollow button
 * - a small form, CSRF-protected and progressively enhanced by
 * follow.js exactly like the author page's control. Every row is the
 * session user's own (the controller never trusts a submitted id).
 *
 * Available variables (from UserController::following):
 *     $authors    - the followed-author rows (author_id, author_name,
 *                   author_book_count, created_at) newest first
 *     $followed   - true (the whole list belongs to the session user)
 *     $total      - the HONEST total count of followed authors (the
 *                   lead text; the rows are the current page)
 *     $pagination - the shared pager (base / page / pages / total /
 *                   perPage / perPages / label / pagerLabel)
 */

$authors  = $authors ?? [];
$followed = (bool) ($followed ?? false);
$count    = (int) ($total ?? count($authors));

?>
<div class="page-intro">
    <p class="eyebrow">My account</p>
    <h1>Authors I follow</h1>
    <p class="lead">
        <?php if ($count === 0): ?>
            You are not following any authors yet.
        <?php else: ?>
            You follow <?= $count ?> author<?= $count === 1 ? '' : 's' ?> - new books and news will find you here.
        <?php endif; ?>
    </p>
</div>

<?php if ($authors === []): ?>
    <div class="card-base p-4 text-center text-muted">
        Head to a book you love and follow its author.
    </div>
<?php else: ?>
    <div class="row g-3 g-xl-4 row-cols-1 row-cols-md-2 row-cols-xl-3">
        <?php foreach ($authors as $author): ?>
            <?php $authorId = (int) ($author['author_id'] ?? $author['id'] ?? 0); ?>
            <div class="col">
                <div class="card-base h-100 p-4 d-flex flex-column">
                    <div class="d-flex align-items-start justify-content-between gap-3">
                        <div class="min-w-0">
                            <h2 class="h5 mb-1">
                                <a href="/authors/<?= $authorId ?>" class="text-decoration-none stretched-link">
                                    <?= e($author['author_name'] ?? 'Unknown author') ?>
                                </a>
                            </h2>
                            <span class="text-muted small">
                                <?= (int) ($author['author_book_count'] ?? 0) ?> book<?= (int) ($author['author_book_count'] ?? 0) === 1 ? '' : 's' ?> by this author
                            </span>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
                        <span class="text-muted small">
                            <i class="fa-solid fa-calendar-check" aria-hidden="true"></i>
                            <?= e(date('M j, Y', strtotime((string) ($author['created_at'] ?? 'now')))) ?>
                        </span>
                        <span class="position-relative" style="z-index: 1;">
                            <?php $follow = [
                                'author_id'  => $authorId,
                                'author'     => (string) ($author['author_name'] ?? ''),
                                'followed'   => $followed,
                                'show_count' => false,
                                'compact'    => true,
                            ]; ?>
                            <?php require root_path('app/Views/components/follow-button.php'); ?>
                        </span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php require root_path('app/Views/components/review-pagination.php'); ?>
<?php endif; ?>