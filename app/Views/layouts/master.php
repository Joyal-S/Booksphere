<?php

declare(strict_types=1);

/**
 * layouts/master.php
 *
 * The MASTER LAYOUT: the outer shell every normal page is rendered
 * inside. It composes the reusable partials:
 *
 *     head.php    -> <head> (meta, title, stylesheets)
 *     header.php  -> top navbar (search, theme, user menu)
 *     sidebar.php -> left navigation (brand, nav groups)
 *     footer.php  -> page footer
 *     scripts.php -> JavaScript at the end of the body
 *
 * The current page is injected through the $__view variable,
 * which is set by View::render().
 */

?>
<!doctype html>
<html lang="en" data-bs-theme="light">
    <?php require root_path('app/Views/partials/head.php'); ?>
    <body>
        <?php require root_path('app/Views/partials/header.php'); ?>
        <div class="app-shell">
            <?php require root_path('app/Views/partials/sidebar.php'); ?>
            <main id="main-content" class="app-content" tabindex="-1">
                <?php require root_path('app/Views/partials/flash.php'); ?>
                <?php require $__view; ?>
            </main>
        </div>
        <?php require root_path('app/Views/partials/footer.php'); ?>
        <?php require root_path('app/Views/partials/scripts.php'); ?>
    </body>
</html>
