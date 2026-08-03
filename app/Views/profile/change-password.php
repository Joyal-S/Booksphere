<?php

declare(strict_types=1);

/**
 * profile/change-password.php
 *
 * The form to change the password. The user must confirm the
 * current password first; the new password must be confirmed and
 * at least 8 characters long. On failure the controller re-renders
 * this view with the errors in $errors.
 */

?>
<div class="page-intro">
    <p class="eyebrow">My account</p>
    <h1>Change password</h1>
    <p class="lead">Choose a strong password you do not use anywhere else.</p>
</div>

<div class="card-base" style="max-width: 520px;">
    <form method="post" action="/change-password">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

        <div class="mb-3">
            <label class="form-label" for="current_password">Current password</label>
            <input class="form-control<?= isset($errors['current_password']) ? ' is-invalid' : '' ?>"
                   type="password" id="current_password" name="current_password"
                   autocomplete="current-password" required autofocus>
            <?php $field = 'current_password'; ?>
            <?php require root_path('app/Views/partials/form-errors.php'); ?>
        </div>

        <div class="mb-3">
            <label class="form-label" for="password">New password</label>
            <input class="form-control<?= isset($errors['password']) ? ' is-invalid' : '' ?>"
                   type="password" id="password" name="password" autocomplete="new-password" required>
            <div class="form-text">At least 8 characters.</div>
            <?php $field = 'password'; ?>
            <?php require root_path('app/Views/partials/form-errors.php'); ?>
        </div>

        <div class="mb-4">
            <label class="form-label" for="password_confirmation">Confirm new password</label>
            <input class="form-control<?= isset($errors['password_confirmation']) ? ' is-invalid' : '' ?>"
                   type="password" id="password_confirmation" name="password_confirmation"
                   autocomplete="new-password" required>
            <?php $field = 'password_confirmation'; ?>
            <?php require root_path('app/Views/partials/form-errors.php'); ?>
        </div>

        <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit">Change password</button>
            <a class="btn btn-outline-secondary" href="/profile">Cancel</a>
        </div>
    </form>
</div>
