<?php

declare(strict_types=1);

/**
 * components/review-card.php
 *
 * The reusable compact REVIEW CARD: avatar with initials, reviewer
 * name, star rating, a short excerpt and the book it was written
 * for. Used by the "Recent Reviews" dashboard section.
 *
 * Included from a view that sets the $review array first:
 *
 *     $review = [
 *         'name'     => 'Riya Sharma',
 *         'initials' => 'RS',
 *         'tone'     => 'avatar-1', // avatar-1..avatar-6 gradient keys
 *         'rating'   => 5,
 *         'text'     => 'Beautifully written...',
 *         'book'     => 'The Midnight Library',
 *         'time'     => '2 days ago',
 *     ];
 */

$review = array_merge([
    'name'     => '',
    'initials' => '',
    'tone'     => 'avatar-1',
    'rating'   => 5,
    'text'     => '',
    'book'     => '',
    'time'     => '',
], $review ?? []);

?>
<article class="review-card">
    <div class="review-card-head">
        <span class="avatar <?= e($review['tone']) ?>" aria-hidden="true"><?= e($review['initials'] !== '' ? $review['initials'] : '?') ?></span>
        <div class="review-card-who">
            <h3 class="review-card-name"><?= e($review['name']) ?></h3>
            <div class="star-row" role="img" aria-label="<?= e((string) $review['rating']) ?> out of 5 stars">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <i class="fa-solid fa-star<?= $i <= (int) $review['rating'] ? ' is-filled' : '' ?>" aria-hidden="true"></i>
                <?php endfor; ?>
            </div>
        </div>
        <span class="review-card-time"><?= e($review['time']) ?></span>
    </div>
    <p class="review-card-text">&ldquo;<?= e($review['text']) ?>&rdquo;</p>
    <p class="review-card-book"><i class="fa-solid fa-book-open" aria-hidden="true"></i> <?= e($review['book']) ?></p>
</article>
