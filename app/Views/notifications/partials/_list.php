<?php

declare(strict_types=1);

/**
 * notifications/partials/_list.php
 *
 * The CENTER's results region: the notification cards, the shared
 * pagination and the four empty states. This EXACT partial renders
 * both on the first (server) paint of the center page and inside
 * every /notifications/fragment answer - the fragment endpoint
 * includes it with the same variables, so the page and the fetch
 * can never drift apart.
 *
 * Available variables:
 *     $payload - the page() payload (items, total, page, pages,
 *                per_page, has_prev, has_next)
 *     $tab     - 'all' | 'unread' | 'read'
 *     $filter  - '' | a FILTER_GROUPS key
 *     $base    - the pagination base URL (defaults to the center)
 */

$payload = $payload ?? [];
$tab     = $tab ?? 'all';
$filter  = $filter ?? '';
$base    = $base ?? '/notifications/center';

$items = (array) ($payload['items'] ?? []);
$total = (int) ($payload['total'] ?? 0);

$pagination = [
    'base'       => $base,
    'params'     => array_filter([
        'tab'    => $tab,
        'filter' => $filter,
    ]),
    'page'       => (int) ($payload['page'] ?? 1),
    'pages'      => (int) ($payload['pages'] ?? 1),
    'total'      => $total,
    'perPage'    => (int) ($payload['per_page'] ?? 25),
    'perPages'   => [],
    'label'      => 'notification',
    'pagerLabel' => 'Notification pages',
];

?>
<?php if ($items === []): ?>
    <?php
    if ($filter !== '') {
        $empty = [
            'icon'    => 'fa-filter',
            'title'   => 'Nothing in this type',
            'message' => 'No notifications of this type yet - try another filter.',
            'class'   => 'empty-state--filter',
        ];
    } elseif ($tab === 'unread') {
        $empty = [
            'icon'    => 'fa-circle-check',
            'title'   => "You're all caught up",
            'message' => 'No unread notifications right now.',
            'class'   => 'empty-state--empty',
        ];
    } elseif ($tab === 'read') {
        $empty = [
            'icon'    => 'fa-clock-rotate-left',
            'title'   => 'Nothing read yet',
            'message' => 'Notifications you mark as read will appear here.',
            'class'   => 'empty-state--empty',
        ];
    } else {
        $empty = [
            'icon'    => 'fa-bell',
            'title'   => 'No notifications yet',
            'message' => 'When something happens - a follow, a milestone, a reply - it shows up here.',
            'class'   => 'empty-state--empty',
        ];
    }
    ?>
    <?php require root_path('app/Views/components/empty-state.php'); ?>
<?php else: ?>
    <?php /* tabindex="-1": the heading is the keyboard-focus anchor
             notifications.js moves focus to after a fragment swap. */ ?>
    <h2 class="visually-hidden" tabindex="-1" data-notif-results-heading>Notifications list</h2>
    <ul class="notif-list" data-animate>
        <?php foreach ($items as $item): ?>
            <li class="notif-list-item">
                <?php require root_path('app/Views/notifications/partials/_item.php'); ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php require root_path('app/Views/components/review-pagination.php'); ?>
<?php endif; ?>
