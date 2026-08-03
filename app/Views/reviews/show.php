<?php

declare(strict_types=1);

/**
 * reviews/show.php
 *
 * The single-review detail page ("View Review", Phase 7.2): the
 * full review with the reviewer's name, its rating, its book link
 * and - for the review's owner or an admin - the Edit action and
 * the Delete confirmation modal.
 *
 * Available variables (from ReviewController::show):
 *     $review    - the review row
 *     $user      - the reviewer's user row (for the name)
 *     $book      - the reviewed book row
 *     $canEdit   - whether the owner-or-admin Edit action renders
 *     $canDelete - whether the owner-or-admin Delete action renders
 */

$isEdited = (int) ($review['is_edited'] ?? 0) === 1;
?>
<div class="page-intro">
    <p class="eyebrow">Reviews &middot; By <?= e($user['full_name'] ?? 'Reader') ?></p>
    <h1><?= e($review['title'] !== '' ? $review['title'] : 'Review') ?></h1>
    <p class="lead">
        on <a href="/books/<?= (int) $review['book_id'] ?>"><?= e($book['title'] ?? 'the book') ?></a>
        &middot; <?= e(date('M j, Y', strtotime((string) $review['created_at']))) ?>
        <?php if ($isEdited): ?>
            &middot; <span class="text-muted">Edited</span>
        <?php endif; ?>
    </p>
</div>

<article class="card-base p-4">
    <?php $ratingInfo = [
        'rating'  => (float) ($review['rating'] ?? 0),
        'count'   => null,
        'compact' => true,
    ]; ?>
    <?php require root_path('app/Views/books/components/rating-stars.php'); ?>

    <p class="mt-3 mb-0"><?= e($review['review'] ?? '') ?></p>

    <?php if ($canEdit || $canDelete): ?>
        <div class="d-flex flex-wrap gap-2 mt-4">
            <?php if ($canEdit): ?>
                <a class="btn btn-primary" href="/reviews/<?= (int) $review['id'] ?>/edit">
                    <i class="fa-solid fa-pen me-1" aria-hidden="true"></i>Edit your review
                </a>
            <?php endif; ?>
            <?php if ($canDelete): ?>
                <button class="btn btn-outline-danger" type="button"
                        data-bs-toggle="modal" data-bs-target="#reviewDeleteModal"
                        data-delete-url="/reviews/<?= (int) $review['id'] ?>/delete"
                        data-delete-title="<?= e($review['title'] !== '' ? $review['title'] : 'Review #' . (int) $review['id']) ?>">
                    <i class="fa-solid fa-trash me-1" aria-hidden="true"></i>Delete your review
                </button>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="mt-3">
        <a class="btn btn-outline-secondary" href="/books/<?= (int) $review['book_id'] ?>">
            <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>Back to the book
        </a>
    </div>
</article>

<?php if ($canDelete): ?>
    <?php require root_path('app/Views/reviews/partials/_delete-modal.php'); ?>
<?php endif; ?>
