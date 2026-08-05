<?php

declare(strict_types=1);

/**
 * library/partials/_bulk-delete-modal.php
 *
 * The BULK REMOVE confirmation modal (Phase 8.4) - the destructive
 * gate the brief demands for bulk deletion. Opening it shows the
 * selected count; confirming posts a real CSRF-protected form to
 * /library/bulk (action=delete). library.js copies the selected
 * ids[] into the form before the modal opens and upgrades the submit
 * to a fetch that repaints the page; the native submit path (a
 * keyboard-only environment) stays valid.
 *
 * Included from a view that sets $statusLabels (for consistency with
 * the other partials) - the token comes from csrf_token().
 */

$statusLabels = $statusLabels ?? [];

?>
<div class="modal fade" id="libraryBulkModal" tabindex="-1" aria-labelledby="libraryBulkTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="libraryBulkTitle">Remove selected books?</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>
                    Remove <strong data-bulk-modal-count>0</strong> selected book(s)
                    from your library?
                    The books stay in the catalogue - only your library entries are deleted.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <form id="libraryBulkDeleteForm" method="post" action="/library/bulk" data-bulk-delete-form>
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="btn btn-danger">
                        <i class="fa-solid fa-trash me-1" aria-hidden="true"></i>Remove
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
