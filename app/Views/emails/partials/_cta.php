<?php

declare(strict_types=1);

/**
 * emails/partials/_cta.php
 *
 * The call-to-action button of an email: a filled primary block that
 * links to the event's page. Rendered as a real <a> with inline
 * styles - no image, no JS - so every client (and every screen
 * reader) reads a plain, meaningful link.
 *
 * Available variables: $actionUrl, $actionLabel
 */

/* Guard: without a destination there is nothing to render. */
$actionUrl   = $actionUrl ?? '';
$actionLabel = $actionLabel ?? '';

if ($actionUrl === '' || $actionLabel === '') {
    return;
}
?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td style="padding:6px 0 4px;">
            <a href="<?= e($actionUrl) ?>" class="email-button" style="display:inline-block; background:#6d4ae0; color:#ffffff; text-decoration:none; font-size:15px; font-weight:600; padding:13px 28px; border-radius:8px; letter-spacing:0.2px;">
                <?= e($actionLabel) ?> &rarr;
            </a>
        </td>
    </tr>
</table>