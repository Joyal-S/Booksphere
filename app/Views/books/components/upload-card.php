<?php

declare(strict_types=1);

/**
 * books/components/upload-card.php
 *
 * The reusable MEDIA UPLOAD CARD: one drag-and-drop zone together
 * with a live preview, "Replace" and "Remove" actions. It replaces
 * the older plain file field for book covers and is written so any
 * future media type (author photos, review images) can reuse it.
 *
 * The server rules are enforced in two places, which is intentional:
 *
 *     - client-side (here + app.js): a friendly, instant response
 *       (type/size guard mirrors config/media.php)
 *     - server-side (MediaService): the actual security boundary,
 *       because client checks are trivially bypassed
 *
 * The "Remove cover" action relies on a hidden flag input. When the
 * user clicks Remove, JavaScript flips it to "1" and the controller
 * / BookService delete the stored file on save. The card for a book
 * sets `name="remove_cover"`; a different media type would set its
 * own flag name here.
 *
 * Usage (a view sets $upload first):
 *
 *     $upload = [
 *         'name'          => 'cover',             // input name
 *         'label'         => 'Cover image',
 *         'value'         => $currentCover,     // current URL or ''
 *         'errors'        => $errors,           // controller errors
 *         'accept'        => '.jpg,.png,.webp...',
 *         'accept_text'   => 'JPG, PNG or WebP, up to 5 MB.',
 *         'max_bytes'     => 5 * 1024 * 1024,
 *         'remove_name'   => 'remove_cover',    // hidden flag input
 *     ];
 *     <?php require root_path('app/Views/books/components/upload-card.php'); ?>
 */

$upload = array_merge([
    'name'        => 'file',
    'label'       => 'File',
    'value'       => '',
    'errors'      => [],
    'accept'      => 'image/jpeg,image/png,image/webp',
    'accept_text' => '',
    'max_bytes'   => 5 * 1024 * 1024,
    'remove_name' => 'remove',
], $upload ?? []);

$hasCover    = $upload['value'] !== '';
$hasError    = !empty($upload['errors'][$upload['name']]);
$inputId     = 'upload-' . preg_replace('/[^a-z0-9_-]/i', '', $upload['name']);
$emptyPrefix = 'The current cover will be removed when you save.';

?>
<div class="upload-card" data-upload-card
     data-has-current="<?= $hasCover ? '1' : '0' ?>"
     <?= $hasError ? 'aria-invalid="true"' : '' ?>>

    <div class="upload-preview" data-upload-preview-wrap>
        <img class="upload-preview-img" data-upload-preview
             src="<?= e($upload['value']) ?>" alt="Cover preview"
             <?= $hasCover ? '' : 'hidden' ?>>
        <span class="upload-preview-empty" data-upload-empty
              <?= $hasCover ? 'hidden' : '' ?>>
            <i class="fa-solid fa-book" aria-hidden="true"></i>
            <span class="d-block small mt-1">No cover selected</span>
        </span>
    </div>

    <div class="upload-body">
        <label class="form-label" for="<?= $inputId ?>">
            <?= e($upload['label']) ?>
        </label>

        <div class="upload-dropzone" data-upload-dropzone role="button" tabindex="0"
             aria-describedby="upload-hint-<?= $inputId ?>">
            <i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i>
            <span data-upload-hint><?= $hasCover ? 'Replace the current cover' : 'Choose a file or drag & drop' ?></span>
            <input class="visually-hidden" type="file" id="<?= $inputId ?>"
                   name="<?= e($upload['name']) ?>"
                   accept="<?= e($upload['accept']) ?>"
                   data-upload-input data-max-bytes="<?= (int) $upload['max_bytes'] ?>">
        </div>

        <div class="upload-actions">
            <button type="button" class="btn btn-sm btn-outline-primary" data-upload-browse>
                <i class="fa-solid fa-rotate me-1" aria-hidden="true"></i>Replace
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger" data-upload-remove-btn
                    <?= $hasCover ? '' : 'hidden' ?>>
                <i class="fa-solid fa-trash me-1" aria-hidden="true"></i>Remove
            </button>
            <input type="hidden" name="<?= e($upload['remove_name']) ?>" value="0"
                   data-upload-remove-flag>
        </div>

        <div class="form-text" id="upload-hint-<?= $inputId ?>">
            <?= e($upload['accept_text']) ?>
        </div>

        <p class="upload-error" data-upload-error hidden></p>

        <?php $field = $upload['name']; ?>
        <?php $errors = $upload['errors']; ?>
        <?php require root_path('app/Views/partials/form-errors.php'); ?>
    </div>
</div>