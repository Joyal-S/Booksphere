<?php

declare(strict_types=1);

/**
 * notifications/partials/_confirm-modal.php
 *
 * The ONE confirmation dialog of the center (Phase 9.4): every
 * destructive action - delete one, delete selected, clear all -
 * opens this modal, whose text is set from the triggering control
 * by notifications.js and whose confirm button then submits the
 * pending (CSRF-protected) form via fetch.
 *
 * The modal can only open through Bootstrap (JavaScript), so it
 * holds no form of its own: the TRUE no-JS path is the real form
 * on the card / toolbar / intro, which submits directly and gets
 * the server's redirect + flash (the controller's dual answer).
 */

?>
<div class="modal fade" id="notifConfirmModal" tabindex="-1" aria-labelledby="notifConfirmTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="notifConfirmTitle">Delete notification?</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p id="notifConfirmBody">This cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="notifConfirmGo">
                    <i class="fa-solid fa-trash me-1" aria-hidden="true"></i>Delete
                </button>
            </div>
        </div>
    </div>
</div>
