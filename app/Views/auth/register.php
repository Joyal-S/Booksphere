<?php

declare(strict_types=1);

/**
 * auth/register.php
 *
 * The account creation form. The "_token" hidden field is required
 * by CsrfMiddleware on the POST route. On validation failure the
 * controller re-renders this view with $errors and the previous
 * input in $old.
 */

?>
<div class="page-intro">
    <p class="eyebrow">Account</p>
    <h1>Create your account</h1>
    <p class="lead">Join BookSphere to save books and get personalised recommendations.</p>
</div>

<div class="card-base" style="max-width: 520px;">
    <form method="post" action="/register">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

        <div class="mb-3">
            <label class="form-label" for="full_name">Full name</label>
            <input class="form-control<?= isset($errors['full_name']) ? ' is-invalid' : '' ?>"
                   type="text" id="full_name" name="full_name" maxlength="100" autocomplete="name"
                   value="<?= e($old['full_name'] ?? '') ?>" required autofocus>
            <?php $field = 'full_name'; ?>
            <?php require root_path('app/Views/partials/form-errors.php'); ?>
        </div>

        <div class="mb-3">
            <label class="form-label" for="email">Email address</label>
            <input class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>"
                   type="email" id="email" name="email" maxlength="255" autocomplete="email"
                   value="<?= e($old['email'] ?? '') ?>" required>
            <?php $field = 'email'; ?>
            <?php require root_path('app/Views/partials/form-errors.php'); ?>
        </div>

        <div class="mb-3">
            <label class="form-label" for="password">Password</label>
            <input class="form-control<?= isset($errors['password']) ? ' is-invalid' : '' ?>"
                   type="password" id="password" name="password" autocomplete="new-password" required>
            <div class="form-text">At least 8 characters.</div>
            <?php $field = 'password'; ?>
            <?php require root_path('app/Views/partials/form-errors.php'); ?>
        </div>

        <div class="mb-4">
            <label class="form-label" for="password_confirmation">Confirm password</label>
            <input class="form-control<?= isset($errors['password_confirmation']) ? ' is-invalid' : '' ?>"
                   type="password" id="password_confirmation" name="password_confirmation"
                   autocomplete="new-password" required>
            <?php $field = 'password_confirmation'; ?>
            <?php require root_path('app/Views/partials/form-errors.php'); ?>
        </div>

        <button class="btn btn-primary w-100" type="submit">Create account</button>
    </form>

    <p class="mt-3 mb-0 small text-center text-muted">
        Already have an account? <a href="/login">Log in</a>
    </p>
</div>
