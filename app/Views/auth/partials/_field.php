<?php

declare(strict_types=1);

/**
 * auth/partials/_field.php
 *
 * The reusable form field of the authentication pages: label, icon,
 * input, optional trailing control (password eye toggle) and inline
 * error message - rendered once, used by every screen so the four
 * forms never duplicate markup.
 *
 * Include with a $f configuration array:
 *
 *     $f = [
 *         'name'         => 'email',            // input name + id
 *         'label'        => 'Email Address',
 *         'type'         => 'email',
 *         'value'        => $old['email'] ?? '',
 *         'placeholder'  => 'you@example.com',
 *         'autocomplete' => 'email',
 *         'icon'         => '<svg>...</svg>',
 *         'trailing'     => '<button ...>...</button>', // optional
 *         'error'        => $errors['email'][0] ?? null,
 *         'hint'         => null,               // optional helper text
 *     ];
 *     require root_path('app/Views/auth/partials/_field.php');
 *
 * The data-auth-field / data-auth-error hooks are what auth.js uses
 * to show client-side validation errors in the exact same UI.
 */

$f = array_merge([
    'name'         => '',
    'label'        => '',
    'type'         => 'text',
    'value'        => '',
    'placeholder'  => '',
    'autocomplete' => '',
    'maxlength'    => null,
    'icon'         => '',
    'trailing'     => '',
    'error'        => null,
    'hint'         => null,
], $f ?? []);

$fieldId = 'field-' . $f['name'];

?>
<div class="auth-field<?= $f['error'] !== null ? ' auth-field--error' : '' ?>">
    <label class="auth-label" for="<?= e($fieldId) ?>"><?= e($f['label']) ?></label>
    <div class="auth-input-wrap">
        <?php if ($f['icon'] !== ''): ?>
            <span class="auth-input-icon" aria-hidden="true"><?= $f['icon'] ?></span>
        <?php endif; ?>
        <input
            class="auth-input"
            id="<?= e($fieldId) ?>"
            name="<?= e($f['name']) ?>"
            type="<?= e($f['type']) ?>"
            value="<?= e((string) $f['value']) ?>"
            placeholder="<?= e($f['placeholder']) ?>"
            data-auth-field="<?= e($f['name']) ?>"
            <?= $f['autocomplete'] !== '' ? 'autocomplete="' . e($f['autocomplete']) . '"' : '' ?>
            <?= $f['maxlength'] !== null ? 'maxlength="' . (int) $f['maxlength'] . '"' : '' ?>
            <?= $f['error'] !== null ? 'aria-invalid="true" aria-describedby="error-' . e($f['name']) . '"' : '' ?>>
        <?= $f['trailing'] ?>
    </div>
    <?php if ($f['error'] !== null): ?>
        <p class="auth-field-error" id="error-<?= e($f['name']) ?>" data-auth-error="<?= e($f['name']) ?>" role="alert">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><?= e($f['error']) ?></span>
        </p>
    <?php else: ?>
        <p class="auth-field-error auth-hidden" id="error-<?= e($f['name']) ?>" data-auth-error="<?= e($f['name']) ?>" role="alert">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span></span>
        </p>
    <?php endif; ?>
    <?php if ($f['hint'] !== null): ?>
        <p class="auth-field-hint"><?= $f['hint'] ?></p>
    <?php endif; ?>
</div>