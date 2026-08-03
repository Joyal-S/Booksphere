<?php

declare(strict_types=1);

/**
 * profile/show.php
 *
 * Displays the logged-in user's profile: name, email, role and
 * member-since date, with links to edit the profile and change
 * the password. $user is provided by the controller.
 */

?>
<div class="page-intro">
    <p class="eyebrow">My account</p>
    <h1>My profile</h1>
    <p class="lead">Your account details and settings.</p>
</div>

<div class="card-base" style="max-width: 640px;">
    <div class="d-flex align-items-start justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            <span class="icon-button" aria-hidden="true" style="font-size: 1.25rem;">
                <i class="fa-solid fa-user"></i>
            </span>
            <div>
                <h2 class="mb-0"><?= e($user['full_name']) ?></h2>
                <span class="badge text-bg-<?= $user['role'] === 'admin' ? 'danger' : 'secondary' ?>"><?= e($user['role']) ?></span>
            </div>
        </div>
    </div>

    <hr>

    <dl class="row mb-0">
        <dt class="col-sm-3 text-muted">Full name</dt>
        <dd class="col-sm-9"><?= e($user['full_name']) ?></dd>

        <dt class="col-sm-3 text-muted">Email address</dt>
        <dd class="col-sm-9"><?= e($user['email']) ?></dd>

        <dt class="col-sm-3 text-muted">Role</dt>
        <dd class="col-sm-9"><?= e($user['role']) ?></dd>

        <dt class="col-sm-3 text-muted">Member since</dt>
        <dd class="col-sm-9"><?= e(date('F j, Y', strtotime($user['created_at']))) ?></dd>
    </dl>
</div>

<div class="d-flex gap-2 mt-4" style="max-width: 640px;">
    <a class="btn btn-primary" href="/profile/edit"><i class="fa-solid fa-pen me-1" aria-hidden="true"></i> Edit profile</a>
    <a class="btn btn-outline-secondary" href="/change-password"><i class="fa-solid fa-key me-1" aria-hidden="true"></i> Change password</a>
</div>
