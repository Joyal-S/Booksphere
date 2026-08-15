<?php

declare(strict_types=1);

/**
 * auth/register.php
 *
 * The account-creation screen. Mirrors auth/login.php: server-side
 * validation failures re-render with $old + $errors, a new account
 * redirects to /login with a flash (PRG). The password field carries
 * a live strength meter that auth.js paints as the user types.
 *
 * Available variables:
 *     $old    - previous input (full_name, email, password, ...)
 *     $errors - per-field error message arrays
 */

?>
<div class="auth-screen">
    <header class="auth-screen-header">
        <h1 class="auth-title">Create Account</h1>
        <p class="auth-sub">Start your reading journey today.</p>
    </header>

    <form class="auth-form" method="post" action="/register" autocomplete="off" data-auth-form>
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

        <div class="auth-grid-2">
            <?php
            $f = [
                'name'         => 'full_name',
                'label'        => 'Full Name',
                'type'         => 'text',
                'value'        => $old['full_name'] ?? '',
                'placeholder'  => 'Enter your full name',
                'autocomplete' => 'off',
                'maxlength'    => 100,
                'icon'         => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
                'error'        => $errors['full_name'][0] ?? null,
            ];
            require root_path('app/Views/auth/partials/_field.php');
            ?>

            <?php
            $f = [
                'name'         => 'email',
                'label'        => 'Email Address',
                'type'         => 'email',
                'value'        => $old['email'] ?? '',
                'placeholder'  => 'Enter your email',
                'autocomplete' => 'off',
                'maxlength'    => 255,
                'icon'         => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="4.5" width="19" height="15" rx="3"/><path d="m3 6 9 6.5L21 6"/></svg>',
                'error'        => $errors['email'][0] ?? null,
            ];
            require root_path('app/Views/auth/partials/_field.php');
            ?>
        </div>

        <?php
        $f = [
            'name'         => 'password',
            'label'        => 'Password',
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
            'label'        => 'Confirm Password',
            'type'         => 'password',
            'placeholder'  => 'Repeat your password',
            'autocomplete' => 'new-password',
            'icon'         => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2.5"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
            'trailing'     => '<button class="auth-eye" type="button" data-auth-eye="field-password_confirmation" aria-label="Show password"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>',
            'error'        => $errors['password_confirmation'][0] ?? null,
        ];
        require root_path('app/Views/auth/partials/_field.php');
        ?>

        <?php $termsError = $errors['terms'][0] ?? null; ?>
        <label class="auth-check<?= $termsError !== null ? ' auth-check--error' : '' ?>">
            <input class="auth-check-input" type="checkbox" name="terms" value="1">
            <span class="auth-check-box" aria-hidden="true">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            </span>
            <span class="auth-check-label">I agree to the <strong>Terms of Service</strong> and <strong>Privacy Policy</strong></span>
        </label>
        <?php if ($termsError !== null): ?>
            <p class="auth-field-error" data-auth-error="terms" role="alert">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span><?= e($termsError) ?></span>
            </p>
        <?php endif; ?>

        <button class="auth-btn auth-btn--primary auth-btn--block" type="submit" data-auth-submit>Create Account</button>
    </form>

    <p class="auth-switch">Already have an account? <a class="auth-link" href="/login">Sign In</a></p>
</div>