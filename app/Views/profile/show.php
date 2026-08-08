<?php

declare(strict_types=1);

/**
 * profile/show.php
 *
 * Displays the logged-in user's profile: name, email, role and
 * member-since date, with links to edit the profile and change
 * the password. $user is provided by the controller.
 */

?>
<div class="page-intro">
    <p class="eyebrow">My account</p>
    <h1>My profile</h1>
    <p class="lead">Your account details and settings.</p>
</div>

<div class="card-base" style="max-width: 640px;">
    <div class="d-flex align-items-start justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            <span class="icon-button" aria-hidden="true" style="font-size: 1.25rem;">
                <i class="fa-solid fa-user"></i>
            </span>
            <div>
                <h2 class="mb-0"><?= e($user['full_name']) ?></h2>
                <span class="badge text-bg-<?= $user['role'] === 'admin' ? 'danger' : 'secondary' ?>"><?= e($user['role']) ?></span>
            </div>
        </div>
    </div>

    <hr>

    <dl class="row mb-0">
        <dt class="col-sm-3 text-muted">Full name</dt>
        <dd class="col-sm-9"><?= e($user['full_name']) ?></dd>

        <dt class="col-sm-3 text-muted">Email address</dt>
        <dd class="col-sm-9"><?= e($user['email']) ?></dd>

        <dt class="col-sm-3 text-muted">Role</dt>
        <dd class="col-sm-9"><?= e($user['role']) ?></dd>

        <dt class="col-sm-3 text-muted">Member since</dt>
        <dd class="col-sm-9"><?= e(date('F j, Y', strtotime($user['created_at']))) ?></dd>
    </dl>
</div>

<?php
// Phase 7.3: the "My rating activity" block - aggregated from the
// user's OWN approved reviews (ReviewService::profileStats()), never
// from placeholder data.
$ratingStats = $ratingStats ?? [];
$givenCount  = (int) ($ratingStats['count'] ?? 0);
$givenAvg    = $ratingStats['average'] ?? null;
$highestBook = $ratingStats['highest'] ?? null;
$latest      = $ratingStats['latest'] ?? ['title' => null, 'rating' => null, 'created_at' => null];

// Phase 7.6: the enriched statistics (favourite genres and the most
// reviewed category) come from ReviewService::userReviewStatistics().
$userReviewStats = $userReviewStats ?? [];
$favouriteGenres = $userReviewStats['favouriteCategories'] ?? [];
$topGenre        = $userReviewStats['mostReviewedCategory'] ?? null;
?>
<div class="card-base mt-4" style="max-width: 640px;">
    <div class="d-flex align-items-center gap-3 mb-3">
        <i class="fa-solid fa-star-half-stroke card-icon" aria-hidden="true"></i>
        <h2 class="mb-0">My rating activity</h2>
    </div>
    <?php if ($givenCount === 0): ?>
        <p class="text-muted mb-0">
            You have not rated any books yet. Head to a book page and share your first review!
        </p>
    <?php else: ?>
        <div class="row g-3">
            <div class="col-6">
                <div class="analytics-tile">
                    <span class="analytics-tile-value"><?= (int) $givenCount ?></span>
                    <span class="analytics-tile-label">Review<?= $givenCount === 1 ? '' : 's' ?> written</span>
                </div>
            </div>
            <div class="col-6">
                <div class="analytics-tile">
                    <span class="analytics-tile-value">
                        <?= e($givenAvg === null ? '—' : number_format((float) $givenAvg, 1)) ?>
                    </span>
                    <span class="analytics-tile-label">Average rating given</span>
                </div>
            </div>
            <div class="col-12">
                <div class="analytics-tile d-flex justify-content-between align-items-center">
                    <div>
                        <span class="analytics-tile-label d-block">Most recent rating</span>
                        <?php if ($latest['title'] !== null): ?>
                            <span class="fw-semibold"><?= e($latest['title']) ?></span>
                            <span class="text-muted small ms-2">
                                <?= e(format_review_date((string) $latest['created_at'])) ?>
                            </span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </div>
                    <?php if (($latest['rating'] ?? null) !== null): ?>
                        <?php $starRating = [
                            'rating'  => (int) $latest['rating'],
                            'count'   => null,
                            'size'    => 'sm',
                            'tooltip' => false,
                        ]; ?>
                        <?php require root_path('app/Views/components/star-rating.php'); ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($highestBook !== null): ?>
                <div class="col-12">
                    <div class="analytics-tile">
                        <span class="analytics-tile-label d-block">Highest rated by you</span>
                        <span class="fw-semibold"><?= e($highestBook) ?></span>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($topGenre !== null): ?>
                <div class="col-12">
                    <div class="analytics-tile">
                        <span class="analytics-tile-label d-block">Most reviewed category</span>
                        <span class="fw-semibold"><?= e($topGenre) ?></span>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($favouriteGenres !== []): ?>
                <div class="col-12">
                    <div class="analytics-tile">
                        <span class="analytics-tile-label d-block">Favourite genres</span>
                        <div class="d-flex gap-2 flex-wrap mt-1">
                            <?php foreach ($favouriteGenres as $genre): ?>
                                <a class="badge text-bg-light border text-decoration-none" href="/categories/<?= (int) $genre['id'] ?>">
                                    <?= e($genre['name']) ?>
                                    <span class="text-muted">(<?= (int) $genre['count'] ?>)</span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php
// Phase 7.6: the Review Activity timeline - how many approved
// reviews the user wrote per month, newest first
// (ReviewService::reviewActivityTimeline()).
$activityTimeline = $activityTimeline ?? [];
?>
<div class="card-base mt-4" style="max-width: 640px;">
    <div class="d-flex align-items-center gap-3 mb-3">
        <i class="fa-solid fa-chart-line card-icon" aria-hidden="true"></i>
        <h2 class="mb-0">Review activity</h2>
    </div>
    <?php if ($activityTimeline === []): ?>
        <p class="text-muted mb-0">
            You have not written any reviews yet. Head to a book page and share your first review!
        </p>
    <?php else: ?>
        <div class="d-flex flex-column gap-2">
            <?php foreach ($activityTimeline as $month): ?>
                <?php $monthCount = (int) $month['count']; ?>
                <?php $monthMax = max(1, (int) ($activityTimeline[0]['count'] ?? 1)); ?>
                <div class="d-flex align-items-center gap-2">
                    <span class="text-muted small" style="width: 90px;"><?= e(date('M Y', strtotime((string) $month['month'] . '-01'))) ?></span>
                    <div class="progress flex-grow-1" style="height: 10px;">
                        <div class="progress-bar" data-bar-percent="<?= (int) round($monthCount / $monthMax * 100) ?>"
                             style="width: 0%"></div>
                    </div>
                    <span class="text-muted small" style="width: 30px; text-align: right;"><?= $monthCount ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php
// Phase 7.5: the "Community reputation" block - the Helpful Score
// (helpful votes received, reviews written, the most helpful
// review), aggregated from the REAL review_helpful_votes votes on
// the user's approved reviews (ReviewService::reviewReputation()).
// Badge tiers (Verified / Top Reviewer / Expert) are prepared for
// but not yet awarded - a later phase ships them.
$reputation = $reputation ?? [];
$helpfulReceived = (int) ($reputation['helpfulReceived'] ?? 0);
$reviewsWritten  = (int) ($reputation['reviewsWritten'] ?? 0);
$mostHelpful     = $reputation['mostHelpful'] ?? null;
?>
<div class="card-base mt-4" style="max-width: 640px;">
    <div class="d-flex align-items-center gap-3 mb-3">
        <i class="fa-solid fa-hand-holding-heart card-icon" aria-hidden="true"></i>
        <h2 class="mb-0">Community reputation</h2>
    </div>
    <?php if ($reviewsWritten === 0): ?>
        <p class="text-muted mb-0">
            Share your first review to start earning helpful votes from the community.
        </p>
    <?php else: ?>
        <div class="row g-3">
            <div class="col-6">
                <div class="analytics-tile">
                    <span class="analytics-tile-value"><?= (int) $helpfulReceived ?></span>
                    <span class="analytics-tile-label">Helpful votes received</span>
                </div>
            </div>
            <div class="col-6">
                <div class="analytics-tile">
                    <span class="analytics-tile-value"><?= (int) $reviewsWritten ?></span>
                    <span class="analytics-tile-label">Reviews written</span>
                </div>
            </div>
            <?php if ($mostHelpful !== null): ?>
                <div class="col-12">
                    <div class="analytics-tile d-flex justify-content-between align-items-center">
                        <div>
                            <span class="analytics-tile-label d-block">Most helpful review</span>
                            <a class="fw-semibold text-decoration-none" href="/reviews/<?= (int) $mostHelpful['id'] ?>">
                                <?= e($mostHelpful['title'] !== '' ? $mostHelpful['title'] : 'Review #' . (int) $mostHelpful['id']) ?>
                            </a>
                            <span class="text-muted small ms-2"><?= e($mostHelpful['book_title'] ?? '') ?></span>
                        </div>
                        <?php if ((int) ($mostHelpful['rating'] ?? 0) > 0): ?>
                            <?php $starRating = [
                                'rating'  => (int) $mostHelpful['rating'],
                                'count'   => null,
                                'size'    => 'sm',
                                'tooltip' => false,
                            ]; ?>
                            <?php require root_path('app/Views/components/star-rating.php'); ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            <div class="col-12">
                <div class="analytics-tile d-flex justify-content-between align-items-center">
                    <div>
                        <span class="analytics-tile-label d-block">Badges</span>
                        <span class="text-muted small">
                            Verified reader, Top Reviewer and Expert badges arrive in a future phase.
                        </span>
                    </div>
                    <i class="fa-solid fa-award text-muted" aria-hidden="true"></i>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="d-flex gap-2 mt-4 flex-wrap" style="max-width: 640px;">
    <a class="btn btn-primary" href="/profile/edit"><i class="fa-solid fa-pen me-1" aria-hidden="true"></i> Edit profile</a>
    <a class="btn btn-outline-secondary" href="/change-password"><i class="fa-solid fa-key me-1" aria-hidden="true"></i> Change password</a>
    <?php // Phase 9.2: the Follow Authors module - the discoverable
          // door to the user's "Authors I follow" list. ?>
    <a class="btn btn-outline-secondary" href="/profile/following"><i class="fa-solid fa-users me-1" aria-hidden="true"></i> Authors I follow</a>
</div>

<?php
// Phase 8.4: the "My Library" block - the personal library summary
// (statusCounts()), the favourite books / categories (favoriteBooks()
// + preferredGenres()) and the recently-added / recently-finished
// shelves, all read through the SHARED LibraryService - the single
// source of truth for the user's library, never placeholder data.
$librarySummary       = $librarySummary ?? [];
$favouriteBooks       = $favouriteBooks ?? [];
$favouriteCategories  = $favouriteCategories ?? [];
$recentlyAddedLib     = $recentlyAddedLib ?? [];
$recentlyFinished     = $recentlyFinished ?? [];
$hasLibrary           = $librarySummary !== [];
?>
<section class="dash-section mt-4">
    <?php $section = ['eyebrow' => 'Your bookshelf', 'title' => 'My Library', 'icon' => 'fa-book', 'link' => ['label' => 'Open library', 'href' => '/library']]; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>

    <?php if (!$hasLibrary): ?>
        <?php $empty = [
            'icon'    => 'fa-book-open',
            'title'   => 'Your library is empty',
            'message' => 'Add books to your personal library to see them here.',
            'action'  => ['label' => 'Browse books', 'href' => '/books'],
        ]; ?>
        <?php require root_path('app/Views/components/empty-state.php'); ?>
    <?php else: ?>
        <div class="row g-3 g-xl-4">
            <div class="col-12 col-xl-4">
                <?php $libraryTiles = [
                    ['icon' => 'fa-book',             'label' => 'Books',            'value' => (int) ($librarySummary['total'] ?? 0)],
                    ['icon' => 'fa-book-open-reader', 'label' => 'Reading now',       'value' => (int) ($librarySummary['currently_reading'] ?? 0)],
                    ['icon' => 'fa-circle-check',     'label' => 'Finished',          'value' => (int) ($librarySummary['finished'] ?? 0)],
                    ['icon' => 'fa-heart',            'label' => 'Favourite books',   'value' => (int) ($librarySummary['favorites'] ?? 0)],
                ]; ?>
                <div class="row g-2 row-cols-2">
                    <?php foreach ($libraryTiles as $tile): ?>
                        <div class="col">
                            <div class="analytics-tile">
                                <span class="analytics-tile-value"><?= (int) $tile['value'] ?></span>
                                <span class="analytics-tile-label"><?= e($tile['label']) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="col-12 col-xl-4">
                <h4 class="h6 mb-2">Favourite books</h4>
                <?php if ($favouriteBooks === []): ?>
                    <p class="text-muted small mb-0">No favourites yet - star the books you love.</p>
                <?php else: ?>
                    <div class="d-flex flex-column gap-2">
                        <?php foreach ($favouriteBooks as $record): ?>
                            <a class="d-flex align-items-center gap-2 text-decoration-none text-reset" href="/books/<?= (int) $record['book_id'] ?>">
                                <?php $cover = [
                                    'src'   => (string) ($record['book_cover'] ?? ''),
                                    'alt'   => 'Cover of ' . (string) ($record['book_title'] ?? ''),
                                    'class' => 'profile-book-thumb',
                                ]; ?>
                                <?php require root_path('app/Views/books/components/book-cover.php'); ?>
                                <span class="small"><?= e((string) ($record['book_title'] ?? '')) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <h4 class="h6 mb-2 mt-3">Favourite categories</h4>
                <?php if ($favouriteCategories === []): ?>
                    <p class="text-muted small mb-0">The categories you keep will appear here.</p>
                <?php else: ?>
                    <div class="d-flex gap-2 flex-wrap">
                        <?php foreach ($favouriteCategories as $genre): ?>
                            <a class="badge text-bg-light border text-decoration-none" href="/categories/<?= (int) $genre['id'] ?>">
                                <?= e((string) $genre['name']) ?>
                                <span class="text-muted">(<?= (int) $genre['count'] ?>)</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="col-12 col-xl-4">
                <h4 class="h6 mb-2">Recently added</h4>
                <?php if ($recentlyAddedLib === []): ?>
                    <p class="text-muted small mb-0">Your newest additions will appear here.</p>
                <?php else: ?>
                    <div class="d-flex flex-column gap-2">
                        <?php foreach ($recentlyAddedLib as $record): ?>
                            <a class="d-flex align-items-center gap-2 text-decoration-none text-reset" href="/books/<?= (int) $record['book_id'] ?>">
                                <?php $cover = [
                                    'src'   => (string) ($record['book_cover'] ?? ''),
                                    'alt'   => 'Cover of ' . (string) ($record['book_title'] ?? ''),
                                    'class' => 'profile-book-thumb',
                                ]; ?>
                                <?php require root_path('app/Views/books/components/book-cover.php'); ?>
                                <span class="small"><?= e((string) ($record['book_title'] ?? '')) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <h4 class="h6 mb-2 mt-3">Recently finished</h4>
                <?php if ($recentlyFinished === []): ?>
                    <p class="text-muted small mb-0">Books you finish will appear here.</p>
                <?php else: ?>
                    <div class="d-flex flex-column gap-2">
                        <?php foreach ($recentlyFinished as $record): ?>
                            <a class="d-flex align-items-center gap-2 text-decoration-none text-reset" href="/books/<?= (int) $record['book_id'] ?>">
                                <?php $cover = [
                                    'src'   => (string) ($record['book_cover'] ?? ''),
                                    'alt'   => 'Cover of ' . (string) ($record['book_title'] ?? ''),
                                    'class' => 'profile-book-thumb',
                                ]; ?>
                                <?php require root_path('app/Views/books/components/book-cover.php'); ?>
                                <span class="small"><?= e((string) ($record['book_title'] ?? '')) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</section>

<?php
// Phase 8.5: the Reading Preferences & Recommendation Insights block -
// the user's top library categories/authors (what the engine learns
// from their shelves), the Recommendation Accuracy figure (how many
// of the recently recommended books the user acted on) and the books
// that shaped the shelves. Everything comes from the SHARED
// RecommendationService; an unwired engine yields an empty block.
$recommendationInsights = $recommendationInsights ?? [];
$insightCategories      = $recommendationInsights['categories'] ?? [];
$insightAuthors         = $recommendationInsights['authors'] ?? [];
$insightAccuracy        = $recommendationInsights['accuracy'] ?? ['recommended' => 0, 'acted' => 0, 'percent' => null];
$insightInfluencing     = $recommendationInsights['influencing'] ?? [];
?>
<section class="dash-section mt-4">
    <?php $section = ['eyebrow' => 'Reading preferences', 'title' => 'Recommendation Insights', 'icon' => 'fa-wand-magic-sparkles', 'link' => ['label' => 'View recommendations', 'href' => '/recommendations']]; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>

    <div class="row g-3 g-xl-4">
        <div class="col-12 col-xl-4">
            <h4 class="h6 mb-2">Favourite categories</h4>
            <?php if ($insightCategories === []): ?>
                <p class="text-muted small mb-0">The categories your library favours will appear here.</p>
            <?php else: ?>
                <div class="d-flex gap-2 flex-wrap">
                    <?php foreach ($insightCategories as $genre): ?>
                        <a class="badge text-bg-light border text-decoration-none" href="/categories/<?= (int) $genre['id'] ?>">
                            <?= e((string) $genre['name']) ?>
                            <span class="text-muted">(<?= (int) $genre['kept'] ?>)</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <h4 class="h6 mb-2 mt-3">Favourite authors</h4>
            <?php if ($insightAuthors === []): ?>
                <p class="text-muted small mb-0">The authors your library favours will appear here.</p>
            <?php else: ?>
                <div class="d-flex gap-2 flex-wrap">
                    <?php foreach ($insightAuthors as $author): ?>
                        <a class="badge text-bg-light border text-decoration-none" href="/authors/<?= (int) $author['id'] ?>">
                            <?= e((string) $author['name']) ?>
                            <span class="text-muted">(<?= (int) $author['kept'] ?>)</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="col-12 col-xl-4">
            <h4 class="h6 mb-2">Recommendation accuracy</h4>
            <?php if ((int) $insightAccuracy['recommended'] === 0): ?>
                <p class="text-muted small mb-0">
                    Keep browsing - once the engine recommends books for you, this shows how many you acted on after being recommended (saved, rated or finished).
                </p>
            <?php else: ?>
                <div class="analytics-tile">
                    <span class="analytics-tile-value">
                        <?= $insightAccuracy['percent'] !== null ? (int) $insightAccuracy['percent'] . '%' : '—' ?>
                    </span>
                    <span class="analytics-tile-label">
                        of <?= (int) $insightAccuracy['recommended'] ?> recent recommendations acted on
                        (<?= (int) $insightAccuracy['acted'] ?> only counted after the recommendation)
                    </span>
                </div>
            <?php endif; ?>
        </div>

        <div class="col-12 col-xl-4">
            <h4 class="h6 mb-2">Books influencing your recommendations</h4>
            <?php if ($insightInfluencing === []): ?>
                <p class="text-muted small mb-0">Your favourites and finished books shape your shelves - they will appear here.</p>
            <?php else: ?>
                <div class="d-flex flex-column gap-2">
                    <?php foreach ($insightInfluencing as $record): ?>
                        <a class="d-flex align-items-center gap-2 text-decoration-none text-reset" href="/books/<?= (int) $record['book_id'] ?>">
                            <?php $cover = [
                                'src'   => (string) ($record['cover_image'] ?? ''),
                                'alt'   => 'Cover of ' . (string) ($record['title'] ?? ''),
                                'class' => 'profile-book-thumb',
                            ]; ?>
                            <?php require root_path('app/Views/books/components/book-cover.php'); ?>
                            <span class="small">
                                <?= e((string) ($record['title'] ?? '')) ?>
                                <?php if ((int) ($record['is_favorite'] ?? 0) === 1): ?>
                                    <i class="fa-solid fa-heart text-danger" title="Favourite" aria-hidden="true"></i>
                                <?php endif; ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php
// Phase 7.4: the profile's Recent Reviews block - the signed-in
// user's latest reviews, rendered with the shared compact review
// card (same design the dashboard uses), fed by the Reviews module.
$recentReviews = $recentReviews ?? [];
?>
<section class="dash-section mt-4">
    <?php $section = ['eyebrow' => 'Your voice', 'title' => 'Recent Reviews', 'icon' => 'fa-comments', 'link' => ['label' => 'View all my reviews', 'href' => '/reviews']]; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>

    <?php if ($recentReviews === []): ?>
        <div class="row g-3 g-xl-4 row-cols-1 row-cols-md-2 row-cols-xl-3">
            <?php foreach ($recentReviews as $review): ?>
                <div class="col">
                    <?php $compact = true; ?>
                    <?php require root_path('app/Views/components/review-card.php'); ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
