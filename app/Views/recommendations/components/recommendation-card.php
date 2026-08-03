<?php

declare(strict_types=1);

/**
 * recommendations/components/recommendation-card.php
 *
 * The REUSABLE RECOMMENDATION CARD of the Phase 6.4 dashboard.
 *
 * Purpose:
 *     One premium, information-dense card that renders a recommended
 *     book with EVERYTHING the user needs to trust it: the cover,
 *     title, author, categories, rating, the recommendation score,
 *     the explainable reason badge and the "Why this book?" panel.
 *
 * Props (the $rec array a view sets before requiring this file):
 *     'book'         => the book row (id, title, authors_list,
 *                       categories_list, cover_image,
 *                       average_rating, ratings_count)
 *     'score'        => 0-100 hybrid score, or null on shelves that
 *                       carry no score (strategy shelves)
 *     'confidence'   => 'high' | 'medium' | 'low' | null
 *     'reason'       => the explainable "why" (composed by the
 *                       engine - this view only prints it)
 *     'reasonPoints' => optional checklist bullets for the "Why
 *                       this recommendation?" panel (derived from
 *                       the engine's matched factors)
 *     'section'      => optional context label ("New release from
 *                       an author you follow." is the reason; this
 *                       adds the author name line)
 *     'inWishlist'   => whether the book is already saved
 *
 * Reusability:
 *     Every dashboard section (Recommended For You, Because You
 *     Liked, Because You Follow, Trending, Recently Added) renders
 *     this one card, so the design can never drift between shelves.
 *     It depends only on the shared book-cover and category-badge
 *     components and the global app.js behaviour.
 *
 * Accessibility:
 *     - the cover link is skipped for keyboard users (the title
 *       link and the Details button both reach /books/{id})
 *     - the wishlist button is a real form (no-JS friendly) with
 *       aria-pressed and a screen-reader label
 *     - the "Why" button toggles the reason panel with
 *       aria-expanded / aria-controls
 *     - the score chip carries its meaning in visible text
 */

$rec = array_merge([
    'book'         => [],
    'score'        => null,
    'confidence'   => null,
    'reason'       => '',
    'reasonPoints' => [],
    'section'      => '',
    'inWishlist'   => false,
], $rec ?? []);

$book = array_merge([
    'id'             => 0,
    'title'          => '',
    'authors_list'   => '',
    'categories_list'=> '',
    'cover_image'    => null,
    'average_rating' => 0.0,
    'ratings_count'  => 0,
], (array) ($rec['book'] ?? []));

$bookId  = (int) $book['id'];
$isSaved = (bool) $rec['inWishlist'];
$score   = $rec['score'] === null ? null : (int) round((float) $rec['score']);
$tone    = $rec['confidence'] === 'high' ? 'high' : ($rec['confidence'] === 'medium' ? 'medium' : 'low');

// Category pills: the first two categories + a "+N" for the rest.
$categories = array_values(array_filter(array_map('trim', explode(',', (string) $book['categories_list']))));
$shownCategories = array_slice($categories, 0, 2);

?>
<article class="rec-card" aria-labelledby="rec-title-<?= $bookId ?>">
    <a class="rec-card-cover" href="/books/<?= $bookId ?>" tabindex="-1" aria-hidden="true">
        <span class="rec-card-cover-frame">
            <?php $cover = [
                'src'   => $book['cover_image'] ?? '',
                'alt'   => 'Cover of ' . $book['title'],
                'class' => 'rec-cover',
            ]; ?>
            <?php require root_path('app/Views/books/components/book-cover.php'); ?>
        </span>
        <?php if ($score !== null): ?>
            <span class="rec-score rec-score-chip tone-<?= e($tone) ?>">
                <span class="rec-score-num"><?= (int) $score ?>%</span>
                <span class="rec-score-label">match</span>
                <span class="visually-hidden">Score <?= e((string) $score) ?> out of 100, <?= e($rec['confidence']) ?> confidence</span>
            </span>
        <?php endif; ?>
    </a>

    <div class="rec-card-body">
        <h3 class="rec-card-title" id="rec-title-<?= $bookId ?>">
            <a href="/books/<?= $bookId ?>"><?= e($book['title']) ?></a>
        </h3>
        <p class="rec-card-author"><?= e($book['authors_list']) ?></p>

        <?php if ($shownCategories !== []): ?>
            <div class="rec-card-categories" aria-label="Categories">
                <?php foreach ($shownCategories as $categoryName): ?>
                    <?php $categoryInfo = ['name' => $categoryName]; ?>
                    <?php require root_path('app/Views/books/components/category-badge.php'); ?>
                <?php endforeach; ?>
                <?php if (count($categories) > count($shownCategories)): ?>
                    <span class="category-badge category-badge-more">+<?= count($categories) - count($shownCategories) ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="rec-card-meta">
            <span class="rec-rating" title="Average rating">
                <i class="fa-solid fa-star" aria-hidden="true"></i>
                <?= e(number_format((float) $book['average_rating'], 1)) ?>
                <?php if ((int) $book['ratings_count'] > 0): ?>
                    <span class="rec-rating-count">(<?= (int) $book['ratings_count'] ?>)</span>
                <?php endif; ?>
            </span>
        </div>

        <?php if ($rec['reason'] !== ''): ?>
            <p class="rec-reason" data-tooltip="<?= e($rec['reason']) ?>">
                <i class="fa-solid fa-lightbulb" aria-hidden="true"></i>
                <span class="rec-reason-text"><?= e($rec['reason']) ?></span>
            </p>
        <?php endif; ?>

        <div class="rec-card-actions">
            <form method="post" action="/wishlist/toggle" data-wishlist-form>
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="book_id" value="<?= $bookId ?>">
                <button class="rec-wish-btn<?= $isSaved ? ' is-saved' : '' ?>" type="submit"
                        aria-pressed="<?= $isSaved ? 'true' : 'false' ?>"
                        data-wishlist-state="<?= $isSaved ? 'saved' : 'open' ?>"
                        title="<?= $isSaved ? 'Remove from wishlist' : 'Add to wishlist' ?>">
                    <i class="fa-<?= $isSaved ? 'solid' : 'regular' ?> fa-heart" aria-hidden="true"></i>
                    <span class="rec-wish-text"><?= $isSaved ? 'In wishlist' : 'Wishlist' ?></span>
                    <span class="visually-hidden"><?= $isSaved ? 'Remove' : 'Add' ?> <?= e($book['title']) ?> from your wishlist</span>
                </button>
            </form>

            <?php if ($rec['reason'] !== ''): ?>
                <button class="rec-why-btn" type="button" data-reason-toggle
                        aria-expanded="false" aria-controls="rec-why-<?= $bookId ?>">
                    <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                    Why
                </button>
            <?php endif; ?>

            <a class="rec-detail-btn" href="/books/<?= $bookId ?>">
                Details
                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                <span class="visually-hidden">View <?= e($book['title']) ?></span>
            </a>
        </div>

        <?php if ($rec['reason'] !== ''): ?>
            <div class="rec-why-panel" id="rec-why-<?= $bookId ?>" data-reason-panel hidden>
                <p class="rec-why-title">Why this recommendation?</p>
                <?php if ($rec['reasonPoints'] !== []): ?>
                    <ul class="rec-why-list">
                        <?php foreach ($rec['reasonPoints'] as $point): ?>
                            <li><i class="fa-solid fa-check" aria-hidden="true"></i> <?= e($point) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="rec-why-line"><?= e($rec['reason']) ?></p>
                <?php endif; ?>
                <?php if ($rec['section'] !== ''): ?>
                    <p class="rec-why-context"><?= e($rec['section']) ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</article>
