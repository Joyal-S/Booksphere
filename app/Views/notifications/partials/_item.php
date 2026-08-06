<?php

declare(strict_types=1);

/**
 * notifications/partials/_item.php
 *
 * ONE notification card. Rendered for every row of the center list
 * (full) and reused in compact form by nothing server-side - the
 * bell dropdown builds its own compact items from the JSON feed.
 *
 * The card carries the whole item's interactions:
 *
 *     - the unread dot + accent bar   (.is-unread on the article)
 *     - the bulk selection checkbox   (form="notif-bulk-form" - the
 *                                      no-JS collection mechanism)
 *     - the action link               (action_url, labelled by its
 *                                      destination)
 *     - the read-state toggle         (a real PATCH form - works
 *                                      without JS; notifications.js
 *                                      upgrades it to a fetch)
 *     - the single delete             (a real DELETE form - the
 *                                      no-JS path; JS intercepts it
 *                                      and routes it through the
 *                                      confirmation modal)
 *
 * Every value is escaped with e() at render time; the accent icon
 * colour comes from the stored color token (primary | info |
 * success | warning | danger), mapped to a CSS class below.
 *
 * Available variables:
 *     $item    - one notification row (id, type, title, message,
 *                icon, color, action_url, is_read, created_at)
 *     $compact - true renders the dropdown-tight variant (no
 *                checkbox, no actions) - reserved for the feed
 */

$item    = $item ?? [];
$compact = (bool) ($compact ?? false);

$isRead = (int) ($item['is_read'] ?? 0) === 1;

// The action link label by destination prefix (a fixed map - never
// user content - with "View details" as the fallback).
$actionHref  = (string) ($item['action_url'] ?? '');
$actionLabel = 'View details';
foreach ([
    '/authors/'        => 'View author',
    '/books/'          => 'View book',
    '/reviews/'        => 'View review',
    '/recommendations' => 'View picks',
    '/library'         => 'View library',
    '/profile'         => 'View profile',
] as $prefix => $label) {
    if (str_starts_with($actionHref, $prefix)) {
        $actionLabel = $label;
        break;
    }
}

?>
<article class="notif-item<?= $isRead ? '' : ' is-unread' ?>" data-notif-item data-notif-id="<?= (int) ($item['id'] ?? 0) ?>">
    <?php if (!$compact): ?>
        <span class="notif-item-check">
            <input type="checkbox" class="form-check-input" form="notif-bulk-form"
                   value="<?= (int) ($item['id'] ?? 0) ?>" data-notif-check
                   aria-label="Select this notification">
        </span>
    <?php endif; ?>

    <span class="notif-icon notif-icon--<?= e((string) ($item['color'] ?? 'primary')) ?>" aria-hidden="true">
        <i class="<?= e((string) ($item['icon'] ?? 'fa-solid fa-bell')) ?>"></i>
    </span>

    <div class="notif-item-body">
        <div class="notif-item-head">
            <h3 class="notif-item-title"><?= e((string) ($item['title'] ?? '')) ?></h3>
            <span class="notif-item-time"><?= e(format_notification_time((string) ($item['created_at'] ?? ''))) ?></span>
        </div>
        <p class="notif-item-message"><?= e((string) ($item['message'] ?? '')) ?></p>

        <?php if (!$compact): ?>
            <div class="notif-item-actions">
                <?php if ($actionHref !== ''): ?>
                    <a class="notif-item-action" href="<?= e($actionHref) ?>">
                        <?= e($actionLabel) ?><i class="fa-solid fa-arrow-right ms-1" aria-hidden="true"></i>
                    </a>
                <?php endif; ?>

                <form method="post" action="/notifications/<?= (int) ($item['id'] ?? 0) ?>/<?= $isRead ? 'unread' : 'read' ?>" data-notif-toggle>
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="_method" value="PATCH">
                    <button type="submit" class="notif-item-action" data-notif-toggle-label aria-pressed="<?= $isRead ? 'true' : 'false' ?>">
                        <i class="fa-regular fa-circle-check me-1" aria-hidden="true"></i>
                        <?= $isRead ? 'Mark as unread' : 'Mark as read' ?>
                    </button>
                </form>

                <form method="post" action="/notifications/<?= (int) ($item['id'] ?? 0) ?>" data-notif-delete-form>
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="_method" value="DELETE">
                    <button type="submit" class="notif-item-action notif-item-action--danger" data-notif-delete-trigger>
                        <i class="fa-regular fa-trash-can me-1" aria-hidden="true"></i>Delete
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$compact): ?>
        <span class="notif-item-dot" aria-hidden="true" title="Unread"></span>
    <?php endif; ?>
</article>
