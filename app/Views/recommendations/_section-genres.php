<?php

declare(strict_types=1);

/**
 * recommendations/_section-genres.php
 *
 * Section 6 of the Phase 6.4 dashboard: "Explore new genres".
 *
 * Purpose:
 *     The branching-out slot: four genres that are NOT yet part of
 *     the user's profile but appear in their current recommendation
 *     batch (an explainable bridge - the batch visibly contains
 *     those genres). Each tile counts the books it appears in and
 *     links straight into the real category listing.
 *
 * Data ($shelf, set by index.php):
 *     'shelf' => ['genres' => [
 *         ['name' => 'Fantasy', 'count' => 3, 'href' => '/books?category=5', 'icon' => '...'],
 *         ...
 *     ]]
 *
 * Responsive: 2 columns / 3 / 4 across breakpoints.
 */

$shelf = array_merge(['genres' => []], $shelf ?? []);

?>
<section class="rec-section" data-reveal>
    <?php $section = [
        'eyebrow' => 'Branch out',
        'title'   => 'Explore new genres',
        'icon'    => 'fa-shapes',
        'link'    => ['label' => 'All categories', 'href' => '/books'],
    ]; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>

    <?php if ($shelf['genres'] === []): ?>
        <div class="card-base">
            <?php $empty = [
                'icon'    => 'fa-shapes',
                'title'   => 'Every genre covered',
                'message' => 'You have books in every corner of the catalogue. Your recommendations already span the whole map.',
                'action'  => ['label' => 'Browse the library', 'href' => '/books', 'icon' => 'fa-book-open'],
            ]; ?>
            <?php require root_path('app/Views/components/empty-state.php'); ?>
        </div>
    <?php else: ?>
        <div class="row g-3 g-xl-4 row-cols-2 row-cols-md-3 row-cols-xl-4" aria-label="Explore new genres">
            <?php foreach ($shelf['genres'] as $genre): ?>
                <?php require root_path('app/Views/recommendations/components/genre-card.php'); ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
