<?php

declare(strict_types=1);

/**
 * partials/auth-head.php
 *
 * The <head> of the standalone authentication pages
 * (layouts/auth.php). Like the landing shell, the auth pages own
 * their whole surface - no app shell stylesheets, no sidebar/navbar.
 *
 * The tiny inline script applies the saved theme BEFORE the CSS
 * paints, so a returning visitor never sees a light flash before the
 * dark theme kicks in. The choice itself lives under the same
 * "booksphere-theme" key the app dashboard uses, so the theme a
 * user picks here carries straight into the signed-in shell.
 *
 * Available variables:
 *     $title - page title passed by the controller
 */

?>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="description" content="Sign in or create your BookSphere account.">
    <title><?= e($title ?? 'Sign in') ?> · BookSphere</title>
    <script>
        try {
            var saved = localStorage.getItem('booksphere-theme');
            if (saved === 'dark' || saved === 'light') {
                document.documentElement.dataset.bsTheme = saved;
            }
        } catch (e) {}
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= e(asset('css/auth.css')) ?>">
</head>