<?php

declare(strict_types=1);

/**
 * reviews/book.php
 *
 * The "Reviews of one book" page (Phase 7.2 + Phase 7.4): the book's
 * rating summary (average + count, aggregated from the approved
 * reviews), the write-review entry point (or the "already reviewed"
 * status) and every approved review in the professional review
 * section - the shared _section partial the book detail page embeds
 * too, with the sort / search / filter toolbar, the pagination and
 * the review cards, so the two pages can never drift apart.
 *
 * Available variables (from ReviewController::bookReviews):
 *     $book       - the book row
 *     $stats      - ['average' => float, 'count' => int]
 *     $breakdown  - ratingBreakdown() rows (distribution bars)
 *     $reviews    - the review rows of the current page
 *     $myReview   - the signed-in user's review of the book, or null
 *     $canManage  - whether review Edit/Delete actions may show
 *     $toolbar    - the Phase 7.4 toolbar payload
 *     $pagination - the Phase 7.4 pagination payload
 */

$stats      = array_merge(['average' => 0.0, 'count' => 0], $stats ?? []);
$reviews    = $reviews ?? [];
$myReview   = $myReview ?? null;
$canManage  = $canManage ?? false;
$breakdown  = $breakdown ?? [];
$toolbar    = $toolbar ?? null;
$pagination = $pagination ?? null;
?>
<div class="page-intro">
    <p class="eyebrow">Reviews &middot; Book #<?= (int) $book['id'] ?></p>
    <h1><?= e($book['title']) ?></h1>
    <p class="lead">
        <?= e(format_rating($stats['average'])) ?> average from
        <?= (int) $stats['count'] ?> review<?= (int) $stats['count'] === 1 ? '' : 's' ?>
    </p>
</div>

<?php require root_path('app/Views/reviews/partials/_section.php'); ?>

<div class="mt-4">
    <a class="btn btn-outline-secondary" href="/books/<?= (int) $book['id'] ?>">
        <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>Back to the book
    </a>
</div>

<?php if ($canManage): ?>
    <?php require root_path('app/Views/reviews/partials/_delete-modal.php'); ?>
<?php endif; ?>

<?php require root_path('app/Views/reviews/partials/_report-modal.php'); ?>
