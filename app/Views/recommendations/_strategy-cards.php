<?php

declare(strict_types=1);

/**
 * recommendations/_strategy-cards.php
 *
 * The preserved Phase 6.2 "strategy architecture" section.
 *
 * Purpose:
 *     When the Phase 6.4 dashboard replaced the old overview, this
 *     block was extracted VERBATIM (class names and copy untouched)
 *     so the RecommendationArchitectureTest keeps passing: the six
 *     strategy cards, the active highlight, the "Running now"
 *     status and the rec-reason/rec-score lines under strategy
 *     results. On the dashboard it now sits at the bottom as the
 *     "How recommendations are made" explainer; on the legacy
 *     strategy pages (popular, top-rated, ...) it still renders
 *     exactly like before through index.php.
 *
 * Data (set by the controller via viewData()):
 *     $strategies - metadata of every registered strategy
 *     $activeKey  - the strategy behind the current page (or null)
 */

$strategies = $strategies ?? [];
$activeKey  = $activeKey ?? null;

?>
<section class="mb-3" data-animate>
    <?php $section = [
        'eyebrow' => 'Strategy architecture',
        'title'   => 'How recommendations are made',
        'icon'    => 'fa-wand-magic-sparkles',
    ]; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>

    <div class="rec-grid">
        <?php foreach ($strategies as $strategy): ?>
            <article class="card-base rec-card<?= $strategy['key'] === $activeKey ? ' rec-card-active' : '' ?>">
                <span class="rec-card-icon" aria-hidden="true">
                    <i class="fa-solid <?= e($strategy['icon']) ?>"></i>
                </span>
                <h3 class="rec-card-title"><?= e($strategy['label']) ?></h3>
                <p class="rec-card-desc"><?= e($strategy['description']) ?></p>
                <div class="rec-card-footer">
                    <span class="rec-card-status"><?= $strategy['key'] === $activeKey ? 'Running now' : 'Algorithm live' ?></span>
                    <a class="btn btn-soft btn-sm" href="<?= e($strategy['url']) ?>">
                        Open
                        <i class="fa-solid fa-arrow-right ms-1" aria-hidden="true"></i>
                    </a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
