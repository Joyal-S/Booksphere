<?php

declare(strict_types=1);

/**
 * community/book.php
 *
 * Dedicated Book Discussion Hub View (Phase C7-C).
 * Established as the central community hub for a specific book.
 * Features book metadata header, activity stats, discovery search within book,
 * compact discussion cards, and preselected post creation.
 */

$book        = $book        ?? [];
$posts       = $posts       ?? [];
$stats       = $stats       ?? ['posts' => 0, 'comments' => 0, 'likes' => 0];
$total       = $total       ?? 0;
$page        = $page        ?? 1;
$pages       = $pages       ?? 1;
$perPage     = $perPage     ?? 20;
$currentSort = $currentSort ?? 'recent';
$query       = $query       ?? '';
$pagination  = $pagination  ?? [];

$bookId     = (int) ($book['id'] ?? 0);
$bookTitle  = (string) ($book['title'] ?? 'Book');
$authors    = $book['authors'] ?? [];
$categories = $book['categories'] ?? [];

$sortLabels = [
    'recent'   => 'Latest Discussions',
    'popular'  => 'Popular',
    'trending' => 'Trending',
];

$sortIcons = [
    'recent'   => 'fa-clock',
    'popular'  => 'fa-fire',
    'trending' => 'fa-arrow-trend-up',
];
?>
<nav aria-label="Breadcrumbs" class="mb-3">
    <ol class="breadcrumb mb-0 small">
        <li class="breadcrumb-item"><a href="/community" class="text-decoration-none text-muted">Community</a></li>
        <li class="breadcrumb-item"><a href="/books/<?= $bookId ?>" class="text-decoration-none text-muted"><?= e($bookTitle) ?></a></li>
        <li class="breadcrumb-item active" aria-current="page">Discussion Hub</li>
    </ol>
</nav>

<!-- Book Header Card -->
<section class="card-base p-4 p-md-5 mb-4">
    <div class="row g-4 align-items-center">
        <div class="col-12 col-sm-4 col-md-3 col-xl-2 text-center text-sm-start">
            <div class="book-detail-cover-wrap mx-auto mx-sm-0" style="max-width: 140px;">
                <?php $cover = [
                    'src'   => $book['cover_image'] ?? '',
                    'alt'   => 'Cover of ' . $bookTitle,
                    'class' => 'img-fluid rounded shadow-sm',
                ]; ?>
                <?php require root_path('app/Views/books/components/book-cover.php'); ?>
            </div>
        </div>

        <div class="col-12 col-sm-8 col-md-9 col-xl-10">
            <div class="d-flex flex-column flex-md-row align-items-md-start justify-content-between gap-3">
                <div>
                    <div class="text-uppercase text-primary fw-semibold small tracking-wide mb-1">
                        <i class="fa-solid fa-comments me-1" aria-hidden="true"></i> Community Discussion Hub
                    </div>
                    <h1 class="h3 fw-bold text-dark mb-1"><?= e($bookTitle) ?></h1>
                    <?php if (!empty($authors)): ?>
                        <p class="text-muted mb-2">
                            By <?php foreach ($authors as $idx => $a): ?>
                                <a href="/authors/<?= (int) $a['id'] ?>" class="text-dark text-decoration-none hover-primary fw-medium"><?= e($a['name']) ?></a><?= $idx < count($authors) - 1 ? ', ' : '' ?>
                            <?php endforeach; ?>
                        </p>
                    <?php endif; ?>

                    <div class="d-flex align-items-center gap-2 flex-wrap mb-3">
                        <?php
                        $summary = [
                            'average' => (float) ($book['average_rating'] ?? 0),
                            'count'   => (int) ($book['ratings_count'] ?? 0),
                        ];
                        $starRating = [
                            'rating' => $summary['average'],
                            'count'  => $summary['count'] > 0 ? $summary['count'] : null,
                            'size'   => 'sm',
                        ];
                        ?>
                        <?php require root_path('app/Views/components/star-rating.php'); ?>

                        <?php foreach ($categories as $c): ?>
                            <span class="badge bg-secondary-subtle text-secondary border border-subtle">
                                <?= e($c['name']) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="d-flex flex-column gap-2 flex-shrink-0">
                    <?php if (auth_check()): ?>
                        <a href="/community/create?book_id=<?= $bookId ?>" class="btn btn-primary px-4 py-2">
                            <i class="fa-solid fa-plus me-1" aria-hidden="true"></i> Start a Discussion
                        </a>
                    <?php else: ?>
                        <a href="/login" class="btn btn-primary px-4 py-2">
                            <i class="fa-solid fa-right-to-bracket me-1" aria-hidden="true"></i> Log in to Discuss
                        </a>
                    <?php endif; ?>
                    <a href="/books/<?= $bookId ?>" class="btn btn-outline-secondary btn-sm text-center">
                        <i class="fa-solid fa-book-open me-1" aria-hidden="true"></i> View Book Details
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Community Activity Stats Strip -->
<div class="row g-3 mb-4">
    <div class="col-4">
        <div class="card-base p-3 text-center">
            <span class="d-block h4 mb-0 fw-bold text-primary"><?= (int) ($stats['posts'] ?? 0) ?></span>
            <span class="text-muted small text-uppercase" style="font-size: 0.6875rem; letter-spacing: 0.05em;">Discussions</span>
        </div>
    </div>
    <div class="col-4">
        <div class="card-base p-3 text-center">
            <span class="d-block h4 mb-0 fw-bold text-primary"><?= (int) ($stats['comments'] ?? 0) ?></span>
            <span class="text-muted small text-uppercase" style="font-size: 0.6875rem; letter-spacing: 0.05em;">Comments</span>
        </div>
    </div>
    <div class="col-4">
        <div class="card-base p-3 text-center">
            <span class="d-block h4 mb-0 fw-bold text-primary"><?= (int) ($stats['likes'] ?? 0) ?></span>
            <span class="text-muted small text-uppercase" style="font-size: 0.6875rem; letter-spacing: 0.05em;">Likes</span>
        </div>
    </div>
</div>

<!-- Search & Discovery Controls for this Book -->
<div class="card-base p-3 mb-4">
    <form action="/community/book/<?= $bookId ?>" method="GET" class="d-flex align-items-center gap-2">
        <?php if ($currentSort !== 'recent'): ?>
            <input type="hidden" name="sort" value="<?= e($currentSort) ?>">
        <?php endif; ?>

        <div class="input-group">
            <span class="input-group-text bg-body-tertiary text-muted border-end-0">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            </span>
            <input type="text"
                   name="q"
                   class="form-control border-start-0 ps-0"
                   placeholder="Search discussions about <?= e($bookTitle) ?>..."
                   value="<?= e($query ?? '') ?>"
                   aria-label="Search discussions for this book">
            <?php if (!empty($query)): ?>
                <a href="/community/book/<?= $bookId ?><?= $currentSort !== 'recent' ? '?sort=' . e($currentSort) : '' ?>" class="btn btn-outline-secondary px-3" title="Clear Search">
                    <i class="fa-solid fa-xmark me-1" aria-hidden="true"></i> Clear
                </a>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary px-4">
                Search
            </button>
        </div>
    </form>
</div>

<!-- Discovery Controls (Sort Pills) -->
<div class="d-flex align-items-center justify-content-between gap-3 mb-4 p-3 rounded bg-body-tertiary border">
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <?php foreach ($sortLabels as $modeKey => $modeLabel): ?>
            <?php
            $isModeActive = ($currentSort === $modeKey) || ($modeKey === 'recent' && !in_array($currentSort, ['popular', 'trending'], true));
            $modeUrlParams = ['sort' => $modeKey];
            if (!empty($query)) {
                $modeUrlParams['q'] = $query;
            }
            $modeUrl = '/community/book/' . $bookId . '?' . http_build_query($modeUrlParams);
            ?>
            <a href="<?= e($modeUrl) ?>"
               class="btn btn-sm <?= $isModeActive ? 'btn-primary' : 'btn-outline-secondary' ?> d-inline-flex align-items-center gap-1-5">
                <i class="fa-solid <?= $sortIcons[$modeKey] ?? 'fa-list' ?>" aria-hidden="true"></i>
                <span><?= e($modeLabel) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Discussion Posts Grid / List -->
<?php if (empty($posts)): ?>
    <div class="card-base p-5 text-center my-4">
        <i class="fa-solid fa-comments fs-1 text-muted mb-3" aria-hidden="true"></i>
        <h2 class="h5 fw-bold mb-2">No discussions yet</h2>
        <p class="text-muted mb-4" style="max-width: 480px; margin-left: auto; margin-right: auto;">
            Start the first conversation about <strong><?= e($bookTitle) ?></strong> and share your perspective with fellow readers.
        </p>
        <?php if (auth_check()): ?>
            <a href="/community/create?book_id=<?= $bookId ?>" class="btn btn-primary px-4 py-2 d-inline-flex align-items-center gap-2 mx-auto" style="width: fit-content;">
                <i class="fa-solid fa-plus" aria-hidden="true"></i> Start a Discussion
            </a>
        <?php else: ?>
            <a href="/login" class="btn btn-primary px-4 py-2 d-inline-flex align-items-center gap-2 mx-auto" style="width: fit-content;">
                <i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i> Log in to Start Discussion
            </a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="d-flex flex-column gap-3 mb-4">
        <?php foreach ($posts as $post): ?>
            <?php
            $pId         = (int) $post['id'];
            $authorId    = (int) ($post['user_id'] ?? 0);
            $authorName  = (string) ($post['author_name'] ?? 'Community Member');
            $initial     = mb_strtoupper(mb_substr($authorName, 0, 1));
            $createdTime = !empty($post['created_at']) ? date('M j, Y', strtotime((string) $post['created_at'])) : '';
            $likesCount  = (int) ($post['like_count'] ?? 0);
            $commCount   = (int) ($post['comment_count'] ?? 0);
            $bodyExcerpt = mb_substr(strip_tags((string) $post['body']), 0, 220) . (mb_strlen((string) $post['body']) > 220 ? '...' : '');
            ?>
            <article class="card-base p-4 hover-shadow transition-all">
                <div class="d-flex align-items-start justify-content-between gap-3 mb-2">
                    <div class="d-flex align-items-center gap-2">
                        <div class="d-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary fw-bold flex-shrink-0"
                             style="width: 36px; height: 36px; font-size: 0.95rem;">
                            <?= e($initial) ?>
                        </div>
                        <div>
                            <a href="/community/user/<?= $authorId ?>" class="fw-semibold text-dark text-decoration-none hover-primary small">
                                <?= e($authorName) ?>
                            </a>
                            <span class="text-muted small">&middot; <?= e($createdTime) ?></span>
                        </div>
                    </div>
                </div>

                <h2 class="h5 fw-bold mb-2">
                    <a href="/community/post/<?= $pId ?>" class="text-dark text-decoration-none hover-primary">
                        <?= e($post['title']) ?>
                    </a>
                </h2>

                <p class="text-secondary small mb-3"><?= e($bodyExcerpt) ?></p>

                <div class="d-flex align-items-center justify-content-between pt-2 border-top text-muted small">
                    <div class="d-flex align-items-center gap-3">
                        <span><i class="fa-regular fa-thumbs-up me-1" aria-hidden="true"></i> <?= $likesCount ?></span>
                        <span><i class="fa-regular fa-comment me-1" aria-hidden="true"></i> <?= $commCount ?></span>
                    </div>
                    <a href="/community/post/<?= $pId ?>" class="btn btn-sm btn-link text-primary text-decoration-none p-0 fw-medium">
                        Read discussion <i class="fa-solid fa-arrow-right ms-1" aria-hidden="true"></i>
                    </a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if (!empty($pagination) && ($pagination['pages'] ?? 1) > 1): ?>
        <?php require root_path('app/Views/components/pagination.php'); ?>
    <?php endif; ?>
<?php endif; ?>
