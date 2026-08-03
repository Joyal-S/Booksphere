<?php

declare(strict_types=1);

/**
 * reviews/partials/_list.php
 *
 * The shared APPROVED REVIEWS list of the book-facing pages (the
 * book detail section and the /books/{id}/reviews page). One
 * markup, two pages - the pages can never drift apart.
 *
 * Available variables (set by the including page):
 *     $reviews   - approved review rows ('user_name', 'rating',
 *                  'title', 'review', 'is_edited', 'created_at')
 *     $canManage - whether the signed-in actor may manage AT LEAST
 *                  one review here (owner of their own row, or an
 *                  admin who may manage any row). Default false.
 *
 * The Edit / Delete actions render per row, ONLY for rows the
 * actor may manage (their own, or any row for an admin), so a
 * visitor never sees controls they cannot use.
 */

$reviews   = $reviews ?? [];
$canManage = $canManage ?? false;
$actorId   = auth()?->id();
?>
<?php if ($reviews === []): ?>
    <?php $empty = [
        'icon'    => 'fa-comment-slash',
        'title'   => 'No reviews yet',
        'message' => 'Be the first reader to review this book.',
    ]; ?>
    <?php require root_path('app/Views/components/empty-state.php'); ?>
<?php else: ?>
    <div class="row g-4">
        <?php foreach ($reviews as $review): ?>
            <?php
            $mine = $canManage
                && $actorId !== null
                && ((int) ($review['user_id'] ?? 0) === $actorId || auth_is_admin());
            ?>
            <div class="col-12">
                <article class="card-base p-4">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                        <div class="d-flex align-items-center gap-2">
                            <span class="fw-semibold"><?= e($review['user_name'] ?? 'Reader') ?></span>
                            <?php if ((int) ($review['is_edited'] ?? 0) === 1): ?>
                                <span class="badge text-bg-light" title="This review was edited">Edited</span>
                            <?php endif; ?>
                        </div>
                        <span class="small text-body-tertiary">
                            <?= e(date('M j, Y', strtotime((string) $review['created_at']))) ?>
                        </span>
                    </div>

                    <?php $ratingInfo = [
                        'rating'  => (float) ($review['rating'] ?? 0),
                        'count'   => null,
                        'compact' => true,
                    ]; ?>
                    <?php require root_path('app/Views/books/components/rating-stars.php'); ?>

                    <h2 class="h6 mt-2 mb-1"><?= e($review['title'] ?? '') ?></h2>
                    <p class="mb-3"><?= e($review['review'] ?? '') ?></p>

                    <?php if ($mine): ?>
                        <div class="d-flex gap-2">
                            <a class="btn btn-sm btn-outline-primary" href="/reviews/<?= (int) $review['id'] ?>/edit">
                                <i class="fa-solid fa-pen me-1" aria-hidden="true"></i>Edit
                            </a>
                            <button class="btn btn-sm btn-outline-danger" type="button"
                                    data-bs-toggle="modal" data-bs-target="#reviewDeleteModal"
                                    data-delete-url="/reviews/<?= (int) $review['id'] ?>/delete"
                                    data-delete-title="<?= e($review['title'] !== '' ? $review['title'] : 'Review #' . (int) $review['id']) ?>">
                                <i class="fa-solid fa-trash me-1" aria-hidden="true"></i>Delete
                            </button>
                        </div>
                    <?php endif; ?>
                </article>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
