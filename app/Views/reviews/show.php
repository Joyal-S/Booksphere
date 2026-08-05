<?php

declare(strict_types=1);

/**
 * reviews/show.php
 *
 * The single-review detail page ("View Review", Phase 7.2 + Phase
 * 7.4): the full review with the reviewer's avatar and name (linked
 * to their public reviews page), the review date, the "Edited"
 * badge, the star rating, the title and the FULL body (the detail
 * page never truncates), the disabled Helpful / placeholder Report
 * buttons (both arrive in Phase 7.5), the verified-reader badge
 * (future-ready) and - for the review's owner or an admin - the
 * Edit action and the Delete confirmation modal.
 *
 * Available variables (from ReviewController::show):
 *     $review    - the review row
 *     $user      - the reviewer's user row (for the name)
 *     $book      - the reviewed book row
 *     $canEdit   - whether the owner-or-admin Edit action renders
 *     $canDelete - whether the owner-or-admin Delete action renders
 */

$isEdited = (int) ($review['is_edited'] ?? 0) === 1;

$name = (string) ($user['full_name'] ?? 'Reader');
?>
<div class="page-intro">
    <p class="eyebrow">Reviews &middot; By <?= e($name) ?></p>
    <h1><?= e($review['title'] !== '' ? $review['title'] : 'Review') ?></h1>
    <p class="lead">
        on <a href="/books/<?= (int) $review['book_id'] ?>"><?= e($book['title'] ?? 'the book') ?></a>
        &middot; <?= e(format_review_date((string) $review['created_at'])) ?>
        <?php if ($isEdited): ?>
            &middot; <span class="text-muted">Edited</span>
        <?php endif; ?>
    </p>
</div>

<article class="card-base p-4">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <?php $avatarName = $name; $avatarHref = '/reviews/user/' . (int) $review['user_id']; ?>
        <?php require root_path('app/Views/reviews/partials/_avatar.php'); ?>
        <div>
            <a class="fw-semibold text-decoration-none" href="/reviews/user/<?= (int) $review['user_id'] ?>">
                <?= e($name) ?>
            </a>
            <div class="d-flex align-items-center gap-2">
                <span class="badge text-bg-success" title="Verified reader review (Phase 7.5)">Verified</span>
                <?php if ($isEdited): ?>
                    <span class="badge text-bg-light" title="This review was edited after it was published">Edited</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php $ratingInfo = [
        'rating'  => (float) ($review['rating'] ?? 0),
        'count'   => null,
        'compact' => true,
    ]; ?>
    <?php require root_path('app/Views/books/components/rating-stars.php'); ?>

    <p class="mt-3 mb-0 review-detail-body"><?= e($review['review'] ?? '') ?></p>

    <div class="d-flex flex-wrap align-items-center gap-2 mt-4">
        <button class="btn btn-sm btn-outline-secondary" type="button" disabled
                title="Helpful votes arrive in Phase 7.5">
            <i class="fa-regular fa-thumbs-up me-1" aria-hidden="true"></i>Helpful
        </button>
        <button class="btn btn-sm btn-outline-secondary" type="button" disabled
                title="Reporting arrives in Phase 7.5">
            <i class="fa-regular fa-flag me-1" aria-hidden="true"></i>Report
        </button>

        <?php if ($canEdit || $canDelete): ?>
            <span class="ms-auto d-flex flex-wrap gap-2">
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
            </span>
        <?php endif; ?>
    </div>

    <div class="mt-3">
        <a class="btn btn-outline-secondary" href="/books/<?= (int) $review['book_id'] ?>">
            <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>Back to the book
        </a>
    </div>
</article>

<?php if ($canDelete): ?>
    <?php require root_path('app/Views/reviews/partials/_delete-modal.php'); ?>
<?php endif; ?>

<?php require root_path('app/Views/reviews/partials/_report-modal.php'); ?>
