<?php

declare(strict_types=1);

/**
 * auth/reset-password.php
 *
 * The redeem-a-reset-token screen. An unknown, expired or already
 * used token renders the invalid state below; otherwise the form
 * posts the token + new password back to /reset-password where the
 * single-use token is consumed.
 *
 * Available variables:
 *     $invalid - true when the presented token is not redeemable
 *     $token   - the raw token (travels back as a hidden field)
 *     $old     - previous input
 *     $errors  - per-field error message arrays
 */

?>
<?php if (!empty($invalid)): ?>
    <div class="auth-success">
        <div class="auth-success-badge auth-success-badge--muted">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        </div>
        <h1 class="auth-success-title">Reset link invalid</h1>
        <p class="auth-success-text">This password reset link is invalid, has expired, or has already been used. Request a fresh link to continue.</p>
        <a class="auth-btn auth-btn--primary auth-btn--block" href="/forgot-password">Request a New Link</a>
        <a class="auth-back auth-back--center" href="/login">Back to Login</a>
    </div>
<?php else: ?>
    <div class="auth-screen">
        <a class="auth-back" href="/login">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
            Back to Login
        </a>

        <header class="auth-screen-header">
            <div class="auth-screen-icon" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2.5"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </div>
            <h1 class="auth-title">Choose a new password</h1>
            <p class="auth-sub">Pick a strong password you haven't used before.</p>
        </header>

        <form class="auth-form" method="post" action="/reset-password" data-auth-form>
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="token" value="<?= e($token) ?>">

            <?php
            $f = [
                'name'         => 'password',
                'label'        => 'New Password',
                'type'         => 'password',
                'placeholder'  => 'Min. 8 characters',
                'autocomplete' => 'new-password',
                'icon'         => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2.5"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
                'trailing'     => '<button class="auth-eye" type="button" data-auth-eye="field-password" aria-label="Show password"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>',
                'error'        => $errors['password'][0] ?? null,
            ];
            require root_path('app/Views/auth/partials/_field.php');
            ?>

            <div class="auth-strength auth-hidden" data-auth-strength>
                <div class="auth-strength-bars" aria-hidden="true">
                    <span class="auth-strength-bar" data-auth-bar></span>
                    <span class="auth-strength-bar" data-auth-bar></span>
                    <span class="auth-strength-bar" data-auth-bar></span>
                    <span class="auth-strength-bar" data-auth-bar></span>
                </div>
                <p class="auth-strength-label" data-auth-strength-label role="status"></p>
            </div>

            <?php
            $f = [
                'name'         => 'password_confirmation',
                'label'        => 'Confirm New Password',
                'type'         => 'password',
                'placeholder'  => 'Repeat your new password',
                'autocomplete' => 'new-password',
                'icon'         => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2.5"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
                'trailing'     => '<button class="auth-eye" type="button" data-auth-eye="field-password_confirmation" aria-label="Show password"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>',
                'error'        => $errors['password_confirmation'][0] ?? null,
            ];
            require root_path('app/Views/auth/partials/_field.php');
            ?>

            <button class="auth-btn auth-btn--primary auth-btn--block" type="submit" data-auth-submit>Update Password</button>
        </form>
    </div>
<?php endif; ?>