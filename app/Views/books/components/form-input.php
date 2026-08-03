<?php

declare(strict_types=1);

/**
 * books/components/form-input.php
 *
 * The reusable FORM FIELD: one labelled input (text, number,
 * textarea, select or file) together with its validation errors
 * and helper text. The book form is assembled from these fields,
 * so every input follows the same markup and error pattern.
 *
 * Usage (a view sets $field first):
 *
 *     $field = [
 *         'name'        => 'title',
 *         'label'       => 'Title',
 *         'type'        => 'text',       // text | number | textarea | select | file
 *         'value'       => $old['title'] ?? '',
 *         'errors'      => $errors,      // the errors array from the controller
 *         'required'    => true,         // optional
 *         'maxlength'   => 255,          // optional
 *         'min' / 'max' => 1, 2026,      // optional (number fields)
 *         'options'     => [key => label], // select fields
 *         'selected'    => 'en',         // select fields
 *         'help'        => 'JPG, PNG or WebP.', // optional helper text
 *         'placeholder' => '9780000000000',     // optional
 *         'autofocus'   => true,         // optional
 *     ];
 *     <?php require root_path('app/Views/books/components/form-input.php'); ?>
 *
 * Bootstrap's is-invalid class is added automatically when the
 * field has errors, and the errors are rendered below the input
 * through the shared partials/form-errors.php.
 */

$field = array_merge([
    'name'        => '',
    'label'       => '',
    'type'        => 'text',
    'value'       => '',
    'errors'      => [],
    'required'    => false,
    'maxlength'   => null,
    'min'         => null,
    'max'         => null,
    'options'     => [],
    'selected'    => null,
    'help'        => '',
    'placeholder' => '',
    'autofocus'   => false,
    'rows'        => 5,
    'accept'      => '',
    'attributes'  => [],   // extra HTML attributes, e.g. data-* hooks
], $field ?? []);

$id        = 'field-' . $field['name'];
$hasError  = !empty($field['errors'][$field['name']]);
$invalid   = $hasError ? ' is-invalid' : '';
$required  = $field['required'] ? ' required' : '';
$maxlength = $field['maxlength'] !== null ? ' maxlength="' . (int) $field['maxlength'] . '"' : '';
$autofocus = $field['autofocus'] ? ' autofocus' : '';
$value     = e((string) $field['value']);
$inputId   = 'id="' . $id . '" name="' . e($field['name']) . '"';

// Render extra attributes (keys stay as-is; values are escaped).
$extraAttributes = '';
foreach ($field['attributes'] as $attrName => $attrValue) {
    $extraAttributes .= ' ' . e((string) $attrName) . '="' . e((string) $attrValue) . '"';
}

?>
<div class="mb-3">
    <label class="form-label" for="<?= $id ?>">
        <?= e($field['label']) ?>
        <?php if ($field['required']): ?>
            <span class="text-danger" aria-hidden="true">*</span>
            <span class="visually-hidden">(required)</span>
        <?php endif; ?>
    </label>

    <?php if ($field['type'] === 'textarea'): ?>
        <textarea class="form-control<?= $invalid ?>" <?= $inputId ?> rows="<?= (int) $field['rows'] ?>"<?= $maxlength . $required . $autofocus ?>
                  placeholder="<?= e($field['placeholder']) ?>"><?= $value ?></textarea>

    <?php elseif ($field['type'] === 'select'): ?>
        <select class="form-select<?= $invalid ?>" <?= $inputId . $required ?>>
            <?php foreach ($field['options'] as $optionValue => $optionLabel): ?>
                <option value="<?= e((string) $optionValue) ?>"
                    <?= ($field['selected'] ?? $field['value']) == $optionValue ? 'selected' : '' ?>>
                    <?= e($optionLabel) ?>
                </option>
            <?php endforeach; ?>
        </select>

    <?php elseif ($field['type'] === 'file'): ?>
        <input class="form-control<?= $invalid ?>" <?= $inputId ?> type="file"
               accept="<?= e($field['accept']) ?>"<?= $required . $extraAttributes ?>>

    <?php else: ?>
        <input class="form-control<?= $invalid ?>" <?= $inputId ?> type="<?= e($field['type']) ?>"<?= $maxlength . $required . $autofocus ?>
               <?= $field['min'] !== null ? 'min="' . (int) $field['min'] . '"' : '' ?>
               <?= $field['max'] !== null ? 'max="' . (int) $field['max'] . '"' : '' ?>
               placeholder="<?= e($field['placeholder']) ?>" value="<?= $value ?>">
    <?php endif; ?>

    <?php if ($field['help'] !== ''): ?>
        <div class="form-text"><?= e($field['help']) ?></div>
    <?php endif; ?>

    <?php $field = $field['name']; ?>
    <?php require root_path('app/Views/partials/form-errors.php'); ?>
</div>
