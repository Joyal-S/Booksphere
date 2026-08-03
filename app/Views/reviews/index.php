<?php

declare(strict_types=1);

/**
 * reviews/index.php
 *
 * The "My Reviews" page: every review of the signed-in user,
 * newest first, with the book it belongs to, its star rating,
 * its title and body, and the Edit / Delete actions (owner-only
 * gates enforced by ReviewPolicy inside the controller). The
 * Delete action uses the shared review confirmation modal
 * (Phase 7.2).
 *
 * Available variables (from ReviewController::index):
 *     $reviews - review rows with 'book_title' attached
 */

$reviews = $reviews ?? [];
?>
<div class="page-intro">
    <p class="eyebrow">Reviews &middot; <?= count($reviews) ?> written</p>
    <h1>My Reviews</h1>
    <p class="lead">The books you rated and reviewed, newest first.</p>
</div>

<?php if ($reviews === []): ?>
    <?php $empty = [
        'icon'    => 'fa-star',
        'title'   => 'No reviews yet',
        'message' => 'Visit any book in the catalogue and write your first review.',
        'action'  => ['label' => 'Browse books', 'href' => '/books'],
    ]; ?>
    <?php require root_path('app/Views/components/empty-state.php'); ?>
<?php else: ?>
    <div class="row g-4">
        <?php foreach ($reviews as $review): ?>
            <div class="col-12 col-md-6 col-xl-4">
                <article class="card-base p-4 h-100 d-flex flex-column">
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                        <a class="fw-semibold text-decoration-none" href="/books/<?= (int) $review['book_id'] ?>">
                            <?= e($review['book_title'] ?? 'Book') ?>
                        </a>
                        <?php if ((int) ($review['is_edited'] ?? 0) === 1): ?>
                            <span class="badge text-bg-light" title="This review was edited after it was published">Edited</span>
                        <?php endif; ?>
                    </div>

                    <?php $ratingInfo = [
                        'rating'  => (float) ($review['rating'] ?? 0),
                        'count'   => null,
                        'compact' => true,
                    ]; ?>
                    <?php require root_path('app/Views/books/components/rating-stars.php'); ?>

                    <h2 class="h6 mt-2 mb-1"><?= e($review['title'] ?? '') ?></h2>
                    <p class="text-body-secondary flex-grow-1 mb-3">
                        <?= e(mb_strlen((string) ($review['review'] ?? '')) > 280
                            ? mb_substr((string) $review['review'], 0, 280) . '&hellip;'
                            : (string) $review['review']) ?>
                    </p>

                    <div class="d-flex align-items-center justify-content-between gap-2">
                        <span class="small text-body-tertiary">
                            <?= e(date('M j, Y', strtotime((string) $review['created_at']))) ?>
                        </span>
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
                    </div>
                </article>
            </div>
        <?php endforeach; ?>
    </div>

    <?php require root_path('app/Views/reviews/partials/_delete-modal.php'); ?>
<?php endif; ?>
