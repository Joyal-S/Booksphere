<?php

declare(strict_types=1);

/**
 * reviews/partials/_delete-modal.php
 *
 * The shared DELETE CONFIRMATION modal of the Reviews module
 * (Phase 7.2). One modal serves every review row on a page: each
 * row's delete button carries data-delete-url (the form target)
 * and data-delete-title (shown as the review name); the generic
 * handler in app.js copies both into the modal before it opens,
 * so the user sees exactly which review is about to be deleted.
 *
 * Delete is a POST to /reviews/{id}/delete, so it carries a CSRF
 * token like every state-changing form. Deleting is permanent:
 * the row is removed and the book's average rating / review count
 * are recalculated by ReviewService.
 *
 * Accessibility: aria-labelledby / aria-describedby announce the
 * whole context when the dialog opens.
 */

?>
<div class="modal fade" id="reviewDeleteModal" tabindex="-1" role="dialog"
     aria-labelledby="reviewDeleteModalLabel" aria-describedby="reviewDeleteModalWarning" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="reviewDeleteModalLabel">Delete review</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">
                    Are you sure you want to delete <strong id="reviewDeleteTitle"></strong>?
                </p>
                <p class="mb-0 mt-2" id="reviewDeleteModalWarning">
                    This permanently removes the review and its rating; the book&rsquo;s
                    average rating and review count are recalculated. This cannot be undone.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="post" id="reviewDeleteForm" class="m-0">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <button class="btn btn-danger" type="submit">
                        <i class="fa-solid fa-trash me-1" aria-hidden="true"></i>Delete review
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
