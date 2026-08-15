<?php

declare(strict_types=1);

/**
 * recommendations/index.php
 *
 * The recommendations page (Phase 6.2-6.4).
 *
 * ONE template, TWO faces - chosen by the controller:
 *
 * 1. Phase 6.4 DASHBOARD ($dashboard !== null, the /recommendations
 *    overview for logged-in users):
 *      - hero: quality ring + refresh action (+ a nudge for
 *        signal-less users)
 *      - the seven shelves: Recommended for you, Because you liked,
 *        Because you follow, Trending near your interests, Recently
 *        added, Explore new genres and Recommendation insights
 *      - every recommendation card explains WHY (engine reason,
 *        score and confidence, "Why this book?" panel)
 *      - skeleton loading while a refresh runs
 *      - the preserved Phase 6.2 strategy cards at the bottom
 *
 * 2. LEGACY STRATEGY PAGE ($dashboard === null, the /recommendations/
 *    popular | top-rated | trending | recent | category/{id} |
 *    book/{id} routes): the original Phase 6.2 page - page intro,
 *    strategy cards, run note and the result shelf with the
 *    rec-reason / rec-score lines under every book card.
 *
 * View variables from RecommendationController::viewData():
 *     $title      - page title
 *     $lead       - one-line explanation
 *     $strategies - metadata of every registered strategy
 *     $activeKey  - the strategy behind the current page (or null)
 *     $result     - the RecommendationResult DTO, or null
 *     $dashboard  - the Phase 6.4 view-model, or null
 */

$strategies = $strategies ?? [];
$activeKey  = $activeKey ?? null;
$result     = $result ?? null;
$dashboard  = $dashboard ?? null;

$title = $title ?? 'Recommendations';
$lead  = $lead ?? '';

?>
<?php if ($dashboard !== null): ?>
    <div class="rec-dashboard" data-dashboard>
        <?php $hero = [
            'eyebrow'    => 'Your reading DNA',
            'title'      => 'Your reading, decoded.',
            'lead'       => 'A blend of six strategies - hybrid scoring, likeness, follows, momentum and freshness - '
                . 'personalized from your ratings, reviews, wishlist and browsing. Every pick explains itself.',
            'hasSignals' => $dashboard['hasSignals'] ?? false,
            'quality'    => $dashboard['quality'] ?? ['score' => null, 'label' => '', 'generatedAt' => ''],
            'updatedAgo' => $dashboard['updatedAgo'] ?? 'Updated just now',
        ]; ?>
        <?php require root_path('app/Views/recommendations/_hero.php'); ?>

        <section class="rec-section rec-skeletons" data-skeletons hidden aria-hidden="true">
            <div class="rec-card-grid">
                <?php $skeletonCount = 8; ?>
                <?php require root_path('app/Views/recommendations/components/skeleton-card.php'); ?>
            </div>
        </section>

        <?php
        $wishlistIds = $dashboard['wishlistIds'] ?? [];

        $shelf = $dashboard['recommended'] ?? [];
        require root_path('app/Views/recommendations/_section-recommended.php');

        $shelf = $dashboard['becauseLiked'] ?? [];
        require root_path('app/Views/recommendations/_section-because-liked.php');

        $shelf = $dashboard['follow'] ?? [];
        require root_path('app/Views/recommendations/_section-follow.php');

        $shelf = $dashboard['trending'] ?? [];
        require root_path('app/Views/recommendations/_section-trending.php');

        $shelf = $dashboard['recent'] ?? [];
        require root_path('app/Views/recommendations/_section-recent.php');

        $shelf = ['genres' => array_map(static fn (array $genre): array => [
            'name'  => (string) ($genre['name'] ?? ''),
            'count' => (int) ($genre['seen'] ?? 0),
            'href'  => '/books?category_id=' . (int) ($genre['id'] ?? 0),
        ], $dashboard['genres'] ?? [])];
        require root_path('app/Views/recommendations/_section-genres.php');

        $shelf = ['stats' => $dashboard['insights'] ?? []];
        require root_path('app/Views/recommendations/_section-insights.php');
        ?>

        <p class="rec-dashboard-meta">
            Phase 6.4 &middot; profile <?= (int) $dashboard['userId'] === 0 ? 'not detected' : '#' . (int) $dashboard['userId'] ?>
            &middot; cached per user &middot; <a href="#how-recommendations-are-made">how recommendations are made</a>
        </p>
    </div>
<?php endif; ?>

<?php if ($dashboard === null): ?>
    <div class="page-intro" data-animate>
        <p class="eyebrow">Library</p>
        <h1><?= e($title) ?></h1>
        <p class="lead"><?= e($lead) ?></p>
    </div>
<?php endif; ?>

<section class="mb-3" id="how-recommendations-are-made" data-animate>
    <?php require root_path('app/Views/recommendations/_strategy-cards.php'); ?>
</section>

<?php if ($result !== null): ?>
    <section class="mb-3" data-animate>
        <?php $section = [
            'eyebrow' => 'Result',
            'title'   => $result->strategyLabel,
            'icon'    => 'fa-wand-magic-sparkles',
        ]; ?>
        <?php require root_path('app/Views/components/section-header.php'); ?>

        <div class="card-base mb-3">
            <p class="rec-run-note">
                <i class="fa-solid fa-microchip me-1" aria-hidden="true"></i>
                <?= e($result->note) ?>
            </p>
        </div>

        <?php if ($result->total === 0): ?>
            <div class="card-base">
                <?php $empty = [
                    'icon'    => 'fa-book-open',
                    'title'   => 'No books on this shelf yet',
                    'message' => 'The algorithm ran as designed, but nothing qualified. This is honest behaviour - '
                        . 'a quiet community or a thin catalogue yields an empty shelf instead of a made-up one.',
                    'action'  => ['label' => 'Back to recommendations', 'href' => '/recommendations', 'icon' => 'fa-arrow-left'],
                ]; ?>
                <?php require root_path('app/Views/components/empty-state.php'); ?>
            </div>
        <?php else: ?>
            <div class="book-browse-grid" aria-label="Recommended books">
                <?php foreach ($result->items as $book): ?>
                    <article class="rec-book">
                        <?php require root_path('app/Views/books/components/book-card.php'); ?>
                        <?php if (isset($book['reason']) && $book['reason'] !== ''): ?>
                            <p class="rec-reason">
                                <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                                <?= e($book['reason']) ?>
                            </p>
                        <?php endif; ?>
                        <?php if (isset($book['score'])): ?>
                            <p class="rec-score">
                                <i class="fa-solid fa-gauge-high" aria-hidden="true"></i>
                                Score <?= e($book['score']) ?>/100
                                &middot; <?= e($book['confidence']) ?> confidence
                            </p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <p class="rec-result-meta">Strategy <code><?= e($result->strategyKey) ?></code> &middot; generated
            <?= e($result->generatedAt) ?> &middot; <?= (int) $result->total ?> items
            &middot; <a href="/recommendations">all strategies</a></p>
    </section>
<?php endif; ?>
