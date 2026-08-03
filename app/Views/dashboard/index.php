<?php

declare(strict_types=1);

/**
 * dashboard/index.php
 *
 * The DASHBOARD - the logged-in user's home screen. Structure:
 *
 *     1. Greeting header with the session user's first name
 *     2. Featured Recommendations   (5 book cards)
 *     3. Trending Books             (5 book cards)
 *     4. Top Rated Books            (5 book cards)
 *     5. Recent Reviews             (4 compact review cards)
 *     6. Library Overview           (5 statistic cards)
 *
 * IMPORTANT: every book, review and statistic below is PLACEHOLDER
 * data. The recommendation engine, wishlist, reviews, analytics,
 * search and Google Books belong to later phases, so this page
 * intentionally shows hard-coded values only.
 *
 * The greeting is the only dynamic part: it reads the logged-in
 * user from the session (set at login time by AuthService).
 */

// ---- Greeting (from the authenticated session) -----------------------
// auth_user() returns the session user (id, full_name, email, role).
$hour      = (int) date('G');
$greeting  = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');
$firstName = ucfirst((string) (explode(' ', (string) auth_user()['full_name'])[0] ?? 'there'));

// ---- Placeholder data -------------------------------------------------
// Keys used by the book card component (see components/placeholder-book-card.php).

$featuredBooks = [
    ['title' => 'The Midnight Library', 'author' => 'Matt Haig', 'year' => 2020, 'genre' => 'Fiction',  'rating' => 4.6, 'votes' => '12.4k', 'tag' => 'Staff Pick', 'cover' => 'cover-1'],
    ['title' => 'Atomic Habits',        'author' => 'James Clear', 'year' => 2018, 'genre' => 'Self-help', 'rating' => 4.8, 'votes' => '28.1k', 'tag' => 'Staff Pick', 'cover' => 'cover-2'],
    ['title' => 'Dune',                 'author' => 'Frank Herbert', 'year' => 1965, 'genre' => 'Sci-Fi',   'rating' => 4.5, 'votes' => '19.7k', 'tag' => 'Classic',   'cover' => 'cover-3'],
    ['title' => 'The Silent Patient',   'author' => 'Alex Michaelides', 'year' => 2019, 'genre' => 'Thriller', 'rating' => 4.4, 'votes' => '15.9k', 'tag' => 'Staff Pick', 'cover' => 'cover-4'],
    ['title' => 'Educated',             'author' => 'Tara Westover', 'year' => 2018, 'genre' => 'Memoir',   'rating' => 4.7, 'votes' => '11.2k', 'tag' => 'Staff Pick', 'cover' => 'cover-5'],
];

$trendingBooks = [
    ['title' => 'Project Hail Mary',        'author' => 'Andy Weir', 'year' => 2021, 'genre' => 'Sci-Fi',     'rating' => 4.7, 'votes' => '9.8k',  'tag' => '#1 Trending', 'cover' => 'cover-6'],
    ['title' => 'The Psychology of Money',  'author' => 'Morgan Housel', 'year' => 2020, 'genre' => 'Finance', 'rating' => 4.6, 'votes' => '14.3k', 'tag' => '#2 Trending', 'cover' => 'cover-2'],
    ['title' => 'Tomorrow, and Tomorrow…',  'author' => 'Gabrielle Zevin', 'year' => 2022, 'genre' => 'Fiction', 'rating' => 4.4, 'votes' => '7.6k', 'tag' => '#3 Trending', 'cover' => 'cover-4'],
    ['title' => 'Fourth Wing',              'author' => 'Rebecca Yarros', 'year' => 2023, 'genre' => 'Fantasy', 'rating' => 4.5, 'votes' => '10.1k', 'tag' => '#4 Trending', 'cover' => 'cover-3'],
    ['title' => 'The Thursday Murder Club', 'author' => 'Richard Osman', 'year' => 2020, 'genre' => 'Mystery', 'rating' => 4.3, 'votes' => '6.4k',  'tag' => '#5 Trending', 'cover' => 'cover-5'],
];

$topRatedBooks = [
    ['title' => 'To Kill a Mockingbird',  'author' => 'Harper Lee',        'year' => 1960, 'genre' => 'Classic',  'rating' => 4.9, 'votes' => '31.5k', 'cover' => 'cover-1'],
    ['title' => 'The Kite Runner',        'author' => 'Khaled Hosseini',   'year' => 2003, 'genre' => 'Fiction',  'rating' => 4.8, 'votes' => '22.7k', 'cover' => 'cover-5'],
    ['title' => 'Sapiens',                'author' => 'Yuval Noah Harari', 'year' => 2011, 'genre' => 'History',  'rating' => 4.7, 'votes' => '26.4k', 'cover' => 'cover-2'],
    ['title' => 'The Hobbit',             'author' => 'J.R.R. Tolkien',    'year' => 1937, 'genre' => 'Fantasy',  'rating' => 4.8, 'votes' => '29.8k', 'cover' => 'cover-6'],
    ['title' => 'Pride and Prejudice',    'author' => 'Jane Austen',       'year' => 1813, 'genre' => 'Classic',  'rating' => 4.6, 'votes' => '17.3k', 'cover' => 'cover-3'],
];

$recentReviews = [
    ['name' => 'Riya Sharma',  'initials' => 'RS', 'tone' => 'avatar-1', 'rating' => 5, 'text' => 'Beautifully written and quietly profound. I could not put it down.', 'book' => 'The Midnight Library', 'time' => '2 days ago'],
    ['name' => 'Arjun Patel',  'initials' => 'AP', 'tone' => 'avatar-2', 'rating' => 4, 'text' => 'Practical, motivating and refreshingly short. The habit loop is explained brilliantly.', 'book' => 'Atomic Habits', 'time' => '4 days ago'],
    ['name' => 'Meera Nair',   'initials' => 'MN', 'tone' => 'avatar-3', 'rating' => 5, 'text' => 'A masterpiece of world-building. Every page feels essential.', 'book' => 'Dune', 'time' => '1 week ago'],
    ['name' => 'Karan Verma',  'initials' => 'KV', 'tone' => 'avatar-4', 'rating' => 5, 'text' => 'Smart, funny and unputdownable. The science is fascinating and the heart is even bigger.', 'book' => 'Project Hail Mary', 'time' => '2 weeks ago'],
];

$libraryStats = [
    ['icon' => 'fa-book',       'label' => 'Total Books',      'value' => 128,  'tone' => 'primary', 'trend' => '+8 this month'],
    ['icon' => 'fa-users',      'label' => 'Active Readers',   'value' => 1204, 'tone' => 'success', 'trend' => '+64 this month'],
    ['icon' => 'fa-comments',   'label' => 'Community Reviews', 'value' => 3482, 'tone' => 'info',    'trend' => '+189 this month'],
    ['icon' => 'fa-layer-group', 'label' => 'Categories',       'value' => 24,   'tone' => 'warning', 'trend' => '+2 this month'],
    ['icon' => 'fa-star',       'label' => 'Average Rating',   'value' => 4.6,  'tone' => 'danger',  'trend' => '+0.1 this month'],
];

?>
<div class="dash-hero" data-animate>
    <div>
        <p class="eyebrow">Welcome back</p>
        <h1><?= e($greeting) ?>, <?= e($firstName) ?> <span class="hero-wave" aria-hidden="true">👋</span></h1>
        <p class="lead">Here is what is happening in your library today.</p>
    </div>
    <div class="dash-date-chip">
        <i class="fa-regular fa-calendar" aria-hidden="true"></i>
        <?= e(date('l, F j, Y')) ?>
    </div>
</div>

<!-- Section 1: Featured Recommendations (5 placeholder books) -->
<section class="dash-section" data-animate>
    <?php $section = ['eyebrow' => 'Curated for you', 'title' => 'Featured Recommendations', 'icon' => 'fa-wand-magic-sparkles', 'link' => ['label' => 'View all', 'href' => '/books']]; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>
    <div class="row g-3 g-xl-4 row-cols-2 row-cols-md-3 row-cols-xl-5">
        <?php foreach ($featuredBooks as $book): ?>
            <div class="col"><?php require root_path('app/Views/components/placeholder-book-card.php'); ?></div>
        <?php endforeach; ?>
    </div>
</section>

<!-- Section 2: Trending Books (5 placeholder books) -->
<section class="dash-section" data-animate>
    <?php $section = ['eyebrow' => 'What readers love', 'title' => 'Trending Books', 'icon' => 'fa-fire', 'link' => ['label' => 'View all', 'href' => '/books']]; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>
    <div class="row g-3 g-xl-4 row-cols-2 row-cols-md-3 row-cols-xl-5">
        <?php foreach ($trendingBooks as $book): ?>
            <div class="col"><?php require root_path('app/Views/components/placeholder-book-card.php'); ?></div>
        <?php endforeach; ?>
    </div>
</section>

<!-- Section 3: Top Rated Books (5 placeholder books) -->
<section class="dash-section" data-animate>
    <?php $section = ['eyebrow' => 'Reader favourites', 'title' => 'Top Rated Books', 'icon' => 'fa-star', 'link' => ['label' => 'View all', 'href' => '/books']]; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>
    <div class="row g-3 g-xl-4 row-cols-2 row-cols-md-3 row-cols-xl-5">
        <?php foreach ($topRatedBooks as $book): ?>
            <div class="col"><?php require root_path('app/Views/components/placeholder-book-card.php'); ?></div>
        <?php endforeach; ?>
    </div>
</section>

<!-- Section 4: Recent Reviews (4 compact placeholder reviews) -->
<section class="dash-section" data-animate>
    <?php $section = ['eyebrow' => 'Community voices', 'title' => 'Recent Reviews', 'icon' => 'fa-comments', 'link' => ['label' => 'View all', 'href' => '/reviews']]; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>
    <div class="row g-3 g-xl-4 row-cols-1 row-cols-md-2">
        <?php foreach ($recentReviews as $review): ?>
            <div class="col"><?php require root_path('app/Views/components/review-card.php'); ?></div>
        <?php endforeach; ?>
    </div>
</section>

<!-- Section 5: Library Overview (5 placeholder statistics) -->
<section class="dash-section" data-animate>
    <?php $section = ['eyebrow' => 'At a glance', 'title' => 'Library Overview', 'icon' => 'fa-chart-column']; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>
    <div class="row g-3 g-xl-4 row-cols-2 row-cols-md-3 row-cols-xl-5">
        <?php foreach ($libraryStats as $stat): ?>
            <div class="col"><?php require root_path('app/Views/components/stat-card.php'); ?></div>
        <?php endforeach; ?>
    </div>
</section>
