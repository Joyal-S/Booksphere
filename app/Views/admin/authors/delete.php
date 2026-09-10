<?php

declare(strict_types=1);

/**
 * admin/authors/delete.php
 *
 * Safe Author Deletion Confirmation Page.
 *
 * Displays:
 * 1. Author profile details and associated book count.
 * 2. High-visibility assurance that books themselves will NEVER be deleted.
 * 3. Complete list of affected books and their co-author statuses.
 * 4. Authorless-book protection: if any book will have 0 authors left,
 *    demands explicit checkbox confirmation before allowing deletion.
 */

$author          = $author ?? [];
$books           = $books ?? [];
$authorlessBooks = $authorlessBooks ?? [];

$aid         = (int) ($author['id'] ?? 0);
$name        = (string) ($author['name'] ?? '');
$totalBooks  = count($books);
$orphanCount = count($authorlessBooks);

?>
<div class="mb-3">
    <a href="/admin/authors" class="text-decoration-none text-muted small">
        <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>Back to Author Management
    </a>
</div>

<div class="page-intro mb-4">
    <p class="eyebrow text-danger fw-bold">
        <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>Action Confirmation
    </p>
    <h1 class="mb-1 text-danger">Delete Author: <?= e($name) ?></h1>
    <p class="lead mb-0">Please review the impact on associated catalogue books before proceeding.</p>
</div>

<!-- Reassurance: Books Are Never Deleted -->
<div class="alert alert-info border-info-subtle d-flex align-items-start gap-3 p-3 mb-4 rounded-3 shadow-sm">
    <div class="fs-4 text-info flex-shrink-0 mt-0.5">
        <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
    </div>
    <div>
        <h2 class="h6 fw-bold mb-1 text-info-emphasis">Safe Deletion Guarantee: Books Are Preserved</h2>
        <p class="mb-0 small text-info-emphasis">
            Deleting this author will <strong>ONLY</strong> remove the author record and the author-book relationships (<code class="text-info-emphasis">book_authors</code>).
            <strong>No books will ever be deleted from the database.</strong> Reviews, ratings, reading history, library shelves, and wishlist entries associated with these books will remain 100% intact.
        </p>
    </div>
</div>

<?php if ($orphanCount > 0): ?>
    <!-- High-Priority Warning: Authorless Books -->
    <div class="alert alert-warning border-warning-subtle d-flex align-items-start gap-3 p-3 mb-4 rounded-3 shadow-sm">
        <div class="fs-4 text-warning flex-shrink-0 mt-0.5">
            <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
        </div>
        <div>
            <h2 class="h6 fw-bold mb-1 text-warning-emphasis">
                Authorless Book Alert: <?= $orphanCount ?> <?= $orphanCount === 1 ? 'Book' : 'Books' ?> Will Have Zero Authors
            </h2>
            <p class="mb-2 small text-warning-emphasis">
                Deleting <strong><?= e($name) ?></strong> will leave the following <?= $orphanCount === 1 ? 'book' : 'books' ?> with <strong>NO remaining authors</strong>:
            </p>
            <ul class="mb-2 small text-warning-emphasis ps-3">
                <?php foreach ($authorlessBooks as $ob): ?>
                    <li>
                        <strong>#<?= (int) $ob['id'] ?>: <?= e($ob['title']) ?></strong>
                        <span class="badge bg-warning-subtle text-warning-emphasis border ms-1">Sole Author</span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="mb-0 small text-warning-emphasis">
                You must explicitly check the confirmation box below to verify that you wish to proceed with leaving these books without an author.
            </p>
        </div>
    </div>
<?php endif; ?>

<!-- Associated Books Breakdown -->
<section class="card-base p-0 overflow-hidden mb-4 shadow-sm">
    <div class="p-3 bg-body-tertiary border-bottom d-flex align-items-center justify-content-between">
        <h3 class="h6 mb-0 fw-bold">
            <i class="fa-solid fa-book-bookmark text-primary me-2" aria-hidden="true"></i>
            Associated Books Breakdown (<?= $totalBooks ?> <?= $totalBooks === 1 ? 'Book' : 'Books' ?>)
        </h3>
        <span class="badge bg-secondary-subtle text-secondary border">Author ID: #<?= $aid ?></span>
    </div>

    <?php if ($books === []): ?>
        <div class="p-4 text-center text-muted">
            <p class="mb-0">This author has no linked books. Safe to delete without impacting any catalogue entries.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light text-muted small text-uppercase">
                    <tr>
                        <th style="width: 80px;" class="ps-4">Book ID</th>
                        <th>Title</th>
                        <th style="width: 140px;">Status</th>
                        <th style="width: 250px;">Multi-Author Status</th>
                        <th style="width: 100px;" class="text-end pe-4">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($books as $b): ?>
                        <?php
                        $bid          = (int) $b['id'];
                        $title        = (string) $b['title'];
                        $status       = (string) ($b['status'] ?? 'published');
                        $authorsCount = (int) ($b['total_authors_count'] ?? 1);
                        $isSole       = ($authorsCount <= 1);
                        ?>
                        <tr>
                            <td class="ps-4 text-muted small font-monospace">#<?= $bid ?></td>
                            <td>
                                <a href="/books/<?= $bid ?>" class="fw-semibold text-decoration-none text-body" target="_blank">
                                    <?= e($title) ?>
                                </a>
                            </td>
                            <td>
                                <span class="badge bg-<?= $status === 'published' ? 'success' : 'secondary' ?>-subtle text-<?= $status === 'published' ? 'success' : 'secondary' ?> border">
                                    <?= e(ucfirst($status)) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($isSole): ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis border">
                                        <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>Will become authorless
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-success-subtle text-success border">
                                        <i class="fa-solid fa-users me-1" aria-hidden="true"></i><?= $authorsCount - 1 ?> other co-author(s) remain
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-4">
                                <a href="/books/<?= $bid ?>" class="btn btn-sm btn-outline-secondary" target="_blank" title="View Book in Catalogue">
                                    <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<!-- Final Confirmation Form -->
<div class="card-base p-4 border border-danger-subtle shadow-sm">
    <h3 class="h5 text-danger mb-3">
        <i class="fa-solid fa-circle-radiation me-2" aria-hidden="true"></i>Confirm Author Deletion
    </h3>

    <form method="post" action="/admin/authors/<?= $aid ?>/delete" id="delete-author-form">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

        <?php if ($orphanCount > 0): ?>
            <div class="form-check p-3 bg-warning-subtle border border-warning rounded-3 mb-4">
                <input class="form-check-input ms-0 me-2" type="checkbox" name="confirm_authorless" value="1" id="confirm-authorless" required>
                <label class="form-check-label text-warning-emphasis fw-bold" for="confirm-authorless">
                    I understand that deleting <?= e($name) ?> will leave <?= $orphanCount ?> book(s) with zero authors, and I explicitly authorize this deletion.
                </label>
            </div>
        <?php endif; ?>

        <div class="d-flex align-items-center gap-3">
            <button type="submit" class="btn btn-danger px-4 py-2 fw-semibold">
                <i class="fa-solid fa-trash me-2" aria-hidden="true"></i>Delete Author Record
            </button>
            <a href="/admin/authors" class="btn btn-outline-secondary px-4 py-2">
                Cancel and Return
            </a>
        </div>
    </form>
</div>
