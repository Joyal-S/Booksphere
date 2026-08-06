<?php

declare(strict_types=1);

/**
 * emails/partials/_footer.php
 *
 * The email footer: the mute/unsubscribe placeholder, the app
 * credit and the copyright line. Every email ends with these muted
 * links so recipients always know where the message came from and
 * how to stop it (the per-subject toggles live on the Settings page
 * - the exact opt-out UI this phase ships).
 *
 * Available variables: $appName, $appUrl, $year
 */

/* Guard: keep the footer renderable in isolation. */
$appName = $appName ?? 'BookSphere';
$appUrl  = $appUrl ?? '';
$year    = $year ?? (string) gmdate('Y');
?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td style="padding-top:24px; border-top:1px solid #efe9f7; font-size:12px; color:#9b8bb5; line-height:1.7;">
            <p style="margin:0 0 6px;">
                You receive this because email notifications are enabled on your
                <a href="<?= e(rtrim($appUrl, '/') . '/settings') ?>" style="color:#6d4ae0; text-decoration:none;"><?= e($appName) ?> account</a>.
                Change or switch off these updates anytime on the Email section of Settings.
            </p>
            <p style="margin:0;">
                &copy; <?= e($year) ?> <?= e($appName) ?>. All rights reserved.
                The curated bookshelf for readers, by readers.
            </p>
        </td>
    </tr>
</table>