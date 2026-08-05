<?php

declare(strict_types=1);

/**
 * library/statistics.php
 *
 * The LIBRARY STATISTICS page (Phase 8.2): the per-user overview of
 * the personal library, rendered with the shared statistic-card
 * component. Cards: total books, the five shelf counters, favourites,
 * the average progress of the started books and the number of books
 * added during the current calendar month. A "recently finished"
 * strip rounds the page out with the finished shelf's newest titles.
 *
 * Available variables (from LibraryController::statistics):
 *     $counts - total / per-status / favourites counters
 *     $stats  - the libraryStatistics() payload (average_progress,
 *               added_this_month, total, favorites)
 */

$counts = $counts ?? [];
$stats  = $stats ?? [];

$cards = [
    ['icon' => 'fa-book',            'label' => 'Total Books',           'value' => $stats['total'] ?? 0,             'tone' => 'primary', 'trend' => 'whole library'],
    ['icon' => 'fa-bookmark',        'label' => 'Want to Read',          'value' => $counts['want_to_read'] ?? 0,     'tone' => 'info'],
    ['icon' => 'fa-book-open-reader','label' => 'Currently Reading',     'value' => $counts['currently_reading'] ?? 0,'tone' => 'warning'],
    ['icon' => 'fa-circle-check',    'label' => 'Finished',              'value' => $counts['finished'] ?? 0,         'tone' => 'success'],
    ['icon' => 'fa-heart',           'label' => 'Favourite Books',       'value' => $counts['favorites'] ?? 0,        'tone' => 'danger'],
    ['icon' => 'fa-gauge-high',      'label' => 'Average Progress',      'value' => round((float) ($stats['average_progress'] ?? 0), 1), 'tone' => 'primary'],
    ['icon' => 'fa-calendar-plus',   'label' => 'Books Added This Month','value' => $stats['added_this_month'] ?? 0,  'tone' => 'success', 'trend' => 'this month'],
];

$extraCounts = [
    ['icon' => 'fa-pause',           'label' => 'On Hold',               'value' => $counts['on_hold'] ?? 0,          'tone' => 'warning'],
    ['icon' => 'fa-ban',             'label' => 'Dropped',               'value' => $counts['dropped'] ?? 0,          'tone' => 'danger'],
];

?>
<div class="page-intro">
    <p class="eyebrow">Personal Library &middot; At a glance</p>
    <h1>Library Statistics</h1>
    <p class="lead">How your personal reading library looks today.</p>
</div>

<section data-animate>
    <div class="row g-3 g-xl-4 row-cols-2 row-cols-md-3 row-cols-xl-4">
        <?php foreach ($cards as $stat): ?>
            <div class="col"><?php require root_path('app/Views/components/stat-card.php'); ?></div>
        <?php endforeach; ?>
    </div>
</section>

<section class="dash-section" data-animate>
    <div class="row g-3 g-xl-4 row-cols-2 row-cols-md-3 row-cols-xl-4">
        <?php foreach ($extraCounts as $stat): ?>
            <div class="col"><?php require root_path('app/Views/components/stat-card.php'); ?></div>
        <?php endforeach; ?>
        <div class="col">
            <div class="card-base stat-cta h-100">
                <span class="stat-icon tone-info" aria-hidden="true"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
                <div class="stat-value">Manage</div>
                <div class="stat-label">
                    <a href="/library">Open your library</a> or
                    <a href="/books">browse the catalogue</a>.
                </div>
            </div>
        </div>
    </div>
</section>