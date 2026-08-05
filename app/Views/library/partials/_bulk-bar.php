<?php

declare(strict_types=1);

/**
 * library/partials/_bulk-bar.php
 *
 * The BULK ACTIONS bar of the library dashboard (Phase 8.4) - one
 * form (id="library-bulk-form") collecting every selected card via
 * the HTML5 form attribute: the grid checkboxes live INSIDE the
 * cards (which carry their own nested forms), so they cannot sit in
 * this form's markup - they point at it with form="library-bulk-form"
 * instead, keeping the card forms valid and the selection working.
 *
 * The bar holds the actions the brief asks for:
 *
 *     - Select all (this page) + the live selection count
 *     - Move To    -> a shelf select + Apply (bulk move_status)
 *     - Favourite / Un-favourite (bulk is_favorite writes)
 *     - Remove     -> the destructive action, always routed through
 *                     the #libraryBulkModal confirmation
 *
 * Progressive enhancement: every non-destructive button is a native
 * submit (name="action" value=...), so with JavaScript disabled the
 * form posts to /library/bulk with the CSRF token and the checked
 * ids[] - the controller answers with a redirect + flash. library.js
 * intercepts the submits, fetches the endpoint and repaints the
 * grid, the counters and the collections in place.
 *
 * Included from a view that sets $statusLabels (status key -> label)
 * and $csrfToken (the CSRF token; a session()-backed token or the
 * csrf_token() helper both work).
 */

$statusLabels = $statusLabels ?? [];

?>
<form id="library-bulk-form" class="library-bulk-bar is-empty" method="post" action="/library/bulk"
      data-library-bulk-form aria-label="Bulk actions for the selected books">
    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

    <div class="library-bulk-left">
        <label class="library-bulk-select-all" title="Select every book on this page">
            <input type="checkbox" class="form-check-input" data-bulk-select-all aria-label="Select all books on this page">
            <span>Select all</span>
        </label>
        <span class="library-bulk-count"><strong data-bulk-count>0</strong> selected</span>
        <button type="button" class="btn btn-sm btn-link library-bulk-clear" data-bulk-clear>Clear</button>
    </div>

    <div class="library-bulk-actions">
        <label class="visually-hidden" for="library-bulk-status">Move selected books to</label>
        <select id="library-bulk-status" class="form-select form-select-sm library-bulk-status" name="status" data-bulk-status>
            <?php foreach ($statusLabels as $key => $label): ?>
                <option value="<?= e($key) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm btn-primary" name="action" value="move_status" data-bulk-action="move_status">
            <i class="fa-solid fa-arrows-left-right me-1" aria-hidden="true"></i>Move
        </button>
        <button type="submit" class="btn btn-sm btn-outline-danger" name="action" value="favorite" data-bulk-action="favorite">
            <i class="fa-solid fa-heart me-1" aria-hidden="true"></i>Favourite
        </button>
        <button type="submit" class="btn btn-sm btn-outline-secondary" name="action" value="unfavorite" data-bulk-action="unfavorite">
            <i class="fa-regular fa-heart me-1" aria-hidden="true"></i>Un-favourite
        </button>
        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#libraryBulkModal">
            <i class="fa-solid fa-trash me-1" aria-hidden="true"></i>Remove
        </button>
    </div>
</form>
