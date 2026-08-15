<?php

declare(strict_types=1);

/**
 * community/edit.php
 *
 * EDIT DISCUSSION page (Phase C4-B):
 * Allows authorized post author/admin to update an existing discussion.
 */

$post  = $post  ?? [];
$books = $books ?? [];

$postId = (int) ($post['id'] ?? 0);

?>
<div class="mb-3">
    <a href="/community/post/<?= $postId ?>" class="text-decoration-none text-muted small">
        <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i> Back to Discussion
    </a>
</div>

<div class="card-base p-4 p-md-5 mb-4 max-w-3xl mx-auto" style="max-width: 800px;">
    <div class="border-bottom pb-3 mb-4">
        <p class="eyebrow text-uppercase fw-semibold text-primary mb-1" style="letter-spacing: 0.05em; font-size: 0.8125rem;">EDIT DISCUSSION</p>
        <h1 class="h3 fw-bold mb-1">Edit Post</h1>
        <p class="text-muted small mb-0">Update your community post details.</p>
    </div>

    <?php if (session()->getFlash('error') !== null): ?>
        <div class="alert alert-danger mb-4 d-flex align-items-center gap-2" role="alert">
            <i class="fa-solid fa-circle-exclamation flex-shrink-0" aria-hidden="true"></i>
            <div><?= e(session()->getFlash('error')) ?></div>
        </div>
    <?php endif; ?>

    <form action="/community/posts/<?= $postId ?>/edit" method="POST" class="d-flex flex-column gap-4">
        <?= csrf_field() ?>

        <!-- Title -->
        <div>
            <label for="title" class="form-label fw-semibold text-dark">Discussion Title <span class="text-danger">*</span></label>
            <input type="text"
                   id="title"
                   name="title"
                   class="form-control form-control-lg"
                   required
                   maxlength="120"
                   value="<?= e($_POST['title'] ?? $post['title'] ?? '') ?>">
        </div>

        <!-- Content Body -->
        <div>
            <label for="body" class="form-label fw-semibold text-dark">Discussion Content <span class="text-danger">*</span></label>
            <textarea id="body"
                      name="body"
                      class="form-control"
                      rows="7"
                      required
                      minlength="10"
                      maxlength="10000"><?= e($_POST['body'] ?? $post['body'] ?? '') ?></textarea>
        </div>

        <!-- Optional Book Attachment -->
        <div>
            <label for="book_id" class="form-label fw-semibold text-dark">Related Book <span class="text-muted fw-normal">(optional)</span></label>
            <select id="book_id" name="book_id" class="form-select">
                <option value="">-- None --</option>
                <?php
                $selectedBookId = isset($_POST['book_id']) ? (int) $_POST['book_id'] : (int) ($post['book_id'] ?? 0);
                ?>
                <?php foreach ($books as $book): ?>
                    <option value="<?= (int) $book['id'] ?>" <?= $selectedBookId === (int) $book['id'] ? 'selected' : '' ?>>
                        <?= e($book['title']) ?><?= !empty($book['publisher']) ? ' (' . e($book['publisher']) . ')' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Form Actions -->
        <div class="d-flex align-items-center justify-content-end gap-3 pt-3 border-top mt-2">
            <a href="/community/post/<?= $postId ?>" class="btn btn-outline-secondary px-4">Cancel</a>
            <button type="submit" class="btn btn-primary px-4">
                <i class="fa-solid fa-check me-1" aria-hidden="true"></i> Save Changes
            </button>
        </div>
    </form>
</div>
