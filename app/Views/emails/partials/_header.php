<?php

declare(strict_types=1);

/**
 * emails/partials/_header.php
 *
 * The brand header of every email: the BookSphere wordmark on the
 * accent color, plus a muted one-line tagline. The wordmark is
 * plain text (an <img> badge would need a hosted asset and falls back
 * badly in dark-mode clients), so it renders everywhere - including
 * when images are blocked. Linked to the app home so it doubles as a
 * prominent "back to the site" affordance.
 *
 * Available variables: $appName, $appUrl, $title
 */

/* Guard: never rendered outside the email stack. */
$appName = $appName ?? 'BookSphere';
$appUrl  = $appUrl ?? '';
?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td style="padding-bottom:20px; border-bottom:1px solid #efeaf7;">
            <a href="<?= e($appUrl) ?>" style="text-decoration:none;">
                <span style="font-family:Georgia,'Times New Roman',serif; font-size:22px; font-weight:700; color:#6d4ae0; letter-spacing:-0.3px;">BookSphere</span>
                <span style="font-size:13px; color:#9b8bb5; margin-left:8px; letter-spacing:1px; text-transform:uppercase;">&middot; Discover, Review, Recommend</span>
            </a>
        </td>
    </tr>
</table>