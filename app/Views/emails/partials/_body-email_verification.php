<?php

declare(strict_types=1);

/**
 * emails/partials/_body-verification.php
 *
 * The email-address verification email (future-ready): produced by
 * the account phase that adds address verification; the template and
 * the type constant exist already.
 *
 * Available variables: $actionUrl, $actionLabel
 */

?>
<h1 id="email-heading" style="margin:20px 0 8px; font-size:22px; line-height:1.3; color:#1c3532;">
    Verify your email address
</h1>
<p style="margin:0 0 20px; font-size:16px; line-height:1.6; color:#44685f;">
    Confirm this address to keep your BookSphere account secure. The
    verification link is single-use and valid for a short time.
</p>
<?php require root_path('app/Views/emails/partials/_cta.php'); ?>