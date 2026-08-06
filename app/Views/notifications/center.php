<?php

declare(strict_types=1);

/**
 * notifications/center.php
 *
 * The NOTIFICATION CENTER (Phase 9.4) - the user's full inbox.
 * Structure, top to bottom:
 *
 *     1. Intro    - the eyebrow + title + lead ("You have 3 unread
 *                   notifications") and the two page-wide actions:
 *                   Mark all read and Clear all (both real forms -
 *                   the no-JS path; notifications.js intercepts them
 *                   for an in-place repaint)
 *     2. Filters  - the tab chips (All / Unread / Read) and the type
 *                   group chips (Follow / Library / Review /
 *                   Recommendation / System). Every chip is a real
 *                   link to /notifications/center?tab=&filter=, so
 *                   the page works without JavaScript; notifications.js
 *                   upgrades the clicks to fragment fetches
 *     3. Bulk bar - the selection toolbar: Select all (this page),
 *                   the selection count and Delete selected. The
 *                   checkboxes live on the cards and reference this
 *                   form (form="notif-bulk-form"), exactly like the
 *                   library's bulk bar
 *     4. Results  - the [data-notif-results] region: the shared
 *                   _list partial (cards + pagination + empty states),
 *                   the SAME fragment every filter / page fetch
 *                   swaps in from /notifications/fragment
 *     5. Modal    - the single shared confirmation dialog for every
 *                   destructive action (delete one, delete selected,
 *                   clear all)
 *
 * Available variables (from NotificationController::center()):
 *     $tab     - 'all' | 'unread' | 'read'
 *     $filter  - '' | one of NotificationService::FILTER_GROUPS keys
 *     $payload - the page() payload (items, total, page, pages,
 *                per_page, has_prev, has_next)
 *     $unread  - the unread count (the badge number)
 */

$tab     = $tab ?? 'all';
$filter  = $filter ?? '';
$payload = $payload ?? [];
$unread  = (int) ($unread ?? 0);
$total   = (int) ($payload['total'] ?? 0);

$firstName = ucfirst((string) (explode(' ', (string) auth_user()['full_name'])[0] ?? 'there'));

?>
<!-- 1. Intro -->
<section class="notif-intro" data-animate>
    <div class="notif-intro-text">
        <p class="eyebrow">Notifications</p>
        <h1>Your inbox</h1>
        <p class="lead" data-notif-lead>
            <?php if ($unread > 0): ?>
                <?= e($firstName) ?>, you have
                <strong data-notif-unread-text><?= $unread ?> unread notification<?= $unread === 1 ? '' : 's' ?></strong>
                waiting for you.
            <?php else: ?>
                You're all caught up, <?= e($firstName) ?> — nothing unread right now.
            <?php endif; ?>
        </p>
    </div>
    <div class="notif-intro-actions">
        <form method="post" action="/notifications/read-all" data-notif-mark-all>
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="_method" value="PATCH">
            <button type="submit" class="btn btn-primary btn-sm" data-notif-mark-all-btn<?= $unread === 0 ? ' disabled' : '' ?>>
                <i class="fa-solid fa-check-double me-1" aria-hidden="true"></i>Mark all read
            </button>
        </form>
        <form method="post" action="/notifications" data-notif-clear-form>
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="_method" value="DELETE">
            <button type="submit" class="btn btn-outline-danger btn-sm"<?= $total === 0 ? ' disabled' : '' ?>>
                <i class="fa-solid fa-trash me-1" aria-hidden="true"></i>Clear all
            </button>
        </form>
    </div>
</section>

<!-- 2. Filter chips (tabs + type groups) -->
<?php require root_path('app/Views/notifications/partials/_filters.php'); ?>

<!-- 3. The bulk toolbar (one form collecting the checked cards) -->
<form method="post" action="/notifications/bulk" id="notif-bulk-form" data-notif-bulk-form class="notif-bulk">
    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
    <label class="notif-bulk-select-all">
        <input type="checkbox" class="form-check-input" data-notif-select-all aria-label="Select all notifications on this page">
        <span>Select all</span>
    </label>
    <span class="notif-bulk-count" data-notif-bulk-count>0 selected</span>
    <button type="submit" class="btn btn-outline-danger btn-sm notif-bulk-delete" data-notif-bulk-delete disabled>
        <i class="fa-solid fa-trash me-1" aria-hidden="true"></i>Delete selected
    </button>
</form>

<div class="visually-hidden" role="status" data-notif-status aria-live="polite"></div>

<!-- 4. The results region: the same _list fragment every filter or
     page fetch swaps in -->
<div data-notif-results data-notif-results-endpoint="/notifications/fragment">
    <?php require root_path('app/Views/notifications/partials/_list.php'); ?>
</div>

<!-- The loading skeleton the fragment fetches swap in while a
     filter / page / delete request is in flight -->
<template data-notif-skeleton>
    <div class="notif-skeleton-stack" aria-hidden="true">
        <div class="notif-skeleton-item">
            <span class="notif-skeleton-icon skeleton"></span>
            <span class="notif-skeleton-body">
                <span class="notif-skeleton-line skeleton"></span>
                <span class="notif-skeleton-line notif-skeleton-line--sm skeleton"></span>
            </span>
        </div>
        <div class="notif-skeleton-item">
            <span class="notif-skeleton-icon skeleton"></span>
            <span class="notif-skeleton-body">
                <span class="notif-skeleton-line skeleton"></span>
                <span class="notif-skeleton-line notif-skeleton-line--sm skeleton"></span>
            </span>
        </div>
        <div class="notif-skeleton-item">
            <span class="notif-skeleton-icon skeleton"></span>
            <span class="notif-skeleton-body">
                <span class="notif-skeleton-line skeleton"></span>
                <span class="notif-skeleton-line notif-skeleton-line--sm skeleton"></span>
            </span>
        </div>
    </div>
</template>

<?php require root_path('app/Views/notifications/partials/_confirm-modal.php'); ?>
