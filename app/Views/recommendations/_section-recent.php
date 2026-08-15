<?php

declare(strict_types=1);

/**
 * recommendations/_section-recent.php
 *
 * Section 5 of the Phase 6.4 dashboard: "Recently added to the
 * library".
 *
 * Purpose:
 *     The freshest arrivals, pulled from the engine's recently
 *     added strategy. This shelf doubles as the "discover what is
 *     new" slot of the dashboard and, like every other shelf, its
 *     cards keep the explainable reason the engine produced.
 *
 * Data ($shelf and $wishlistIds, set by index.php):
 *     'shelf' => ['items' => [...book rows with reason...]]
 *
 * Responsive: 1 column / 2 / 4 across breakpoints.
 */

$shelf = array_merge(['items' => []], $shelf ?? []);
$wishlistIds = $wishlistIds ?? [];

?>
<section class="rec-section" data-reveal>
    <?php $section = [
        'eyebrow' => 'Fresh arrivals',
        'title'   => 'Recently added to the library',
        'icon'    => 'fa-clock',
        'link'    => ['label' => 'All recent', 'href' => '/recommendations/recent'],
    ]; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>

    <?php if ($shelf['items'] === []): ?>
        <div class="card-base">
            <?php $empty = [
                'icon'    => 'fa-clock',
                'title'   => 'The shelf is empty',
                'message' => 'New books land here the moment the library grows. Check back soon.',
                'action'  => ['label' => 'Browse the library', 'href' => '/books', 'icon' => 'fa-book-open'],
            ]; ?>
            <?php require root_path('app/Views/components/empty-state.php'); ?>
        </div>
    <?php else: ?>
        <div class="rec-card-grid rec-card-grid-4" aria-label="Recently added books">
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
