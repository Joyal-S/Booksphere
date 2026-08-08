<?php

declare(strict_types=1);

/**
 * partials/sidebar.php
 *
 * The SIDEBAR (left navigation). Contains the brand, the main
 * navigation grouped by area, and a small user card at the bottom.
 *
 * Behaviour:
 *     - the active page gets "is-active" from the $active value
 *       the controller passes (e.g. "dashboard", "books")
 *     - on desktop a button in the navbar collapses it to icons
 *       (body class "sidebar-collapsed")
 *     - on mobile (< 992px) it slides in as an overlay when the
 *       navbar hamburger is pressed (body class "sidebar-open")
 *
 * Guests only see the brand and log in / register links, because
 * the full navigation belongs to the signed-in area.
 *
 * NOTE: variables are named $sessionUser (not $user) so they can
 * never overwrite the $user that controllers pass to views -
 * partials share the view's variable scope.
 */

$active      = $active ?? '';
$sessionUser = auth_user();

?>
<aside class="sidebar" aria-label="Primary navigation">
    <div class="sidebar-backdrop" data-sidebar-close></div>

    <a class="sidebar-brand" href="/" aria-label="BookSphere home">
        <span class="sidebar-brand-mark"><i class="fa-solid fa-book-open-reader" aria-hidden="true"></i></span>
        <span class="sidebar-brand-text">BookSphere</span>
    </a>

    <?php if (auth_check()): ?>
        <nav class="sidebar-nav">
            <p class="sidebar-group-label">Menu</p>
            <a class="nav-item<?= $active === 'dashboard' ? ' is-active' : '' ?>" href="/" title="Dashboard">
                <i class="fa-solid fa-chart-line" aria-hidden="true"></i><span>Dashboard</span>
            </a>
            <a class="nav-item<?= $active === 'books' ? ' is-active' : '' ?>" href="/books" title="Browse Books">
                <i class="fa-solid fa-book-open" aria-hidden="true"></i><span>Browse Books</span>
            </a>
            <a class="nav-item<?= $active === 'search' ? ' is-active' : '' ?>" href="/search" title="Search everything">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><span>Search</span>
            </a>
            <a class="nav-item<?= $active === 'categories' ? ' is-active' : '' ?>" href="/categories" title="Categories">
                <i class="fa-solid fa-tags" aria-hidden="true"></i><span>Categories</span>
            </a>
            <a class="nav-item<?= $active === 'authors' ? ' is-active' : '' ?>" href="/authors" title="Authors">
                <i class="fa-solid fa-user-pen" aria-hidden="true"></i><span>Authors</span>
            </a>

            <p class="sidebar-group-label">Library</p>
            <!-- Phase 8.2: the sidebar Wishlist link now lands on the
                 real Personal Library page ("My Library"). The legacy
                 /wishlist route still works (a coming-soon page); the
                 wishlist BACKEND moved to the library module in 8.1. -->
            <a class="nav-item<?= $active === 'library' ? ' is-active' : '' ?>" href="/library" title="My Library">
                <i class="fa-solid fa-bookmark" aria-hidden="true"></i><span>My Library</span>
            </a>
            <a class="nav-item<?= $active === 'recommendations' ? ' is-active' : '' ?>" href="/recommendations" title="Recommendations">
                <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i><span>Recommendations</span>
            </a>
            <a class="nav-item<?= $active === 'reviews' ? ' is-active' : '' ?>" href="/reviews" title="Reviews">
                <i class="fa-solid fa-star" aria-hidden="true"></i><span>Reviews</span>
            </a>

            <p class="sidebar-group-label">System</p>
            <!-- Phase 12.1: the Analytics link now serves the real
                 user-analytics page (GET /analytics) - the personal
                 reading statistics of the signed-in user. -->
            <a class="nav-item<?= $active === 'analytics' ? ' is-active' : '' ?>" href="/analytics" title="My reading analytics">
                <i class="fa-solid fa-chart-column" aria-hidden="true"></i><span>Analytics</span>
            </a>
            <!-- Phase 12.2: the Book Analytics link serves the whole
                 catalogue's numbers (GET /book-analytics) - shelves,
                 ratings, rankings, metadata and monthly activity. -->
            <a class="nav-item<?= $active === 'book-analytics' ? ' is-active' : '' ?>" href="/book-analytics" title="Book analytics">
                <i class="fa-solid fa-chart-pie" aria-hidden="true"></i><span>Book Analytics</span>
            </a>
            <!-- Phase 12.5: the print-only report of the personal
                 analytics (GET /analytics/report). -->
            <a class="nav-item<?= $active === 'analytics-report' ? ' is-active' : '' ?>" href="/analytics/report" title="My print-friendly reading report">
                <i class="fa-solid fa-file-lines" aria-hidden="true"></i><span>My Report</span>
            </a>
            <a class="nav-item<?= $active === 'notifications' ? ' is-active' : '' ?>" href="/notifications/center" title="Notifications">
                <i class="fa-solid fa-bell" aria-hidden="true"></i><span>Notifications</span>
            </a>
            <a class="nav-item<?= $active === 'settings' ? ' is-active' : '' ?>" href="/settings" title="Settings">
                <i class="fa-solid fa-gear" aria-hidden="true"></i><span>Settings</span>
            </a>
            <?php if (auth_is_admin()): ?>
                <a class="nav-item<?= $active === 'google-books' ? ' is-active' : '' ?>" href="/admin/google-books" title="Google Books provider search">
                    <i class="fa-solid fa-book-open-reader" aria-hidden="true"></i><span>Google Books</span>
                </a>
                <a class="nav-item<?= $active === 'admin' ? ' is-active' : '' ?>" href="/admin" title="Administration">
                    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i><span>Administration</span>
                </a>
                <!-- Phase 12.5: the print-only administration report
                     (GET /admin/analytics/report). -->
                <a class="nav-item<?= $active === 'admin-analytics-report' ? ' is-active' : '' ?>" href="/admin/analytics/report" title="Print-friendly administration report">
                    <i class="fa-solid fa-file-arrow-down" aria-hidden="true"></i><span>Analytics Report</span>
                </a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <span class="avatar avatar-brand" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) ($sessionUser['full_name'] ?? ''), 0, 1))) ?></span>
            <div class="sidebar-user-text">
                <strong><?= e($sessionUser['full_name']) ?></strong>
                <small><?= e($sessionUser['role']) ?></small>
            </div>
            <form method="post" action="/logout" class="m-0" title="Log out">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <button class="icon-button icon-button-sm" type="submit" aria-label="Log out">
                    <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
                </button>
            </form>
        </div>
    <?php else: ?>
        <nav class="sidebar-nav">
            <p class="sidebar-group-label">Account</p>
            <a class="nav-item<?= $active === 'login' ? ' is-active' : '' ?>" href="/login">
                <i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i><span>Log in</span>
            </a>
            <a class="nav-item<?= $active === 'register' ? ' is-active' : '' ?>" href="/register">
                <i class="fa-solid fa-user-plus" aria-hidden="true"></i><span>Register</span>
            </a>
        </nav>
    <?php endif; ?>
</aside>
