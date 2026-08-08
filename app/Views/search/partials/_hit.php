<?php

declare(strict_types=1);

/**
 * search/partials/_hit.php
 *
 * ONE search result row. The shape is decided here, ONCE, for every
 * scope the search can return (the formatter already gave us a
 * SearchHit with title / subtitle / url / data):
 *
 *     - books       -> the module's own book-card (cover, title,
 *                      authors, rating) linking to the detail page
 *     - authors     -> an author row (avatar, name, "Author")
 *     - categories  -> a category row (tag icon, name, "Category")
 *     - publishers  -> a publisher row (building icon, name)
 *     - reviews     -> a review row (quote icon, the reviewed book's
 *                      title, "Review · ratings_count stars")
 *
 * Every entity's open link ($hit->url) comes from the formatter, so
 * this partial never builds its own URLs.
 *
 * Available variables:
 *     $hit - a SearchHit
 */
?>

<?php if ($hit->entity === 'books'): ?>
    <?php $book = $hit->data; ?>
    <div class="search-hit">
        <?php require root_path('app/Views/books/components/book-card.php'); ?>
    </div>
<?php else: ?>
    <?php $entityLabel = match ($hit->entity) {
        'authors'    => 'Author',
        'categories' => 'Category',
        'publishers' => 'Publisher',
        'reviews'    => 'Review',
        default      => ucfirst($hit->entity),
    }; ?>
    <a class="card-base search-hit search-hit--<?= e($hit->entity) ?>" href="<?= e($hit->url) ?>">
        <span class="search-hit-icon" aria-hidden="true">
            <i class="fa-solid <?= match ($hit->entity) {
                'authors'    => 'fa-user-pen',
                'categories' => 'fa-tags',
                'publishers' => 'fa-building',
                'reviews'    => 'fa-star',
                default      => 'fa-search',
            } ?>"></i>
        </span>
        <span class="search-hit-body">
            <span class="search-hit-title"><?= e($hit->title) ?></span>
            <span class="search-hit-meta"><?= e($hit->subtitle ?? $entityLabel) ?></span>
        </span>
        <span class="search-hit-go" aria-hidden="true"><i class="fa-solid fa-arrow-right"></i></span>
    </a>
<?php endif; ?>