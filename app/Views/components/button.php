<?php

declare(strict_types=1);

/**
 * components/button.php
 *
 * The reusable BUTTON component. Renders either an <a> (when
 * $button['href'] is set) or a <button> with the same visual
 * treatment, so callers never have to repeat the class list.
 *
 * Included from a view that sets the $button array first:
 *
 *     $button = [
 *         'label'   => 'Log out',
 *         'variant' => 'primary',  // primary | secondary | outline | ghost | soft | danger
 *         'size'    => 'sm',       // optional: sm | lg
 *         'icon'    => 'fa-right-from-bracket', // optional
 *         'href'    => '/logout',  // optional; makes it a link
 *         'type'    => 'button',   // used only for real buttons
 *     ];
 */

$button = array_merge([
    'label'   => '',
    'variant' => 'primary',
    'size'    => '',
    'icon'    => '',
    'href'    => null,
    'type'    => 'button',
], $button ?? []);

$classes = 'btn btn-' . e($button['variant']) . ($button['size'] !== '' ? ' btn-' . e($button['size']) : '');

$iconHtml = $button['icon'] !== ''
    ? '<i class="fa-solid ' . e($button['icon']) . ' me-1" aria-hidden="true"></i>'
    : '';

if ($button['href'] !== null):
    ?>
    <a class="<?= $classes ?>" href="<?= e($button['href']) ?>"><?= $iconHtml ?><?= e($button['label']) ?></a>
<?php else: ?>
    <button class="<?= $classes ?>" type="<?= e($button['type']) ?>"><?= $iconHtml ?><?= e($button['label']) ?></button>
<?php endif; ?>
