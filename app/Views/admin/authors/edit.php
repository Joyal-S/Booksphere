<?php

declare(strict_types=1);

/**
 * admin/authors/edit.php
 *
 * The ADMIN AUTHOR EDIT page.
 * Allows administrators to edit an author's name and biography.
 * Shows associated books and provides safe links.
 */

$author = $author ?? [];
$books  = $books ?? [];
$input  = $input ?? [];
$errors = $errors ?? [];

$aid       = (int) ($author['id'] ?? 0);
$name      = (string) ($input['name'] ?? $author['name'] ?? '');
$biography = (string) ($input['biography'] ?? $author['biography'] ?? '');

?>
<div class="mb-3">
    <a href="/admin/authors" class="text-decoration-none text-muted small">
        <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>Back to Author Management
    </a>
</div>

<div class="page-intro d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between gap-3 mb-4">
    <div>
        <p class="eyebrow">Administration &bull; Author #<?= $aid ?></p>
        <h1 class="mb-1">Edit Author</h1>
        <p class="lead mb-0">Update author details or review associated books in the catalogue.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="/authors/<?= $aid ?>" class="btn btn-outline-secondary" target="_blank" title="View Public Profile">
            <i class="fa-solid fa-arrow-up-right-from-square me-1" aria-hidden="true"></i>Public Page
        </a>
        <a href="/admin/authors/<?= $aid ?>/delete" class="btn btn-outline-danger" title="Delete Author">
            <i class="fa-solid fa-trash me-1" aria-hidden="true"></i>Delete
        </a>
    </div>
</div>

<div class="row g-4">
    <!-- Form Column -->
    <div class="col-12 col-lg-7">
        <div class="card-base p-4 shadow-sm">
            <form method="post" action="/admin/authors/<?= $aid ?>/edit" novalidate>
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

                <!-- Author Name -->
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="author-name">
                        Author Name <span class="text-danger">*</span>
                    </label>
                    <input class="form-control<?= isset($errors['name']) ? ' is-invalid' : '' ?>"
                           type="text" id="author-name" name="name"
                           value="<?= e($name) ?>" required maxlength="255">
                    <?php if (isset($errors['name'])): ?>
                        <div class="invalid-feedback d-block">
                            <?= e($errors['name'][0]) ?>
                        </div>
                    <?php endif; ?>
                    <div class="form-text">The full display name of the author across the catalogue.</div>
                </div>

                <!-- Biography -->
                <div class="mb-4">
                    <label class="form-label fw-semibold" for="author-bio">Biography</label>
                    <textarea class="form-control<?= isset($errors['biography']) ? ' is-invalid' : '' ?>"
                              id="author-bio" name="biography" rows="6" maxlength="5000"
                              placeholder="Brief biographical information, background, or notable awards..."><?= e($biography) ?></textarea>
                    <?php if (isset($errors['biography'])): ?>
                        <div class="invalid-feedback d-block">
                            <?= e($errors['biography'][0]) ?>
                        </div>
                    <?php endif; ?>
                    <div class="form-text">Optional author biography (maximum 5,000 characters).</div>
                </div>

                <!-- Action Buttons -->
                <div class="d-flex align-items-center gap-2 pt-2 border-top">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fa-solid fa-check me-1" aria-hidden="true"></i>Save Changes
                    </button>
                    <a href="/admin/authors" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Associated Books Sidebar -->
    <div class="col-12 col-lg-5">
        <div class="card-base p-4 shadow-sm h-100">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h3 class="h5 mb-0">
                    <i class="fa-solid fa-book-bookmark text-primary me-2" aria-hidden="true"></i>Associated Books
                </h3>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                    <?= count($books) ?> <?= count($books) === 1 ? 'book' : 'books' ?>
                </span>
            </div>

            <?php if ($books === []): ?>
                <p class="text-muted small mb-0">No books currently linked to this author.</p>
            <?php else: ?>
                <div class="list-group list-group-flush border rounded-3 overflow-hidden">
                    <?php foreach ($books as $b): ?>
                        <?php
                        $bid           = (int) $b['id'];
                        $title         = (string) $b['title'];
                        $status        = (string) ($b['status'] ?? 'published');
                        $authorsCount  = (int) ($b['total_authors_count'] ?? 1);
                        $rating        = (float) ($b['average_rating'] ?? 0);
                        ?>
                        <div class="list-group-item list-group-item-action d-flex align-items-center justify-content-between p-3">
                            <div class="min-w-0 pe-2">
                                <a href="/books/<?= $bid ?>" class="fw-medium text-decoration-none text-body d-block text-truncate">
                                    <?= e($title) ?>
                                </a>
                                <div class="text-muted small mt-0.5">
                                    <span class="badge bg-<?= $status === 'published' ? 'success' : 'secondary' ?>-subtle text-<?= $status === 'published' ? 'success' : 'secondary' ?> border">
                                        <?= e(ucfirst($status)) ?>
                                    </span>
                                    <?php if ($authorsCount > 1): ?>
                                        <span class="badge bg-info-subtle text-info-emphasis border ms-1">
                                            <?= $authorsCount ?> co-authors
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning-subtle text-warning-emphasis border ms-1">
                                            Sole author
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <a href="/books/<?= $bid ?>" class="btn btn-sm btn-outline-secondary flex-shrink-0" title="View Book">
                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
