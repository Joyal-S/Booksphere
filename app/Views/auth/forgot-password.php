<?php

declare(strict_types=1);

/**
 * auth/forgot-password.php
 *
 * The password reset request form. Structure only: no email is
 * actually sent in this phase. The controller always answers with
 * the same neutral confirmation so addresses cannot be probed.
 */

?>
<div class="page-intro">
    <p class="eyebrow">Account</p>
    <h1>Reset your password</h1>
    <p class="lead">Enter your email address and we will send you a reset link.</p>
</div>

<div class="card-base" style="max-width: 520px;">
    <form method="post" action="/forgot-password">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

        <div class="mb-4">
            <label class="form-label" for="email">Email address</label>
            <input class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>"
                   type="email" id="email" name="email" maxlength="255" autocomplete="email"
                   value="<?= e($old['email'] ?? '') ?>" required autofocus>
            <?php $field = 'email'; ?>
            <?php require root_path('app/Views/partials/form-errors.php'); ?>
        </div>

        <button class="btn btn-primary w-100" type="submit">Send reset link</button>
    </form>

    <p class="mt-3 mb-0 small text-center text-muted">
        Remembered it? <a href="/login">Back to log in</a>
    </p>
</div>
