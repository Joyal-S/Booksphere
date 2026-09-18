<?php

declare(strict_types=1);

/**
 * categories/index.php
 *
 * The CATEGORY DIRECTORY: Browse the library by genre through a
 * thematic exploration grid featuring curated icon badges, community ratings,
 * and quick-filter pills.
 */

$categories = $categories ?? [];
$totalCategories = count($categories);

$categoryIcons = [
    'fiction' => 'fa-book-open',
    'classic-fiction' => 'fa-landmark',
    'science-fiction' => 'fa-rocket',
    'fantasy' => 'fa-wand-magic-sparkles',
    'mystery-thriller' => 'fa-user-secret',
    'romance' => 'fa-heart',
    'biography-memoir' => 'fa-scroll',
    'self-help' => 'fa-lightbulb',
    'history' => 'fa-landmark',
    'technology' => 'fa-laptop-code',
    'short-stories' => 'fa-layer-group',
    'psychology' => 'fa-brain',
    'business-economics' => 'fa-chart-line',
    'science' => 'fa-flask',
    'philosophy' => 'fa-lightbulb',
    'poetry-essays' => 'fa-pen-fancy',
    'young-adult' => 'fa-star',
];

?>
<div class="categories-hero">
    <div class="categories-hero-content">
        <span class="eyebrow"><i class="fa-solid fa-compass"></i> Genre Exploration</span>
        <h1>Explore Categories</h1>
        <p class="lead">Browse the library by genre, with community ratings, curated selections, and thematic journeys.</p>
    </div>
    <div class="categories-hero-illustration" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="96" height="96" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
            <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
            <path d="M8 7h8"></path>
            <path d="M8 11h6"></path>
        </svg>
    </div>
</div>

<?php if ($categories === []): ?>
    <div class="card-base p-4 text-center text-muted">
        No categories in the catalogue yet.
    </div>
<?php else: ?>
    <div class="category-filter-pills" role="tablist" aria-label="Genre categories filter">
        <button type="button" class="genre-pill active" data-filter="all" role="tab" aria-selected="true">
            <i class="fa-solid fa-layer-group"></i> All Genres (<?= $totalCategories ?>)
        </button>
        <button type="button" class="genre-pill" data-filter="popular" role="tab" aria-selected="false">
            <i class="fa-solid fa-fire"></i> Popular
        </button>
        <button type="button" class="genre-pill" data-filter="top-rated" role="tab" aria-selected="false">
            <i class="fa-solid fa-star"></i> Top Rated
        </button>
        <button type="button" class="genre-pill" data-filter="most-reviewed" role="tab" aria-selected="false">
            <i class="fa-solid fa-comments"></i> Most Reviewed
        </button>
    </div>

    <div id="categoriesGrid" class="row g-3 g-xl-4 row-cols-1 row-cols-sm-2 row-cols-md-2 row-cols-xl-3">
        <?php foreach ($categories as $category): ?>
            <?php
                $slug = (string) ($category['slug'] ?? '');
                $icon = $categoryIcons[$slug] ?? 'fa-book';
                $badgeClass = 'genre-badge--' . ($slug !== '' ? $slug : 'default');
                $reviewCount = (int) ($category['count'] ?? 0);
                $rating = (float) ($category['average'] ?? 0.0);
            ?>
            <div class="col category-item"
                 data-slug="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>"
                 data-rating="<?= $rating ?>"
                 data-reviews="<?= $reviewCount ?>">
                <div class="category-explore-card">
                    <div>
                        <div class="category-card-top">
                            <div class="genre-badge <?= $badgeClass ?>" aria-hidden="true">
                                <i class="fa-solid <?= $icon ?>"></i>
                            </div>
                            <span class="genre-explore-arrow" aria-hidden="true">
                                <i class="fa-solid fa-arrow-right"></i>
                            </span>
                        </div>
                        <h2 class="category-card-name">
                            <a href="/categories/<?= (int) $category['id'] ?>" class="stretched-link">
                                <?= e($category['name']) ?>
                            </a>
                        </h2>
                    </div>
                    <div class="category-card-meta">
                        <div class="category-reviews-meta">
                            <?php $counter = ['count' => $reviewCount]; ?>
                            <?php require root_path('app/Views/components/review-counter.php'); ?>
                        </div>
                        <div class="category-rating-meta">
                            <?php if ($reviewCount > 0): ?>
                                <?php $badge = ['rating' => $rating, 'size' => 'sm']; ?>
                                <?php require root_path('app/Views/components/rating-badge.php'); ?>
                            <?php else: ?>
                                <span class="text-muted small">Not rated yet</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div id="noCategoriesMatch" class="card-base p-4 text-center text-muted d-none my-4">
        <p class="mb-2">No categories found for this filter.</p>
        <button type="button" id="btnResetCategoryFilter" class="btn btn-sm btn-outline">Show All Genres</button>
    </div>

    <script>
    (function () {
        const pills = Array.from(document.querySelectorAll('.genre-pill'));
        const grid = document.getElementById('categoriesGrid');
        const emptyState = document.getElementById('noCategoriesMatch');
        const resetBtn = document.getElementById('btnResetCategoryFilter');

        if (!grid || pills.length === 0) return;

        const items = Array.from(grid.querySelectorAll('.category-item'));

        function filterCategories(filter) {
            let visibleCount = 0;

            items.forEach(item => {
                const rating = parseFloat(item.getAttribute('data-rating') || '0');
                const reviews = parseInt(item.getAttribute('data-reviews') || '0', 10);
                let show = true;

                if (filter === 'popular') {
                    show = reviews >= 1;
                } else if (filter === 'top-rated') {
                    show = rating >= 4.0;
                } else if (filter === 'most-reviewed') {
                    show = reviews >= 5;
                }

                if (show) {
                    item.classList.remove('d-none');
                    visibleCount++;
                } else {
                    item.classList.add('d-none');
                }
            });

            if (emptyState) {
                if (visibleCount === 0) {
                    emptyState.classList.remove('d-none');
                } else {
                    emptyState.classList.add('d-none');
                }
            }
        }

        pills.forEach(pill => {
            pill.addEventListener('click', function () {
                pills.forEach(p => {
                    p.classList.remove('active');
                    p.setAttribute('aria-selected', 'false');
                });
                pill.classList.add('active');
                pill.setAttribute('aria-selected', 'true');
                filterCategories(pill.getAttribute('data-filter') || 'all');
            });
        });

        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                const allPill = pills.find(p => p.getAttribute('data-filter') === 'all');
                if (allPill) {
                    allPill.click();
                }
            });
        }
    })();
    </script>
<?php endif; ?>
