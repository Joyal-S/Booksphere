<?php

declare(strict_types=1);

/**
 * components/section-header.php
 *
 * The reusable SECTION HEADER: an icon tile, an eyebrow label and
 * a title on the left, with an optional "View all" link on the
 * right. Used at the top of every dashboard section so all sections
 * share the same rhythm.
 *
 * Included from a view that sets the $section array first:
 *
 *     $section = [
 *         'eyebrow' => 'Curated for you',
 *         'title'   => 'Featured Recommendations',
 *         'icon'    => 'fa-wand-magic-sparkles',
 *         'link'    => ['label' => 'View all', 'href' => '/books'], // optional
 *     ];
 */

$section = array_merge([
    'eyebrow' => '',
    'title'   => '',
    'icon'    => '',
    'link'    => null,
], $section ?? []);

?>
<div class="section-header">
    <div class="section-header-left">
        <?php if ($section['icon'] !== ''): ?>
            <span class="section-icon" aria-hidden="true"><i class="fa-solid <?= e($section['icon']) ?>"></i></span>
        <?php endif; ?>
        <div>
            <?php if ($section['eyebrow'] !== ''): ?>
                <p class="eyebrow"><?= e($section['eyebrow']) ?></p>
            <?php endif; ?>
            <h2 class="section-title"><?= e($section['title']) ?></h2>
        </div>
    </div>
    <?php if ($section['link'] !== null): ?>
        <a class="section-link" href="<?= e($section['link']['href'] ?? '/') ?>">
            <?= e($section['link']['label'] ?? 'View all') ?>
            <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
        </a>
    <?php endif; ?>
</div>
