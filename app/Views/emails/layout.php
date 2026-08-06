<?php

declare(strict_types=1);

/**
 * emails/layout.php
 *
 * The shared HTML shell of every email BookSphere sends (Phase 9.5).
 * Pure inline-CSS HTML built for broad email-client support:
 *
 *     - responsive: a 600 px container that shrinks to the screen on
 *       small devices (the base wrapper is 100% width and the inner
 *       fixed-width column collapses via a small-screen media query)
 *     - accessible: real headings and link text, an aria-labelledby
 *       main region, sufficient contrast on every color, no blinking
 *       or auto-playing content
 *     - a CTA button styled as a filled primary block, prefixed by
 *       the message and followed by the muted footer (unsubscribe
 *       placeholder + copyright), exactly the brand header/content/
 *       footer structure the phase asked for
 *
 * The bright, high-contrast palette matches the app's light theme so
 * the emails read as BookSphere even unskinned. Every dynamic value
 * below is escaped with e() at render time.
 *
 * Available variables (built by EmailNotificationService::htmlFor()):
 *     $appName, $appUrl, $recipient, $title, $message, $actionUrl,
 *     $actionLabel, $year, $bodyHtml
 */

/* Guard: this template is only ever rendered through View::fragment. */
$bodyHtml = $bodyHtml ?? '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title><?= e($title ?? $appName) ?></title>
    <style>
        /* Responsive: collapse the fixed width card on phones. */
        @media only screen and (max-width: 600px) {
            .email-card { width: 100% !important; }
            .email-pad  { padding: 24px 20px !important; }
            .email-button {
                display: block !important;
                width: 100% !important;
                text-align: center;
            }
        }
    </style>
</head>
<body style="margin:0; padding:0; background:#f4f1fa; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Inter,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f1fa;">
        <tr>
            <td align="center" style="padding:20px 0;">
                <table role="presentation" class="email-card" width="600" cellpadding="0" cellspacing="0" style="width:600px; max-width:600px; background:#ffffff; border-radius:14px; overflow:hidden; border:1px solid #e8e3f0; box-shadow:0 2px 10px rgba(52,34,92,0.06);">
                    <tr>
                        <td class="email-pad" style="padding:32px 36px;">
                            <?php require root_path('app/Views/emails/partials/_header.php'); ?>
                            <div role="article" aria-labelledby="email-heading">
                                <?= $bodyHtml ?>
                            </div>
                            <?php require root_path('app/Views/emails/partials/_footer.php'); ?>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>