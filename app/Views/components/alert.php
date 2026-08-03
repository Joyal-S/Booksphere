<?php

declare(strict_types=1);

/**
 * components/alert.php
 *
 * The reusable ALERT component. Renders a dismissible Bootstrap
 * alert with a matching icon so success/error/info messages look
 * consistent everywhere. The flash message partials/flash.php is
 * its main consumer.
 *
 * Included from a view that sets the $alert array first:
 *
 *     $alert = [
 *         'type'    => 'success', // success | danger | warning | info
 *         'message' => 'Your profile has been updated.',
 *     ];
 */

$alert = array_merge([
    'type'    => 'info',
    'message' => '',
], $alert ?? []);

$icons = [
    'success' => 'fa-circle-check',
    'danger'  => 'fa-circle-exclamation',
    'warning' => 'fa-triangle-exclamation',
    'info'    => 'fa-circle-info',
];

?>
<div class="alert alert-<?= e($alert['type']) ?> alert-dismissible fade show" role="alert">
    <i class="fa-solid <?= e($icons[$alert['type']] ?? $icons['info']) ?> alert-icon" aria-hidden="true"></i>
    <span><?= e($alert['message']) ?></span>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
