<?php

declare(strict_types=1);

/**
 * dashboard/index.php
 *
 * The DASHBOARD - the logged-in user's home screen. Structure:
 *
 *     1. Greeting header with the session user's first name
 *     2. Continue Reading          (Phase 8.2: REAL - the books the
 *                                   user is currently reading, newest
 *                                   activity first, with the progress
 *                                   bar and a Resume button, read
 *                                   through the shared LibraryService)
 *     3. Recently Added            (Phase 8.4: REAL - the user's own
 *                                   newest library additions)
 *     4. My Favourite Books        (Phase 8.4: REAL - the user's own
 *                                   starred books)
 *     5. Featured Recommendations   (5 book cards - placeholder)
 *     6. Trending Books             (5 book cards - placeholder)
 *     7. Top Rated Books            (4 book cards - Phase 7.3: REAL
 *                                    aggregation over approved reviews)
 *     8. Community Favourite Books  (4 book cards - Phase 7.6: REAL,
 *                                    the most-reviewed books)
 *     9. Recent Reviews             (4 compact review cards - Phase
 *                                    7.4: REAL latest community reviews)
 *    10. Highest Rated Reviews      (4 compact review cards - Phase
 *                                    7.4: REAL, rating-first)
 *    11. My Latest Review           (1 compact review card - Phase
 *                                    7.4: REAL, the signed-in user's
 *                                    most recent review)
 *    12. My Highest Rated Book      (1 book card - Phase 7.6: REAL,
 *                                    the book the user rated highest)
 *    13. Library Overview           (Phase 8.4: the user's OWN library
 *                                    numbers - total / reading / finished
 *                                    / favourites / want-to-read - plus
 *                                    the Smart Collections quick access)
 *
 * IMPORTANT: the Featured, Trending and (when the library module is
 * not wired) the Library Overview sections are PLACEHOLDER data. The
 * recommendation engine, wishlist, analytics, search and Google Books
 * belong to later phases, so those sections intentionally show
 * hard-coded values only. The Continue Reading shelf (Phase 8.2), the
 * Recently Added and My Favourite Books shelves and the Library
 * Overview (Phase 8.4), the Top Rated Books section (Phase 7.3), the
 * review sections (Phase 7.4) and the Community Favourite / My
 * Highest Rated Book sections (Phase 7.6) are real: the dashboard
 * controller asks the Reviews and Personal Library modules for the
 * data (one composed dashboardStatistics() payload, a single
 * currentlyReading() read) and only presents it.
 *
 * The greeting is the only dynamic part: it reads the logged-in
 * user from the session (set at login time by AuthService).
 */

// ---- Greeting (from the authenticated session) -----------------------
// auth_user() returns the session user (id, full_name, email, role).
$hour      = (int) date('G');
$greeting  = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');
$firstName = ucfirst((string) (explode(' ', (string) auth_user()['full_name'])[0] ?? 'there'));

$libraryCounts = $libraryCounts ?? [];
$collections   = $collections ?? [];

// Phase 8.4: the Library Overview stats row is now the user's OWN
// library numbers (read through the shared LibraryService). When the
// module is not wired (standalone tests / controller without the
// service) the row is EMPTY and the section below renders nothing -
// the section is skipped entirely, so the page never shows a fake
// zeroed overview and never trips an undefined variable.
$libraryStats = [];

if ($libraryCounts !== []) {
    $libraryStats = [
        ['icon' => 'fa-book',             'label' => 'Total Books',        'value' => (int) ($libraryCounts['total'] ?? 0), 'tone' => 'primary'],
        ['icon' => 'fa-book-open-reader', 'label' => 'Currently Reading',  'value' => (int) ($libraryCounts['currently_reading'] ?? 0), 'tone' => 'warning'],
        ['icon' => 'fa-circle-check',     'label' => 'Finished',           'value' => (int) ($libraryCounts['finished'] ?? 0), 'tone' => 'success'],
        ['icon' => 'fa-heart',            'label' => 'Favourites',         'value' => (int) ($libraryCounts['favorites'] ?? 0), 'tone' => 'danger'],
        ['icon' => 'fa-bookmark',         'label' => 'Want to Read',       'value' => (int) ($libraryCounts['want_to_read'] ?? 0), 'tone' => 'info'],
    ];
}

?>
<div class="dash-hero" data-animate>
    <div class="dash-hero-content">
        <p class="eyebrow">Welcome back</p>
        <h1><?= e($greeting) ?>, <?= e($firstName) ?> <span class="hero-wave" aria-hidden="true">👋</span></h1>
        <p class="lead">Here is what is happening in your library today.</p>
    </div>
    <div class="dash-hero-right">
        <div class="dash-hero-art" aria-hidden="true">
            <svg viewBox="0 0 48 40" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M4 8.5C11.5 5.5 19 6.5 24 9.5C29 6.5 36.5 5.5 44 8.5V33C36.5 30 29 31 24 34C19 31 11.5 30 4 33V8.5Z" fill="url(#heroBookBg)" stroke="var(--primary)" stroke-width="2" stroke-linejoin="round"/>
                <path d="M24 9.5V34" stroke="var(--primary)" stroke-width="2" stroke-linecap="round"/>
                <path d="M10 15C14.5 13.5 19 14 21 15" stroke="var(--primary)" stroke-width="1.5" stroke-linecap="round" opacity="0.55"/>
                <path d="M10 20C14.5 18.5 19 19 21 20" stroke="var(--primary)" stroke-width="1.5" stroke-linecap="round" opacity="0.55"/>
                <path d="M10 25C14.5 23.5 19 24 21 25" stroke="var(--primary)" stroke-width="1.5" stroke-linecap="round" opacity="0.55"/>
                <path d="M27 15C29 14 33.5 13.5 38 15" stroke="var(--primary)" stroke-width="1.5" stroke-linecap="round" opacity="0.55"/>
                <path d="M27 20C29 19 33.5 18.5 38 20" stroke="var(--primary)" stroke-width="1.5" stroke-linecap="round" opacity="0.55"/>
                <path d="M27 25C29 24 33.5 23.5 38 25" stroke="var(--primary)" stroke-width="1.5" stroke-linecap="round" opacity="0.55"/>
                <path d="M24 6V11" stroke="#8b5cf6" stroke-width="2" stroke-linecap="round"/>
                <defs>
                    <linearGradient id="heroBookBg" x1="4" y1="6" x2="44" y2="34" gradientUnits="userSpaceOnUse">
                        <stop stop-color="#ffffff"/>
                        <stop offset="1" stop-color="#f5f3ff"/>
                    </linearGradient>
                </defs>
            </svg>
        </div>
        <div class="dash-date-chip">
            <i class="fa-regular fa-calendar" aria-hidden="true"></i>
            <?= e(date('l, F j, Y')) ?>
        </div>
    </div>
</div>

<!-- Quick Access Shelf: 3 Compact Cards (Continue Reading, Recently Added, My Favourite Books) -->
<div class="dash-quick-access" data-animate>
    <div class="row g-3 g-xl-4 row-cols-1 row-cols-lg-3">
        <!-- Card 1: Continue Reading -->
        <div class="col">
            <div class="card h-100 dash-quick-card">
                <div class="dash-quick-header">
                    <div class="dash-quick-title-group">
                        <span class="dash-quick-icon dash-quick-icon--reading">
                            <i class="fa-solid fa-book-open-reader" aria-hidden="true"></i>
                        </span>
                        <div>
                            <span class="dash-quick-eyebrow">Pick up where you left off</span>
                            <h2 class="dash-quick-title">Continue Reading</h2>
                        </div>
                    </div>
                    <a class="dash-quick-link" href="/library">
                        <span>Open my library</span>
                        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                    </a>
                </div>
                <div class="dash-quick-body">
                    <?php $continueReading = $continueReading ?? []; ?>
                    <?php if ($continueReading === []): ?>
                        <div class="dash-quick-empty">
                            <div class="dash-quick-empty-icon"><i class="fa-solid fa-book-open" aria-hidden="true"></i></div>
                            <p class="dash-quick-empty-text">You are not reading anything right now.</p>
                            <a class="btn btn-sm btn-primary" href="/books">Browse books</a>
                        </div>
                    <?php else: ?>
                        <div class="dash-quick-items">
                            <?php foreach ($continueReading as $record): ?>
                                <?php require root_path('app/Views/library/partials/_continue-card.php'); ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Card 2: Recently Added -->
        <div class="col">
            <div class="card h-100 dash-quick-card">
                <div class="dash-quick-header">
                    <div class="dash-quick-title-group">
                        <span class="dash-quick-icon dash-quick-icon--recent">
                            <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
                        </span>
                        <div>
                            <span class="dash-quick-eyebrow">Newest in your library</span>
                            <h2 class="dash-quick-title">Recently Added</h2>
                        </div>
                    </div>
                    <a class="dash-quick-link" href="/library">
                        <span>Open my library</span>
                        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                    </a>
                </div>
                <div class="dash-quick-body">
                    <?php $recentlyAdded = $recentlyAdded ?? []; ?>
                    <?php if ($recentlyAdded === []): ?>
                        <div class="dash-quick-empty">
                            <div class="dash-quick-empty-icon"><i class="fa-solid fa-plus" aria-hidden="true"></i></div>
                            <p class="dash-quick-empty-text">Your library is empty - add your first book.</p>
                            <a class="btn btn-sm btn-primary" href="/books">Browse books</a>
                        </div>
                    <?php else: ?>
                        <div class="dash-quick-items dash-quick-book-list">
                            <?php foreach ($recentlyAdded as $record): ?>
                                <div class="dash-quick-book-item">
                                    <a href="/books/<?= (int) $record['book_id'] ?>" class="dash-quick-book-cover">
                                        <?php $cover = [
                                            'src'   => (string) ($record['book_cover'] ?? ''),
                                            'alt'   => 'Cover of ' . (string) ($record['book_title'] ?? ''),
                                            'class' => 'book-cover',
                                        ]; ?>
                                        <?php require root_path('app/Views/books/components/book-cover.php'); ?>
                                    </a>
                                    <div class="dash-quick-book-info">
                                        <span class="status-badge status-<?= e((string) ($record['library_status'] ?? 'want_to_read')) ?> mb-1">
                                            <?= e((string) ($statusLabels[$record['library_status'] ?? 'want_to_read'] ?? $record['library_status'] ?? 'want_to_read')) ?>
                                        </span>
                                        <h3 class="dash-quick-book-title">
                                            <a href="/books/<?= (int) $record['book_id'] ?>">
                                                <?= e((string) ($record['book_title'] ?? '')) ?>
                                            </a>
                                        </h3>
                                        <?php $starRating = [
                                            'rating' => (float) ($record['book_average_rating'] ?? 0),
                                            'count'  => (int) ($record['book_ratings_count'] ?? 0) > 0 ? (int) $record['book_ratings_count'] : null,
                                            'size'   => 'sm',
                                            'tooltip'=> false,
                                        ]; ?>
                                        <?php require root_path('app/Views/components/star-rating.php'); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Card 3: My Favourite Books -->
        <div class="col">
            <div class="card h-100 dash-quick-card">
                <div class="dash-quick-header">
                    <div class="dash-quick-title-group">
                        <span class="dash-quick-icon dash-quick-icon--favourites">
                            <i class="fa-solid fa-heart" aria-hidden="true"></i>
                        </span>
                        <div>
                            <span class="dash-quick-eyebrow">Your personal picks</span>
                            <h2 class="dash-quick-title">My Favourite Books</h2>
                        </div>
                    </div>
                    <a class="dash-quick-link" href="/library?status=favorites">
                        <span>Favourites shelf</span>
                        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                    </a>
                </div>
                <div class="dash-quick-body">
                    <?php $favouriteBooks = $favouriteBooks ?? []; ?>
                    <?php if ($favouriteBooks === []): ?>
                        <div class="dash-quick-empty">
                            <div class="dash-quick-empty-icon"><i class="fa-regular fa-heart" aria-hidden="true"></i></div>
                            <p class="dash-quick-empty-text">Star the books you love from your library page.</p>
                            <a class="btn btn-sm btn-primary" href="/library">Open my library</a>
                        </div>
                    <?php else: ?>
                        <div class="dash-quick-items dash-quick-book-list">
                            <?php foreach ($favouriteBooks as $record): ?>
                                <div class="dash-quick-book-item">
                                    <a href="/books/<?= (int) $record['book_id'] ?>" class="dash-quick-book-cover">
                                        <?php $cover = [
                                            'src'   => (string) ($record['book_cover'] ?? ''),
                                            'alt'   => 'Cover of ' . (string) ($record['book_title'] ?? ''),
                                            'class' => 'book-cover',
                                        ]; ?>
                                        <?php require root_path('app/Views/books/components/book-cover.php'); ?>
                                    </a>
                                    <div class="dash-quick-book-info">
                                        <span class="status-badge status-<?= e((string) ($record['library_status'] ?? 'want_to_read')) ?> mb-1">
                                            <?= e((string) ($statusLabels[$record['library_status'] ?? 'want_to_read'] ?? $record['library_status'] ?? 'want_to_read')) ?>
                                        </span>
                                        <h3 class="dash-quick-book-title">
                                            <a href="/books/<?= (int) $record['book_id'] ?>">
                                                <?= e((string) ($record['book_title'] ?? '')) ?>
                                            </a>
                                        </h3>
                                        <?php $starRating = [
                                            'rating' => (float) ($record['book_average_rating'] ?? 0),
                                            'count'  => (int) ($record['book_ratings_count'] ?? 0) > 0 ? (int) $record['book_ratings_count'] : null,
                                            'size'   => 'sm',
                                            'tooltip'=> false,
                                        ]; ?>
                                        <?php require root_path('app/Views/components/star-rating.php'); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Section 2: Recommended For You (Phase 8.5: REAL - the personal
     hybrid shelf of the engine, with every card's score and
     explainable reason, replacing the Phase 6.0 placeholder books) -->
<?php $shelf = [
    'eyebrow'         => 'Curated for you',
    'title'           => 'Recommended for You',
    'icon'            => 'fa-wand-magic-sparkles',
    'link'            => ['label' => 'View all', 'href' => '/recommendations'],
    'items'           => $recommendedForYou ?? [],
    'empty'           => 'Rate a few books or save some to your library to get personal recommendations.',
    'columns'         => 'row-cols-2 row-cols-md-3 row-cols-xl-5',
    'container_class' => 'dash-shelf-container dash-shelf-curated',
]; ?>
<?php require root_path('app/Views/recommendations/components/shelf-strip.php'); ?>

<!-- Section 3: Because You Read (Phase 8.5: REAL - the library-derived
     shelf: similar to the books you finished, weighted and explained
     by the engine from your reading history) -->
<?php $shelf = [
    'eyebrow'         => 'From your reading history',
    'title'           => 'Because You Read',
    'icon'            => 'fa-book-open-reader',
    'link'            => ['label' => 'Open my library', 'href' => '/library'],
    'items'           => $becauseYouRead ?? [],
    'empty'           => 'Finish a book in your library and we will recommend what to read next.',
    'columns'         => 'row-cols-2 row-cols-md-3 row-cols-xl-5',
    'container_class' => 'dash-shelf-container dash-shelf-history',
]; ?>
<?php require root_path('app/Views/recommendations/components/shelf-strip.php'); ?>

<!-- Section 4: Trending Books (Phase 8.5: REAL - the engine's
     momentum shelf, replacing the placeholder books) -->
<?php $shelf = [
    'eyebrow' => 'What readers love',
    'title'   => 'Trending Books',
    'icon'    => 'fa-fire',
    'link'    => ['label' => 'View all', 'href' => '/recommendations/trending'],
    'items'   => $trendingBooks ?? [],
    'empty'   => 'No momentum yet - be the first to review a book.',
    'columns' => 'row-cols-2 row-cols-md-3 row-cols-xl-5',
]; ?>
<?php require root_path('app/Views/recommendations/components/shelf-strip.php'); ?>

<!-- Section 5: Top Rated Books (Phase 7.3: real aggregation over
     approved reviews - ReviewService::highestRatedBooks() via the
     dashboard controller, not the placeholder data above) -->
<section class="dash-section" data-animate>
    <?php $section = ['eyebrow' => 'Reader favourites', 'title' => 'Top Rated Books', 'icon' => 'fa-star', 'link' => ['label' => 'View all', 'href' => '/books']]; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>
    <?php $topRated = $topRated ?? []; ?>
    <?php if ($topRated === []): ?>
        <div class="card-base p-4 text-center text-muted">
            No rated books yet - be the first to review a book.
        </div>
    <?php else: ?>
        <div class="row g-3 g-xl-4 row-cols-2 row-cols-md-3 row-cols-xl-5">
            <?php foreach ($topRated as $book): ?>
                <div class="col">
                    <div class="card h-100 card-hover">
                        <a href="/books/<?= (int) $book['id'] ?>" class="text-decoration-none">
                            <?php if (!empty($book['cover_image'])): ?>
                                <img src="<?= e($book['cover_image']) ?>" alt="<?= e($book['title']) ?> cover"
                                     class="card-img-top book-cover" loading="lazy"
                                     onerror="this.onerror=null;this.src='/assets/images/cover-placeholder.svg';">
                            <?php else: ?>
                                <img src="/assets/images/cover-placeholder.svg" alt="<?= e($book['title']) ?> cover"
                                     class="card-img-top book-cover" loading="lazy">
                            <?php endif; ?>
                        </a>
                        <div class="card-body p-3">
                            <h3 class="book-card-title">
                                <a href="/books/<?= (int) $book['id'] ?>" class="text-decoration-none stretched-link">
                                    <?= e($book['title']) ?>
                                </a>
                            </h3>
                            <?php $starRating = [
                                'rating' => (float) $book['average'],
                                'count'  => (int) $book['count'],
                                'size'   => 'sm',
                                'tooltip'=> false,
                            ]; ?>
                            <?php require root_path('app/Views/components/star-rating.php'); ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- Section 5: Community Favourite Books (Phase 7.6: REAL - the
     most-reviewed books of the catalogue, the community's most
     talked-about titles - ReviewService::communityFavorites()) -->
<section class="dash-section" data-animate>
    <?php $section = ['eyebrow' => 'Community picks', 'title' => 'Community Favourite Books', 'icon' => 'fa-heart', 'link' => ['label' => 'Browse all books', 'href' => '/books']]; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>
    <?php $communityFavourites = $communityFavourites ?? []; ?>
    <?php if ($communityFavourites === []): ?>
        <div class="card-base p-4 text-center text-muted">
            No reviewed books yet - be the first to review a book.
        </div>
    <?php else: ?>
        <div class="row g-3 g-xl-4 row-cols-2 row-cols-md-3 row-cols-xl-4">
            <?php foreach ($communityFavourites as $book): ?>
                <div class="col">
                    <div class="card h-100 card-hover">
                        <a href="/books/<?= (int) $book['id'] ?>" class="text-decoration-none">
                            <?php if (!empty($book['cover_image'])): ?>
                                <img src="<?= e($book['cover_image']) ?>" alt="<?= e($book['title']) ?> cover"
                                     class="card-img-top book-cover" loading="lazy"
                                     onerror="this.onerror=null;this.src='/assets/images/cover-placeholder.svg';">
                            <?php else: ?>
                                <img src="/assets/images/cover-placeholder.svg" alt="<?= e($book['title']) ?> cover"
                                     class="card-img-top book-cover" loading="lazy">
                            <?php endif; ?>
                        </a>
                        <div class="card-body p-3">
                            <h3 class="book-card-title">
                                <a href="/books/<?= (int) $book['id'] ?>" class="text-decoration-none stretched-link">
                                    <?= e($book['title']) ?>
                                </a>
                            </h3>
                            <?php $starRating = [
                                'rating' => (float) $book['average'],
                                'count'  => (int) $book['count'],
                                'size'   => 'sm',
                                'tooltip'=> false,
                            ]; ?>
                            <?php require root_path('app/Views/components/star-rating.php'); ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- Section 6: Recent Reviews (Phase 7.4: REAL latest community
     reviews - ReviewService::latestReviews() via the dashboard
     controller, rendered with the existing compact card design) -->
<section class="dash-section" data-animate>
    <?php $section = ['eyebrow' => 'Community voices', 'title' => 'Recent Reviews', 'icon' => 'fa-comments', 'link' => ['label' => 'Browse all reviews', 'href' => '/reviews/search']]; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>
    <?php $latestReviews = $latestReviews ?? []; ?>
    <?php if ($latestReviews === []): ?>
        <div class="card-base p-4 text-center text-muted">
            No community reviews yet - be the first to review a book.
        </div>
    <?php else: ?>
        <div class="row g-3 g-xl-4 row-cols-1 row-cols-md-2">
            <?php foreach ($latestReviews as $review): ?>
                <div class="col"><?php $compact = true; ?><?php require root_path('app/Views/components/review-card.php'); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- Section 7: Highest Rated Reviews (Phase 7.4: REAL rating-first
     community reviews - ReviewService::highestRatedReviews()) -->
<section class="dash-section" data-animate>
    <?php $section = ['eyebrow' => 'Reader favourites', 'title' => 'Highest Rated Reviews', 'icon' => 'fa-star', 'link' => ['label' => 'Most relevant first', 'href' => '/reviews/search?sort=relevant']]; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>
    <?php $highestRatedReviews = $highestRatedReviews ?? []; ?>
    <?php if ($highestRatedReviews === []): ?>
        <div class="card-base p-4 text-center text-muted">
            No community reviews yet - be the first to review a book.
        </div>
    <?php else: ?>
        <div class="row g-3 g-xl-4 row-cols-1 row-cols-md-2">
            <?php foreach ($highestRatedReviews as $review): ?>
                <div class="col"><?php $compact = true; ?><?php require root_path('app/Views/components/review-card.php'); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- Section 8: My Latest Review (Phase 7.4: REAL - the signed-in
     user's most recent review, or an invitation to write one) -->
<section class="dash-section" data-animate>
    <?php $section = ['eyebrow' => 'Your voice', 'title' => 'My Latest Review', 'icon' => 'fa-user-pen', 'link' => ['label' => 'View all my reviews', 'href' => '/reviews']]; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>
    <?php $myLatestReview = $myLatestReview ?? null; ?>
    <?php if ($myLatestReview === null): ?>
        <div class="card-base p-4 text-center text-muted">
            You have not reviewed a book yet.
            <a class="btn btn-sm btn-primary ms-2" href="/books">Write your first review</a>
        </div>
    <?php else: ?>
        <div class="row g-3 g-xl-4 row-cols-1 row-cols-md-2">
            <div class="col">
                <?php $compact = true; ?>
                <?php $review = $myLatestReview; ?>
                <?php require root_path('app/Views/components/review-card.php'); ?>
            </div>
        </div>
    <?php endif; ?>
</section>

<!-- Section 9: My Highest Rated Book (Phase 7.6: REAL - the book the
     signed-in user rated highest in a review, or an invitation to
     rate one) -->
<section class="dash-section" data-animate>
    <?php $section = ['eyebrow' => 'Your picks', 'title' => 'My Highest Rated Book', 'icon' => 'fa-star-half-stroke', 'link' => ['label' => 'View all my reviews', 'href' => '/reviews']]; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>
    <?php $myHighestRatedBook = $myHighestRatedBook ?? null; ?>
    <?php if ($myHighestRatedBook === null): ?>
        <div class="card-base p-4 text-center text-muted">
            You have not reviewed a book yet.
            <a class="btn btn-sm btn-primary ms-2" href="/books">Write your first review</a>
        </div>
    <?php else: ?>
        <div class="row g-3 g-xl-4 row-cols-2 row-cols-md-3 row-cols-xl-4">
            <div class="col">
                <div class="card h-100 card-hover">
                    <a href="/books/<?= (int) $myHighestRatedBook['id'] ?>" class="text-decoration-none">
                        <?php if (!empty($myHighestRatedBook['cover_image'])): ?>
                            <img src="<?= e($myHighestRatedBook['cover_image']) ?>" alt="<?= e($myHighestRatedBook['title']) ?> cover"
                                 class="card-img-top book-cover" loading="lazy"
                                 onerror="this.onerror=null;this.src='/assets/images/cover-placeholder.svg';">
                        <?php else: ?>
                            <div class="book-cover-placeholder">
                                <i class="fa-solid fa-book-open" aria-hidden="true"></i>
                            </div>
                        <?php endif; ?>
                    </a>
                    <div class="card-body p-3">
                        <h3 class="book-card-title">
                            <a href="/books/<?= (int) $myHighestRatedBook['id'] ?>" class="text-decoration-none stretched-link">
                                <?= e($myHighestRatedBook['title']) ?>
                            </a>
                        </h3>
                        <p class="text-muted small mb-1">Your highest rating</p>
                        <?php $starRating = [
                            'rating' => (float) $myHighestRatedBook['average'],
                            'count'  => null,
                            'size'   => 'sm',
                            'tooltip'=> false,
                        ]; ?>
                        <?php require root_path('app/Views/components/star-rating.php'); ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</section>

<!-- Section 10: Library Overview (Phase 8.4: the user's OWN library
     numbers when the library module is wired - the shared LibraryService
     is the source, never placeholder data). The collections quick
     access strip below jumps straight to each Smart Collection. The
     whole section renders only when the library module is wired. -->
<?php if ($libraryStats !== []): ?>
<section class="dash-section" data-animate>
    <?php $section = ['eyebrow' => 'At a glance', 'title' => 'Library Overview', 'icon' => 'fa-chart-column']; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>
    <div class="row g-3 g-xl-4 row-cols-2 row-cols-md-3 row-cols-xl-5">
        <?php foreach ($libraryStats as $stat): ?>
            <div class="col"><?php require root_path('app/Views/components/stat-card.php'); ?></div>
        <?php endforeach; ?>
    </div>

    <?php
    // Phase 8.4: the Smart Collections quick access - plain links that
    // open each collection in the library dashboard.
    $collectionQuick = [
        ['key' => 'all',               'label' => 'All',              'icon' => 'fa-layer-group',      'url' => '/library'],
        ['key' => 'want_to_read',      'label' => 'Want to Read',     'icon' => 'fa-bookmark',         'url' => '/library?status=want_to_read'],
        ['key' => 'currently_reading', 'label' => 'Reading Now',      'icon' => 'fa-book-open-reader', 'url' => '/library?status=currently_reading'],
        ['key' => 'finished',          'label' => 'Finished',         'icon' => 'fa-circle-check',     'url' => '/library?status=finished'],
        ['key' => 'favorites',         'label' => 'Favourites',       'icon' => 'fa-heart',            'url' => '/library/favorites'],
    ];
    ?>
    <div class="library-collections library-collections--quick mt-3">
        <?php foreach ($collectionQuick as $item): ?>
            <?php $data = (array) ($collections[$item['key']] ?? []); ?>
            <?php $count = (int) ($data['count'] ?? 0); ?>
            <a class="library-collection library-collection--<?= e($item['key']) ?>" href="<?= e($item['url']) ?>">
                <span class="library-collection-icon" aria-hidden="true"><i class="fa-solid <?= e($item['icon']) ?>"></i></span>
                <span class="library-collection-body">
                    <strong class="library-collection-name"><?= e($item['label']) ?></strong>
                    <span class="library-collection-count">
                        <span><?= $count ?></span>
                        <?= $count === 1 ? 'book' : 'books' ?>
                    </span>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
