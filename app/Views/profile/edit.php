<?php

declare(strict_types=1);

/**
 * profile/edit.php
 *
 * The form to change the display name and email address. On
 * validation failure the controller re-renders this view with
 * $errors and the previous input in $old.
 */

?>
<div class="page-intro">
    <p class="eyebrow">My account</p>
    <h1>Edit profile</h1>
    <p class="lead">Update your name and email address.</p>
</div>

<div class="card-base" style="max-width: 520px;">
    <form method="post" action="/profile/edit">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

        <div class="mb-3">
            <label class="form-label" for="full_name">Full name</label>
            <input class="form-control<?= isset($errors['full_name']) ? ' is-invalid' : '' ?>"
                   type="text" id="full_name" name="full_name" maxlength="100" autocomplete="name"
                   value="<?= e($old['full_name'] ?? '') ?>" required autofocus>
            <?php $field = 'full_name'; ?>
            <?php require root_path('app/Views/partials/form-errors.php'); ?>
        </div>

        <div class="mb-4">
            <label class="form-label" for="email">Email address</label>
            <input class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>"
                   type="email" id="email" name="email" maxlength="255" autocomplete="email"
                   value="<?= e($old['email'] ?? '') ?>" required>
            <?php $field = 'email'; ?>
            <?php require root_path('app/Views/partials/form-errors.php'); ?>
        </div>

        <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit">Save changes</button>
            <a class="btn btn-outline-secondary" href="/profile">Cancel</a>
        </div>
    </form>
</div>
