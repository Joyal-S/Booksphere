<?php

declare(strict_types=1);

/**
 * reviews/book.php
 *
 * The "Reviews of one book" page: the book's rating summary
 * (average + count, from the denormalized books columns kept in
 * step by ReviewService), the write-review entry point (or the
 * "already reviewed" status) and every approved review, newest
 * first. The list and the write block are the SHARED partials the
 * book detail page embeds too, so the two pages can never drift.
 *
 * Available variables (from ReviewController::bookReviews):
 *     $book      - the book row
 *     $stats     - ['average' => float, 'count' => int]
 *     $reviews   - approved review rows with 'user_name' attached
 *     $myReview  - the signed-in user's review of the book, or null
 *     $canManage - whether review Edit/Delete actions may show
 */

$stats      = array_merge(['average' => 0.0, 'count' => 0], $stats ?? []);
$reviews    = $reviews ?? [];
$myReview   = $myReview ?? null;
$canManage  = $canManage ?? false;
?>
<div class="page-intro">
    <p class="eyebrow">Reviews &middot; Book #<?= (int) $book['id'] ?></p>
    <h1><?= e($book['title']) ?></h1>
    <p class="lead">
        <?= e(number_format((float) $stats['average'], 1)) ?> average from
        <?= (int) $stats['count'] ?> review<?= (int) $stats['count'] === 1 ? '' : 's' ?>
    </p>
</div>

<?php $ratingInfo = [
    'rating' => (float) $stats['average'],
    'count'  => (int) $stats['count'],
]; ?>
<div class="card-base p-4 mb-4">
    <?php require root_path('app/Views/books/components/rating-stars.php'); ?>
    <div class="mt-3">
        <?php require root_path('app/Views/reviews/partials/_write-section.php'); ?>
    </div>
</div>

<?php require root_path('app/Views/reviews/partials/_list.php'); ?>

<div class="mt-4">
    <a class="btn btn-outline-secondary" href="/books/<?= (int) $book['id'] ?>">
        <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>Back to the book
    </a>
</div>

<?php if ($canManage): ?>
    <?php require root_path('app/Views/reviews/partials/_delete-modal.php'); ?>
<?php endif; ?>
