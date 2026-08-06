<?php

declare(strict_types=1);

/**
 * notifications/partials/_filters.php
 *
 * The CENTER's filter chips: the read-state tabs (All / Unread /
 * Read) and the type group chips (Follow / Library / Review /
 * Recommendation / System). Every chip is a real link to
 * /notifications/center that preserves BOTH state keys, so the
 * page deep-links and works without JavaScript; notifications.js
 * intercepts the clicks and swaps the results region through
 * /notifications/fragment instead.
 *
 * The tab keys and the group keys are the exact constants of
 * NotificationService (TABS / FILTER_GROUPS) - the chip contract
 * can never drift from the service's filters.
 *
 * Available variables:
 *     $tab    - 'all' | 'unread' | 'read'
 *     $filter - '' | a FILTER_GROUPS key
 *     $unread - the unread count (drawn on the Unread tab)
 */

$tab    = $tab ?? 'all';
$filter = $filter ?? '';
$unread = (int) ($unread ?? 0);

$tabs = [
    'all'    => 'All',
    'unread' => 'Unread' . ($unread > 0 ? " ($unread)" : ''),
    'read'   => 'Read',
];

$groups = [
    'follow'         => 'Follow',
    'library'        => 'Library',
    'review'         => 'Review',
    'recommendation' => 'Recommendation',
    'system'         => 'System',
];

// The URL of a chip: base + the tab + the optional group.
$chipUrl = static function (string $tabKey, string $filterKey) use ($tab, $filter): string {
    $params = ['tab' => $tabKey];

    if ($filterKey !== '') {
        $params['filter'] = $filterKey;
    }

    return '/notifications/center?' . http_build_query($params);
};

?>
<nav class="notif-chips" aria-label="Notification filters" data-animate>
    <div class="notif-chips-row" role="group" aria-label="Read state">
        <?php foreach ($tabs as $key => $label): ?>
            <a class="notif-chip<?= $key === $tab ? ' is-active' : '' ?>"
               href="<?= e($chipUrl($key, $filter)) ?>"
               data-notif-chip
               <?= $key === $tab ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="notif-chips-row" role="group" aria-label="Type">
        <a class="notif-chip notif-chip--group<?= $filter === '' ? ' is-active' : '' ?>"
           href="<?= e($chipUrl($tab, '')) ?>"
           data-notif-chip
           <?= $filter === '' ? 'aria-current="page"' : '' ?>>All types</a>
        <?php foreach ($groups as $key => $label): ?>
            <a class="notif-chip notif-chip--group<?= $key === $filter ? ' is-active' : '' ?>"
               href="<?= e($chipUrl($tab, $key)) ?>"
               data-notif-chip
               <?= $key === $filter ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
</nav>
