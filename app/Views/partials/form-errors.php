<?php

declare(strict_types=1);

/**
 * partials/form-errors.php
 *
 * Renders the validation errors of one form field below its input.
 * Included from a form view, which must set these variables:
 *
 *     $field  - the field name, e.g. "email"
 *     $errors - the errors array passed by the controller
 *
 * The Bootstrap "invalid-feedback" style is forced visible with
 * d-block because the inputs do not use the novalidate interplay
 * that normally toggles it.
 */

if (!empty($errors[$field])): ?>
    <div class="invalid-feedback d-block">
        <?php foreach ($errors[$field] as $message): ?>
            <p class="mb-0"><?= e($message) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
