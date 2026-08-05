<?php

declare(strict_types=1);

/**
 * library/partials/_delete-modal.php
 *
 * The REMOVE-FROM-LIBRARY confirmation modal (Phase 8.2). One shared
 * Bootstrap modal serves every Remove button of the library page:
 * when it opens, the clicked button (relatedTarget) supplies the
 * form target and the book title, so the modal always posts to the
 * right record and never removes something else. It is wired by the
 * shared bindDeleteModal helper in app.js (no per-page script).
 *
 * The remove is a plain CSRF-protected POST (/library/{id}/delete);
 * the no-JS path already works because the buttons use the native
 * Bootstrap data attributes.
 */

?>
<div class="modal fade" id="libraryDeleteModal" tabindex="-1" aria-labelledby="libraryDeleteTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="libraryDeleteTitle">Remove book?</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>
                    Remove <strong id="libraryDeleteName"></strong> from your library?
                    The book stays in the catalogue - only your library entry is deleted.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <form id="libraryDeleteForm" method="post" action="/library">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <button type="submit" class="btn btn-danger">
                        <i class="fa-solid fa-trash me-1" aria-hidden="true"></i>Remove
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>