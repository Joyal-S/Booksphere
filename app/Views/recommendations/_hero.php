<?php

declare(strict_types=1);

/**
 * recommendations/_hero.php
 *
 * The HERO of the Phase 6.4 Recommendation Dashboard.
 *
 * Purpose:
 *     The opening panel answers "why should I trust this page?"
 *     in one glance: the headline and lead frame the dashboard, the
 *     quality ring turns the engine's confidence score into a
 *     visible number, and the refresh action lets the user ask for
 *     fresh picks after they rate, review or save books.
 *
 * Data ($hero array, set by recommendations/index.php from the
 * dashboard presenter):
 *     'eyebrow'     => small label above the title
 *     'title'       => the H1
 *     'lead'        => one sentence explaining the page
 *     'hasSignals'  => false renders the "personalize your shelf"
 *                      nudge instead of the quality ring
 *     'quality'     => ['score' => int|null, 'label' => string,
 *                       'generatedAt' => string]
 *     'updatedAgo'  => the Phase 6.5 freshness phrase ("Updated X
 *                      minutes ago"), with the exact generation time
 *                      as a tooltip
 *
 * Refresh flow:
 *     The form POSTs to /recommendations/refresh with CSRF. With
 *     JavaScript, app.js intercepts the submit, flips the button
 *     to its "Running now…" state and replaces every shelf with
 *     skeleton cards until the fresh page arrives. Without
 *     JavaScript the plain form submit still works.
 *
 * Accessibility:
 *     The quality ring is a decorative visual; its meaning is
 *     announced through an aria-label on the wrapper, and the score
 *     itself is plain text, not an image or a chart library.
 */

$hero = array_merge([
    'eyebrow'     => 'Your reading DNA',
    'title'       => 'Your reading, decoded.',
    'lead'        => '',
    'hasSignals'  => true,
    'quality'     => ['score' => null, 'label' => '', 'generatedAt' => ''],
    'updatedAgo'  => 'Updated just now',
], $hero ?? []);

$qualityScore = $hero['quality']['score'] === null ? null : (int) round((float) $hero['quality']['score']);

?>
<section class="rec-hero" data-animate>
    <div class="card-base rec-hero-panel">
        <div class="rec-hero-copy">
            <p class="eyebrow"><?= e($hero['eyebrow']) ?></p>
            <h1 class="rec-hero-title"><?= e($hero['title']) ?></h1>
            <p class="rec-hero-lead"><?= e($hero['lead']) ?></p>

            <div class="rec-hero-actions">
                <form method="post" action="/recommendations/refresh" class="rec-refresh-form" data-refresh-form>
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <button type="submit" class="btn btn-primary rec-refresh-btn" data-refresh-button>
                        <i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i>
                        <span class="rec-refresh-text">Refresh recommendations</span>
                        <span class="rec-refresh-running">Running now&hellip;</span>
                        <span class="visually-hidden">Rebuild your personalized recommendations</span>
                    </button>
                </form>
                <a class="btn btn-soft" href="/books">
                    <i class="fa-solid fa-compass" aria-hidden="true"></i>
                    Browse books
                </a>
            </div>
        </div>

        <?php if ($hero['hasSignals'] && $qualityScore !== null): ?>
            <div class="rec-hero-metrics">
                <div class="rec-quality tone-<?= e($hero['quality']['label'] ?? 'low') ?>"
                     role="img"
                     aria-label="Recommendation quality: <?= $qualityScore ?> out of 100, <?= e($hero['quality']['label']) ?> confidence">
                    <div class="rec-quality-ring" data-quality-score="<?= $qualityScore ?>" style="--q: <?= $qualityScore ?>%">
                        <span class="rec-quality-score"><span data-count="<?= $qualityScore ?>">0</span>%</span>
                        <span class="rec-quality-cap">match</span>
                    </div>
                    <p class="rec-quality-label">Recommendation quality</p>
                    <p class="rec-quality-time" title="<?= $hero['quality']['generatedAt'] !== '' ? 'Generated ' . e($hero['quality']['generatedAt']) : '' ?>">
                        <i class="fa-regular fa-clock" aria-hidden="true"></i>
                        <?= e($hero['updatedAgo']) ?>
                    </p>
                </div>
            </div>
        <?php else: ?>
            <div class="rec-hero-metrics rec-hero-nudge" role="status">
                <span class="rec-nudge-icon" aria-hidden="true"><i class="fa-solid fa-user-plus"></i></span>
                <h2 class="rec-nudge-title">Personalize your reading shelf</h2>
                <p class="rec-nudge-text">
                    Recommendations are tailored to the books you rate, review and save.
                    Explore the library to teach us your taste.
                </p>
            </div>
        <?php endif; ?>
    </div>
</section>
