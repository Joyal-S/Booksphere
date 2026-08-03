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
    'id'             => 0,
    'title'          => '',
    'authors_list'   => '',
    'cover_image'    => null,
    'average_rating' => 0.0,
    'ratings_count'  => 0,
    'status'         => 'published',
], $book ?? []);

?>
<a class="book-card-module" href="/books/<?= (int) $book['id'] ?>" title="View <?= e($book['title']) ?>">
    <span class="book-card-module-cover">
        <?php $cover = [
            'src' => $book['cover_image'] ?? '',
            'alt' => 'Cover of ' . ($book['title'] ?? ''),
        ]; ?>
        <?php require root_path('app/Views/books/components/book-cover.php'); ?>
    </span>
    <span class="book-card-module-body">
        <span class="book-card-module-title"><?= e($book['title']) ?></span>
        <span class="book-card-module-meta">
            <span class="book-rating"><i class="fa-solid fa-star" aria-hidden="true"></i>
                <?= e(number_format((float) $book['average_rating'], 1)) ?></span>
            <?php if ((int) $book['ratings_count'] > 0): ?>
                <span class="book-rating-count">(<?= (int) $book['ratings_count'] ?> ratings)</span>
            <?php endif; ?>
            <?php if (!empty($book['status'])): ?>
                <span class="status-badge status-<?= e($book['status']) ?>">
                    <?= e(ucfirst($book['status'])) ?>
                </span>
            <?php endif; ?>
        </span>
    </span>
</a>