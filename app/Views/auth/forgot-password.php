<?php

declare(strict_types=1);

/**
 * auth/forgot-password.php
 *
 * The request-a-reset-email screen. A failed validation re-renders
 * this view with $errors; a successful request re-renders with
 * $sent = true and shows the neutral success card (which never
 * reveals whether the address actually has an account). No mailer is
 * configured yet, so in demo mode the issued reset link is shown in
 * a clearly-labelled note.
 *
 * Available variables:
 *     $old        - previous input, e.g. ['email' => ...]
 *     $errors     - per-field error message arrays
 *     $sent       - whether a request was just accepted
 *     $sent_to    - the submitted email address
 *     $reset_link - demo-mode reset link, or null when the account
 *                   does not exist (neutral anti-probing response)
 */

?>
<?php if (!empty($sent)): ?>
    <div class="auth-success">
        <div class="auth-success-badge">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 16 16 10 8 10"/></svg>
        </div>
        <h1 class="auth-success-title">Check your inbox</h1>
        <p class="auth-success-text">If an account exists for <strong><?= e($sent_to) ?></strong>, a password reset link has been generated for it. The link expires after 60 minutes and can be used only once.</p>

        <?php if ($reset_link !== null): ?>
            <div class="auth-demo-note">
                <p><strong>Demo mode</strong> — no mail service is configured, so your reset link is:</p>
                <a href="<?= e($reset_link) ?>"><?= e($reset_link) ?></a>
            </div>
        <?php endif; ?>

        <a class="auth-btn auth-btn--primary auth-btn--block" href="/login">Back to Login</a>
    </div>
<?php else: ?>
    <div class="auth-screen">
        <a class="auth-back" href="/login">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
            Back to Login
        </a>

        <header class="auth-screen-header">
            <div class="auth-screen-icon" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>
            </div>
            <h1 class="auth-title">Forgot Password?</h1>
            <p class="auth-sub">Enter your email address and we'll send you a link to reset your password.</p>
        </header>

        <form class="auth-form" method="post" action="/forgot-password" data-auth-form>
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
            ?>

            <button class="auth-btn auth-btn--primary auth-btn--block" type="submit" data-auth-submit>Send Reset Link</button>
        </form>
    </div>
<?php endif; ?>