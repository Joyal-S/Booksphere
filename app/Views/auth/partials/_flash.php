<?php

declare(strict_types=1);

/**
 * auth/partials/_flash.php
 *
 * One-time flash messages (set with session()->flash() after a
 * redirect, PRG pattern) rendered as banners styled for the auth
 * pages, then cleared so they appear exactly once. The counterpart
 * of the app shell's partials/flash.php, scoped to auth.css.
 *
 * Available variables:
 *     $flashSuccess / $flashError - read from the session
 */

$flashSuccess = session()->getFlash('success');
$flashError   = session()->getFlash('error');

if ($flashSuccess !== null): ?>
    <div class="auth-alert auth-alert--success" role="status">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <p><?= e((string) $flashSuccess) ?></p>
    </div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
    <div class="auth-alert auth-alert--danger" role="alert">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <p><?= e((string) $flashError) ?></p>
    </div>
<?php endif; ?>

<?php session()->clearFlash();