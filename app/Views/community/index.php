<?php

declare(strict_types=1);

/**
 * community/index.php
 *
 * BookSphere Reader Community — Discovery, Search & Feed
 * Modern social community presentation adhering to BookSphere design system.
 */

$posts          = $posts          ?? [];
$total          = $total          ?? 0;
$page           = $page           ?? 1;
$pages          = $pages          ?? 1;
$perPage        = $perPage        ?? 20;
$currentSort    = $currentSort    ?? 'recent';
$currentFeed    = $currentFeed    ?? 'all';
$selectedBook   = $selectedBook   ?? null;
$selectedAuthor = $selectedAuthor ?? null;
$query          = $query          ?? null;
$books          = $books          ?? [];
$authors        = $authors        ?? [];
$pagination     = $pagination     ?? [];

$sortLabels = [
    'recent'   => 'Recent',
    'popular'  => 'Popular',
    'trending' => 'Trending',
];
$sortIcons = [
    'recent'   => 'fa-clock',
    'popular'  => 'fa-fire',
    'trending' => 'fa-arrow-trend-up',
];

// Discovery Mode Tabs
$feedTabs = [];
if (auth_check()) {
    $feedTabs[] = [
        'key'    => 'personalized',
        'label'  => 'For You',
        'icon'   => 'fa-wand-magic-sparkles',
        'active' => $currentSort === 'personalized',
        'url'    => '/community?' . http_build_query(array_filter([
            'sort'      => 'personalized',
            'book_id'   => $selectedBook,
            'author_id' => $selectedAuthor,
            'q'         => $query,
        ])),
    ];
    $feedTabs[] = [
        'key'    => 'following',
        'label'  => 'Following',
        'icon'   => 'fa-users',
        'active' => $currentFeed === 'following',
        'url'    => '/community?' . http_build_query(array_filter([
            'feed'      => 'following',
            'sort'      => ($currentSort !== 'recent' && $currentSort !== 'personalized') ? $currentSort : null,
            'book_id'   => $selectedBook,
            'author_id' => $selectedAuthor,
            'q'         => $query,
        ])),
    ];
}

$feedTabs[] = [
    'key'    => 'recent',
    'label'  => 'Latest',
    'icon'   => 'fa-clock',
    'active' => $currentFeed !== 'following' && ($currentSort === 'recent' || !in_array($currentSort, ['popular', 'trending', 'personalized'], true)),
    'url'    => '/community?' . http_build_query(array_filter([
        'sort'      => 'recent',
        'book_id'   => $selectedBook,
        'author_id' => $selectedAuthor,
        'q'         => $query,
    ])),
];

$feedTabs[] = [
    'key'    => 'popular',
    'label'  => 'Popular',
    'icon'   => 'fa-fire',
    'active' => $currentFeed !== 'following' && $currentSort === 'popular',
    'url'    => '/community?' . http_build_query(array_filter([
        'sort'      => 'popular',
        'book_id'   => $selectedBook,
        'author_id' => $selectedAuthor,
        'q'         => $query,
    ])),
];

$feedTabs[] = [
    'key'    => 'trending',
    'label'  => 'Trending',
    'icon'   => 'fa-arrow-trend-up',
    'active' => $currentFeed !== 'following' && $currentSort === 'trending',
    'url'    => '/community?' . http_build_query(array_filter([
        'sort'      => 'trending',
        'book_id'   => $selectedBook,
        'author_id' => $selectedAuthor,
        'q'         => $query,
    ])),
];
?>

<!-- 1. Community Hero Section -->
<div class="community-hero mb-4">
    <div class="row align-items-center position-relative">
        <div class="col-12 col-md-8 col-lg-8">
            <p class="community-hero-eyebrow mb-1">
                <i class="fa-solid fa-users" aria-hidden="true"></i> COMMUNITY
            </p>
            <h1 class="community-hero-title mb-2">BookSphere Community</h1>
            <p class="community-hero-lead mb-0">
                Discover conversations, share your thoughts, and connect with other readers.
            </p>
        </div>
        <div class="col-md-4 col-lg-4 d-none d-md-flex justify-content-end community-hero-visual" aria-hidden="true">
            <svg viewBox="0 0 200 160" width="180" height="144" fill="none" xmlns="http://www.w3.org/2000/svg">
                <!-- Stacked books and reader ambiance -->
                <defs>
                    <linearGradient id="bookGrad1" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#5b4bdb" />
                        <stop offset="100%" stop-color="#4536c4" />
                    </linearGradient>
                    <linearGradient id="bookGrad2" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#8b80ff" />
                        <stop offset="100%" stop-color="#6f62f0" />
                    </linearGradient>
                    <linearGradient id="bookGrad3" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#c7d2fe" />
                        <stop offset="100%" stop-color="#a5b4fc" />
                    </linearGradient>
                    <linearGradient id="steamGrad" x1="0%" y1="100%" x2="0%" y2="0%">
                        <stop offset="0%" stop-color="#8b80ff" stop-opacity="0.6"/>
                        <stop offset="100%" stop-color="#8b80ff" stop-opacity="0"/>
                    </linearGradient>
                </defs>
                <!-- Soft background glow -->
                <circle cx="110" cy="85" r="65" fill="#eeecff" fill-opacity="0.6" />
                <!-- Bottom Base Book (Spine purple) -->
                <rect x="25" y="118" width="140" height="22" rx="4" fill="url(#bookGrad1)" />
                <rect x="35" y="122" width="128" height="14" rx="2" fill="#ffffff" fill-opacity="0.9" />
                <line x1="165" y1="120" x2="165" y2="138" stroke="#ffffff" stroke-width="2" stroke-linecap="round" />
                <!-- Middle Book (Spine lavender) -->
                <rect x="38" y="94" width="120" height="20" rx="4" fill="url(#bookGrad2)" />
                <rect x="46" y="98" width="108" height="12" rx="2" fill="#ffffff" fill-opacity="0.9" />
                <path d="M70 94 L75 106 L80 94" fill="#ffb703" />
                <!-- Top Open Book -->
                <path d="M55 76 C72 68 88 74 100 80 C112 74 128 68 145 76 L142 85 C126 77 112 82 100 87 C88 82 74 77 58 85 Z" fill="url(#bookGrad3)" />
                <path d="M58 74 C73 66 88 72 100 78 C112 72 127 66 142 74 L140 76 C126 69 112 74 100 80 C88 74 74 69 60 76 Z" fill="#ffffff" />
                <!-- Reading Glasses resting -->
                <circle cx="85" cy="58" r="10" stroke="#5b4bdb" stroke-width="2.5" fill="none" />
                <circle cx="115" cy="58" r="10" stroke="#5b4bdb" stroke-width="2.5" fill="none" />
                <path d="M95 56 C100 53 105 53 110 56" stroke="#5b4bdb" stroke-width="2.5" fill="none" />
                <!-- Warm Coffee Mug -->
                <rect x="145" y="55" width="22" height="24" rx="4" fill="#ffffff" stroke="#8b80ff" stroke-width="2" />
                <path d="M167 60 C172 60 174 64 174 67 C174 70 172 73 167 73" stroke="#8b80ff" stroke-width="2" fill="none" />
                <path d="M152 48 Q155 42 153 36" stroke="url(#steamGrad)" stroke-width="2" stroke-linecap="round" fill="none" />
                <path d="M158 49 Q161 43 159 37" stroke="url(#steamGrad)" stroke-width="2" stroke-linecap="round" fill="none" />
                <!-- Subtle spark stars -->
                <path d="M42 45 L44 50 L49 52 L44 54 L42 59 L40 54 L35 52 L40 50 Z" fill="#f59e0b" fill-opacity="0.85" />
                <path d="M175 30 L176 34 L180 35 L176 36 L175 40 L174 36 L170 35 L174 34 Z" fill="#5b4bdb" fill-opacity="0.75" />
            </svg>
        </div>
    </div>

    <!-- Supporting Concepts -->
    <div class="community-concept-pills">
        <div class="row g-3">
            <div class="col-12 col-md-4">
                <div class="community-concept-card">
                    <div class="community-concept-icon">
                        <i class="fa-regular fa-lightbulb" aria-hidden="true"></i>
                    </div>
                    <div>
                        <h3 class="community-concept-title">Share Ideas</h3>
                        <p class="community-concept-desc">Start discussions about books, authors, and more.</p>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="community-concept-card">
                    <div class="community-concept-icon">
                        <i class="fa-solid fa-user-group" aria-hidden="true"></i>
                    </div>
                    <div>
                        <h3 class="community-concept-title">Connect</h3>
                        <p class="community-concept-desc">Meet fellow readers and exchange perspectives.</p>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="community-concept-card">
                    <div class="community-concept-icon">
                        <i class="fa-solid fa-seedling" aria-hidden="true"></i>
                    </div>
                    <div>
                        <h3 class="community-concept-title">Grow Together</h3>
                        <p class="community-concept-desc">A community that reads, learns, and inspires.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Two-Column Community Layout -->
<div class="row g-4">
    <!-- Main Column: Community Discussion Feed & Primary Controls -->
    <div class="col-12 col-xl-8">

        <!-- 2. Start a Discussion Card (Composer CTA) -->
        <div class="community-composer mb-4">
            <div class="community-composer-inner">
                <div class="community-composer-content">
                    <div class="community-composer-icon">
                        <i class="fa-solid fa-pen-fancy" aria-hidden="true"></i>
                    </div>
                    <div class="community-composer-text">
                        <h2 class="community-composer-title">Start a Discussion</h2>
                        <p class="community-composer-subtitle">Join the conversation. Exchange ideas, post book thoughts, and connect with fellow readers.</p>
                    </div>
                </div>
                <div class="community-composer-action">
                    <a href="/community/create" class="btn btn-primary px-3 py-2 text-nowrap shadow-sm d-inline-flex align-items-center justify-content-center">
                        <i class="fa-solid fa-plus me-1" aria-hidden="true"></i> Start a Discussion
                    </a>
                </div>
            </div>
        </div>

        <!-- 3. Community Search Bar -->
        <div class="community-search-box mb-4">
            <form action="/community" method="GET" class="d-flex align-items-center w-100">
                <?php if ($currentSort !== 'recent'): ?>
                    <input type="hidden" name="sort" value="<?= e($currentSort) ?>">
                <?php endif; ?>
                <?php if ($selectedBook !== null && $selectedBook > 0): ?>
                    <input type="hidden" name="book_id" value="<?= (int) $selectedBook ?>">
                <?php endif; ?>
                <?php if ($selectedAuthor !== null && $selectedAuthor > 0): ?>
                    <input type="hidden" name="author_id" value="<?= (int) $selectedAuthor ?>">
                <?php endif; ?>

                <div class="community-search-input-group">
                    <span class="community-search-icon">
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    </span>
                    <input type="text"
                           name="q"
                           class="community-search-input"
                           placeholder="Search community discussions by title, content, or author..."
                           value="<?= e($query ?? '') ?>"
                           aria-label="Search community discussions">
                    <?php if (!empty($query)): ?>
                        <?php
                        $clearSearchParams = [];
                        if ($currentSort !== 'recent') {
                            $clearSearchParams['sort'] = $currentSort;
                        }
                        if ($selectedBook !== null && $selectedBook > 0) {
                            $clearSearchParams['book_id'] = $selectedBook;
                        }
                        if ($selectedAuthor !== null && $selectedAuthor > 0) {
                            $clearSearchParams['author_id'] = $selectedAuthor;
                        }
                        $clearSearchUrl = '/community' . (!empty($clearSearchParams) ? '?' . http_build_query($clearSearchParams) : '');
                        ?>
                        <a href="<?= e($clearSearchUrl) ?>" class="btn btn-outline-secondary btn-sm d-flex align-items-center px-2 py-1 text-nowrap" title="Clear Search">
                            <i class="fa-solid fa-xmark me-1" aria-hidden="true"></i> Clear
                        </a>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary px-3 py-1-5 fw-semibold text-nowrap">
                        Search
                    </button>
                </div>
            </form>
        </div>

        <!-- 4. Segmented Discovery Tabs & Dropdown Filters Bar -->
        <div class="community-filter-bar mb-4 d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
            <!-- Segmented Discovery Navigation Tabs -->
            <div class="community-nav-tabs" aria-label="Community Feed Navigation">
                <?php foreach ($feedTabs as $tab): ?>
                    <a href="<?= e($tab['url']) ?>"
                       class="community-nav-tab <?= $tab['active'] ? 'active' : '' ?>"
                       <?= $tab['active'] ? 'aria-current="page"' : '' ?>>
                        <i class="fa-solid <?= e($tab['icon']) ?>" aria-hidden="true"></i>
                        <span><?= e($tab['label']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Compact Integrated Dropdown Filters (Book & Author) -->
            <div class="d-flex flex-column flex-sm-row align-items-sm-center gap-2">
                <!-- Book Filter Dropdown -->
                <?php if (!empty($books)): ?>
                    <div class="community-select-pill">
                        <i class="fa-solid fa-book text-primary small" aria-hidden="true"></i>
                        <select id="community-book-filter"
                                aria-label="Filter by book"
                                onchange="if (this.value) { window.location.href = this.value; }">
                            <?php
                            $allBooksUrlParams = [];
                            if ($currentSort !== 'recent') {
                                $allBooksUrlParams['sort'] = $currentSort;
                            }
                            if ($selectedAuthor !== null && $selectedAuthor > 0) {
                                $allBooksUrlParams['author_id'] = $selectedAuthor;
                            }
                            if (!empty($query)) {
                                $allBooksUrlParams['q'] = $query;
                            }
                            $allBooksUrl = '/community' . (!empty($allBooksUrlParams) ? '?' . http_build_query($allBooksUrlParams) : '');
                            ?>
                            <option value="<?= e($allBooksUrl) ?>" <?= $selectedBook === null ? 'selected' : '' ?>>
                                All Books
                            </option>
                            <?php foreach ($books as $b): ?>
                                <?php
                                $bId = (int) $b['id'];
                                $bTitle = (string) ($b['title'] ?? 'Untitled');
                                $bookUrlParams = ['book_id' => $bId];
                                if ($currentSort !== 'recent') {
                                    $bookUrlParams['sort'] = $currentSort;
                                }
                                if ($selectedAuthor !== null && $selectedAuthor > 0) {
                                    $bookUrlParams['author_id'] = $selectedAuthor;
                                }
                                if (!empty($query)) {
                                    $bookUrlParams['q'] = $query;
                                }
                                $bUrl = '/community?' . http_build_query($bookUrlParams);
                                ?>
                                <option value="<?= e($bUrl) ?>" <?= $selectedBook === $bId ? 'selected' : '' ?>>
                                    <?= e(mb_strlen($bTitle) > 28 ? mb_substr($bTitle, 0, 28) . '…' : $bTitle) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <!-- Author Filter Dropdown -->
                <?php if (!empty($authors)): ?>
                    <div class="community-select-pill">
                        <i class="fa-solid fa-user text-primary small" aria-hidden="true"></i>
                        <select id="community-author-filter"
                                aria-label="Filter by author"
                                onchange="if (this.value) { window.location.href = this.value; }">
                            <?php
                            $allAuthorsUrlParams = [];
                            if ($currentSort !== 'recent') {
                                $allAuthorsUrlParams['sort'] = $currentSort;
                            }
                            if ($selectedBook !== null && $selectedBook > 0) {
                                $allAuthorsUrlParams['book_id'] = $selectedBook;
                            }
                            if (!empty($query)) {
                                $allAuthorsUrlParams['q'] = $query;
                            }
                            $allAuthorsUrl = '/community' . (!empty($allAuthorsUrlParams) ? '?' . http_build_query($allAuthorsUrlParams) : '');
                            ?>
                            <option value="<?= e($allAuthorsUrl) ?>" <?= $selectedAuthor === null ? 'selected' : '' ?>>
                                All Authors
                            </option>
                            <?php foreach ($authors as $a): ?>
                                <?php
                                $aId = (int) $a['id'];
                                $aName = (string) ($a['full_name'] ?? 'Author');
                                $authorUrlParams = ['author_id' => $aId];
                                if ($currentSort !== 'recent') {
                                    $authorUrlParams['sort'] = $currentSort;
                                }
                                if ($selectedBook !== null && $selectedBook > 0) {
                                    $authorUrlParams['book_id'] = $selectedBook;
                                }
                                if (!empty($query)) {
                                    $authorUrlParams['q'] = $query;
                                }
                                $aUrl = '/community?' . http_build_query($authorUrlParams);
                                ?>
                                <option value="<?= e($aUrl) ?>" <?= $selectedAuthor === $aId ? 'selected' : '' ?>>
                                    <?= e(mb_strlen($aName) > 24 ? mb_substr($aName, 0, 24) . '…' : $aName) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 5. Feed Header & Active Filter Badges -->
        <div class="community-feed-header">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <h2 class="community-feed-heading">
                    <span><?= $currentSort === 'recent' ? 'Latest' : e($sortLabels[$currentSort] ?? 'Recent') ?> Discussions</span>
                    <span class="badge bg-secondary-subtle text-secondary-emphasis rounded-pill"><?= (int) $total ?></span>
                </h2>

                <!-- Active Search Badge -->
                <?php if (!empty($query)): ?>
                    <?php
                    $noSearchParams = [];
                    if ($currentSort !== 'recent') $noSearchParams['sort'] = $currentSort;
                    if ($selectedBook !== null && $selectedBook > 0) $noSearchParams['book_id'] = $selectedBook;
                    if ($selectedAuthor !== null && $selectedAuthor > 0) $noSearchParams['author_id'] = $selectedAuthor;
                    $noSearchUrl = '/community' . (!empty($noSearchParams) ? '?' . http_build_query($noSearchParams) : '');
                    ?>
                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle d-inline-flex align-items-center gap-1">
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                        <span>"<?= e($query) ?>"</span>
                        <a href="<?= e($noSearchUrl) ?>" class="text-info-emphasis ms-1 text-decoration-none" title="Clear search">×</a>
                    </span>
                <?php endif; ?>

                <!-- Active Book Filter Badge -->
                <?php if ($selectedBook !== null && $selectedBook > 0): ?>
                    <?php
                    $noBookParams = [];
                    if ($currentSort !== 'recent') $noBookParams['sort'] = $currentSort;
                    if ($selectedAuthor !== null && $selectedAuthor > 0) $noBookParams['author_id'] = $selectedAuthor;
                    if (!empty($query)) $noBookParams['q'] = $query;
                    $noBookUrl = '/community' . (!empty($noBookParams) ? '?' . http_build_query($noBookParams) : '');
                    ?>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle d-inline-flex align-items-center gap-1">
                        <i class="fa-solid fa-book" aria-hidden="true"></i>
                        <span>Book Filter</span>
                        <a href="<?= e($noBookUrl) ?>" class="text-primary ms-1 text-decoration-none" title="Clear book filter">×</a>
                    </span>
                <?php endif; ?>

                <!-- Active Author Filter Badge -->
                <?php if ($selectedAuthor !== null && $selectedAuthor > 0): ?>
                    <?php
                    $noAuthorParams = [];
                    if ($currentSort !== 'recent') $noAuthorParams['sort'] = $currentSort;
                    if ($selectedBook !== null && $selectedBook > 0) $noAuthorParams['book_id'] = $selectedBook;
                    if (!empty($query)) $noAuthorParams['q'] = $query;
                    $noAuthorUrl = '/community' . (!empty($noAuthorParams) ? '?' . http_build_query($noAuthorParams) : '');
                    ?>
                    <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle d-inline-flex align-items-center gap-1">
                        <i class="fa-solid fa-user" aria-hidden="true"></i>
                        <span>Author Filter</span>
                        <a href="<?= e($noAuthorUrl) ?>" class="text-success-emphasis ms-1 text-decoration-none" title="Clear author filter">×</a>
                    </span>
                <?php endif; ?>
            </div>

            <!-- Clear All Filters Link -->
            <?php if (!empty($query) || ($selectedBook !== null && $selectedBook > 0) || ($selectedAuthor !== null && $selectedAuthor > 0)): ?>
                <a href="/community" class="btn btn-link btn-sm text-decoration-none text-muted p-0">
                    <i class="fa-solid fa-rotate-left me-1" aria-hidden="true"></i> Clear All Filters
                </a>
            <?php endif; ?>
        </div>

        <!-- 6. Feed Post Cards List / Empty State -->
        <?php if (empty($posts)): ?>
            <?php
            if (!empty($query)) {
                $emptyTitle   = 'No discussions found';
                $emptyMessage = 'No community discussions match your search query "' . e($query) . '". Try a different search or clear your filters.';
            } elseif ($selectedBook !== null || $selectedAuthor !== null) {
                $emptyTitle   = 'No matching discussions';
                $emptyMessage = 'No community discussions match the selected filter parameters.';
            } else {
                $emptyTitle   = match ($currentSort) {
                    'trending' => 'No trending discussions yet',
                    'popular'  => 'No popular discussions yet',
                    default    => 'No discussions yet',
                };
                $emptyMessage = 'Be the first reader to start a conversation.';
            }

            $empty = [
                'icon'    => !empty($query) ? 'fa-magnifying-glass' : 'fa-comments',
                'title'   => $emptyTitle,
                'message' => $emptyMessage,
            ];
            require root_path('app/Views/components/empty-state.php');
            ?>

            <?php if (!empty($query) || $selectedBook !== null || $selectedAuthor !== null): ?>
                <div class="text-center mt-3 mb-4">
                    <a href="/community" class="btn btn-outline-primary btn-sm px-3">
                        <i class="fa-solid fa-list me-1" aria-hidden="true"></i> View All Community Discussions
                    </a>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="community-feed d-flex flex-column gap-3 mb-4">
                <?php foreach ($posts as $post): ?>
                    <?php
                    $authorName = (string) ($post['author_name'] ?? 'Anonymous Reader');
                    $initial    = mb_strtoupper(mb_substr($authorName, 0, 1));
                    $timeAgo    = function_exists('format_notification_time')
                        ? format_notification_time($post['created_at'] ?? '')
                        : (string) ($post['created_at'] ?? '');
                    $hasBook    = isset($post['book_id']) && (int) $post['book_id'] > 0;
                    $bookTitle  = (string) ($post['book_title'] ?? 'Linked Book');
                    ?>
                    <article class="community-card">
                        <div class="d-flex align-items-start gap-3">
                            <!-- Author Avatar -->
                            <div class="flex-shrink-0">
                                <a href="/community/user/<?= (int) ($post['user_id'] ?? 0) ?>" class="text-decoration-none" title="View profile of <?= e($authorName) ?>">
                                    <?php if (!empty($post['author_avatar'])): ?>
                                        <img src="<?= e($post['author_avatar']) ?>" alt="<?= e($authorName) ?>" class="community-avatar avatar-img">
                                    <?php else: ?>
                                        <div class="community-avatar">
                                            <?= e($initial) ?>
                                        </div>
                                    <?php endif; ?>
                                </a>
                            </div>

                            <!-- Post Content & Hierarchy -->
                            <div class="flex-grow-1 min-w-0">
                                <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                                    <a href="/community/user/<?= (int) ($post['user_id'] ?? 0) ?>" class="community-author-name text-truncate">
                                        <?= e($authorName) ?>
                                    </a>
                                    <span class="community-post-time flex-shrink-0"><?= e($timeAgo) ?></span>
                                </div>

                                <h3 class="community-post-title">
                                    <a href="/community/post/<?= (int) $post['id'] ?>">
                                        <?= e($post['title']) ?>
                                    </a>
                                </h3>

                                <p class="community-post-body text-break">
                                    <?= e(mb_strimwidth((string) ($post['body'] ?? ''), 0, 280, '...')) ?>
                                </p>

                                <!-- Optional Book Attachment -->
                                <?php if ($hasBook): ?>
                                    <div>
                                        <a href="/books/<?= (int) $post['book_id'] ?>" class="community-book-attachment" title="Discussed book: <?= e($bookTitle) ?>">
                                            <i class="fa-solid fa-book text-primary" aria-hidden="true"></i>
                                            <span class="text-truncate" style="max-width: 320px;"><?= e($bookTitle) ?></span>
                                        </a>
                                    </div>
                                <?php endif; ?>

                                <!-- Engagement Stats Footer -->
                                <div class="community-card-footer">
                                    <div class="community-interactions">
                                        <span class="community-stat-pill" title="Likes">
                                            <i class="fa-regular fa-heart me-1" aria-hidden="true"></i>
                                            <span><?= (int) ($post['like_count'] ?? 0) ?></span>
                                        </span>
                                        <span class="community-stat-pill" title="Comments">
                                            <i class="fa-regular fa-comment me-1" aria-hidden="true"></i>
                                            <span><?= (int) ($post['comment_count'] ?? 0) ?></span>
                                        </span>
                                    </div>
                                    <a href="/community/post/<?= (int) $post['id'] ?>" class="community-read-more" title="Join discussion">
                                        <span>Join Discussion</span>
                                        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($pages > 1): ?>
                <?php require root_path('app/Views/components/review-pagination.php'); ?>
            <?php endif; ?>
        <?php endif; ?>

    </div>

    <!-- Right Sidebar Column: Community Information & Guidelines (Desktop Only) -->
    <div class="col-12 col-xl-4 d-none d-xl-block">
        <aside class="community-sidebar" aria-label="Community Information Sidebar">

            <!-- Card A: Community at a Glance (Verified Real Metrics) -->
            <div class="community-sidebar-card">
                <div class="community-sidebar-header">
                    <i class="fa-solid fa-chart-simple text-primary" aria-hidden="true"></i>
                    <h3 class="community-sidebar-title">Community at a Glance</h3>
                </div>
                <div class="community-metrics-grid">
                    <div class="community-metric-box">
                        <div class="community-metric-value"><?= (int) $total ?></div>
                        <div class="community-metric-label">Discussions</div>
                    </div>
                    <div class="community-metric-box">
                        <div class="community-metric-value"><?= count($authors) ?></div>
                        <div class="community-metric-label">Active Readers</div>
                    </div>
                </div>
            </div>

            <!-- Card B: Featured Book Hubs (Real Database Books) -->
            <?php if (!empty($books)): ?>
                <?php $featuredBooks = array_slice($books, 0, 5); ?>
                <div class="community-sidebar-card">
                    <div class="community-sidebar-header">
                        <i class="fa-solid fa-book-open-reader text-primary" aria-hidden="true"></i>
                        <h3 class="community-sidebar-title">Popular Book Hubs</h3>
                    </div>
                    <p class="text-muted small mb-2">Explore active discussions around trending titles:</p>
                    <div class="d-flex flex-column gap-1">
                        <?php foreach ($featuredBooks as $fb): ?>
                            <?php
                            $fbId = (int) $fb['id'];
                            $fbTitle = (string) ($fb['title'] ?? 'Untitled Book');
                            $fbUrl = '/community?book_id=' . $fbId;
                            $isSelected = $selectedBook === $fbId;
                            ?>
                            <a href="<?= e($fbUrl) ?>"
                               class="community-sidebar-book-item <?= $isSelected ? 'bg-primary-subtle text-primary fw-bold' : '' ?>"
                               title="<?= e($fbTitle) ?>">
                                <span class="d-flex align-items-center gap-2 text-truncate">
                                    <i class="fa-solid fa-book text-primary small" aria-hidden="true"></i>
                                    <span class="text-truncate"><?= e($fbTitle) ?></span>
                                </span>
                                <i class="fa-solid fa-chevron-right text-muted" style="font-size: 0.7rem;" aria-hidden="true"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Card C: Community Guidelines -->
            <div class="community-sidebar-card">
                <div class="community-sidebar-header">
                    <i class="fa-solid fa-shield-heart text-primary" aria-hidden="true"></i>
                    <h3 class="community-sidebar-title">Community Guidelines</h3>
                </div>
                <div class="d-flex flex-column">
                    <div class="community-guideline-item">
                        <i class="fa-solid fa-circle-check community-guideline-icon" aria-hidden="true"></i>
                        <span>Be respectful and kind to fellow readers and authors.</span>
                    </div>
                    <div class="community-guideline-item">
                        <i class="fa-solid fa-circle-check community-guideline-icon" aria-hidden="true"></i>
                        <span>Keep discussions focused on books, reading, and literature.</span>
                    </div>
                    <div class="community-guideline-item">
                        <i class="fa-solid fa-circle-check community-guideline-icon" aria-hidden="true"></i>
                        <span>No spam, repetitive posts, or unsolicited self-promotion.</span>
                    </div>
                    <div class="community-guideline-item">
                        <i class="fa-solid fa-circle-check community-guideline-icon" aria-hidden="true"></i>
                        <span>Share constructive insights and respect different viewpoints.</span>
                    </div>
                    <div class="community-guideline-item">
                        <i class="fa-solid fa-circle-check community-guideline-icon" aria-hidden="true"></i>
                        <span>Help make BookSphere a welcoming, inspiring reading space.</span>
                    </div>
                </div>
            </div>

        </aside>
    </div>
</div>
