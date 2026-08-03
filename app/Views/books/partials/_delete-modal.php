<?php

declare(strict_types=1);

/**
 * books/partials/_delete-modal.php
 *
 * The shared DELETE CONFIRMATION modal. One modal serves every
 * row: the row's delete button carries data-delete-url (the form
 * target), data-delete-title (shown as the book name) and
 * data-delete-cover (a small cover preview inside the dialog).
 * A small handler in app.js copies all three into the modal
 * before it opens, so the administrator sees exactly which book
 * is about to be deleted - title and cover together.
 *
 * Delete is a POST to the route /books/{id}/delete, so it carries
 * a CSRF token like every state-changing form. The delete is a
 * SOFT delete: the row keeps its data and an administrator can
 * restore it later.
 *
 * Accessibility: aria-labelledby points at the modal title and
 * aria-describedby at the warning paragraph, so screen readers
 * announce the whole context when the dialog opens.
 */

?>
<div class="modal fade" id="deleteModal" tabindex="-1" role="dialog"
     aria-labelledby="deleteModalLabel" aria-describedby="deleteModalWarning" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="deleteModalLabel">Delete book</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="delete-modal-preview">
                    <img class="modal-cover" id="deleteBookCover" src="" alt="Cover of the book to delete" hidden>
                    <span class="modal-cover modal-cover-fallback" id="deleteBookCoverFallback" hidden>
                        <i class="fa-solid fa-book" aria-hidden="true"></i>
                    </span>
                    <div>
                        <p class="mb-0">
                            Are you sure you want to delete <strong id="deleteBookTitle"></strong>?
                        </p>
                        <p class="mb-0 mt-2" id="deleteModalWarning">
                            This is a soft delete: the book is hidden from the catalogue,
                            but its data is kept so an administrator can restore it later.
                        </p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="post" id="deleteForm" class="m-0">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <button class="btn btn-danger" type="submit">
                        <i class="fa-solid fa-trash me-1" aria-hidden="true"></i>Delete book
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
