<?php

declare(strict_types=1);

/**
 * community/create.php
 *
 * START A DISCUSSION page (Phase C4-B):
 * Allows authenticated users to create a new community discussion post
 * with an optional book reference attachment.
 */

$books = $books ?? [];

?>
<div class="mb-3">
    <a href="/community" class="text-decoration-none text-muted small">
        <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i> Back to Community Feed
    </a>
</div>

<div class="card-base p-4 p-md-5 mb-4 max-w-3xl mx-auto" style="max-width: 800px;">
    <div class="border-bottom pb-3 mb-4">
        <p class="eyebrow text-uppercase fw-semibold text-primary mb-1" style="letter-spacing: 0.05em; font-size: 0.8125rem;">START A DISCUSSION</p>
        <h1 class="h3 fw-bold mb-1">Create Community Post</h1>
        <p class="text-muted small mb-0">Share your thoughts, ask questions, or start a debate with fellow readers.</p>
    </div>

    <?php if (session()->getFlash('error') !== null): ?>
        <div class="alert alert-danger mb-4 d-flex align-items-center gap-2" role="alert">
            <i class="fa-solid fa-circle-exclamation flex-shrink-0" aria-hidden="true"></i>
            <div><?= e(session()->getFlash('error')) ?></div>
        </div>
    <?php endif; ?>

    <form action="/community/posts" method="POST" class="d-flex flex-column gap-4">
        <?= csrf_field() ?>

        <!-- Title -->
        <div>
            <label for="title" class="form-label fw-semibold text-dark">Discussion Title <span class="text-danger">*</span></label>
            <input type="text"
                   id="title"
                   name="title"
                   class="form-control form-control-lg"
                   placeholder="What book changed your perspective?"
                   required
                   maxlength="120"
                   value="<?= e($_POST['title'] ?? '') ?>">
            <div class="form-text small text-muted">Keep title clear and concise (up to 120 characters).</div>
        </div>

        <!-- Content Body -->
        <div>
            <label for="body" class="form-label fw-semibold text-dark">Discussion Content <span class="text-danger">*</span></label>
            <textarea id="body"
                      name="body"
                      class="form-control"
                      rows="7"
                      placeholder="Write something you'd like to discuss..."
                      required
                      minlength="10"
                      maxlength="10000"><?= e($_POST['body'] ?? '') ?></textarea>
            <div class="form-text small text-muted">Minimum 10 characters required.</div>
        </div>

        <!-- Optional Book Attachment -->
        <?php $activeBookId = (int) ($_POST['book_id'] ?? ($selectedBook ?? ($_GET['book_id'] ?? 0))); ?>
        <div>
            <label for="book_id" class="form-label fw-semibold text-dark">Related Book <span class="text-muted fw-normal">(optional)</span></label>
            <select id="book_id" name="book_id" class="form-select">
                <option value="">-- Select a book to tag (optional) --</option>
                <?php foreach ($books as $book): ?>
                    <option value="<?= (int) $book['id'] ?>" <?= ($activeBookId > 0 && $activeBookId === (int) $book['id']) ? 'selected' : '' ?>>
                        <?= e($book['title']) ?><?= !empty($book['publisher']) ? ' (' . e($book['publisher']) . ')' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="form-text small text-muted">Associate your post with a book in the BookSphere catalog.</div>
        </div>

        <!-- Form Actions -->
        <div class="d-flex align-items-center justify-content-end gap-3 pt-3 border-top mt-2">
            <a href="/community" class="btn btn-outline-secondary px-4">Cancel</a>
            <button type="submit" class="btn btn-primary px-4">
                <i class="fa-solid fa-paper-plane me-1" aria-hidden="true"></i> Publish Discussion
            </button>
        </div>
    </form>
</div>
