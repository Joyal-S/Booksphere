<?php

declare(strict_types=1);

/**
 * emails/partials/_body-follow.php
 *
 * The "you started following an author" email body: a heading, the
 * friendly message the in-app notification carried, and the CTA back
 * to the author's page.
 *
 * Available variables: $recipient, $title, $message, $actionUrl,
 *     $actionLabel
 */

$message = $message ?? '';
?>
<h1 id="email-heading" style="margin:20px 0 8px; font-size:22px; line-height:1.3; color:#221a35;">
    Hi <?= e($recipient !== '' ? $recipient : 'reader') ?>,
</h1>
<p style="margin:0 0 20px; font-size:16px; line-height:1.6; color:#4f4468;">
    <?= e($message !== '' ? $message : 'You are now following ' . $title . '.'); ?>
</p>
<?php require root_path('app/Views/emails/partials/_cta.php'); ?>