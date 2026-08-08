<?php

declare(strict_types=1);

/**
 * components/stat-card.php
 *
 * The reusable STATISTIC CARD of the library overview. Shows an
 * icon tile, a value (animated with a count-up when data-count is
 * set), a label and an optional trend line.
 *
 * Included from a view that sets the $stat array first:
 *
 *     $stat = [
 *         'icon'  => 'fa-book',
 *         'label' => 'Total Books',
 *         'value' => 128,
 *         'tone'  => 'primary', // primary | success | warning | danger | info
 *         'trend' => '+8 this month', // optional
 *     ];
 *
 * The value counts up on load via app.js (skipped for users who
 * prefer reduced motion). The server renders the FINAL value as the
 * baseline text - a no-JS visitor or a reduced-motion user never
 * sees a permanent zero (Phase 12.6 audit); the count-up in app.js
 * only animates FROM that same number.
 */

$stat = array_merge([
    'icon'  => 'fa-circle-info',
    'label' => '',
    'value' => 0,
    'tone'  => 'primary',
    'trend' => '',
], $stat ?? []);

$isNumber = is_numeric($stat['value']);

if ($isNumber) {
    $raw      = (string) $stat['value'];
    $decimals = str_contains($raw, '.') ? strlen(substr($raw, strpos($raw, '.') + 1)) : 0;
    $display  = number_format((float) $stat['value'], $decimals, '.', ',');
} else {
    $display = (string) $stat['value'];
}

?>
<div class="stat-card">
    <div class="stat-card-top">
        <span class="stat-icon tone-<?= e($stat['tone']) ?>" aria-hidden="true">
            <i class="fa-solid <?= e($stat['icon']) ?>"></i>
        </span>
        <?php if ($stat['trend'] !== ''): ?>
            <span class="stat-trend"><i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i> <?= e($stat['trend']) ?></span>
        <?php endif; ?>
    </div>
    <div class="stat-value" <?= $isNumber ? 'data-count="' . e((string) $stat['value']) . '"' : '' ?>>
        <?= e($display) ?>
    </div>
    <div class="stat-label"><?= e($stat['label']) ?></div>
</div>