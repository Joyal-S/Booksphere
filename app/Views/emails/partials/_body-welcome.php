<?php

declare(strict_types=1);

/**
 * emails/partials/_body-welcome.php
 *
 * The account-welcome email (reserved: produced the day registration
 * is wired to email; the template renders now).
 *
 * Available variables: $recipient, $actionUrl, $actionLabel
 */

?>
<h1 id="email-heading" style="margin:20px 0 8px; font-size:22px; line-height:1.3; color:#1c3532;">
    Welcome, <?= e($recipient !== '' ? $recipient : 'reader') ?>
</h1>
<p style="margin:0 0 20px; font-size:16px; line-height:1.6; color:#44685f;">
    Your BookSphere account is ready. Browse the catalogue, rate and
    review what you&rsquo;ve read, and discover the books people like you love.
</p>
<?php require root_path('app/Views/emails/partials/_cta.php'); ?>