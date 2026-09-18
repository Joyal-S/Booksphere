<?php

declare(strict_types=1);

/**
 * books/components/book-card.php
 *
 * A BOOK CARD for a REAL database row, used by the Book module's
 * browse screens. Unlike the dashboard's placeholder card
 * (components/placeholder-book-card.php, which renders stylised
 * gradients), this card renders actual catalogue data: the stored
 * cover image, title, authors, rating and status of one row,
 * linking to the book detail page.
 *
 * Included from a view that sets $book (a row from BookRepository,
 * as returned by BookService::list / Book::paginate / find*):
 *
 *     $book = [
 *         'id'            => 7,
 *         'title'         => 'The Midnight Library',
 *         'subtitle'      => null,
 *         'authors_list'  => 'Matt Haig',
 *         'cover_image'   => 'https://...',
 *         'average_rating'=> 4.6,
 *         'ratings_count' => 12,
 *         'status'        => 'published',
 *     ];
 *
 * Safe defaults keep the card renderable even if a key is missing.
 */

$book = array_merge([
    'id'              => 0,
    'title'           => '',
    'subtitle'        => null,
    'authors_list'    => '',
    'categories_list' => '',
    'cover_image'     => null,
    'average_rating'  => 0.0,
    'ratings_count'   => 0,
    'status'          => 'published',
], $book ?? []);

$bookId     = (int) $book['id'];
$authors    = trim((string) ($book['authors_list'] ?? ''));
$categories = array_values(array_filter(array_map('trim', explode(',', (string) ($book['categories_list'] ?? '')))));
$category   = $categories[0] ?? '';

?>
<article class="book-card-module" aria-labelledby="book-title-<?= $bookId ?>">
    <a class="book-card-module-cover" href="/books/<?= $bookId ?>" tabindex="-1" aria-hidden="true">
        <?php $cover = [
            'src'   => $book['cover_image'] ?? '',
            'alt'   => 'Cover of ' . ($book['title'] ?? ''),
            'class' => 'book-cover',
        ]; ?>
        <?php require root_path('app/Views/books/components/book-cover.php'); ?>
    </a>
    <div class="book-card-module-body">
        <h3 class="book-card-module-title" id="book-title-<?= $bookId ?>">
            <a href="/books/<?= $bookId ?>"><?= e($book['title']) ?></a>
        </h3>
        <?php if ($authors !== ''): ?>
            <p class="book-card-module-author"><?= e($authors) ?></p>
        <?php endif; ?>
        <?php if ($category !== ''): ?>
            <div class="book-card-module-categories">
                <span class="category-badge"><?= e($category) ?></span>
            </div>
        <?php endif; ?>
        <div class="book-card-module-meta">
            <?php $starRating = [
                'rating' => (float) $book['average_rating'],
                'count'  => (int) $book['ratings_count'] > 0 ? (int) $book['ratings_count'] : null,
                'size'   => 'sm',
                'tooltip'=> false,
            ]; ?>
            <?php require root_path('app/Views/components/star-rating.php'); ?>
            <?php if (!empty($book['status'])): ?>
                <span class="status-badge status-<?= e($book['status']) ?>">
                    <?= e(ucfirst($book['status'])) ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="book-card-module-actions">
            <form method="post" action="/wishlist/toggle" data-wishlist-form>
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="book_id" value="<?= $bookId ?>">
                <button class="btn-book-wishlist" type="submit" title="Add to wishlist">
                    <i class="fa-regular fa-heart" aria-hidden="true"></i>
                    <span>Wishlist</span>
                </button>
            </form>
            <a class="btn-book-details" href="/books/<?= $bookId ?>">
                <span>Details</span>
                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
    </div>
</article>