<?php

declare(strict_types=1);

/**
 * search/partials/_hit.php
 *
 * Render ONE search result item row in the Global Search view.
 * Supported entities: books, authors, categories, publishers, reviews.
 */

$entityLabel = match ($hit->entity) {
    'books'      => 'Book',
    'authors'    => 'Author',
    'categories' => 'Category',
    'publishers' => 'Publisher',
    'reviews'    => 'Review',
    default      => ucfirst($hit->entity),
};

$iconClass = match ($hit->entity) {
    'books'      => 'fa-book',
    'authors'    => 'fa-user-pen',
    'categories' => 'fa-tags',
    'publishers' => 'fa-building',
    'reviews'    => 'fa-comment-dots',
    default      => 'fa-magnifying-glass',
};
?>

<?php if ($hit->entity === 'books'): ?>
    <?php $book = $hit->data; ?>
    <a class="card-base p-3 search-hit search-hit--book d-flex align-items-center gap-3 text-decoration-none text-reset hover-elevate" href="<?= e($hit->url) ?>">
        <span class="search-hit-icon flex-shrink-0 d-flex align-items-center justify-content-center rounded-2 bg-primary-subtle text-primary" style="width: 42px; height: 42px;">
            <i class="fa-solid fa-book fa-lg" aria-hidden="true"></i>
        </span>
        <div class="search-hit-body flex-grow-1 min-w-0">
            <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                <span class="search-hit-title fw-semibold text-body h6 mb-0"><?= e($hit->title) ?></span>
                <?php if (!empty($book['average_rating'])): ?>
                    <span class="badge rounded-pill text-bg-warning text-dark small ms-auto">
                        <i class="fa-solid fa-star fa-xs me-1" aria-hidden="true"></i><?= e(format_rating($book['average_rating'])) ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="search-hit-meta text-muted small d-flex align-items-center gap-2 flex-wrap">
                <?php if (!empty($book['authors_list'])): ?>
                    <span><?= e($book['authors_list']) ?></span>
                <?php endif; ?>
                <?php if (!empty($book['category_name'])): ?>
                    <span>&middot;</span>
                    <span><?= e($book['category_name']) ?></span>
                <?php endif; ?>
                <?php if (!empty($book['published_year'])): ?>
                    <span>&middot;</span>
                    <span><?= e((string) $book['published_year']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <span class="search-hit-go flex-shrink-0 text-muted" aria-hidden="true"><i class="fa-solid fa-arrow-right"></i></span>
    </a>
<?php else: ?>
    <a class="card-base p-3 search-hit search-hit--<?= e($hit->entity) ?> d-flex align-items-center gap-3 text-decoration-none text-reset hover-elevate" href="<?= e($hit->url) ?>">
        <span class="search-hit-icon flex-shrink-0 d-flex align-items-center justify-content-center rounded-2 bg-secondary-subtle text-secondary" style="width: 42px; height: 42px;">
            <i class="fa-solid <?= e($iconClass) ?> fa-lg" aria-hidden="true"></i>
        </span>
        <div class="search-hit-body flex-grow-1 min-w-0">
            <span class="search-hit-title fw-semibold text-body h6 d-block mb-1"><?= e($hit->title) ?></span>
            <span class="search-hit-meta text-muted small d-block"><?= e($hit->subtitle ?? $entityLabel) ?></span>
        </div>
        <span class="search-hit-go flex-shrink-0 text-muted" aria-hidden="true"><i class="fa-solid fa-arrow-right"></i></span>
    </a>
<?php endif; ?>