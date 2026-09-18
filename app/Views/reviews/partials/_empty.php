<?php

declare(strict_types=1);

/**
 * reviews/partials/_empty.php
 *
 * The shared EMPTY STATES of the professional review lists (Phase
 * 7.4). One partial renders the right message for the situation,
 * through the reusable empty-state component:
 *
 *     1. a search term with no results  -> "No reviews match your
 *        search" + a Clear search action
 *     2. active filters with no results -> "No reviews match your
 *        filters" + a Reset filters action
 *     3. nothing at all                 -> the caller's own "No
 *        reviews yet" copy (book page, My Reviews, user page)
 *
 * Available variables:
 *     $emptyBase - ['title' => string, 'message' => string,
 *                   'action' => ?['label' => string, 'href' => string]]
 *                  the "no reviews at all" copy of the including page
 *     $toolbar   - the toolbar payload (null when the page has no
 *                  toolbar); its 'q' / 'rating' / 'edited' / 'mine'
 *                  keys decide whether a search or filter state is
 *                  active
 */

$emptyBase = array_merge([
    'title'   => 'No reviews yet',
    'message' => '',
    'action'  => null,
], $emptyBase ?? []);

$toolbar    = $toolbar ?? null;
$searching  = $toolbar !== null && trim((string) ($toolbar['q'] ?? '')) !== '';
$filtering  = $toolbar !== null
    && ((int) ($toolbar['rating'] ?? 0) > 0 || !empty($toolbar['edited']) || !empty($toolbar['mine']));

if ($searching || $filtering) {
    $empty = [
        'icon'    => $searching ? 'fa-magnifying-glass' : 'fa-filter',
        'title'   => $searching ? 'No reviews match your search' : 'No reviews match your filters',
        'message' => $searching
            ? 'Try a different keyword, or clear the search to see every review.'
            : 'Widen the filters, or reset them to see every review.',
        'action'  => [
            'label' => $searching ? 'Clear search' : 'Reset filters',
            'href'  => $toolbar['base'],
        ],
        'class'   => 'empty-state--' . ($searching ? 'search' : 'filter'),
    ];
} else {
    $empty = [
        'icon'    => 'fa-feather-pointed',
        'title'   => $emptyBase['title'],
        'message' => $emptyBase['message'],
        'action'  => $emptyBase['action'],
        'class'   => 'empty-state--review',
    ];
}

require root_path('app/Views/components/empty-state.php');
?>

<?php if (!$searching && !$filtering): ?>
    <div class="review-empty-context-grid mt-4" data-animate>
        <div class="review-empty-context-card">
            <span class="review-context-icon review-context-icon--perspective" aria-hidden="true">
                <i class="fa-solid fa-feather-pointed"></i>
            </span>
            <h3 class="review-context-title">Share your perspective</h3>
            <p class="review-context-desc">Express what made each book resonate with you and guide fellow readers.</p>
        </div>
        <div class="review-empty-context-card">
            <span class="review-context-icon review-context-icon--profile" aria-hidden="true">
                <i class="fa-solid fa-fingerprint"></i>
            </span>
            <h3 class="review-context-title">Build your reading profile</h3>
            <p class="review-context-desc">Your ratings and critiques sharpen your personal recommendations.</p>
        </div>
        <div class="review-empty-context-card">
            <span class="review-context-icon review-context-icon--impact" aria-hidden="true">
                <i class="fa-solid fa-comments"></i>
            </span>
            <h3 class="review-context-title">Make an impact</h3>
            <p class="review-context-desc">Join thoughtful discussions and shape BookSphere's community ratings.</p>
        </div>
    </div>
<?php endif; ?>
