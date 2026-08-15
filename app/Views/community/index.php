<?php

declare(strict_types=1);

/**
 * community/index.php
 *
 * The COMMUNITY DISCOVERY, SEARCH & FEED page (Phase C6-C):
 * Supports discovery modes (Recent, Popular, Trending), Community search,
 * book-specific filters, author filters, pagination, and empty/error states.
 */

$posts          = $posts          ?? [];
$total          = $total          ?? 0;
$page           = $page           ?? 1;
$pages          = $pages          ?? 1;
$perPage        = $perPage        ?? 20;
$currentSort    = $currentSort    ?? 'recent';
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
?>
<div class="page-intro mb-4">
    <p class="eyebrow text-uppercase fw-semibold text-primary mb-1" style="letter-spacing: 0.05em; font-size: 0.8125rem;">COMMUNITY</p>
    <h1 class="display-6 fw-bold mb-2">BookSphere Community</h1>
    <p class="lead text-muted mb-0">Discover conversations, share your thoughts, and connect with other readers.</p>
</div>

<!-- Community Action / Intro Area -->
<div class="card-base p-4 mb-4">
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
        <div>
            <h2 class="h5 mb-1">Join the Conversation</h2>
            <p class="text-muted small mb-0">Exchange ideas, post book thoughts, and join discussions with the BookSphere reading community.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="/community/create" class="btn btn-primary px-3 py-2">
                <i class="fa-solid fa-plus me-1" aria-hidden="true"></i> Start a Discussion
            </a>
        </div>
    </div>
</div>

<!-- Community Search Bar -->
<div class="card-base p-3 mb-4">
    <form action="/community" method="GET" class="d-flex align-items-center gap-2">
        <?php if ($currentSort !== 'recent'): ?>
            <input type="hidden" name="sort" value="<?= e($currentSort) ?>">
        <?php endif; ?>
        <?php if ($selectedBook !== null && $selectedBook > 0): ?>
            <input type="hidden" name="book_id" value="<?= (int) $selectedBook ?>">
        <?php endif; ?>
        <?php if ($selectedAuthor !== null && $selectedAuthor > 0): ?>
            <input type="hidden" name="author_id" value="<?= (int) $selectedAuthor ?>">
        <?php endif; ?>

        <div class="input-group">
            <span class="input-group-text bg-body-tertiary text-muted border-end-0">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            </span>
            <input type="text"
                   name="q"
                   class="form-control border-start-0 ps-0"
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
                <a href="<?= e($clearSearchUrl) ?>" class="btn btn-outline-secondary d-flex align-items-center px-3" title="Clear Search">
                    <i class="fa-solid fa-xmark me-1" aria-hidden="true"></i> Clear
                </a>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary px-4">
                Search
            </button>
        </div>
    </form>
</div>

<!-- Unified Feed Navigation & Discovery Controls (ISSUE-C8A-02 Streamlined Tab Bar) -->
<?php
$currentFeed = $currentFeed ?? 'all';
$currentSort = $currentSort ?? 'recent';

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

<div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-4 p-3 rounded bg-body-tertiary border shadow-sm">
    <!-- Unified Mode Navigation Pills -->
    <div class="d-flex align-items-center gap-2 flex-nowrap overflow-x-auto pb-1" style="scrollbar-width: thin;" aria-label="Community Feed Navigation">
        <?php foreach ($feedTabs as $tab): ?>
            <a href="<?= e($tab['url']) ?>"
               class="btn btn-sm <?= $tab['active'] ? 'btn-primary shadow-sm fw-bold' : 'btn-outline-secondary' ?> d-inline-flex align-items-center gap-1-5 px-3 py-1-5"
               <?= $tab['active'] ? 'aria-current="page"' : '' ?>>
                <i class="fa-solid <?= e($tab['icon']) ?>" aria-hidden="true"></i>
                <span><?= e($tab['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Dropdown Filters (Book & Author) -->
    <div class="d-flex flex-column flex-sm-row align-items-sm-center gap-2">
        <!-- Optional Book Filter Dropdown -->
        <?php if (!empty($books)): ?>
            <div class="d-flex align-items-center gap-2">
                <label for="community-book-filter" class="text-muted small fw-semibold text-nowrap mb-0">
                    <i class="fa-solid fa-filter me-1" aria-hidden="true"></i> Book:
                </label>
                <select id="community-book-filter"
                        class="form-select form-select-sm"
                        style="max-width: 220px;"
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
                            <?= e(mb_strlen($bTitle) > 30 ? mb_substr($bTitle, 0, 30) . '…' : $bTitle) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <!-- Optional Author Filter Dropdown -->
        <?php if (!empty($authors)): ?>
            <div class="d-flex align-items-center gap-2">
                <label for="community-author-filter" class="text-muted small fw-semibold text-nowrap mb-0">
                    <i class="fa-solid fa-user me-1" aria-hidden="true"></i> Author:
                </label>
                <select id="community-author-filter"
                        class="form-select form-select-sm"
                        style="max-width: 200px;"
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
                            <?= e(mb_strlen($aName) > 25 ? mb_substr($aName, 0, 25) . '…' : $aName) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Feed Header & Active Filter Badges -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3 pb-2 border-bottom">
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <h2 class="h6 text-uppercase fw-bold text-muted mb-0" style="letter-spacing: 0.05em; font-size: 0.75rem;">
            <?= $currentSort === 'recent' ? 'Latest' : e($sortLabels[$currentSort] ?? 'Recent') ?> Discussions
        </h2>
        <span class="badge bg-secondary-subtle text-secondary-emphasis rounded-pill"><?= (int) $total ?></span>

        <!-- Active Search Tag -->
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

        <!-- Active Book Filter Tag -->
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

        <!-- Active Author Filter Tag -->
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
            <article class="card-base p-4 transition-all">
                <div class="d-flex align-items-start gap-3">
                    <!-- Author Avatar Initials -->
                    <div class="flex-shrink-0">
                        <a href="/community/user/<?= (int) ($post['user_id'] ?? 0) ?>" class="text-decoration-none" title="View profile of <?= e($authorName) ?>">
                            <div class="d-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary fw-bold" style="width: 42px; height: 42px; font-size: 1rem;">
                                <?= e($initial) ?>
                            </div>
                        </a>
                    </div>

                    <!-- Post Body & Info -->
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                            <a href="/community/user/<?= (int) ($post['user_id'] ?? 0) ?>" class="fw-semibold text-dark text-decoration-none hover-primary text-truncate">
                                <?= e($authorName) ?>
                            </a>
                            <span class="text-muted small flex-shrink-0"><?= e($timeAgo) ?></span>
                        </div>

                        <h3 class="h5 mb-2">
                            <a href="/community/post/<?= (int) $post['id'] ?>" class="text-decoration-none text-dark hover-primary fw-semibold">
                                <?= e($post['title']) ?>
                            </a>
                        </h3>

                        <p class="text-secondary mb-3 text-break" style="line-height: 1.55;">
                            <?= e(mb_strimwidth((string) ($post['body'] ?? ''), 0, 280, '...')) ?>
                        </p>

                        <!-- Optional Book Attachment -->
                        <?php if ($hasBook): ?>
                            <div class="mb-3">
                                <a href="/books/<?= (int) $post['book_id'] ?>" class="d-inline-flex align-items-center gap-2 px-3 py-1-5 rounded bg-body-tertiary text-decoration-none border text-reset hover-border-primary transition-all">
                                    <i class="fa-solid fa-book text-primary" aria-hidden="true"></i>
                                    <span class="small fw-medium text-truncate" style="max-width: 320px;"><?= e($bookTitle) ?></span>
                                </a>
                            </div>
                        <?php endif; ?>

                        <!-- Engagement Footer -->
                        <div class="d-flex align-items-center gap-4 text-muted small pt-1">
                            <span title="Likes">
                                <i class="fa-regular fa-heart me-1" aria-hidden="true"></i>
                                <span><?= (int) ($post['like_count'] ?? 0) ?></span>
                            </span>
                            <span title="Comments">
                                <i class="fa-regular fa-comment me-1" aria-hidden="true"></i>
                                <span><?= (int) ($post['comment_count'] ?? 0) ?></span>
                            </span>
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
