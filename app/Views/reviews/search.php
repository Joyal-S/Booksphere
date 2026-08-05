<?php

declare(strict_types=1);

/**
 * reviews/search.php
 *
 * The REVIEW SEARCH page (Phase 7.4): the server-side search across
 * every approved review in the catalogue - one keyword matched
 * against the review title, the review body and the reviewer's name
 * inside the SQL, with the community statistics on top, the full
 * toolbar (search, sort, per-page, rating / edited / "my reviews
 * only" filters) and the paginated timeline of matching review
 * cards. The page doubles as the community review timeline: with an
 * empty search box it lists every recent review, sorted and
 * filtered exactly like the search results.
 *
 * Available variables (from ReviewController::search):
 *     $reviews    - the matching review rows of the current page
 *     $stats      - reviewStatistics() over the filtered rows
 *     $breakdown  - distributionBreakdown() rows
 *     $toolbar    - the Phase 7.4 toolbar payload (showMine on)
 *     $pagination - the Phase 7.4 pagination payload
 */

$reviews    = $reviews ?? [];
$stats      = $stats ?? [];
$breakdown  = $breakdown ?? [];
$toolbar    = $toolbar ?? null;
$pagination = $pagination ?? null;

$searched = $toolbar !== null && trim((string) $toolbar['q']) !== '';
?>
<div class="page-intro">
    <p class="eyebrow">Community &middot; <?= (int) ($stats['total'] ?? 0) ?> approved reviews</p>
    <h1>Review Search</h1>
    <p class="lead">
        Search every review by its title, its body or the reviewer's name &mdash;
        server-side, so the count and the pagination always tell the truth.
    </p>
</div>

<?php if ((int) ($stats['total'] ?? 0) > 0): ?>
    <?php $stats['breakdown'] = $breakdown; ?>
    <div class="mb-4">
        <?php require root_path('app/Views/components/review-stats.php'); ?>
    </div>
<?php endif; ?>

<?php if ($toolbar !== null): ?>
    <div class="review-toolbar-wrap mb-3">
        <?php require root_path('app/Views/reviews/partials/_toolbar.php'); ?>
    </div>
<?php endif; ?>

<div data-review-list>
    <?php $skeletons = ['count' => 3]; ?>
    <?php require root_path('app/Views/components/loading-skeleton.php'); ?>

    <?php if ($reviews === []): ?>
        <?php if (!$searched && (int) ($stats['total'] ?? 0) === 0): ?>
            <?php $emptyBase = [
                'title'   => 'No community reviews yet',
                'message' => 'Reviews appear here the moment readers rate a book. Be the first voice.',
                'action'  => ['label' => 'Browse books', 'href' => '/books'],
            ]; ?>
        <?php else: ?>
            <?php $emptyBase = [
                'title'   => 'No reviews found',
                'message' => 'Try a different keyword or widen the filters.',
            ]; ?>
        <?php endif; ?>
        <?php require root_path('app/Views/reviews/partials/_empty.php'); ?>
    <?php else: ?>
        <?php require root_path('app/Views/reviews/partials/_list.php'); ?>

        <?php if ($pagination !== null): ?>
            <div class="mt-4">
                <?php require root_path('app/Views/components/review-pagination.php'); ?>
            </div>
        <?php endif; ?>

        <?php require root_path('app/Views/reviews/partials/_delete-modal.php'); ?>
        <?php require root_path('app/Views/reviews/partials/_report-modal.php'); ?>
    <?php endif; ?>
</div>
