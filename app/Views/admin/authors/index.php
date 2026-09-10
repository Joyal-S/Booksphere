<?php

declare(strict_types=1);

/**
 * admin/authors/index.php
 *
 * The ADMIN AUTHOR MANAGEMENT directory.
 * Provides administrators with:
 * - Search/filtering by author name or biography.
 * - Sorting by name, book count, or creation order.
 * - Associated book count per author.
 * - Direct links to public profile, author editor, and safe deletion confirmation.
 * - Server-side pagination.
 */

$result     = $result ?? ['items' => [], 'total' => 0, 'page' => 1, 'perPage' => 20, 'pages' => 1];
$authors    = $result['items'] ?? [];
$total      = (int) ($result['total'] ?? 0);
$q          = (string) ($q ?? '');
$sort       = (string) ($sort ?? 'name_asc');
$pagination = $pagination ?? null;

$sortOptions = [
    'name_asc'   => 'Name (A–Z)',
    'name_desc'  => 'Name (Z–A)',
    'books_desc' => 'Most books',
    'books_asc'  => 'Fewest books',
    'newest'     => 'Newest added',
    'oldest'     => 'Oldest added',
];

?>
<div class="page-intro d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between gap-3 mb-4">
    <div>
        <p class="eyebrow">Administration</p>
        <h1 class="mb-1">Author Management</h1>
        <p class="lead mb-0">Search, review book counts, edit profiles, and safely manage catalogue authors.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="badge bg-secondary-subtle text-secondary fs-6 px-3 py-2 border">
            <i class="fa-solid fa-users me-1" aria-hidden="true"></i><?= number_format($total) ?> Total Authors
        </span>
    </div>
</div>

<!-- Toolbar: Search & Sort Form -->
<form class="card-base p-3 mb-4" method="get" action="/admin/authors" role="search">
    <div class="row g-2 align-items-center">
        <div class="col-12 col-md-6 col-lg-7">
            <div class="input-group">
                <span class="input-group-text bg-body border-end-0">
                    <i class="fa-solid fa-magnifying-glass text-muted" aria-hidden="true"></i>
                </span>
                <input class="form-control border-start-0" type="search" name="q" value="<?= e($q) ?>"
                       placeholder="Search authors by name or biography..." aria-label="Search authors">
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <select class="form-select" name="sort" aria-label="Sort authors">
                <?php foreach ($sortOptions as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= $sort === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2 col-lg-2 d-flex gap-2">
            <button class="btn btn-primary flex-grow-1" type="submit">
                <i class="fa-solid fa-filter me-1" aria-hidden="true"></i>Filter
            </button>
            <?php if ($q !== '' || $sort !== 'name_asc'): ?>
                <a class="btn btn-outline-secondary" href="/admin/authors" title="Reset filters">
                    <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                </a>
            <?php endif; ?>
        </div>
    </div>
</form>

<!-- Authors Table -->
<section class="card-base p-0 overflow-hidden mb-4 shadow-sm">
    <?php if ($authors === []): ?>
        <div class="p-5 text-center text-muted">
            <i class="fa-solid fa-user-slash fs-1 d-block mb-3 opacity-50" aria-hidden="true"></i>
            <h4>No authors found</h4>
            <p class="mb-3">No author records match your search query "<strong><?= e($q) ?></strong>".</p>
            <a href="/admin/authors" class="btn btn-outline-primary btn-sm">Clear Search</a>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="admin-authors-table">
                <thead class="table-light text-muted small text-uppercase">
                    <tr>
                        <th style="width: 80px;" class="ps-4">ID</th>
                        <th>Author Name</th>
                        <th style="width: 180px;">Associated Books</th>
                        <th style="width: 140px;">Created</th>
                        <th style="width: 220px;" class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($authors as $author): ?>
                        <?php
                        $aid         = (int) $author['id'];
                        $name        = (string) $author['name'];
                        $bio         = (string) ($author['biography'] ?? '');
                        $booksCount  = (int) ($author['books_count'] ?? 0);
                        $activeCount = (int) ($author['active_books_count'] ?? 0);
                        $createdAt   = (string) ($author['created_at'] ?? '');
                        $dateDisplay = $createdAt !== '' ? date('M j, Y', strtotime($createdAt)) : '—';
                        ?>
                        <tr>
                            <td class="ps-4 text-muted small font-monospace">#<?= $aid ?></td>
                            <td>
                                <div class="fw-semibold">
                                    <a href="/authors/<?= $aid ?>" class="text-decoration-none text-body hover-primary">
                                        <?= e($name) ?>
                                    </a>
                                </div>
                                <?php if ($bio !== ''): ?>
                                    <div class="text-muted small text-truncate" style="max-width: 450px;" title="<?= e($bio) ?>">
                                        <?= e(mb_strlen($bio) > 90 ? mb_substr($bio, 0, 90) . '…' : $bio) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($booksCount > 0): ?>
                                    <a href="/authors/<?= $aid ?>" class="badge bg-primary-subtle text-primary border border-primary-subtle text-decoration-none">
                                        <i class="fa-solid fa-book me-1" aria-hidden="true"></i>
                                        <?= $booksCount ?> <?= $booksCount === 1 ? 'book' : 'books' ?>
                                    </a>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-muted border">
                                        0 books
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small"><?= e($dateDisplay) ?></td>
                            <td class="text-end pe-4">
                                <div class="btn-group btn-group-sm">
                                    <a href="/authors/<?= $aid ?>" class="btn btn-outline-secondary" title="View Public Profile">
                                        <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
                                    </a>
                                    <a href="/admin/authors/<?= $aid ?>/edit" class="btn btn-outline-primary" title="Edit Author">
                                        <i class="fa-solid fa-pen-to-square me-1" aria-hidden="true"></i>Edit
                                    </a>
                                    <a href="/admin/authors/<?= $aid ?>/delete" class="btn btn-outline-danger" title="Delete Author">
                                        <i class="fa-solid fa-trash me-1" aria-hidden="true"></i>Delete
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<!-- Pagination Bar -->
<?php if ($pagination !== null && (int) ($pagination['pages'] ?? 1) > 1): ?>
    <div class="d-flex justify-content-center mb-4">
        <?php require root_path('app/Views/components/review-pagination.php'); ?>
    </div>
<?php endif; ?>
