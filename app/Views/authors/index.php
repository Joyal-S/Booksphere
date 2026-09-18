<?php

declare(strict_types=1);

/**
 * authors/index.php
 *
 * The AUTHOR DIRECTORY: Authors catalogue presented as a distinguished
 * literary directory with people-focused avatars, community review stats,
 * live search, sorting, and responsive layout toggling.
 */

$authors = $authors ?? [];
$totalAuthors = count($authors);

$getAuthorInitials = static function (string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    if (empty($parts) || $parts[0] === '') {
        return 'A';
    }
    if (count($parts) === 1) {
        return mb_strtoupper(mb_substr($parts[0], 0, 2));
    }
    return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1));
};

?>
<div class="authors-hero">
    <div class="authors-hero-content">
        <span class="eyebrow"><i class="fa-solid fa-feather"></i> Literary Minds</span>
        <h1>Authors Directory</h1>
        <p class="lead">Discover <?= $totalAuthors ?> creators, thought leaders, and storytellers shaping the BookSphere library.</p>
    </div>
    <div class="authors-hero-illustration" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="96" height="96" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M20.24 12.24a6 6 0 0 0-8.49-8.49L5 10.5V19h8.5z"></path>
            <line x1="16" y1="8" x2="2" y2="22"></line>
            <line x1="17.5" y1="15" x2="9" y2="15"></line>
        </svg>
    </div>
</div>

<?php if ($authors === []): ?>
    <div class="card-base p-4 text-center text-muted">
        No authors in the catalogue yet.
    </div>
<?php else: ?>
    <div class="authors-toolbar" role="toolbar" aria-label="Author directory controls">
        <div class="author-search-box">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" id="authorSearch" class="form-control" placeholder="Search authors by name..." aria-label="Search authors by name">
        </div>
        <div class="author-toolbar-controls">
            <select id="authorSort" class="form-select author-sort-select" aria-label="Sort authors">
                <option value="name-asc">Name (A &rarr; Z)</option>
                <option value="name-desc">Name (Z &rarr; A)</option>
                <option value="rating-desc">Highest Rated</option>
                <option value="reviews-desc">Most Reviews</option>
            </select>
            <div class="btn-group author-view-btn-group" role="group" aria-label="Layout view switcher">
                <button type="button" id="btnViewGrid" class="btn active" title="Grid View" aria-label="Grid View" aria-pressed="true">
                    <i class="fa-solid fa-table-cells-large"></i>
                </button>
                <button type="button" id="btnViewList" class="btn" title="List View" aria-label="List View" aria-pressed="false">
                    <i class="fa-solid fa-list"></i>
                </button>
            </div>
            <span id="authorCountBadge" class="author-count-badge">Showing <?= $totalAuthors ?> authors</span>
        </div>
    </div>

    <div id="authorsGrid" class="authors-grid row g-3 row-cols-1 row-cols-sm-2 row-cols-md-2 row-cols-xl-3">
        <?php foreach ($authors as $author): ?>
            <?php
                $name = (string) $author['name'];
                $initials = $getAuthorInitials($name);
                $avatarNum = (abs(crc32($name)) % 6) + 1;
                $reviewCount = (int) ($author['count'] ?? 0);
                $rating = (float) ($author['average'] ?? 0.0);
            ?>
            <div class="col author-item"
                 data-name="<?= htmlspecialchars(mb_strtolower($name), ENT_QUOTES, 'UTF-8') ?>"
                 data-rating="<?= $rating ?>"
                 data-reviews="<?= $reviewCount ?>">
                <div class="author-card">
                    <div class="author-card-header">
                        <div class="author-avatar avatar-<?= $avatarNum ?>" aria-hidden="true">
                            <?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div class="author-card-info">
                            <h2 class="author-card-name">
                                <a href="/authors/<?= (int) $author['id'] ?>" class="stretched-link">
                                    <?= e($name) ?>
                                </a>
                            </h2>
                        </div>
                    </div>
                    <div class="author-card-footer">
                        <div class="author-reviews-meta">
                            <?php $counter = ['count' => $reviewCount]; ?>
                            <?php require root_path('app/Views/components/review-counter.php'); ?>
                        </div>
                        <div class="author-rating-meta">
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

    <div id="noAuthorsMatch" class="card-base p-4 text-center text-muted d-none my-4">
        <p class="mb-2">No authors found matching "<strong id="searchQueryDisplay"></strong>".</p>
        <button type="button" id="btnResetAuthorSearch" class="btn btn-sm btn-outline">Clear Search</button>
    </div>

    <script>
    (function () {
        const searchInput = document.getElementById('authorSearch');
        const sortSelect = document.getElementById('authorSort');
        const btnGrid = document.getElementById('btnViewGrid');
        const btnList = document.getElementById('btnViewList');
        const grid = document.getElementById('authorsGrid');
        const countBadge = document.getElementById('authorCountBadge');
        const emptyState = document.getElementById('noAuthorsMatch');
        const queryDisplay = document.getElementById('searchQueryDisplay');
        const resetBtn = document.getElementById('btnResetAuthorSearch');

        if (!grid) return;

        const items = Array.from(grid.querySelectorAll('.author-item'));
        const totalCount = items.length;

        function applyFilterAndSort() {
            const query = (searchInput ? searchInput.value.trim().toLowerCase() : '');
            const sortMode = sortSelect ? sortSelect.value : 'name-asc';

            let visibleCount = 0;

            items.forEach(item => {
                const name = item.getAttribute('data-name') || '';
                const matches = query === '' || name.includes(query);
                if (matches) {
                    item.classList.remove('d-none');
                    visibleCount++;
                } else {
                    item.classList.add('d-none');
                }
            });

            // Sort
            items.sort((a, b) => {
                const nameA = a.getAttribute('data-name') || '';
                const nameB = b.getAttribute('data-name') || '';
                const ratingA = parseFloat(a.getAttribute('data-rating') || '0');
                const ratingB = parseFloat(b.getAttribute('data-rating') || '0');
                const reviewsA = parseInt(a.getAttribute('data-reviews') || '0', 10);
                const reviewsB = parseInt(b.getAttribute('data-reviews') || '0', 10);

                switch (sortMode) {
                    case 'name-desc':
                        return nameB.localeCompare(nameA);
                    case 'rating-desc':
                        if (ratingB !== ratingA) return ratingB - ratingA;
                        return reviewsB - reviewsA;
                    case 'reviews-desc':
                        if (reviewsB !== reviewsA) return reviewsB - reviewsA;
                        return ratingB - ratingA;
                    case 'name-asc':
                    default:
                        return nameA.localeCompare(nameB);
                }
            });

            // Re-order DOM elements
            items.forEach(item => grid.appendChild(item));

            // Badge text
            if (countBadge) {
                if (query !== '') {
                    countBadge.textContent = 'Showing ' + visibleCount + ' of ' + totalCount + ' authors';
                } else {
                    countBadge.textContent = 'Showing ' + totalCount + ' authors';
                }
            }

            // Empty state
            if (emptyState && queryDisplay) {
                if (visibleCount === 0) {
                    queryDisplay.textContent = query;
                    emptyState.classList.remove('d-none');
                } else {
                    emptyState.classList.add('d-none');
                }
            }
        }

        if (searchInput) {
            searchInput.addEventListener('input', applyFilterAndSort);
        }

        if (sortSelect) {
            sortSelect.addEventListener('change', applyFilterAndSort);
        }

        if (btnGrid && btnList) {
            btnGrid.addEventListener('click', function () {
                grid.classList.remove('view-list');
                btnGrid.classList.add('active');
                btnGrid.setAttribute('aria-pressed', 'true');
                btnList.classList.remove('active');
                btnList.setAttribute('aria-pressed', 'false');
            });

            btnList.addEventListener('click', function () {
                grid.classList.add('view-list');
                btnList.classList.add('active');
                btnList.setAttribute('aria-pressed', 'true');
                btnGrid.classList.remove('active');
                btnGrid.setAttribute('aria-pressed', 'false');
            });
        }

        if (resetBtn && searchInput) {
            resetBtn.addEventListener('click', function () {
                searchInput.value = '';
                applyFilterAndSort();
                searchInput.focus();
            });
        }
    })();
    </script>
<?php endif; ?>
