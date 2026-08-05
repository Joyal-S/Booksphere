<?php

declare(strict_types=1);

/**
 * layouts/auth.php
 *
 * The standalone layout of the authentication pages. Like
 * layouts/landing.php it does NOT include the app header / sidebar /
 * footer: the auth pages are a full-bleed split screen that owns its
 * whole surface.
 *
 * Structure (mirrors the approved cover design):
 *     left  -> the brand panel (logo, illustration, stats) - always
 *              the same across the four screens, kept in the
 *              auth/partials/_brand-panel.php partial
 *     right -> the form column: optional mobile brand mark, flash
 *              messages, the auth card, the page footer
 *
 * The card's tabs (Sign In / Create Account) render only when the
 * controller passes $tabs = true - the forgot/reset screens hide
 * them, exactly like the reference design.
 *
 * Available variables:
 *     $active - "login" | "register" | "forgot" | "reset"
 *     $tabs   - whether to show the Sign In / Create Account tabs
 *     $title  - page title (used by auth-head)
 */

?>
<!doctype html>
<html lang="en" data-bs-theme="light">
    <?php require root_path('app/Views/partials/auth-head.php'); ?>
    <body class="auth-page">
        <a class="auth-skip" href="#auth-main">Skip to content</a>

        <!-- Dark / light toggle (same preference key as the app shell). -->
        <button id="auth-theme-toggle" type="button" aria-label="Toggle dark mode" title="Toggle dark mode">
            <svg id="auth-icon-moon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
            <svg id="auth-icon-sun" class="auth-hidden" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <circle cx="12" cy="12" r="5"/>
                <line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/>
                <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                <line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/>
                <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
            </svg>
        </button>

        <div class="auth-shell">
            <aside class="auth-brand">
                <?php require root_path('app/Views/auth/partials/_brand-panel.php'); ?>
            </aside>

            <main id="auth-main" class="auth-main" tabindex="-1">
                <div class="auth-column">

                    <!-- Mobile brand mark (hidden on desktop). -->
                    <div class="auth-mobile-brand" aria-hidden="true">
                        <span class="auth-mobile-mark">B</span>
                        <span class="auth-mobile-name">BookSphere</span>
                    </div>

                    <?php require root_path('app/Views/auth/partials/_flash.php'); ?>

                    <div class="auth-card">
                        <?php if (!empty($tabs)): ?>
                            <nav class="auth-tabs" aria-label="Authentication">
                                <a class="auth-tab<?= ($active ?? '') === 'login' ? ' auth-tab--active' : '' ?>" href="/login" aria-current="<?= ($active ?? '') === 'login' ? 'page' : 'false' ?>">Sign In</a>
                                <a class="auth-tab<?= ($active ?? '') === 'register' ? ' auth-tab--active' : '' ?>" href="/register" aria-current="<?= ($active ?? '') === 'register' ? 'page' : 'false' ?>">Create Account</a>
                            </nav>
                        <?php endif; ?>

                        <?php require $__view; ?>
                    </div>

                    <footer class="auth-footer">
                        <div class="auth-footer-links">
                            <a href="/">Privacy Policy</a>
                            <span class="auth-footer-dot" aria-hidden="true">·</span>
                            <a href="/">Terms of Service</a>
                            <span class="auth-footer-dot" aria-hidden="true">·</span>
                            <a href="/">Help</a>
                        </div>
                        <p class="auth-copyright">© <?= date('Y') ?> BookSphere, Inc.</p>
                    </footer>

                </div>
            </main>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
        <script src="<?= e(asset('js/auth.js')) ?>"></script>
    </body>
</html>