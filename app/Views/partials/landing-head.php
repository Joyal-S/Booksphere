<?php

declare(strict_types=1);

/**
 * partials/landing-head.php
 *
 * The <head> of the public cover/landing page (layouts/landing.php).
 * Deliberately leaner than the shared partials/head.php: the cover
 * page is a standalone marketing surface and does NOT ship the app
 * shell stylesheets (app.css, library.css, ...) or the sidebar/navbar
 * markup.
 *
 * Available variables:
 *     $title - page title passed by the controller
 *
 * Fonts: Inter for the interface, Playfair Display (serif) for the
 * display headlines and JetBrains Mono for the labels/footer — kept
 * exactly as the approved cover design.
 */

?>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="description" content="BookSphere — the intelligent book discovery and recommendation platform. Discover books, review and rate them, follow authors and organise a personal library.">
    <title><?= e($title ?? 'BookSphere') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400;1,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= e(asset('css/fontawesome.min.css')) ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= e(asset('css/landing.css')) ?>">
</head>