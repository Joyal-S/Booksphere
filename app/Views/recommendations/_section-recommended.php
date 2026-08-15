<?php

declare(strict_types=1);

/**
 * recommendations/_section-recommended.php
 *
 * Section 1 of the Phase 6.4 dashboard: "Recommended for you".
 *
 * Purpose:
 *     The hybrid personalization shelf (Phase 6.3): the engine
 *     blends category, author, wishlist, rating, trending and
 *     popularity signals and every card here carries the WHY (the
 *     engine reason), the numeric score and the confidence tone.
 *     The "Why" panel on each card unpacks the matched signals.
 *
 * Data ($shelf and $wishlistIds, set by index.php):
 *     'shelf' => ['items' => [...recommendation items...]]
 *
 *     Every item is a RecommendationResult item (book row plus the
 *     engine's 'reason', 'score', 'confidence' and 'matched'
 *     fields). index.php pre-computes 'reasonPoints' and
 *     'inWishlist' per item before this partial renders the cards.
 *
 * Responsive: 1 column / 3 / 5 across breakpoints.
 */

$shelf = array_merge(['items' => []], $shelf ?? []);
$wishlistIds = $wishlistIds ?? [];

?>
<section class="rec-section" data-reveal>
    <?php $section = [
        'eyebrow' => 'Curated for you',
        'title'   => 'Recommended for you',
        'icon'    => 'fa-wand-magic-sparkles',
    ]; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>

    <?php if (($shelf['updatedAgo'] ?? '') !== ''): ?>
        <p class="rec-freshness">
            <i class="fa-regular fa-clock" aria-hidden="true"></i>
            <?= e($shelf['updatedAgo']) ?>
        </p>
    <?php endif; ?>

    <?php if ($shelf['items'] === []): ?>
        <div class="card-base">
            <?php $empty = [
                'icon'    => 'fa-wand-magic-sparkles',
                'title'   => 'Your shelf is warming up',
                'message' => 'The engine needs a little signal before it can curate for you. Rate a book, write a review or save a title to your wishlist and refresh.',
                'action'  => ['label' => 'Browse the library', 'href' => '/books', 'icon' => 'fa-book-open'],
            ]; ?>
            <?php require root_path('app/Views/components/empty-state.php'); ?>
        </div>
    <?php else: ?>
        <div class="rec-card-grid" aria-label="Recommended for you">
            <?php foreach ($shelf['items'] as $item): ?>
                <?php $rec = [
                    'book'         => $item['book'] ?? $item,
                    'score'        => $item['score'] ?? null,
                    'confidence'   => $item['confidence'] ?? null,
                    'reason'       => $item['reason'] ?? '',
                    'reasonPoints' => $item['reasonPoints'] ?? [],
                    'inWishlist'   => in_array((int) ($item['book']['id'] ?? $item['id'] ?? 0), $wishlistIds, true),
                ]; ?>
                <?php require root_path('app/Views/recommendations/components/recommendation-card.php'); ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
