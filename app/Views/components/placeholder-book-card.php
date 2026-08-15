<?php

declare(strict_types=1);

/**
 * components/placeholder-book-card.php
 *
 * The PLACEHOLDER BOOK CARD used by the dashboard. It renders a
 * stylised cover (CSS gradient + serif typography, because the
 * dashboard shows showcase data without cover images) and the book
 * metadata. The real catalogue cards live in
 * books/components/book-card.php - the two are deliberately
 * separate because they render different data shapes.
 *
 * Included from a view that sets the $book array first:
 *
 *     $book = [
 *         'title'  => 'The Midnight Library',
 *         'author' => 'Matt Haig',
 *         'year'   => 2020,
 *         'genre'  => 'Fiction',
 *         'rating' => 4.6,
 *         'votes'  => '12.4k',   // optional, rating count
 *         'tag'    => 'Staff Pick', // optional corner badge
 *         'cover'  => 'cover-1',    // CSS gradient key, cover-1..cover-6
 *     ];
 *
 * The whole card is a link to the catalogue page so it always
 * navigates somewhere real, even while books are placeholders.
 */

// Safe defaults keep the card renderable even if a key is missing.
$book = array_merge([
    'title'  => '',
    'author' => '',
    'year'   => '',
    'genre'  => '',
    'rating' => 0.0,
    'votes'  => '',
    'tag'    => '',
    'cover'  => 'cover-1',
], $book ?? []);

?>
<a class="book-card" href="/books" title="View <?= e($book['title']) ?>">
    <div class="book-cover <?= e($book['cover']) ?>">
        <span class="book-cover-genre"><?= e($book['genre']) ?></span>
        <?php if ($book['tag'] !== ''): ?>
            <span class="book-cover-badge"><?= e($book['tag']) ?></span>
        <?php endif; ?>
        <span class="book-cover-title"><?= e($book['title']) ?></span>
        <span class="book-cover-author"><?= e($book['author']) ?></span>
    </div>
    <div class="book-card-body">
        <h3 class="book-card-title"><?= e($book['title']) ?></h3>
        <p class="book-card-author"><?= e($book['author']) ?><?= $book['year'] !== '' ? ' &middot; ' . e((string) $book['year']) : '' ?></p>
        <div class="book-card-meta">
            <span class="book-rating"><i class="fa-solid fa-star" aria-hidden="true"></i> <?= e(format_rating($book['rating'])) ?></span>
            <?php if ($book['votes'] !== ''): ?>
                <span class="book-rating-count"><?= e($book['votes']) ?> ratings</span>
            <?php endif; ?>
        </div>
    </div>
</a>
