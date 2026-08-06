<?php

declare(strict_types=1);

/**
 * emails/partials/_body-reply.php
 *
 * The "someone replied to your review" email (reserved: the
 * review_replied event has no live producer yet - the template is
 * ready for the phase that adds replies).
 *
 * Available variables: $title, $message, $actionUrl, $actionLabel
 */

$message = $message ?? '';
?>
<h1 id="email-heading" style="margin:20px 0 8px; font-size:22px; line-height:1.3; color:#1c3532;">
    <?= e($title) ?>
</h1>
<p style="margin:0 0 20px; font-size:16px; line-height:1.6; color:#44685f;">
    <?= e($message) ?>
</p>
<?php require root_path('app/Views/emails/partials/_cta.php'); ?>