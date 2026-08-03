<?php

declare(strict_types=1);

/**
 * books/components/book-table-row.php
 *
 * The reusable TABLE ROW for the book management list. One book
 * row is rendered entirely by this component: cover thumbnail,
 * title + subtitle, authors, category badges, year, rating, status
 * badge and the view/edit/delete action buttons.
 *
 * Why it exists:
 *     - The list page only loops over this component; the row
 *       markup (and its accessibility) is defined exactly once.
 *     - Every cell copies the same data attributes the delete
 *       modal needs (data-delete-url / -title / -cover), so the
 *       confirmation dialog always posts to the correct route.
 *
 * Usage (a view sets $rowBook, $statuses and $isAdmin first):
 *
 *     $rowBook  = $book;   // one book row from BookRepository
 *     $statuses = [...];   // BookService::STATUSES
 *     $isAdmin  = true;    // shows the edit/delete actions (admin only)
 *     <?php require root_path('app/Views/books/components/book-table-row.php'); ?>
 */

$rowBook     = array_merge([
    'id'             => 0,
    'title'          => '',
    'subtitle'       => null,
    'cover_image'    => null,
    'authors_list'   => '',
    'categories_list'=> '',
    'published_year' => null,
    'average_rating' => 0.0,
    'ratings_count'  => 0,
    'status'         => 'draft',
], (array) ($rowBook ?? []));

$statuses = $statuses ?? [];
$isAdmin  = $isAdmin ?? false;

?>
<tr data-book-row>
    <td class="ps-4">
        <?php $cover = [
            'src'   => $rowBook['cover_image'] ?? '',
            'alt'   => 'Cover of ' . ($rowBook['title'] ?? ''),
            'class' => 'table-cover',
        ]; ?>
        <?php require root_path('app/Views/books/components/book-cover.php'); ?>
    </td>
    <td>
        <a class="table-title" href="/books/<?= (int) $rowBook['id'] ?>"><?= e($rowBook['title']) ?></a>
        <?php if (!empty($rowBook['subtitle'])): ?>
            <small class="d-block table-subtitle"><?= e($rowBook['subtitle']) ?></small>
        <?php endif; ?>
    </td>
    <td class="table-muted"><?= e($rowBook['authors_list']) ?></td>
    <td class="table-muted">
        <?php
        $names = array_values(array_filter(array_map('trim', explode(',', (string) $rowBook['categories_list']))));
        foreach (array_slice($names, 0, 2) as $name):
            $categoryInfo = ['name' => $name]; ?>
            <?php require root_path('app/Views/books/components/category-badge.php'); ?>
        <?php endforeach;
        if (count($names) > 2): ?>
            <span class="category-badge category-badge-more">+<?= count($names) - 2 ?></span>
        <?php endif; ?>
    </td>
    <td class="text-center"><?= $rowBook['published_year'] !== null ? (int) $rowBook['published_year'] : '&ndash;' ?></td>
    <td>
        <?php $ratingInfo = [
            'rating'  => $rowBook['average_rating'],
            'count'   => (int) $rowBook['ratings_count'],
            'compact' => true,
        ]; ?>
        <?php require root_path('app/Views/books/components/rating-stars.php'); ?>
    </td>
    <?php if ($isAdmin): ?>
        <td>
            <span class="status-badge status-<?= e($rowBook['status']) ?>">
                <?= e(($statuses[$rowBook['status']] ?? $rowBook['status'])) ?>
            </span>
        </td>
        <td class="pe-4 text-end">
            <div class="d-inline-flex gap-1">
                <a class="icon-button icon-button-sm" href="/books/<?= (int) $rowBook['id'] ?>"
                   title="View book" aria-label="View <?= e($rowBook['title']) ?>">
                    <i class="fa-solid fa-eye" aria-hidden="true"></i>
                </a>
                <a class="icon-button icon-button-sm" href="/books/<?= (int) $rowBook['id'] ?>/edit"
                   title="Edit book" aria-label="Edit <?= e($rowBook['title']) ?>">
                    <i class="fa-solid fa-pen" aria-hidden="true"></i>
                </a>
                <button class="icon-button icon-button-sm icon-button-danger" type="button"
                        data-bs-toggle="modal" data-bs-target="#deleteModal"
                        data-delete-url="/books/<?= (int) $rowBook['id'] ?>/delete"
                        data-delete-title="<?= e($rowBook['title']) ?>"
                        data-delete-cover="<?= e($rowBook['cover_image'] ?? '') ?>"
                        title="Delete book" aria-label="Delete <?= e($rowBook['title']) ?>">
                    <i class="fa-solid fa-trash" aria-hidden="true"></i>
                </button>
            </div>
        </td>
    <?php endif; ?>
</tr>