<?php

declare(strict_types=1);

/**
 * search/partials/_history-modal.php
 *
 * The ONE confirmation dialog of the search history (Phase 11.5):
 * both destructive actions - delete one row and clear the whole
 * history - open this modal, whose text and button are set from the
 * triggering control by search.js, and whose confirm button then
 * SUBMITS the pending CSRF-protected form and lets the server
 * redirect + flash (the controller's dual answer is not needed
 * here - the no-JS form's plain POST is the modal's own submit).
 *
 * The modal can only open through Bootstrap (JavaScript), so it
 * holds no form of its own: the TRUE no-JS path is the inline
 * <form> on each row / the toolbar (data-history-delete-form /
 * data-history-clear-form), which posts straight to the same
 * routes. This partial stays top-level so the page always has
 * exactly one dialog.
 */

?>
<div class="modal fade" id="historyConfirmModal" tabindex="-1" aria-labelledby="historyConfirmTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="historyConfirmTitle">Confirm</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p id="historyConfirmBody">This cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="historyConfirmGo">
                    <i class="fa-solid fa-trash me-1" aria-hidden="true"></i>Remove
                </button>
            </div>
        </div>
    </div>
</div>