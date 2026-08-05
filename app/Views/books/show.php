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
 *     $reviews        - review rows of the current page (empty
 *                       without the ReviewService, e.g. in tests)
 *     $reviewStats    - ['average' => float, 'count' => int]
 *     $reviewBreakdown- ratingBreakdown() rows (distribution bars)
 *     $myReview       - the signed-in user's review of this book
 *     $canManage      - whether review Edit/Delete actions may show
 *     $reviewSection  - whether the Reviews & Ratings section
 *                       renders (the ReviewService is wired)
 *     $toolbar / $pagination - the Phase 7.4 review list payload
 *                       (ReviewListPresenter), or null
 *     $communityStats - the Phase 7.5 community statistics panel
 *                       (communityStats()), or null
 *     $libraryItem    - the signed-in user's library record for this
 *                       book, or null (Phase 8.2 - chooses between
 *                       the Add and the Update library panel)
 *     $libraryStatuses- status key -> display label (Phase 8.2)
 *     $librarySection - whether the library panel renders (the
 *                       LibraryService is wired)
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
                <?php
                // The detail-page stars ALWAYS reflect the real
                // approved reviews (Phase 7.3) - never the seeded
                // sample columns - so the header, the count and the
                // distribution bars below stay consistent.
                $summary = [
                    'average' => (float) ($reviewStats['average'] ?? 0),
                    'count'   => (int) ($reviewStats['count'] ?? 0),
                ];
                $starRating = [
                    'rating' => $summary['average'],
                    'count'  => $summary['count'] > 0 ? $summary['count'] : null,
                    'size'   => 'lg',
                ];
                ?>
                <?php require root_path('app/Views/components/star-rating.php'); ?>
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
                        <strong><?= (int) ($reviewStats['count'] ?? 0) ?></strong>
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
                        <dd><?= e(format_review_date((string) $book['created_at'])) ?></dd>
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

<?php if ($librarySection ?? false): ?>
    <!-- Phase 8.2: the Personal Library panel - "Add to library"
         when the book is not saved yet, the full "Update library
         entry" panel (status, favourite, progress, remove) when it
         is. The library SQL stays in the Library module; this page
         only presents the user's own record. -->
    <?php require root_path('app/Views/library/partials/_book-panel.php'); ?>
<?php endif; ?>

<?php if ($reviewSection): ?>
    <?php
    // The Reviews & Ratings section (Phase 7.2 + Phase 7.3 + Phase
    // 7.4): the shared _section partial renders the summary header
    // (average + stars + "Based on N reviews"), the animated rating
    // distribution bars, the write-review entry point, the review
    // toolbar (search within the book, sort, per-page, filters),
    // the professional review cards with pagination and the three
    // empty states - the SAME markup the /books/{id}/reviews page
    // uses, so the two pages can never drift apart.
    $breakdown = $reviewBreakdown ?? [];
    $stats     = $reviewStats ?? ['average' => 0.0, 'count' => 0];
    ?>

    <?php
    // Phase 7.5: the community statistics panel (total reviews,
    // helpful votes, average rating and the most helpful / newest /
    // highest rated review) sits between the book card and the
    // review section.
    ?>
    <?php require root_path('app/Views/reviews/partials/_community-stats.php'); ?>

    <?php require root_path('app/Views/reviews/partials/_section.php'); ?>

    <?php if ($canManage): ?>
        <?php require root_path('app/Views/reviews/partials/_delete-modal.php'); ?>
    <?php endif; ?>

    <?php require root_path('app/Views/reviews/partials/_report-modal.php'); ?>
<?php endif; ?>

<?php
// Phase 8.5: the book-detail recommendation sections - "Readers also
// enjoyed", "More by these authors", "Similar categories", "Similar
// ratings", "Similar popularity" and the user's personal shelf. Every
// non-empty section renders as a shelf-strip of the same
// recommendation cards the /recommendations dashboard uses; the
// reasons come from the engine, this page only prints them.
$bookRecommendations = $bookRecommendations ?? [];

$bookSectionTitles = [
    'readers_also_enjoyed' => 'Readers also enjoyed',
    'same_author'          => 'More by these authors',
    'same_category'        => 'Similar categories',
    'similar_rating'       => 'Similar ratings',
    'similar_popularity'   => 'Similar popularity',
    'recommended_for_you'  => 'Recommended for you',
];
?>

<?php foreach ($bookSectionTitles as $key => $title): ?>
    <?php if (isset($bookRecommendations[$key]) && $bookRecommendations[$key] !== []): ?>
        <?php $shelf = [
            'eyebrow' => 'More to explore',
            'title'   => $title,
            'icon'    => $key === 'recommended_for_you' ? 'fa-wand-magic-sparkles' : 'fa-book-open',
            'link'    => null,
            'items'   => $bookRecommendations[$key],
            'empty'   => '',
            'columns' => 'row-cols-2 row-cols-md-3 row-cols-xl-4',
        ]; ?>
        <?php require root_path('app/Views/recommendations/components/shelf-strip.php'); ?>
    <?php endif; ?>
<?php endforeach; ?>