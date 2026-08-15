<?php

declare(strict_types=1);

/**
 * recommendations/_section-trending.php
 *
 * Section 4 of the Phase 6.4 dashboard: "Trending near your
 * interests".
 *
 * Purpose:
 *     The community pulse: books gaining momentum across the
 *     library (recent activity-weighted momentum from the engine's
 *     trending strategy), re-ordered so titles that share a
 *     category or author with the user's profile float to the top.
 *     The personalization angle is visible in the card reasons,
 *     which say when a title overlaps the user's tastes.
 *
 * Data ($shelf and $wishlistIds, set by index.php):
 *     'shelf' => ['items' => [...book rows with reason...]]
 *
 * Responsive: 1 column / 3 / 5 across breakpoints.
 */

$shelf = array_merge(['items' => []], $shelf ?? []);
$wishlistIds = $wishlistIds ?? [];

?>
<section class="rec-section" data-reveal>
    <?php $section = [
        'eyebrow' => 'The community pulse',
        'title'   => 'Trending near your interests',
        'icon'    => 'fa-chart-line',
        'link'    => ['label' => 'All trending', 'href' => '/recommendations/trending'],
    ]; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>

    <?php if ($shelf['items'] === []): ?>
        <div class="card-base">
            <?php $empty = [
                'icon'    => 'fa-chart-line',
                'title'   => 'Nothing trending yet',
                'message' => 'Momentum builds from real reader activity. When the community starts moving, this shelf fills up.',
                'action'  => ['label' => 'Browse the library', 'href' => '/books', 'icon' => 'fa-book-open'],
            ]; ?>
            <?php require root_path('app/Views/components/empty-state.php'); ?>
        </div>
    <?php else: ?>
        <div class="rec-card-grid" aria-label="Trending near your interests">
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
