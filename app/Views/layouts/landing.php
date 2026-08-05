<?php

declare(strict_types=1);

/**
 * layouts/landing.php
 *
 * The bare layout of the public cover/landing page. Unlike
 * layouts.master it does NOT include the app header / sidebar /
 * footer / scripts partials: the cover is a standalone, dark,
 * full-bleed page that owns its whole surface.
 *
 * It still composes the shared document parts the same way the
 * master layout does — a head partial, a skip link and the scripts
 * at the end of the body — so the page keeps the application's
 * structure and only swaps the CSS/JS payload for its own
 * (css/landing.css + js/landing.js).
 */

?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
    <?php require root_path('app/Views/partials/landing-head.php'); ?>
    <body class="landing-page">
        <a class="landing-skip" href="#landing-main">Skip to content</a>

        <!-- Decorative layered background (pure CSS + two inline waves). -->
        <div class="landing-bg" aria-hidden="true">
            <div class="landing-bg-gradient"></div>
            <div class="landing-bg-waves">
                <svg viewBox="0 0 1440 320" preserveAspectRatio="none" class="landing-wave landscape--bottom">
                    <path d="M0,160 C240,240 480,80 720,160 C960,240 1200,80 1440,160 L1440,320 L0,320 Z" fill="rgba(91,63,166,0.06)"/>
                    <path d="M0,200 C360,120 720,280 1080,180 C1260,130 1380,220 1440,200 L1440,320 L0,320 Z" fill="rgba(49,46,129,0.07)"/>
                </svg>
                <svg viewBox="0 0 800 500" preserveAspectRatio="none" class="landing-wave-top" aria-hidden="true">
                    <path d="M800,0 C600,80 400,0 200,120 C100,180 0,120 0,200 L0,0 Z" fill="rgba(155,121,216,1)"/>
                </svg>
            </div>
            <?php for ($i = 0; $i < 15; $i++): ?>
                <span class="landing-particle"></span>
            <?php endfor; ?>
        </div>

        <main id="landing-main" class="landing-content">
            <?php require $__view; ?>
        </main>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
        <script src="<?= e(asset('js/landing.js')) ?>"></script>
    </body>
</html>