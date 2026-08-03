<?php

declare(strict_types=1);

/**
 * reviews/partials/_write-section.php
 *
 * The write-review block of the book-facing pages (Phase 7.2): the
 * entry point of the Create-Review workflow.
 *
 *     - The signed-in user has NOT reviewed the book  -> a "Write
 *       Review" button that reveals the shared review form
 *       (reviews/_form.php, POST /books/{id}/reviews) in a
 *       Bootstrap collapse.
 *     - The user HAS reviewed the book -> the "You have already
 *       reviewed this book." message with links to their review
 *       and the edit form (the one-review-per-book business rule
 *       shown before any submit).
 *
 * Available variables (set by the including page):
 *     $book     - the book row
 *     $myReview - the signed-in user's review of the book, or null
 */

$myReview = $myReview ?? null;
?>
<?php if ($myReview === null): ?>
    <button class="btn btn-primary" type="button" data-bs-toggle="collapse"
            data-bs-target="#writeReviewCollapse" aria-expanded="false"
            aria-controls="writeReviewCollapse">
        <i class="fa-solid fa-pen me-1" aria-hidden="true"></i>Write Review
    </button>

    <div class="collapse mt-3" id="writeReviewCollapse">
        <?php
        $action     = '/books/' . (int) $book['id'] . '/reviews';
        $submitLabel = 'Submit Review';
        $backHref    = '/books/' . (int) $book['id'];
        $backLabel   = 'Back to the book';
        ?>
        <?php require root_path('app/Views/reviews/_form.php'); ?>
    </div>
<?php else: ?>
    <div class="alert alert-info d-flex flex-wrap align-items-center gap-2 mb-0" role="alert">
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
        <span class="flex-grow-1">You have already reviewed this book.</span>
        <a class="btn btn-sm btn-outline-primary" href="/reviews/<?= (int) $myReview['id'] ?>">
            View your review
        </a>
        <a class="btn btn-sm btn-outline-secondary" href="/reviews/<?= (int) $myReview['id'] ?>/edit">
            Edit your review
        </a>
    </div>
<?php endif; ?>
