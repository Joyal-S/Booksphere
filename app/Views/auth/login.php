<?php

declare(strict_types=1);

/**
 * auth/login.php
 *
 * The sign-in form. A failed login re-renders this view with the
 * error in $errors; after a successful login the controller
 * redirects to the home page. When the login is locked (too many
 * failed attempts) the error tells the user how long to wait.
 */

?>
<div class="page-intro">
    <p class="eyebrow">Account</p>
    <h1>Welcome back</h1>
    <p class="lead">Log in to continue to your BookSphere library.</p>
</div>

<div class="card-base" style="max-width: 520px;">
    <form method="post" action="/login">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

        <div class="mb-3">
            <label class="form-label" for="email">Email address</label>
            <input class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>"
                   type="email" id="email" name="email" maxlength="255" autocomplete="email"
                   value="<?= e($old['email'] ?? '') ?>" required autofocus>
            <?php $field = 'email'; ?>
            <?php require root_path('app/Views/partials/form-errors.php'); ?>
        </div>

        <div class="mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <label class="form-label mb-0" for="password">Password</label>
                <a class="small" href="/forgot-password">Forgot password?</a>
            </div>
            <input class="form-control<?= isset($errors['password']) ? ' is-invalid' : '' ?>"
                   type="password" id="password" name="password" autocomplete="current-password" required>
            <?php $field = 'password'; ?>
            <?php require root_path('app/Views/partials/form-errors.php'); ?>
        </div>

        <button class="btn btn-primary w-100" type="submit">Log in</button>
    </form>

    <p class="mt-3 mb-0 small text-center text-muted">
        New to BookSphere? <a href="/register">Create an account</a>
    </p>
</div>
