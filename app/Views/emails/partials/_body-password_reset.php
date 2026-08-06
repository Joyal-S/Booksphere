<?php

declare(strict_types=1);

/**
 * emails/partials/_body-reset.php
 *
 * The password-reset email (future-ready): the single-use link the
 * forgot-password flow will hand to the service once it is wired to
 * email. The reset link renders as the CTA; the anchor text itself
 * tells the reader what to reset.
 *
 * Available variables: $title, $actionUrl, $actionLabel
 */

?>
<h1 id="email-heading" style="margin:20px 0 8px; font-size:22px; line-height:1.3; color:#1c3532;">
    Reset your password
</h1>
<p style="margin:0 0 20px; font-size:16px; line-height:1.6; color:#44685f;">
    Someone asked to reset the password on your BookSphere account. If this
    was you, use the button below. The link is single-use and expires after
    a short time &mdash; if you did not ask for this, you can ignore the email.
</p>
<?php require root_path('app/Views/emails/partials/_cta.php'); ?>