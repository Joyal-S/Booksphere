<?php

declare(strict_types=1);

/**
 * library/partials/_book-panel.php
 *
 * The LIBRARY PANEL of the book detail page (Phase 8.2): the block
 * that lets the signed-in user put the book into their library or
 * manage the entry they already have.
 *
 * Two states, chosen by the controller:
 *
 *     - $libraryItem === null  -> "Add to Library": a status select
 *       and an Add button (POST /library with the book id).
 *     - $libraryItem !== null  -> "In your library": the current
 *       status badge, the favourite toggle (fetch), the progress
 *       bar + slider (fetch), a status select (fetch) and a Remove
 *       button (native POST + redirect, the no-JS safe path).
 *
 * Every control is a real CSRF-protected form; library.js upgrades
 * the fetch-capable ones in place. The record id for the update
 * routes is the LIBRARY record id ($libraryItem['id']), never the
 * book id.
 *
 * Included from a view that sets:
 *
 *     $book             - the book row (id, title)
 *     $libraryItem      - the user's library record or null
 *     $libraryStatuses  - status key -> display label
 */

$book             = $book ?? [];
$libraryItem      = $libraryItem ?? null;
$libraryStatuses  = $libraryStatuses ?? [];
$bookId           = (int) ($book['id'] ?? 0);

?>
<div class="library-panel" data-library-panel data-book-id="<?= $bookId ?>">
    <?php if ($libraryItem === null): ?>

        <!-- State 1: the book is not in the library yet -->
        <div class="library-panel-head">
            <span class="section-icon" aria-hidden="true"><i class="fa-solid fa-bookmark" aria-hidden="true"></i></span>
            <div>
                <p class="eyebrow">Personal Library</p>
                <h2 class="section-title">Add to your library</h2>
            </div>
        </div>
        <form class="library-panel-form" method="post" action="/library" data-library-panel-add>
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="book_id" value="<?= $bookId ?>">
            <label class="visually-hidden" for="library-add-status">Status for <?= e($book['title'] ?? 'this book') ?></label>
            <select class="form-select" id="library-add-status" name="status">
                <?php foreach ($libraryStatuses as $key => $label): ?>
                    <option value="<?= e($key) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add to Library
            </button>
        </form>

    <?php else: ?>

        <?php
        $status   = (string) ($libraryItem['library_status'] ?? 'want_to_read');
        $favorite = (int) ($libraryItem['is_favorite'] ?? 0) === 1;
        $progress = max(0, min(100, (int) ($libraryItem['progress_percentage'] ?? 0)));
        $recordId = (int) $libraryItem['id'];
        $title    = (string) ($book['title'] ?? 'this book');
        ?>

        <!-- State 2: the book is already in the library -->
        <div class="library-panel-head">
            <span class="status-badge status-<?= e($status) ?>">
                <?= e($libraryStatuses[$status] ?? $status) ?>
            </span>
            <form method="post" action="/library/<?= $recordId ?>/favorite" data-library-panel-favorite>
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <button type="submit"
                        class="library-fav-btn library-fav-btn-lg<?= $favorite ? ' is-favorite' : '' ?>"
                        aria-pressed="<?= $favorite ? 'true' : 'false' ?>"
                        aria-label="<?= $favorite ? 'Remove ' . e($title) . ' from your favourites' : 'Add ' . e($title) . ' to your favourites' ?>"
                        title="<?= $favorite ? 'In your favourites' : 'Add to favourites' ?>">
                    <i class="fa-solid fa-heart" aria-hidden="true"></i>
                </button>
            </form>
        </div>

        <div class="library-panel-progress" data-library-panel-progress>
            <div class="d-flex justify-content-between small mb-1">
                <span>Reading progress</span>
                <strong data-library-panel-progress-value><?= $progress ?>%</strong>
            </div>
            <div class="progress library-progress"
                 role="progressbar"
                 aria-label="Reading progress of <?= e($title) ?>"
                 aria-valuenow="<?= $progress ?>"
                 aria-valuemin="0"
                 aria-valuemax="100">
                <div class="progress-bar" data-library-panel-progress-bar style="width: <?= $progress ?>%"></div>
            </div>
            <form method="post" action="/library/<?= $recordId ?>/progress" data-library-panel-progress-form>
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <label class="visually-hidden" for="library-panel-progress-input">Update reading progress of <?= e($title) ?></label>
                <input type="range" class="library-progress-input" id="library-panel-progress-input"
                       name="progress" min="0" max="100" step="1" value="<?= $progress ?>"
                       data-library-panel-progress-input aria-label="Update reading progress of <?= e($title) ?>">
                <button type="submit" class="btn btn-sm btn-outline-secondary library-progress-save">
                    <i class="fa-solid fa-check me-1" aria-hidden="true"></i>Save
                </button>
            </form>
        </div>

        <div class="library-panel-actions">
            <form method="post" action="/library/<?= $recordId ?>" data-library-panel-status>
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <label class="visually-hidden" for="library-panel-status">Change status of <?= e($title) ?></label>
                <select class="form-select form-select-sm" id="library-panel-status" name="status" data-library-panel-status-select>
                    <?php foreach ($libraryStatuses as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $status === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <!-- The JS handler (library.js handlePanelRemove) asks the
                 one confirmation; the no-JS path is the plain POST +
                 flash redirect used by every other library control -->
            <form method="post" action="/library/<?= $recordId ?>/delete" data-library-panel-remove>
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">
                    <i class="fa-solid fa-trash me-1" aria-hidden="true"></i>Remove from Library
                </button>
            </form>
        </div>

    <?php endif; ?>
</div>