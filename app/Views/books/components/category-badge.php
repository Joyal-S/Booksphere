<?php

declare(strict_types=1);

/**
 * books/components/category-badge.php
 *
 * The reusable CATEGORY BADGE: a small pill that renders one
 * category of a book. The list table, the detail page and the
 * book card all use it so category chips look identical and can
 * become clickable links in one place later.
 *
 * Usage (a view sets $categoryInfo first):
 *
 *     $categoryInfo = [
 *         'name' => 'Fiction',
 *         'href' => '/books?category=5',   // optional, makes it a link
 *     ];
 *     <?php require root_path('app/Views/books/components/category-badge.php'); ?>
 */

$categoryInfo = array_merge([
    'name' => '',
    'href' => null,
], $categoryInfo ?? []);

if ($categoryInfo['name'] === '') {
    return;
}

?>
<?php if ($categoryInfo['href'] !== null): ?>
    <a class="category-badge" href="<?= e($categoryInfo['href']) ?>">
        <i class="fa-solid fa-tag" aria-hidden="true"></i><?= e($categoryInfo['name']) ?>
    </a>
<?php else: ?>
    <span class="category-badge">
        <i class="fa-solid fa-tag" aria-hidden="true"></i><?= e($categoryInfo['name']) ?>
    </span>
<?php endif; ?>
