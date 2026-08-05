<?php

declare(strict_types=1);

/**
 * partials/head.php
 *
 * The <head> section of every page: meta tags, the page title and
 * all stylesheets. It is a partial so the master layout stays
 * short and alternative layouts (e.g. login) can reuse it.
 *
 * Available variables:
 *     $title - page title passed by the controller
 *
 * Fonts: Inter for the interface and Fraunces (serif) for the
 * stylised book covers, both from Google Fonts.
 */

?>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title><?= e($title ?? 'BookSphere') ?> · BookSphere</title>
    <script>document.documentElement.classList.add('js');</script>
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/rating.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/reviews.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/library.css')) ?>">
</head>
