<?php

declare(strict_types=1);

/**
 * recommendations/_section-because-liked.php
 *
 * Section 2 of the Phase 6.4 dashboard: "Because you liked".
 *
 * Purpose:
 *     Up to three anchor books (the ones the user recently viewed
 *     and rated well) each produce a mini-shelf of similar picks
 *     via the engine's getMoreLikeThis(). Every anchor is labelled
 *     with its cover and title so the user always knows WHY each
 *     group exists - the same explainable loop as every other
 *     section, but grouped around a conversation starter.
 *
 * Data ($shelf and $wishlistIds, set by index.php):
 *     'shelf' => ['anchors' => [
 *         ['anchor' => [...book row...], 'items' => [...picks...]],
 *         ...
 *     ]]
 *
 * Responsive: 1 column / 2 / 4 per anchor group.
 */

$shelf = array_merge(['anchors' => []], $shelf ?? []);
$wishlistIds = $wishlistIds ?? [];

?>
<section class="rec-section" data-reveal>
    <?php $section = [
        'eyebrow' => 'Because you liked',
        'title'   => 'Picks born from your recent reads',
        'icon'    => 'fa-heart',
    ]; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>

    <?php if ($shelf['anchors'] === []): ?>
        <div class="card-base">
            <?php $empty = [
                'icon'    => 'fa-heart',
                'title'   => 'No anchors yet',
                'message' => 'Once you open and rate a few books, this section builds little shelves of similar reads around them.',
                'action'  => ['label' => 'Browse the library', 'href' => '/books', 'icon' => 'fa-book-open'],
            ]; ?>
            <?php require root_path('app/Views/components/empty-state.php'); ?>
        </div>
    <?php else: ?>
        <?php foreach ($shelf['anchors'] as $anchor): ?>
            <div class="rec-anchor-block">
                <div class="rec-anchor-head">
                    <span class="rec-anchor-cover">
                        <?php $cover = [
                            'src'   => $anchor['anchor']['cover_image'] ?? '',
                            'alt'   => 'Cover of ' . ($anchor['anchor']['title'] ?? ''),
                            'class' => 'rec-anchor-cover-img',
                        ]; ?>
                        <?php require root_path('app/Views/books/components/book-cover.php'); ?>
                    </span>
                    <div class="rec-anchor-copy">
                        <p class="eyebrow">Anchor read</p>
                        <h3 class="rec-anchor-title">Because you liked &ldquo;<?= e($anchor['anchor']['title'] ?? '') ?>&rdquo;</h3>
                        <p class="rec-anchor-sub"><?= (int) count($anchor['items']) ?> similar picks &mdash; same authors, themes and readers.</p>
                    </div>
                </div>

                <div class="rec-card-grid rec-card-grid-4" aria-label="Similar to <?= e($anchor['anchor']['title'] ?? '') ?>">
                    <?php foreach ($anchor['items'] as $item): ?>
                        <?php $rec = [
                            'book'         => $item['book'] ?? $item,
                            'score'        => $item['score'] ?? null,
                            'confidence'   => $item['confidence'] ?? null,
                            'reason'       => $item['reason'] ?? '',
                            'reasonPoints' => $item['reasonPoints'] ?? [],
                            'section'      => 'Recommended because you liked &ldquo;' . ($anchor['anchor']['title'] ?? '') . '&rdquo;.',
                            'inWishlist'   => in_array((int) ($item['book']['id'] ?? $item['id'] ?? 0), $wishlistIds, true),
                        ]; ?>
                        <?php require root_path('app/Views/recommendations/components/recommendation-card.php'); ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
