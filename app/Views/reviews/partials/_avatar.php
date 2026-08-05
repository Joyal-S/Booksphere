<?php

declare(strict_types=1);

/**
 * reviews/partials/_avatar.php
 *
 * The REUSABLE REVIEWER AVATAR (Phase 7.7 dedup): the initials
 * circle with a deterministic gradient tone (avatar-1..avatar-6,
 * the same reviewer always gets the same tone - computed from the
 * name, no extra column). Every review surface used to compute the
 * tone + initials and render the anchor/span itself; this partial
 * is the single implementation.
 *
 * Sets $avatarName first:
 *
 *     $avatarName  = 'Riya Sharma';   // required: the reviewer name
 *     $avatarHref  = '';              // optional: link when the
 *                                     // reviewer has a page
 *     $avatarTitle = '';              // optional: link title /
 *                                     // aria-label (defaults to
 *                                     // "All reviews by {name}")
 *
 * Renders a link when $avatarHref is set, a decorative span
 * (aria-hidden) otherwise.
 */

$avatarName  = (string) ($avatarName ?? '');
$avatarHref  = (string) ($avatarHref ?? '');
$avatarTitle = (string) ($avatarTitle ?? 'All reviews by ' . $avatarName);

$tone     = 'avatar-' . ((crc32($avatarName) % 6) + 1);
$initials = implode('', array_map(
    static fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)),
    array_slice(array_values(array_filter(explode(' ', $avatarName))), 0, 2),
));
$initials = $initials !== '' ? $initials : '?';
?>
<?php if ($avatarHref !== ''): ?>
    <a class="avatar <?= e($tone) ?>" href="<?= e($avatarHref) ?>"
       title="<?= e($avatarTitle) ?>" aria-label="<?= e($avatarTitle) ?>">
        <?= e($initials) ?>
    </a>
<?php else: ?>
    <span class="avatar <?= e($tone) ?>" aria-hidden="true"><?= e($initials) ?></span>
<?php endif; ?>
