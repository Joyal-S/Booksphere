<?php

declare(strict_types=1);

/**
 * components/empty-state.php
 *
 * The reusable EMPTY STATE: a friendly icon, title and message,
 * used wherever a page has nothing real to show yet (e.g. the
 * placeholder pages of later-phase features). An optional action
 * button is rendered through components/button.php.
 *
 * Included from a view that sets the $empty array first:
 *
 *     $empty = [
 *         'icon'    => 'fa-box-open',
 *         'title'   => 'Coming soon',
 *         'message' => 'This module arrives in a later phase.',
 *         'action'  => ['label' => 'Back to dashboard', 'href' => '/'], // optional
 *     ];
 */

$empty = array_merge([
    'icon'    => 'fa-box-open',
    'title'   => 'Nothing here yet',
    'message' => '',
    'action'  => null,
    // Optional modifier class for a tone of empty state
    // (e.g. "empty-state--search", "empty-state--filter").
    'class'   => '',
], $empty ?? []);

?>
<div class="empty-state<?= $empty['class'] !== '' ? ' ' . e($empty['class']) : '' ?>">
    <span class="empty-state-icon" aria-hidden="true"><i class="fa-solid <?= e($empty['icon']) ?>"></i></span>
    <h2 class="empty-state-title"><?= e($empty['title']) ?></h2>
    <?php if ($empty['message'] !== ''): ?>
        <p class="empty-state-message"><?= e($empty['message']) ?></p>
    <?php endif; ?>
    <?php if ($empty['action'] !== null): ?>
        <div class="empty-state-action">
            <?php $button = $empty['action'] + ['variant' => 'primary']; ?>
            <?php require root_path('app/Views/components/button.php'); ?>
        </div>
    <?php endif; ?>
</div>
