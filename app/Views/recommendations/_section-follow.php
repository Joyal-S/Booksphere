<?php

declare(strict_types=1);

/**
 * recommendations/_section-follow.php
 *
 * Section 3 of the Phase 6.4 dashboard: "Because you follow".
 *
 * Purpose:
 *     The follow shelf: books by the authors the user's profile
 *     says they follow (from recently viewed and highly rated
 *     reads). The engine's author strategy decides the ordering,
 *     and the card reason names the author, so the "you follow
 *     this author" story is told on every single card.
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
        'eyebrow' => 'Your authors',
        'title'   => 'Because you follow',
        'icon'    => 'fa-user-pen',
    ]; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>

    <?php if ($shelf['items'] === []): ?>
        <div class="card-base">
            <?php $empty = [
                'icon'    => 'fa-user-pen',
                'title'   => 'No authors to follow yet',
                'message' => 'Read and rate a few books and this shelf starts surfacing new releases from the authors you clearly enjoy.',
                'action'  => ['label' => 'Browse the library', 'href' => '/books', 'icon' => 'fa-book-open'],
            ]; ?>
            <?php require root_path('app/Views/components/empty-state.php'); ?>
        </div>
    <?php else: ?>
        <div class="row g-3 g-xl-4 row-cols-1 row-cols-md-2 row-cols-xl-4" aria-label="Because you follow">
            <?php foreach ($shelf['items'] as $item): ?>
                <?php $rec = [
                    'book'         => $item['book'] ?? $item,
                    'score'        => $item['score'] ?? null,
                    'confidence'   => $item['confidence'] ?? null,
                    'reason'       => $item['reason'] ?? '',
                    'reasonPoints' => $item['reasonPoints'] ?? [],
                    'section'      => 'A new release from an author you follow.',
                    'inWishlist'   => in_array((int) ($item['book']['id'] ?? $item['id'] ?? 0), $wishlistIds, true),
                ]; ?>
                <?php require root_path('app/Views/recommendations/components/recommendation-card.php'); ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
