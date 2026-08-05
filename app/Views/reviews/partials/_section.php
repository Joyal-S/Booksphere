<?php

declare(strict_types=1);

/**
 * reviews/partials/_section.php
 *
 * The complete, SHARED book review section (Phase 7.4) - the
 * professional review interface embedded by the book detail page
 * and the /books/{id}/reviews page, so the two can never drift:
 *
 *     1. the summary header (review-header): title, average, stars
 *        and the truthful "Based on N reviews" line
 *     2. the animated rating distribution bars (shared partial)
 *     3. the write-review entry point (or "already reviewed")
 *     4. the review toolbar: search box (within the book), sort,
 *        per-page select and the filter chips
 *     5. the review cards in a timeline layout with the loading
 *        skeletons and the three empty states
 *     6. the pagination (pager links + per-page select)
 *
 * Available variables (set by the including page):
 *     $book            - the book row
 *     $stats           - ['average' => float, 'count' => int]
 *     $breakdown       - ratingBreakdown() rows (distribution bars)
 *     $myReview        - the signed-in user's review, or null
 *     $canManage       - whether review Edit/Delete actions may show
 *     $reviews         - the review rows of the current page
 *     $toolbar         - the toolbar payload (ReviewListPresenter),
 *                        or null to hide the toolbar entirely
 *     $pagination      - the pagination payload, or null
 *     $reviewSectionTitle - optional custom section title
 */

$stats    = array_merge(['average' => 0.0, 'count' => 0], $stats ?? []);
$reviews  = $reviews ?? [];
$breakdown = $breakdown ?? [];
$myReview = $myReview ?? null;
$canManage = $canManage ?? false;
$toolbar   = $toolbar ?? null;
$pagination = $pagination ?? null;
$reviewSectionTitle = $reviewSectionTitle ?? 'Reviews & Ratings';
?>
<section class="mt-4" aria-label="Reviews and ratings">
    <div class="card-base p-4">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
            <?php $header = [
                'title'   => $reviewSectionTitle,
                'average' => (float) $stats['average'],
                'count'   => (int) $stats['count'],
            ]; ?>
            <?php require root_path('app/Views/components/review-header.php'); ?>

            <?php if ((int) $stats['count'] > 0): ?>
                <a class="btn btn-sm btn-outline-secondary" href="/books/<?= (int) $book['id'] ?>/reviews">
                    View all reviews
                    <i class="fa-solid fa-arrow-right ms-1" aria-hidden="true"></i>
                </a>
            <?php endif; ?>
        </div>

        <?php if ($breakdown !== [] && (int) $stats['count'] > 0): ?>
            <?php require root_path('app/Views/reviews/partials/_rating-distribution.php'); ?>
        <?php endif; ?>

        <div class="mt-3">
            <?php require root_path('app/Views/reviews/partials/_write-section.php'); ?>
        </div>

        <?php if ($toolbar !== null): ?>
            <div class="review-toolbar-wrap mt-4">
                <?php require root_path('app/Views/reviews/partials/_toolbar.php'); ?>
            </div>
        <?php endif; ?>

        <div class="mt-2" data-review-list>
            <?php $skeletons = ['count' => 3]; ?>
            <?php require root_path('app/Views/components/loading-skeleton.php'); ?>

            <?php if ($reviews === []): ?>
                <?php $emptyBase = [
                    'title'   => 'No reviews yet',
                    'message' => 'Be the first reader to review this book.',
                ]; ?>
                <?php require root_path('app/Views/reviews/partials/_empty.php'); ?>
            <?php else: ?>
                <?php require root_path('app/Views/reviews/partials/_list.php'); ?>

                <?php if ($pagination !== null): ?>
                    <div class="mt-4">
                        <?php require root_path('app/Views/components/review-pagination.php'); ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</section>
