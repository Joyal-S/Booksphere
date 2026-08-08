<?php

declare(strict_types=1);

/**
 * search/partials/_history.php
 *
 * The signed-in user's recent searches (Phase 11.5). Rendered on the
 * search page under the toolbar whenever history is enabled and the
 * user has rows: each saved search is ONE row that lets them
 *     - re-RUN the past search: the row is an <a href="..."> to the
 *       exact search page URL of that search (query + scope + filters
 *       already baked in, built by SearchHistoryService::list via
 *       SearchService::queryString), so it works with no JavaScript;
 *       with JS (search.js - data-history-search) the click instead
 *       REPOPULATES the search form and re-runs the live fetch - no
 *       page reload, no scroll jump
 *     - DELETE one row: a small, owner-scoped, CSRF-protected POST
 *       with the DELETE _method spoof (the only-owner gate lives in
 *       the history service), which the search page's confirm modal
 *       guards with a Bootstrap dialog when JS is on
 *     - CLEAR the whole history: the toolbar's clear button posts
 *       to /search/history with the same spoof + CSRF
 *
 * Progressive enhancement: with JavaScript disabled every action is
 * a plain <form method="post"> - the delete posts a hidden
 * _method=DELETE the router matches, the re-run is the plain link's
 * href, and the server flashes + redirects after each write. The
 * confirm dialog is pure client polish.
 *
 * $history: the SearchHistoryService::list() rows (already decorated),
 *           each array {id, query, scope, filters, count, lastUsedLabel,
 *           createdAtLabel, url}; [] renders the empty state.
 */

$history     = $history ?? [];
$scopeLabels = [
    'books'      => 'Books',
    'authors'    => 'Authors',
    'categories' => 'Categories',
    'publishers' => 'Publishers',
    'reviews'    => 'Reviews',
];

?>

<section class="card-base search-history mt-3" aria-labelledby="search-history-title" data-search-history>
    <div class="search-history-head">
        <h2 class="search-history-title" id="search-history-title">Search history</h2>
        <?php if ($history !== []): ?>
            <form method="post" action="/search/history" data-history-clear-form>
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="_method" value="DELETE">
                <button type="submit" class="btn btn-sm btn-outline-secondary" data-history-clear>
                    <i class="fa-solid fa-rotate-left me-1" aria-hidden="true"></i>Clear all
                    <span class="visually-hidden">Clear your search history</span>
                </button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($history === []): ?>
        <p class="search-history-empty">
            No saved searches yet &mdash; your recent searches will appear here so you can re-run them.
        </p>
    <?php else: ?>
        <ul class="search-history-list">
            <?php foreach ($history as $row): ?>
                <?php $filterCount = count($row['filters']) ?>
                <li class="history-item">
                    <div class="history-item-main">
                        <a class="history-item-link"
                           href="<?= e($row['url']) ?>"
                           data-history-search
                           data-q="<?= e($row['query']) ?>"
                           data-scope="<?= e($row['scope']) ?>"
                           data-filters="<?= e(json_encode($row['filters'])) ?>"
                           title="Search again for &ldquo;<?= e($row['query']) ?>&rdquo;">
                            <i class="fa-solid fa-clock-rotate-left history-item-icon" aria-hidden="true"></i>
                            <span class="history-item-term"><?= e($row['query']) ?></span>
                            <?php if ($filterCount > 0): ?>
                                <span class="history-item-filters badge rounded-pill text-bg-secondary"><?= $filterCount ?> filter<?= $filterCount === 1 ? '' : 's' ?></span>
                            <?php endif; ?>
                        </a>
                    </div>
                    <div class="history-item-meta">
                        <span class="history-item-scope"><?= e($scopeLabels[$row['scope']] ?? ucfirst($row['scope'])) ?></span>
                        <span class="history-item-time"><?= e($row['lastUsedLabel']) ?></span>
                        <?php if ($row['count'] > 1): ?>
                            <span class="history-item-count"><?= $row['count'] ?>×</span>
                        <?php endif; ?>
                    </div>
                    <form method="post" action="/search/history/<?= (int) $row['id'] ?>" class="history-item-delete" data-history-delete-form>
                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="_method" value="DELETE">
                        <button type="submit" class="btn btn-sm btn-icon history-item-delete-btn"
                                title="Remove &ldquo;<?= e($row['query']) ?>&rdquo; from history"
                                data-history-delete>
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                            <span class="visually-hidden">Remove &ldquo;<?= e($row['query']) ?>&rdquo; from history</span>
                        </button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>