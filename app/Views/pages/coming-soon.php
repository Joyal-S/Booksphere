<?php

declare(strict_types=1);

/**
 * pages/coming-soon.php
 *
 * The shared placeholder page of the main navigation (Browse
 * Books, Categories, Authors, Wishlist, Recommendations, Reviews,
 * Analytics, Settings). These features belong to later phases;
 * this page shows a friendly empty state instead of a 404.
 *
 * Available variables:
 *     $page - ['title', 'icon', 'description'] set by PageController
 */

?>
<div class="page-intro" data-animate>
    <p class="eyebrow">Coming soon</p>
    <h1><?= e($page['title']) ?></h1>
    <p class="lead"><?= e($page['description']) ?></p>
</div>

<div class="card-base" data-animate style="max-width: 560px;">
    <?php $empty = [
        'icon'    => $page['icon'],
        'title'   => $page['title'] . ' is on its way',
        'message' => 'This module is planned for a later phase of the project. Your navigation already knows its place, so nothing is broken.',
        'action'  => ['label' => 'Back to dashboard', 'href' => '/', 'icon' => 'fa-arrow-left'],
    ]; ?>
    <?php require root_path('app/Views/components/empty-state.php'); ?>
</div>
