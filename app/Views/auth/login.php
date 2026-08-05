<?php

declare(strict_types=1);

/**
 * auth/login.php
 *
 * The sign-in screen inside the shareable auth layout. On validation
 * or credential failure the controller re-renders this view with
 * $errors and the previous input in $old; after a successful login it
 * redirects (PRG). auth.js progressively enhances the form with
 * inline client-side validation and loading states.
 *
 * Available variables:
 *     $old    - previous input, e.g. ['email' => ..., 'remember' => ...]
 *     $errors - per-field error message arrays
 */

?>
<div class="auth-screen">
    <header class="auth-screen-header">
        <h1 class="auth-title">Welcome back</h1>
        <p class="auth-sub">Sign in to continue discovering your next favourite book.</p>
    </header>

    <form class="auth-form" method="post" action="/login" data-auth-form>
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

        <?php
        $f = [
            'name'         => 'email',
            'label'        => 'Email Address',
            'type'         => 'email',
            'value'        => $old['email'] ?? '',
            'placeholder'  => 'you@example.com',
            'autocomplete' => 'email',
            'maxlength'    => 255,
            'icon'         => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="4.5" width="19" height="15" rx="3"/><path d="m3 6 9 6.5L21 6"/></svg>',
            'error'        => $errors['email'][0] ?? null,
        ];
        require root_path('app/Views/auth/partials/_field.php');

        $f = [
            'name'         => 'password',
            'label'        => 'Password',
            'type'         => 'password',
            'placeholder'  => 'Enter your password',
            'autocomplete' => 'current-password',
            'icon'         => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2.5"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
            'trailing'     => '<button class="auth-eye" type="button" data-auth-eye="field-password" aria-label="Show password"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>',
            'error'        => $errors['password'][0] ?? null,
        ];
        require root_path('app/Views/auth/partials/_field.php');
        ?>

        <?php $checked = !empty($old['remember']); ?>
        <div class="auth-row auth-row--between">
            <label class="auth-check">
                <input class="auth-check-input" type="checkbox" name="remember" value="1" <?= $checked ? 'checked' : '' ?>>
                <span class="auth-check-box" aria-hidden="true">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                </span>
                <span class="auth-check-label">Remember me for 30 days</span>
            </label>
            <a class="auth-link" href="/forgot-password">Forgot password?</a>
        </div>

        <button class="auth-btn auth-btn--primary auth-btn--block" type="submit" data-auth-submit>Sign In</button>
    </form>

    <p class="auth-switch">Don't have an account? <a class="auth-link" href="/register">Create Account</a></p>
</div>