<?php

declare(strict_types=1);

/**
 * partials/header.php
 *
 * The NAVBAR (top navigation) shown on every page. From left to
 * right:
 *
 *     - mobile hamburger  -> opens the sidebar overlay (< 992px)
 *     - collapse toggle   -> shrinks the sidebar on desktop
 *     - brand             -> shown on small screens only (the
 *                            sidebar holds the brand on desktop)
 *     - search bar        -> placeholder input, no logic yet
 *     - theme toggle      -> light/dark, handled by app.js
 *     - notification bell -> placeholder dropdown only
 *     - user dropdown     -> profile, password, settings, log out
 *
 * Guests (login/register pages) see the brand, the theme toggle
 * and log in / register buttons instead of the user menu.
 *
 * NOTE: variables are named $sessionUser (not $user) so they can
 * never overwrite the $user that controllers pass to views -
 * partials share the view's variable scope.
 */

$sessionUser = auth_user();
$initials    = '';

if ($sessionUser !== null) {
    $parts = preg_split('/\s+/', trim((string) ($sessionUser['full_name'] ?? ''))) ?: [];
    $initials = mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1) . mb_substr($parts[1] ?? '', 0, 1));
}

?>
<header class="navbar-app">
    <div class="navbar-app-left">
        <?php if (auth_check()): ?>
            <button class="icon-button d-lg-none" type="button" data-sidebar-open aria-label="Open navigation">
                <i class="fa-solid fa-bars" aria-hidden="true"></i>
            </button>
            <button class="icon-button d-none d-lg-inline-flex" type="button" data-sidebar-collapse aria-label="Collapse sidebar" title="Collapse sidebar">
                <i class="fa-solid fa-angles-left" aria-hidden="true"></i>
            </button>
        <?php endif; ?>

        <a class="brand d-lg-none" href="/" aria-label="BookSphere home">
            <i class="fa-solid fa-book-open-reader" aria-hidden="true"></i><span>BookSphere</span>
        </a>

        <?php if (auth_check()): ?>
            <div class="search-bar" role="search">
                <i class="fa-solid fa-magnifying-glass search-bar-icon" aria-hidden="true"></i>
                <input type="search" class="search-bar-input" placeholder="Search books, authors, genres…" aria-label="Search" data-search-input>
                <kbd class="search-bar-hint">Ctrl K</kbd>
            </div>
        <?php endif; ?>
    </div>

    <div class="navbar-app-right">
        <button class="icon-button" type="button" data-theme-toggle aria-label="Switch colour theme">
            <i class="fa-solid fa-moon theme-icon" aria-hidden="true"></i>
        </button>

        <?php if (auth_check()): ?>
            <div class="dropdown">
                <button class="icon-button position-relative" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications">
                    <i class="fa-regular fa-bell" aria-hidden="true"></i>
                    <span class="notif-dot" aria-hidden="true"></span>
                </button>
                <div class="dropdown-menu dropdown-menu-end notif-menu">
                    <div class="notif-menu-header">Notifications</div>
                    <div class="notif-menu-empty">
                        <i class="fa-regular fa-bell" aria-hidden="true"></i>
                        <p>No notifications yet.<br>Alerts arrive in a later phase.</p>
                    </div>
                </div>
            </div>

            <div class="dropdown">
                <button class="user-chip" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="avatar avatar-brand" aria-hidden="true"><?= e($initials) ?></span>
                    <span class="user-chip-text d-none d-xl-block">
                        <strong><?= e($sessionUser['full_name']) ?></strong>
                        <small><?= e($sessionUser['email']) ?></small>
                    </span>
                    <i class="fa-solid fa-chevron-down user-chip-caret" aria-hidden="true"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end user-menu">
                    <li class="user-menu-head">
                        <span class="avatar avatar-brand" aria-hidden="true"><?= e($initials) ?></span>
                        <span>
                            <strong><?= e($sessionUser['full_name']) ?></strong>
                            <small><?= e($sessionUser['role']) ?></small>
                        </span>
                    </li>
                    <li><a class="dropdown-item" href="/profile"><i class="fa-solid fa-user me-2" aria-hidden="true"></i>My profile</a></li>
                    <li><a class="dropdown-item" href="/change-password"><i class="fa-solid fa-key me-2" aria-hidden="true"></i>Change password</a></li>
                    <li><a class="dropdown-item" href="/settings"><i class="fa-solid fa-gear me-2" aria-hidden="true"></i>Settings</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="post" action="/logout" class="m-0">
                            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                            <button class="dropdown-item" type="submit"><i class="fa-solid fa-right-from-bracket me-2" aria-hidden="true"></i>Log out</button>
                        </form>
                    </li>
                </ul>
            </div>
        <?php else: ?>
            <a class="btn btn-outline-secondary btn-sm" href="/login">Log in</a>
            <a class="btn btn-primary btn-sm" href="/register">Register</a>
        <?php endif; ?>
    </div>
</header>
