<?php

declare(strict_types=1);

/**
 * admin/index.php
 *
 * The administration landing page. Reaching this page at all is the
 * proof of role-based authorization: the AdminMiddleware only lets
 * users with the "admin" role through.
 *
 * Phase 7.3: the page now carries the live RATING ANALYTICS, computed
 * by the Reviews module (ReviewService::adminAnalytics()) - the
 * catalogue average, the rating distribution over all approved
 * reviews, the highest and lowest rated books and the books that
 * have no reviews yet. Every figure aggregates the real reviews
 * table; the sample rating columns on the seeded books are never
 * shown to the administrator.
 *
 * The recommendation engine monitoring page lives at
 * /admin/recommendations (see admin/recommendations.php).
 */

$analytics = $ratingAnalytics ?? [];

$overall    = (float) ($analytics['overallAverage']['average'] ?? 0.0);
$reviewCount = (int) ($analytics['overallAverage']['count'] ?? 0);
$distribution = $analytics['distribution'] ?? [];
$highest     = $analytics['highestRated'] ?? [];
$lowest      = $analytics['lowestRated'] ?? [];
$unrated     = $analytics['booksWithoutRatings'] ?? [];
$categories  = $analytics['categoryAverage'] ?? [];

// Phase 7.6: the extended platform picture.
$totalReviews      = (int) ($analytics['totalReviews'] ?? 0);
$activeReviewers   = (int) ($analytics['activeReviewers'] ?? 0);
$withoutReviews    = (int) ($analytics['booksWithoutReviews'] ?? 0);
$topReviewers      = $analytics['mostActiveReviewers'] ?? [];
$reviewedCategories = $analytics['mostReviewedCategories'] ?? [];
$authors           = $analytics['authorAverage'] ?? [];
?>
<div class="page-intro">
    <p class="eyebrow">Restricted area</p>
    <h1>Administration</h1>
    <p class="lead">You are signed in as an administrator. Here is the live state of the library's ratings.</p>
</div>

<!-- Phase 7.3: Rating analytics (aggregated from the reviews table) -->
<section class="dash-section" data-animate>
    <?php $section = ['eyebrow' => 'Community signal', 'title' => 'Rating Analytics', 'icon' => 'fa-chart-simple']; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>

    <!-- Phase 7.6: the platform headline numbers -->
    <div class="row g-3 g-xl-4 mb-4">
        <div class="col-6 col-md-3">
            <?php $stat = ['icon' => 'fa-comments', 'label' => 'Total reviews', 'value' => $totalReviews, 'tone' => 'info']; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
        <div class="col-6 col-md-3">
            <?php $stat = ['icon' => 'fa-users', 'label' => 'Active reviewers', 'value' => $activeReviewers, 'tone' => 'success']; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
        <div class="col-6 col-md-3">
            <?php $stat = ['icon' => 'fa-book', 'label' => 'Books without reviews', 'value' => $withoutReviews, 'tone' => 'warning']; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
        <div class="col-6 col-md-3">
            <?php $stat = ['icon' => 'fa-star', 'label' => 'Average platform rating', 'value' => number_format($overall, 2), 'tone' => 'primary']; ?>
            <?php require root_path('app/Views/components/stat-card.php'); ?>
        </div>
    </div>

    <div class="row g-3 g-xl-4 mb-4">
        <div class="col-12 col-md-4">
            <div class="card-base h-100 p-4 text-center">
                <p class="eyebrow">Catalogue average</p>
                <div class="analytics-average-value"><?= e(number_format($overall, 2)) ?>/5</div>
                <p class="text-muted mb-0">from <?= $reviewCount ?> approved review<?= $reviewCount === 1 ? '' : 's' ?></p>
                <?php $starRating = [
                    'rating' => $overall,
                    'count'  => null,
                    'size'   => 'md',
                    'tooltip'=> false,
                ]; ?>
                <div class="mt-2 d-flex justify-content-center"><?php require root_path('app/Views/components/star-rating.php'); ?></div>
            </div>
        </div>
        <div class="col-12 col-md-8">
            <div class="card-base h-100 p-4">
                <h3 class="section-title">Distribution</h3>
                <?php $breakdown = []; $total = 0; ?>
                <?php for ($star = 5; $star >= 1; $star--): ?>
                    <?php $count = (int) ($distribution[$star] ?? 0); $total += $count; ?>
                    <?php $breakdown[] = ['stars' => $star, 'count' => $count, 'total' => 0]; ?>
                <?php endfor; ?>
                <?php foreach ($breakdown as &$row): ?>
                    <?php $row['percent'] = $total > 0 ? (int) round(((int) $row['count'] / $total) * 100) : 0; ?>
                    <?php $row['total'] = $total; ?>
                <?php endforeach; unset($row); ?>
                <?php $title = ''; $empty = 'No ratings yet.'; ?>
                <?php require root_path('app/Views/reviews/partials/_rating-distribution.php'); ?>
            </div>
        </div>
    </div>

    <div class="row g-3 g-xl-4">
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card-base h-100 p-4">
                <h3 class="section-title">Highest rated</h3>
                <?php if ($highest === []): ?><p class="text-muted mb-0">No rated books yet.</p><?php endif; ?>
                <ol class="analytics-list mb-0">
                    <?php foreach ($highest as $book): ?>
                        <li>
                            <a href="/books/<?= (int) $book['id'] ?>"><?= e($book['title']) ?></a>
                            <span class="text-muted"><?= e(number_format((float) $book['average'], 1)) ?> &middot; <?= (int) $book['count'] ?> review<?= (int) $book['count'] === 1 ? '' : 's' ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card-base h-100 p-4">
                <h3 class="section-title">Lowest rated</h3>
                <?php if ($lowest === []): ?><p class="text-muted mb-0">No rated books yet.</p><?php endif; ?>
                <ol class="analytics-list mb-0">
                    <?php foreach ($lowest as $book): ?>
                        <li>
                            <a href="/books/<?= (int) $book['id'] ?>"><?= e($book['title']) ?></a>
                            <span class="text-muted"><?= e(number_format((float) $book['average'], 1)) ?> &middot; <?= (int) $book['count'] ?> review<?= (int) $book['count'] === 1 ? '' : 's' ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card-base h-100 p-4">
                <h3 class="section-title">Without ratings</h3>
                <?php if ($unrated === []): ?><p class="text-muted mb-0">Every book has a review. Nice work!</p><?php endif; ?>
                <ul class="analytics-list mb-0">
                    <?php foreach ($unrated as $book): ?>
                        <li>
                            <a href="/books/<?= (int) $book['id'] ?>"><?= e($book['title']) ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card-base h-100 p-4">
                <h3 class="section-title">Average by category</h3>
                <?php if ($categories === []): ?><p class="text-muted mb-0">No categories with reviews yet.</p><?php endif; ?>
                <ul class="analytics-list mb-0">
                    <?php foreach ($categories as $category): ?>
                        <li>
                            <span><?= e($category['name']) ?></span>
                            <span class="text-muted"><?= e(number_format((float) $category['average'], 2)) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <!-- Phase 7.6: the extended analytics blocks -->
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card-base h-100 p-4">
                <h3 class="section-title">Most active reviewers</h3>
                <?php if ($topReviewers === []): ?><p class="text-muted mb-0">No reviews yet.</p><?php endif; ?>
                <ol class="analytics-list mb-0">
                    <?php foreach ($topReviewers as $reviewer): ?>
                        <li>
                            <a href="/reviews/user/<?= (int) $reviewer['id'] ?>" class="text-decoration-none"><?= e($reviewer['user_name']) ?></a>
                            <span class="text-muted">
                                <?= (int) $reviewer['count'] ?> review<?= (int) $reviewer['count'] === 1 ? '' : 's' ?>
                                &middot; <?= (int) $reviewer['helpful'] ?> helpful
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card-base h-100 p-4">
                <h3 class="section-title">Most reviewed categories</h3>
                <?php if ($reviewedCategories === []): ?><p class="text-muted mb-0">No reviews yet.</p><?php endif; ?>
                <ol class="analytics-list mb-0">
                    <?php foreach ($reviewedCategories as $category): ?>
                        <li>
                            <a href="/categories/<?= (int) $category['id'] ?>" class="text-decoration-none"><?= e($category['name']) ?></a>
                            <span class="text-muted">
                                <?= (int) $category['count'] ?> review<?= (int) $category['count'] === 1 ? '' : 's' ?>
                                &middot; <?= e(number_format((float) $category['average'], 2)) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card-base h-100 p-4">
                <h3 class="section-title">Average by author</h3>
                <?php if ($authors === []): ?><p class="text-muted mb-0">No authors with reviews yet.</p><?php endif; ?>
                <ul class="analytics-list mb-0">
                    <?php foreach ($authors as $author): ?>
                        <li>
                            <a href="/authors/<?= (int) $author['id'] ?>" class="text-decoration-none"><?= e($author['name']) ?></a>
                            <span class="text-muted"><?= e(number_format((float) $author['average'], 2)) ?> &middot; <?= (int) $author['count'] ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</section>
