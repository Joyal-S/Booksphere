<?php

declare(strict_types=1);

/**
 * books/show.php
 *
 * The book detail page: a large cover on the left and, on the
 * right, a statistics strip (rating + votes), the full metadata,
 * the description, and the author names. Categories render as
 * reusable badges; the rating uses the reusable rating-stars
 * component. Edit and delete actions sit at the bottom.
 *
 * Phase 7.2: below the card, the Reviews & Ratings section - the
 * write-review entry point, the signed-in user's "already
 * reviewed" status and the approved review list (shared partials
 * with the /books/{id}/reviews page).
 *
 * Available variables (from BookController::show):
 *     $book        - the book row, with 'authors' and 'categories'
 *     $statuses    - status key -> label
 *     $isAdmin     - whether the edit/delete actions are shown
 *     $reviews        - approved review rows (empty without the
 *                       ReviewService, e.g. in older tests)
 *     $reviewStats    - ['average' => float, 'count' => int]
 *     $myReview       - the signed-in user's review of this book
 *     $canManage      - whether review Edit/Delete actions may show
 *     $reviewSection  - whether the Reviews & Ratings section
 *                       renders (the ReviewService is wired)
 */

$authors    = $book['authors'] ?? [];
$categories = $book['categories'] ?? [];
$reviews    = $reviews ?? [];
$reviewStats = array_merge(['average' => 0.0, 'count' => 0], $reviewStats ?? []);
$myReview   = $myReview ?? null;
$canManage  = $canManage ?? false;
$reviewSection = $reviewSection ?? false;
?>
<div class="page-intro">
    <p class="eyebrow">Catalogue &middot; Book #<?= (int) $book['id'] ?></p>
    <h1><?= e($book['title']) ?></h1>
    <?php if (!empty($book['subtitle'])): ?>
        <p class="lead"><?= e($book['subtitle']) ?></p>
    <?php endif; ?>
</div>

<div class="card-base p-4">
    <div class="row g-4">
        <div class="col-12 col-md-4 col-xl-3">
            <div class="book-detail-cover-wrap">
                <?php $cover = [
                    'src'   => $book['cover_image'] ?? '',
                    'alt'   => 'Cover of ' . ($book['title'] ?? ''),
                    'class' => 'book-detail-cover',
                ]; ?>
                <?php require root_path('app/Views/books/components/book-cover.php'); ?>
            </div>
        </div>

        <div class="col-12 col-md-8 col-xl-9">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-4">
                <span class="status-badge status-<?= e($book['status']) ?>">
                    <?= e($statuses[$book['status']] ?? $book['status']) ?>
                </span>
                <?php $ratingInfo = [
                    'rating' => $book['average_rating'] ?? 0,
                    'count'  => (int) ($book['ratings_count'] ?? 0),
                ]; ?>
                <?php require root_path('app/Views/books/components/rating-stars.php'); ?>
            </div>

            <!-- Statistics strip: rating, votes, pages, year -->
            <div class="book-stats" aria-label="Book statistics">
                <div class="book-stat">
                    <i class="fa-solid fa-star" aria-hidden="true"></i>
                    <div>
                        <strong><?= e(number_format((float) ($book['average_rating'] ?? 0), 1)) ?></strong>
                        <span>Average rating</span>
                    </div>
                </div>
                <div class="book-stat">
                    <i class="fa-solid fa-users" aria-hidden="true"></i>
                    <div>
                        <strong><?= (int) ($book['ratings_count'] ?? 0) ?></strong>
                        <span>Ratings</span>
                    </div>
                </div>
                <?php if ($book['page_count'] !== null): ?>
                    <div class="book-stat">
                        <i class="fa-solid fa-file-lines" aria-hidden="true"></i>
                        <div>
                            <strong><?= (int) $book['page_count'] ?></strong>
                            <span>Pages</span>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($book['published_year'] !== null): ?>
                    <div class="book-stat">
                        <i class="fa-solid fa-calendar" aria-hidden="true"></i>
                        <div>
                            <strong><?= (int) $book['published_year'] ?></strong>
                            <span>First published</span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <dl class="book-meta">
                <?php if ($authors !== []): ?>
                    <div class="book-meta-item">
                        <dt><?= count($authors) === 1 ? 'Author' : 'Authors' ?></dt>
                        <dd><?= e(implode(', ', array_column($authors, 'name'))) ?></dd>
                    </div>
                <?php endif; ?>
                <?php if ($categories !== []): ?>
                    <div class="book-meta-item">
                        <dt>Categories</dt>
                        <dd class="book-meta-badges">
                            <?php foreach ($categories as $category): ?>
                                <?php $categoryInfo = ['name' => $category['name']]; ?>
                                <?php require root_path('app/Views/books/components/category-badge.php'); ?>
                            <?php endforeach; ?>
                        </dd>
                    </div>
                <?php endif; ?>
                <?php if (!empty($book['publisher'])): ?>
                    <div class="book-meta-item">
                        <dt>Publisher</dt>
                        <dd><?= e($book['publisher']) ?></dd>
                    </div>
                <?php endif; ?>
                <?php if (!empty($book['language'])): ?>
                    <div class="book-meta-item">
                        <dt>Language</dt>
                        <dd><?= e(strtoupper($book['language'])) ?></dd>
                    </div>
                <?php endif; ?>
                <?php if (!empty($book['isbn'])): ?>
                    <div class="book-meta-item">
                        <dt>ISBN</dt>
                        <dd><?= e($book['isbn']) ?></dd>
                    </div>
                <?php endif; ?>
                <?php if (!empty($book['created_at'])): ?>
                    <div class="book-meta-item">
                        <dt>Added</dt>
                        <dd><?= e(date('M j, Y', strtotime($book['created_at']))) ?></dd>
                    </div>
                <?php endif; ?>
            </dl>

            <?php if (!empty($book['description'])): ?>
                <div class="book-description">
                    <h2 class="section-title">About this book</h2>
                    <p><?= e($book['description']) ?></p>
                </div>
            <?php endif; ?>

            <div class="d-flex flex-wrap gap-2 mt-4">
                <?php if ($isAdmin): ?>
                    <a class="btn btn-primary" href="/books/<?= (int) $book['id'] ?>/edit">
                        <i class="fa-solid fa-pen me-1" aria-hidden="true"></i>Edit book
                    </a>
                    <button class="btn btn-outline-danger" type="button"
                            data-bs-toggle="modal" data-bs-target="#deleteModal"
                            data-delete-url="/books/<?= (int) $book['id'] ?>/delete"
                            data-delete-title="<?= e($book['title']) ?>"
                            data-delete-cover="<?= e($book['cover_image'] ?? '') ?>">
                        <i class="fa-solid fa-trash me-1" aria-hidden="true"></i>Delete book
                    </button>
                <?php endif; ?>
                <a class="btn btn-outline-secondary" href="/books">
                    <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>Back to catalogue
                </a>
            </div>
        </div>
    </div>
</div>

<?php if ($isAdmin): ?>
    <?php require root_path('app/Views/books/partials/_delete-modal.php'); ?>
<?php endif; ?>

<?php if ($reviewSection): ?>
    <?php
    // The Reviews & Ratings section (Phase 7.2): the write-review
    // entry point (or the "already reviewed" status), the rating
    // summary and every approved review, newest first.
    $ratingInfo = [
        'rating' => (float) $reviewStats['average'],
        'count'  => (int) $reviewStats['count'],
    ];
    ?>
    <section class="mt-4" aria-label="Reviews and ratings">
        <div class="card-base p-4">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                <h2 class="section-title mb-0">Reviews &amp; Ratings</h2>
                <?php require root_path('app/Views/books/components/rating-stars.php'); ?>
            </div>

            <?php require root_path('app/Views/reviews/partials/_write-section.php'); ?>

            <div class="mt-4">
                <?php require root_path('app/Views/reviews/partials/_list.php'); ?>
            </div>
        </div>
    </section>

    <?php if ($canManage): ?>
        <?php require root_path('app/Views/reviews/partials/_delete-modal.php'); ?>
    <?php endif; ?>
<?php endif; ?>