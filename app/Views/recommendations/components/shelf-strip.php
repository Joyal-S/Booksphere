<?php

declare(strict_types=1);

/**
 * recommendations/components/shelf-strip.php
 *
 * The REUSABLE recommendation shelf of Phase 8.5.
 *
 * Purpose:
 *     One partial renders any recommendation section on ANY page -
 *     the dashboard, the book detail page, the library dashboard and
 *     the profile - with the same premium recommendation-card
 *     component the /recommendations dashboard uses, so the design
 *     can never drift between surfaces.
 *
 * Props (the $shelf array a view sets before requiring this file):
 *     'eyebrow'  => the section header eyebrow ('Curated for you')
 *     'title'    => the section header title ('Recommended for you')
 *     'icon'     => the section header icon class
 *     'link'     => optional ['label' => ..., 'href' => ...] header link
 *     'items'    => the recommended item arrays (flat book rows with
 *                   score / reason / confidence - exactly the shape
 *                   RecommendationService returns)
 *     'empty'    => the empty-state text
 *     'columns'  => the responsive row-columns classes
 *
 * Every item's explanation is printed only - the engine composes the
 * reasons, this view can never invent them.
 */

$shelf = array_merge([
    'eyebrow' => '',
    'title'   => '',
    'icon'    => 'fa-wand-magic-sparkles',
    'link'    => null,
    'items'   => [],
    'empty'   => 'Nothing here yet.',
    'columns' => 'row-cols-2 row-cols-md-3 row-cols-xl-4',
], $shelf ?? []);

?>
<section class="dash-section" data-animate>
    <?php if ($shelf['title'] !== ''): ?>
        <?php $section = [
            'eyebrow' => $shelf['eyebrow'],
            'title'   => $shelf['title'],
            'icon'    => $shelf['icon'],
            'link'    => $shelf['link'],
        ]; ?>
        <?php require root_path('app/Views/components/section-header.php'); ?>
    <?php endif; ?>

    <?php if ($shelf['items'] === []): ?>
        <div class="card-base p-4 text-center text-muted">
            <i class="fa-solid fa-book-open fa-lg me-2" aria-hidden="true"></i>
            <?= e($shelf['empty']) ?>
        </div>
    <?php else: ?>
        <div class="row g-3 g-xl-4 <?= e($shelf['columns']) ?>">
            <?php foreach ($shelf['items'] as $item): ?>
                <div class="col">
                    <?php
                    $rec = [
                        'book'         => $item,
                        'score'        => isset($item['score']) && is_numeric($item['score']) ? (float) $item['score'] : null,
                        'confidence'   => (string) ($item['confidence'] ?? ''),
                        'reason'       => (string) ($item['reason'] ?? ''),
                        'reasonPoints' => [],
                        'inWishlist'   => false,
                    ];
                    ?>
                    <?php require root_path('app/Views/recommendations/components/recommendation-card.php'); ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
