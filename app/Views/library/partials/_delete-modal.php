<?php

declare(strict_types=1);

/**
 * library/partials/_delete-modal.php
 *
 * The REMOVE-FROM-LIBRARY confirmation modal (Phase 8.2). One shared
 * Bootstrap modal serves every Remove control of the library page:
 * when it opens, the clicked control (relatedTarget) supplies the
 * form target and the book title, so the modal always posts to the
 * right record and never removes something else. It is wired by the
 * bindDeleteModal helper in library.js.
 *
 * The remove is a plain CSRF-protected POST (/library/{id}/delete);
 * the library.js show.bs.modal handler rewrites the form action from
 * the clicked control's data-delete-url before it is ever submitted.
 * The default action below is a placeholder - the modal can only open
 * via Bootstrap (JavaScript), and every open rewrites it. The TRUE
 * no-JS path is the card / row's inline remove form
 * ([data-library-delete-form]), a plain POST to the same endpoint
 * that works with scripts disabled.
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