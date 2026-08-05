<?php

declare(strict_types=1);

/**
 * authors/index.php
 *
 * The AUTHOR DIRECTORY (Phase 7.6): every author of the catalogue
 * with the community rating their books earned (ReviewService::
 * authorAverage() aggregation over approved reviews, composed with
 * the author list by AuthorController). Each row links to the
 * author's page.
 */

$authors = $authors ?? [];

?>
<div class="page-intro">
    <p class="eyebrow">Catalogue</p>
    <h1>Authors</h1>
    <p class="lead">Every author in the library, with the rating the community gave their books.</p>
</div>

<?php if ($authors === []): ?>
    <div class="card-base p-4 text-center text-muted">
        No authors in the catalogue yet.
    </div>
<?php else: ?>
    <div class="row g-3 g-xl-4 row-cols-1 row-cols-md-2 row-cols-xl-3">
        <?php foreach ($authors as $author): ?>
            <div class="col">
                <div class="card-base h-100 p-4">
                    <div class="d-flex align-items-start justify-content-between gap-3">
                        <div class="min-w-0">
                            <h2 class="h5 mb-1">
                                <a href="/authors/<?= (int) $author['id'] ?>" class="text-decoration-none stretched-link">
                                    <?= e($author['name']) ?>
                                </a>
                            </h2>
                            <?php $counter = ['count' => (int) $author['count']]; ?>
                            <?php require root_path('app/Views/components/review-counter.php'); ?>
                        </div>
                        <?php if ((int) $author['count'] > 0): ?>
                            <?php $badge = ['rating' => (float) $author['average'], 'size' => 'sm']; ?>
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
