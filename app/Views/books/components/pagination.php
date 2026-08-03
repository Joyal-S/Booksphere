<?php

declare(strict_types=1);

/**
 * books/components/pagination.php
 *
 * The reusable PAGINATION BAR: First / Previous / windowed page
 * numbers / Next / Last, plus the "Showing X–Y of Z books" summary.
 *
 * Why it exists:
 *     - Every paginated screen (the browse page, its live-search
 *       partial) renders one identical, accessible control.
 *     - The windowed numbers keep the bar short even when there
 *       are hundreds of pages: the current page, its neighbours,
 *       page 1 and the last page, with ellipses in between.
 *
 * Usage (a view sets $pagination first):
 *
 *     $pagination = [
 *         'page'    => 2,                    // current page
 *         'pages'   => 21,                   // total pages
 *         'pageUrl' => fn (int $n): string => "/books?page=$n", // URL builder
 *         'summary' => 'Showing 11–20 of 205 books',             // optional
 *     ];
 *     <?php require root_path('app/Views/books/components/pagination.php'); ?>
 *
 * Accessibility: the nav is labelled, the Previous/Next/First/Last
 * links carry aria-labels, the current page is marked with
 * aria-current="page", and inactive links are disabled rather than
 * removed, so keyboard users always know where they are.
 */

$pagination = $pagination ?? [];
$page       = max(1, (int) ($pagination['page'] ?? 1));
$pages      = max(1, (int) ($pagination['pages'] ?? 1));
$pageUrl    = $pagination['pageUrl'] ?? fn (int $n): string => '#';
$summary    = (string) ($pagination['summary'] ?? '');

// Windowed page numbers: keep the current page, up to 2 neighbours
// on each side, page 1 and the last page. Gaps become "…" items
// (represented by null in the array).
$numbers = [];

foreach (range(1, $pages) as $number) {
    $nearCurrent = abs($number - $page) <= 2;
    $isEdge      = $number === 1 || $number === $pages;

    if (!$nearCurrent && !$isEdge) {
        continue;
    }

    $previous = $numbers !== [] ? $numbers[count($numbers) - 1] : null;

    if ($previous !== null && $number - $previous > 1) {
        $numbers[] = null; // gap -> ellipsis
    }

    $numbers[] = $number;
}

?>
<?php if ($pages > 1): ?>
    <div class="pagination-bar">
        <?php if ($summary !== ''): ?>
            <p class="pagination-summary"><?= e($summary) ?></p>
        <?php endif; ?>
        <nav aria-label="Catalogue pages">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>">
                    <a class="page-link" href="<?= e($pageUrl(1)) ?>" aria-label="First page"<?= $page <= 1 ? ' aria-disabled="true" tabindex="-1"' : '' ?>>
                        <i class="fa-solid fa-angles-left" aria-hidden="true"></i>
                        <span class="visually-hidden">First page</span>
                    </a>
                </li>
                <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>">
                    <a class="page-link" href="<?= e($pageUrl($page - 1)) ?>" aria-label="Previous page"<?= $page <= 1 ? ' aria-disabled="true" tabindex="-1"' : '' ?>>
                        <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                        <span class="visually-hidden">Previous page</span>
                    </a>
                </li>

                <?php foreach ($numbers as $number): ?>
                    <?php if ($number === null): ?>
                        <li class="page-item disabled" aria-hidden="true">
                            <span class="page-link">&hellip;</span>
                        </li>
                    <?php else: ?>
                        <li class="page-item<?= $number === $page ? ' active' : '' ?>">
                            <a class="page-link" href="<?= e($pageUrl($number)) ?>"
                               aria-label="Page <?= $number ?>"<?= $number === $page ? ' aria-current="page"' : '' ?>>
                                <?= $number ?>
                            </a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>

                <li class="page-item<?= $page >= $pages ? ' disabled' : '' ?>">
                    <a class="page-link" href="<?= e($pageUrl($page + 1)) ?>" aria-label="Next page"<?= $page >= $pages ? ' aria-disabled="true" tabindex="-1"' : '' ?>>
                        <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                        <span class="visually-hidden">Next page</span>
                    </a>
                </li>
                <li class="page-item<?= $page >= $pages ? ' disabled' : '' ?>">
                    <a class="page-link" href="<?= e($pageUrl($pages)) ?>" aria-label="Last page"<?= $page >= $pages ? ' aria-disabled="true" tabindex="-1"' : '' ?>>
                        <i class="fa-solid fa-angles-right" aria-hidden="true"></i>
                        <span class="visually-hidden">Last page</span>
                    </a>
                </li>
            </ul>
        </nav>
    </div>
<?php endif; ?>
