<?php

declare(strict_types=1);

/**
 * profile/edit.php
 *
 * The form to change the profile picture, display name, and email address.
 * On validation failure the controller re-renders this view with
 * $errors and the previous input in $old.
 */

$user = $user ?? [];
$initials = strtoupper(substr($user['full_name'] ?? 'U', 0, 1));
$hasAvatar = !empty($user['avatar_path']);
?>
<div class="page-intro">
    <p class="eyebrow">My account</p>
    <h1>Edit profile</h1>
    <p class="lead">Update your profile photo, display name, and email address.</p>
</div>

<div class="row g-4" style="max-width: 960px;">
    <!-- Profile Photo Management Card -->
    <div class="col-12 col-md-5">
        <div class="card-base h-100 d-flex flex-column justify-content-between">
            <div>
                <h2 class="h5 mb-1 d-flex align-items-center gap-2">
                    <i class="fa-solid fa-camera text-primary" aria-hidden="true"></i>
                    Profile picture
                </h2>
                <p class="text-muted small mb-3">Upload a JPG, PNG, or WebP photo up to 5 MB.</p>

                <!-- Avatar Preview Area -->
                <div class="d-flex flex-column align-items-center text-center my-3">
                    <div id="avatar_preview_container" class="position-relative mb-3" style="width: 110px; height: 110px;">
                        <img id="avatar_preview_img"
                             src="<?= $hasAvatar ? e(avatar_url($user['avatar_path'])) : '' ?>"
                             alt="<?= e($user['full_name'] ?? 'Profile picture') ?>"
                             class="avatar-img shadow-sm"
                             style="width: 110px; height: 110px; object-fit: cover; border-radius: 50%; border: 3px solid var(--border); display: <?= $hasAvatar ? 'block' : 'none' ?>;">

                        <div id="avatar_fallback_circle"
                             class="shadow-sm"
                             style="width: 110px; height: 110px; border-radius: 50%; background: linear-gradient(135deg, var(--brand-primary, #6366f1), #8b5cf6); color: #fff; font-size: 2.5rem; font-weight: 700; display: <?= $hasAvatar ? 'none' : 'flex' ?>; align-items: center; justify-content: center; border: 3px solid var(--border);">
                            <?= e($initials) ?>
                        </div>
                    </div>

                    <div id="file_feedback" class="small text-muted mb-2" style="min-height: 1.25rem;"></div>
                </div>

                <!-- Avatar Upload Form -->
                <form method="post" action="/profile/avatar" enctype="multipart/form-data" id="avatar_form" class="mb-3">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

                    <div class="mb-3">
                        <label for="avatar_input" class="form-label small fw-semibold">Choose photo</label>
                        <input class="form-control form-control-sm"
                               type="file"
                               id="avatar_input"
                               name="avatar"
                               accept="image/jpeg,image/png,image/webp"
                               required>
                    </div>

                    <button type="submit" id="avatar_submit_btn" class="btn btn-primary btn-sm w-100 d-inline-flex align-items-center justify-content-center gap-2">
                        <i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i>
                        <span><?= $hasAvatar ? 'Change Photo' : 'Upload Photo' ?></span>
                    </button>
                </form>
            </div>

            <!-- Remove Photo Form (if avatar is set) -->
            <?php if ($hasAvatar): ?>
                <div class="pt-3 border-top" style="border-color: var(--border) !important;">
                    <form method="post" action="/profile/avatar/remove" onsubmit="return confirm('Are you sure you want to remove your profile photo?');">
                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm w-100 d-inline-flex align-items-center justify-content-center gap-2">
                            <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                            <span>Remove Photo</span>
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Account Details Form Card -->
    <div class="col-12 col-md-7">
        <div class="card-base h-100">
            <h2 class="h5 mb-1 d-flex align-items-center gap-2">
                <i class="fa-solid fa-user-pen text-primary" aria-hidden="true"></i>
                Account details
            </h2>
            <p class="text-muted small mb-4">Update your display name and email address.</p>

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
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var fileInput = document.getElementById('avatar_input');
    var previewImg = document.getElementById('avatar_preview_img');
    var fallbackCircle = document.getElementById('avatar_fallback_circle');
    var feedback = document.getElementById('file_feedback');
    var submitBtn = document.getElementById('avatar_submit_btn');

    if (!fileInput) return;

    var maxSizeBytes = 5 * 1024 * 1024; // 5 MB
    var allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];

    fileInput.addEventListener('change', function () {
        if (!fileInput.files || !fileInput.files[0]) {
            return;
        }

        var file = fileInput.files[0];

        // Format validation (MIME & extension)
        var ext = file.name.split('.').pop().toLowerCase();
        var allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
        if ((file.type && !allowedTypes.includes(file.type)) || !allowedExts.includes(ext)) {
            feedback.innerHTML = '<span class="text-danger"><i class="fa-solid fa-circle-exclamation me-1"></i>Please upload a JPG, PNG, or WebP image.</span>';
            submitBtn.disabled = true;
            return;
        }

        // Size validation
        if (file.size > maxSizeBytes) {
            feedback.innerHTML = '<span class="text-danger"><i class="fa-solid fa-circle-exclamation me-1"></i>Image must be 5 MB or smaller.</span>';
            submitBtn.disabled = true;
            return;
        }

        // Live preview
        var reader = new FileReader();
        reader.onload = function (e) {
            previewImg.src = e.target.result;
            previewImg.style.display = 'block';
            if (fallbackCircle) {
                fallbackCircle.style.display = 'none';
            }
            var sizeMb = (file.size / (1024 * 1024)).toFixed(2);
            feedback.innerHTML = '<span class="text-success"><i class="fa-solid fa-circle-check me-1"></i>' + file.name + ' (' + sizeMb + ' MB) ready to upload.</span>';
            submitBtn.disabled = false;
        };
        reader.readAsDataURL(file);
    });
});
</script>

