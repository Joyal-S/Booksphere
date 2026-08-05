<?php

declare(strict_types=1);

/**
 * library/index.php
 *
 * The PREMIUM "My Library" dashboard (Phase 8.3) - the personal
 * reading library of the signed-in user. Structure, top to bottom:
 *
 *     1. Header      - the greeting ("Good morning, Riya"), the day,
 *                      and three at-a-glance chips: the reading
 *                      streak, the books in the library and the
 *                      reading progress
 *     2. Statistics  - six statistic cards (total / currently
 *                      reading / finished / favourites / average
 *                      progress / added this month)
 *     3. Quick actions - Browse Books, Continue Reading, View
 *                      Recommendations, Import Books (admin only)
 *     4. Continue Reading - the resume shelf (real, read through the
 *                      shared LibraryService; refreshable via
 *                      /library/continue-reading)
 *     5. Filter bar  - the search box (title / description /
 *                      publisher / language / author / category),
 *                      the status / category / author / rating
 *                      selects, the favourite / recency toggles, the
 *                      sort dropdown (incl. Most Reviewed / Most
 *                      Recommended) and the grid / list view switch
 *                      (a GET form - the no-JS path; library.js turns
 *                      every control into a fetch that swaps the
 *                      results region)
 *     6. Collections - the Phase 8.4 Smart Collections rail: All +
 *                      the five shelves + Favourites, each with its
 *                      book count / average rating / last updated
 *     7. Book grid   - the active-filter chips, the grid of library
 *                      cards (or the list of rows) with the bulk
 *                      selection checkboxes, the empty states and
 *                      the pagination - the shared _grid fragment
 *                      every rendering path uses
 *     8. Bulk bar    - the Phase 8.4 bulk actions (Select all /
 *                      Move To / Favourite / Un-favourite / Remove
 *                      with confirmation) - one form collecting the
 *                      checked cards of the region above
 *     9. Reading summary - favourite genre / author, average rating
 *                      given, the streak - plus a statistics link
 *    10. Remove modals - the single-record delete modal and the
 *                      bulk-delete confirmation modal
 *
 * Available variables (from LibraryController::dashboardViewData):
 *     $dashboard    - statistics / summary / streak / recommended
 *     $continue     - the continue-reading shelf rows (or [])
 *     $collections  - the collectionStatistics() payload (count /
 *                     average rating / last updated per collection)
 *     $grid         - the buildGrid() payload (items, total, page,
 *                     pages, view, sort, sorts, filters, options,
 *                     counts, recommended, statusLabels, activeShelf)
 *     $prefs        - the persisted sort / view
 *     $statusLabels - status key -> display label
 *     $activeShelf  - 'all' | one status | 'favorites'
 */

$dashboard    = $dashboard ?? [];
$continue     = $continue ?? [];
$collections  = $collections ?? [];
$grid         = $grid ?? [];
$prefs        = $prefs ?? [];
$statusLabels = $statusLabels ?? [];
$activeShelf  = $activeShelf ?? 'all';

$statistics = (array) ($dashboard['statistics'] ?? []);
$summary    = (array) ($dashboard['summary'] ?? []);
$streak     = (array) ($dashboard['streak'] ?? []);
$counts     = (array) ($grid['counts'] ?? []);

// The greeting (the same clock pattern as the dashboard).
$hour      = (int) date('G');
$greeting  = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');
$firstName = ucfirst((string) (explode(' ', (string) auth_user()['full_name'])[0] ?? 'there'));

// The header chips: the current streak, the total books and the
// average reading progress of the started books.
$streakCurrent = (int) ($streak['current'] ?? 0);
$totalBooks    = (int) ($statistics['total'] ?? 0);
$progressAvg   = (int) round((float) ($statistics['average_progress'] ?? 0));

// The statistics row (every cell addressable for the JS repaint).
$statsRow = [
    ['key' => 'total',               'icon' => 'fa-book',              'label' => 'Total Books',       'value' => $totalBooks, 'tone' => 'primary'],
    ['key' => 'currently_reading',   'icon' => 'fa-book-open-reader',  'label' => 'Currently Reading', 'value' => (int) ($counts['currently_reading'] ?? 0), 'tone' => 'warning'],
    ['key' => 'finished',            'icon' => 'fa-circle-check',      'label' => 'Finished',          'value' => (int) ($counts['finished'] ?? 0), 'tone' => 'success'],
    ['key' => 'favorites',           'icon' => 'fa-heart',             'label' => 'Favourites',        'value' => (int) ($counts['favorites'] ?? 0), 'tone' => 'danger'],
    ['key' => 'average_progress',    'icon' => 'fa-gauge-high',        'label' => 'Average Progress',  'value' => round((float) ($statistics['average_progress'] ?? 0), 1), 'tone' => 'primary'],
    ['key' => 'added_this_month',    'icon' => 'fa-calendar-plus',     'label' => 'Added This Month',  'value' => (int) ($statistics['added_this_month'] ?? 0), 'tone' => 'success'],
];

// The quick actions: [icon, label, subtitle, href, admin-only].
$quickActions = [
    ['icon' => 'fa-book',              'label' => 'Browse Books',          'subtitle' => 'Discover the catalogue',   'href' => '/books',           'admin' => false],
    ['icon' => 'fa-book-open-reader',  'label' => 'Continue Reading',      'subtitle' => 'Pick up where you left off', 'href' => '#continue-reading', 'admin' => false],
    ['icon' => 'fa-wand-magic-sparkles', 'label' => 'View Recommendations', 'subtitle' => 'What to read next',         'href' => '/recommendations', 'admin' => false],
    ['icon' => 'fa-cloud-arrow-down',  'label' => 'Import Books',          'subtitle' => 'Add from the catalogue',    'href' => '/books',           'admin' => true],
];

// The reading summary cards (the summary payload of the dashboard).
$summaryCards = [
    ['icon' => 'fa-tags',   'label' => 'Favourite Genre',     'value' => $summary['favourite_genre'] ?? '—', 'tone' => 'info'],
    ['icon' => 'fa-user-pen', 'label' => 'Favourite Author',  'value' => $summary['favourite_author'] ?? '—', 'tone' => 'warning'],
    ['icon' => 'fa-star',   'label' => 'Average Rating Given', 'value' => (float) ($summary['average_rating_given'] ?? 0), 'tone' => 'danger'],
    ['icon' => 'fa-fire',   'label' => 'Reading Streak',      'value' => $streakCurrent . ' day' . ($streakCurrent === 1 ? '' : 's'), 'tone' => 'success', 'trend' => $streakCurrent > 0 ? 'longest ' . (int) ($streak['longest'] ?? 0) : 'start one today'],
];

?>
<!-- 1. Header: the greeting + the streak / books / progress chips -->
<div class="library-hero" data-animate>
    <div>
        <p class="eyebrow">My Library</p>
        <h1><?= e($greeting) ?>, <?= e($firstName) ?> <span class="hero-wave" aria-hidden="true">👋</span></h1>
        <p class="lead" data-library-total>
            You keep <?= $totalBooks ?> <?= $totalBooks === 1 ? 'book' : 'books' ?> in your library.
            <?php if (($counts['currently_reading'] ?? 0) > 0): ?>
                You are currently reading <?= (int) $counts['currently_reading'] ?>.
            <?php endif; ?>
        </p>
        <a class="btn btn-primary mt-2" href="/books">
            <i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add Books
        </a>
    </div>
    <div class="library-hero-chips">
        <div class="library-stat-chip library-stat-chip--streak" data-chip-streak title="Consecutive days with library activity">
            <span class="library-stat-chip-icon"><i class="fa-solid fa-fire" aria-hidden="true"></i></span>
            <div>
                <strong data-chip-streak-value><?= $streakCurrent ?> day<?= $streakCurrent === 1 ? '' : 's' ?></strong>
                <span>reading streak</span>
            </div>
        </div>
        <div class="library-stat-chip" data-chip-total title="Books in your library">
            <span class="library-stat-chip-icon"><i class="fa-solid fa-book" aria-hidden="true"></i></span>
            <div>
                <strong data-chip-total-value><?= $totalBooks ?></strong>
                <span>books in library</span>
            </div>
        </div>
        <div class="library-stat-chip" data-chip-progress title="Average reading progress">
            <span class="library-stat-chip-icon"><i class="fa-solid fa-gauge-high" aria-hidden="true"></i></span>
            <div>
                <strong data-chip-progress-value><?= $progressAvg ?>%</strong>
                <span>reading progress</span>
            </div>
        </div>
    </div>
</div>

<!-- 2. Statistics cards (repainted in place by library.js - during
     the refresh the cells are skeleton-filled, then rebuilt from the
     data attributes below with the fresh numbers) -->
<section class="library-stats" data-animate data-library-stats data-library-stats-endpoint="/library/statistics">
    <div class="row g-3 g-xl-4 row-cols-2 row-cols-md-3 row-cols-xl-6">
        <?php foreach ($statsRow as $stat): ?>
            <div class="col" data-stat-cell="<?= e($stat['key']) ?>"
                 data-stat-icon="<?= e($stat['icon']) ?>" data-stat-label="<?= e($stat['label']) ?>"
                 data-stat-tone="<?= e($stat['tone']) ?>" data-stat-value="<?= e((string) $stat['value']) ?>">
                <?php $stat = $stat; ?>
                <?php require root_path('app/Views/components/stat-card.php'); ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- 3. Quick actions -->
<section class="library-quick-actions" data-animate>
    <?php foreach ($quickActions as $action): ?>
        <?php if ($action['admin'] && !auth_is_admin()) { continue; } ?>
        <a class="library-quick-action" href="<?= e($action['href']) ?>">
            <span class="library-quick-icon" aria-hidden="true"><i class="fa-solid <?= e($action['icon']) ?>"></i></span>
            <span class="library-quick-body">
                <strong><?= e($action['label']) ?></strong>
                <small><?= e($action['subtitle']) ?></small>
            </span>
            <i class="fa-solid fa-arrow-right library-quick-arrow" aria-hidden="true"></i>
        </a>
    <?php endforeach; ?>
</section>

<!-- 4. Continue Reading (the real resume shelf - read through the
     shared LibraryService, refreshable via /library/continue-reading) -->
<section class="dash-section" data-animate>
    <?php $section = ['eyebrow' => 'Pick up where you left off', 'title' => 'Continue Reading', 'icon' => 'fa-book-open-reader', 'link' => ['label' => 'Browse books', 'href' => '/books']]; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>
    <div data-library-continue>
        <?php require root_path('app/Views/library/partials/_continue-grid.php'); ?>
    </div>
</section>

<!-- 4b. Recommended for your library (Phase 8.5: the library-derived
     shelves - the weighted "Because this is in your library" shelf,
     the collaborative "People who saved this also liked" shelf, the
     favourite category / author shelves and the community's recent
     discoveries. Every non-empty section renders with the same
     recommendation cards the /recommendations dashboard uses) -->
<?php
$libraryRecommendations = $libraryRecommendations ?? [];

$librarySectionTitles = [
    'because_in_library'    => 'Because this is in your library',
    'people_also_saved'     => 'People who saved this also liked',
    'favourite_category'    => 'Your favourite category',
    'favourite_author'      => 'Your favourite author',
    'recently_discovered'   => 'Recently discovered',
];
?>

<?php foreach ($librarySectionTitles as $key => $title): ?>
    <?php if (isset($libraryRecommendations[$key]) && $libraryRecommendations[$key] !== []): ?>
        <?php $shelf = [
            'eyebrow' => 'Recommended for you',
            'title'   => $title,
            'icon'    => $key === 'recently_discovered' ? 'fa-fire' : 'fa-wand-magic-sparkles',
            'link'    => ['label' => 'View recommendations', 'href' => '/recommendations'],
            'items'   => $libraryRecommendations[$key],
            'empty'   => '',
            'columns' => 'row-cols-2 row-cols-md-3 row-cols-xl-4',
        ]; ?>
        <?php require root_path('app/Views/recommendations/components/shelf-strip.php'); ?>
    <?php endif; ?>
<?php endforeach; ?>

<!-- 5. Search + filters + sort + view (the no-JS GET form; library.js
     turns every control into a fetch) -->
<?php $filters = $grid['filters'] ?? []; ?>
<?php $options = $grid['options'] ?? []; ?>
<?php $sorts   = $grid['sorts'] ?? []; ?>
<?php $total   = $grid['total'] ?? 0; ?>
<?php require root_path('app/Views/library/partials/_filters.php'); ?>

<!-- 6. Smart Collections rail (Phase 8.4): All + the five shelves +
     Favourites, each with its book count / average rating / last
     updated. Replaces the Phase 8.3 tabs but keeps their data
     contract ([data-library-tabs] / [data-library-tab]), so the
     counter refresh and the tab highlight keep working unchanged -->
<?php require root_path('app/Views/library/partials/_collections.php'); ?>

<div class="visually-hidden" role="status" data-library-status aria-live="polite"></div>

<!-- 7. The results region: chips + grid/list + pagination - the same
     fragment every filter / sort / search fetch swaps in. The grid
     cards carry the bulk-selection checkboxes (form="library-bulk-form",
     the form lives in the _bulk-bar partial below) -->
<div data-library-results>
    <?php require root_path('app/Views/library/partials/_grid.php'); ?>
</div>

<!-- 8. The bulk actions bar (Phase 8.4) - one form collecting the
     selected checkboxes of the region above -->
<?php require root_path('app/Views/library/partials/_bulk-bar.php'); ?>

<!-- 9. Reading summary (the reading-analytics of the dashboard) -->
<section class="dash-section" data-animate>
    <?php $section = ['eyebrow' => 'Your reading at a glance', 'title' => 'Reading Summary', 'icon' => 'fa-chart-pie', 'link' => ['label' => 'Full statistics', 'href' => '/library/statistics']]; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>
    <div class="row g-3 g-xl-4 row-cols-2 row-cols-md-3 row-cols-xl-4">
        <?php foreach ($summaryCards as $stat): ?>
            <div class="col"><?php require root_path('app/Views/components/stat-card.php'); ?></div>
        <?php endforeach; ?>
    </div>
</section>

<?php require root_path('app/Views/library/partials/_delete-modal.php'); ?>
<?php require root_path('app/Views/library/partials/_bulk-delete-modal.php'); ?>