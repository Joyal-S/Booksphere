<?php

declare(strict_types=1);

/**
 * categories/index.php
 *
 * The CATEGORY DIRECTORY (Phase 7.6): every category of the
 * catalogue with the community rating its books earned
 * (ReviewService::categoryAverage() aggregation over approved
 * reviews, composed with the category list by CategoryController).
 * Each row links to the category's page.
 */

$categories = $categories ?? [];

?>
<div class="page-intro">
    <p class="eyebrow">Catalogue</p>
    <h1>Categories</h1>
    <p class="lead">Browse the library by genre, with the rating the community gave each one.</p>
</div>

<?php if ($categories === []): ?>
    <div class="card-base p-4 text-center text-muted">
        No categories in the catalogue yet.
    </div>
<?php else: ?>
    <div class="row g-3 g-xl-4 row-cols-1 row-cols-md-2 row-cols-xl-3">
        <?php foreach ($categories as $category): ?>
            <div class="col">
                <div class="card-base h-100 p-4">
                    <div class="d-flex align-items-start justify-content-between gap-3">
                        <div class="min-w-0">
                            <h2 class="h5 mb-1">
                                <a href="/categories/<?= (int) $category['id'] ?>" class="text-decoration-none stretched-link">
                                    <?= e($category['name']) ?>
                                </a>
                            </h2>
                            <?php $counter = ['count' => (int) $category['count']]; ?>
                            <?php require root_path('app/Views/components/review-counter.php'); ?>
                        </div>
                        <?php if ((int) $category['count'] > 0): ?>
                            <?php $badge = ['rating' => (float) $category['average'], 'size' => 'sm']; ?>
                            <?php require root_path('app/Views/components/rating-badge.php'); ?>
                        <?php else: ?>
                            <span class="text-muted small">Not rated yet</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
