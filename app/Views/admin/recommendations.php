<?php

declare(strict_types=1);

/**
 * admin/recommendations.php
 *
 * The Phase 6.5 recommendation engine monitoring page (admin only -
 * the route carries AdminMiddleware).
 *
 * Purpose:
 *     ONE page where an administrator can SEE the engine working:
 *
 *         1. Cache    - how many user shelves are stored, their size,
 *                       how many are stale (past the TTL), which
 *                       users are cached, whether the directory is
 *                       writable - plus the flush tool (the one
 *                       write action of the page)
 *         2. Config   - the exact tuning values the engine runs
 *                       with (hybrid weights, profile, candidates,
 *                       confidence thresholds, rate limits) - the
 *                       page always shows what config says
 *         3. Data     - the signal totals (published books, reviews,
 *                       wishlist saves, tracked views, average
 *                       rating) and the top categories / authors by
 *                       community activity
 *         4. Scores   - the average popularity and trending scores
 *                       of the active catalogue, raw and normalized
 *                       to the shared 0-100 scale
 *
 * Data ($metrics array, set by AdminController::metrics() from
 * RecommendationMetrics::summary()):
 *     'cache'       => stats block, or an all-zero fallback
 *     'config'      => hybrid_weights/profile/candidates/confidence/security
 *     'data'        => totals + topCategories + topAuthors
 *     'scores'      => popularity/trending raw + percent + sampleSize
 *     'generatedAt' => when this snapshot was taken (UTC)
 *
 * Every number is derived live from the database and the cache
 * directory at request time - nothing is fabricated.
 */

$m = $metrics;

$cache  = $m['cache'] ?? [];
$config = $m['config'] ?? [];
$data   = $m['data'] ?? [];
$scores = $m['scores'] ?? [];

$fmtBytes = static fn (int $bytes): string => $bytes < 1024
    ? $bytes . ' B'
    : number_format($bytes / 1024, 1) . ' KB';

$fmtTime = static function (mixed $timestamp): string {
    if ($timestamp === null || (int) $timestamp === 0) {
        return '—';
    }

    return gmdate('j M Y, g:i A', (int) $timestamp) . ' UTC';
};

$topList = static function (array $rows): void {
    foreach ($rows as $row): ?>
        <li class="d-flex justify-content-between align-items-center gap-2 py-1 border-bottom border-secondary-subtle">
            <span class="text-truncate"><?= e((string) ($row['name'] ?? '—')) ?></span>
            <span class="badge text-bg-light"><?= (int) ($row['signal'] ?? 0) ?> signals</span>
        </li>
    <?php endforeach;
};

?>
<div class="page-intro" data-animate>
    <p class="eyebrow">Admin &middot; Phase 6.5 &middot; live snapshot <?= e((string) ($m['generatedAt'] ?? '')) ?></p>
    <h1>Recommendation Engine</h1>
    <p class="lead">
        The live health picture of the recommendation engine - cache, configuration, data signals and
        average scores. Read-only, except for the explicit cache flush below.
    </p>
</div>

<?php if (count($cache) > 0): ?>
    <div class="row g-3 g-xl-4 mb-3 row-cols-1 row-cols-sm-2 row-cols-xl-4" data-animate>
        <?php $stat = ['icon' => 'fa-box-archive', 'label' => 'Cached Shelves', 'value' => (int) ($cache['files'] ?? 0), 'tone' => 'primary', 'trend' => ($cache['enabled'] ?? false) ? 'cache enabled' : 'cache disabled']; ?>
        <?php require root_path('app/Views/components/stat-card.php'); ?>

        <?php $stat = ['icon' => 'fa-database', 'label' => 'Cache Size', 'value' => $fmtBytes((int) ($cache['bytes'] ?? 0)), 'tone' => 'info', 'trend' => 'on disk']; ?>
        <?php require root_path('app/Views/components/stat-card.php'); ?>

        <?php $stat = ['icon' => 'fa-hourglass-half', 'label' => 'Stale Entries', 'value' => (int) ($cache['stale'] ?? 0), 'tone' => ((int) ($cache['stale'] ?? 0) > 0 ? 'warning' : 'success'), 'trend' => 'past the TTL']; ?>
        <?php require root_path('app/Views/components/stat-card.php'); ?>

        <?php $stat = ['icon' => 'fa-users', 'label' => 'Cached Users', 'value' => count($cache['users'] ?? []), 'tone' => 'success', 'trend' => 'one shelf each']; ?>
        <?php require root_path('app/Views/components/stat-card.php'); ?>
    </div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-xl-6">
        <div class="card-base h-100" data-animate>
            <?php $section = ['eyebrow' => 'Maintenance', 'title' => 'Cache directory', 'icon' => 'fa-box-archive']; ?>
            <?php require root_path('app/Views/components/section-header.php'); ?>

            <dl class="row mb-3">
                <dt class="col-sm-4">Directory</dt>
                <dd class="col-sm-8 text-break"><code><?= e((string) ($cache['directory'] ?? '—')) ?></code></dd>

                <dt class="col-sm-4">TTL</dt>
                <dd class="col-sm-8"><?= (int) ($cache['ttl'] ?? 0) / 60 ?> minutes</dd>

                <dt class="col-sm-4">Writable</dt>
                <dd class="col-sm-8">
                    <span class="badge <?= ($cache['writable'] ?? false) ? 'text-bg-success' : 'text-bg-danger' ?>">
                        <?= ($cache['writable'] ?? false) ? 'yes' : 'no' ?>
                    </span>
                </dd>

                <dt class="col-sm-4">Oldest shelf</dt>
                <dd class="col-sm-8"><?= $fmtTime($cache['oldest'] ?? null) ?></dd>

                <dt class="col-sm-4">Newest shelf</dt>
                <dd class="col-sm-8"><?= $fmtTime($cache['newest'] ?? null) ?></dd>
            </dl>

            <form method="post" action="/admin/recommendations/cache/flush" class="d-flex align-items-center gap-3">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <button type="submit" class="btn btn-danger">
                    <i class="fa-solid fa-broom me-1" aria-hidden="true"></i>
                    Flush cache
                </button>
                <span class="text-secondary small">
                    Drops every cached shelf; the next dashboard visit rebuilds from the latest signals.
                </span>
            </form>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="card-base h-100" data-animate>
            <?php $section = ['eyebrow' => 'Tuning', 'title' => 'Live configuration', 'icon' => 'fa-sliders']; ?>
            <?php require root_path('app/Views/components/section-header.php'); ?>

            <dl class="row mb-0">
                <dt class="col-sm-4">Hybrid weights</dt>
                <dd class="col-sm-8">
                    <?php
                    $weights = $config['hybrid_weights'] ?? [];
                    $parts = [];

                    foreach (['category', 'author', 'wishlist', 'rating', 'trending', 'popularity'] as $factor) {
                        $parts[] = $factor . ' ' . (float) ($weights[$factor] ?? 0);
                    }
                    ?>
                    <?= e(implode(' &middot; ', $parts)) ?>
                </dd>

                <dt class="col-sm-4">Candidate pool</dt>
                <dd class="col-sm-8">
                    <?php $candidates = $config['candidates'] ?? []; ?>
                    <?= e('pool ' . (int) ($candidates['pool_limit'] ?? 0) . ' / signal cap ' . (int) ($candidates['signal_book_cap'] ?? 0) . ' / fallback ' . (int) ($candidates['popularity_fallback'] ?? 0)) ?>
                </dd>

                <dt class="col-sm-4">Confidence</dt>
                <dd class="col-sm-8">
                    <?php $confidence = $config['confidence'] ?? []; ?>
                    <?= e('high &ge; ' . (int) ($confidence['high'] ?? 0) . ' / medium &ge; ' . (int) ($confidence['medium'] ?? 0)) ?>
                </dd>

                <dt class="col-sm-4">Rate limits</dt>
                <dd class="col-sm-8">
                    <?php
                    $limits = $config['security']['rate_limit'] ?? [];
                    $limitParts = [];

                    foreach (['wishlist_toggle', 'refresh'] as $bucket) {
                        $spec = (array) ($limits[$bucket] ?? []);
                        $limitParts[] = $bucket . ': ' . (int) ($spec['limit'] ?? 0) . ' per ' . (int) ($spec['window_seconds'] ?? 0) . 's';
                    }
                    ?>
                    <?= e(implode(' &middot; ', $limitParts)) ?>
                </dd>
            </dl>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-xl-4">
        <div class="card-base h-100" data-animate>
            <?php $section = ['eyebrow' => 'Signals', 'title' => 'Data health', 'icon' => 'fa-chart-simple']; ?>
            <?php require root_path('app/Views/components/section-header.php'); ?>

            <dl class="row mb-0">
                <?php
                $totals = $data['totals'] ?? [];
                $rows = [
                    ['Published books', (int) ($totals['published_books'] ?? 0)],
                    ['Reviews / ratings', (int) ($totals['reviews'] ?? 0)],
                    ['Wishlist saves', (int) ($totals['wishlist'] ?? 0)],
                    ['Tracked views', (int) ($totals['book_views'] ?? 0)],
                    ['Average rating', (float) ($totals['average_rating'] ?? 0)],
                ];
                foreach ($rows as [$label, $value]): ?>
                    <dt class="col-sm-6"><?= e($label) ?></dt>
                    <dd class="col-sm-6"><?= $label === 'Average rating' ? e(format_rating($value)) : (is_float($value) ? e(number_format($value, 2)) : e(number_format($value))) ?></dd>
                <?php endforeach; ?>
            </dl>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card-base h-100" data-animate>
            <?php $section = ['eyebrow' => 'Community', 'title' => 'Top categories by signal', 'icon' => 'fa-tags']; ?>
            <?php require root_path('app/Views/components/section-header.php'); ?>

            <ul class="list-unstyled mb-0">
                <?php $topList($data['topCategories'] ?? []); ?>
            </ul>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card-base h-100" data-animate>
            <?php $section = ['eyebrow' => 'Community', 'title' => 'Top authors by signal', 'icon' => 'fa-user-pen']; ?>
            <?php require root_path('app/Views/components/section-header.php'); ?>

            <ul class="list-unstyled mb-0">
                <?php $topList($data['topAuthors'] ?? []); ?>
            </ul>
        </div>
    </div>
</div>

<div class="card-base mb-3" data-animate>
    <?php $section = ['eyebrow' => 'Engine output', 'title' => 'Average catalogue scores', 'icon' => 'fa-gauge-high']; ?>
    <?php require root_path('app/Views/components/section-header.php'); ?>

    <div class="row g-3 row-cols-1 row-cols-sm-2 row-cols-xl-4">
        <?php
        $popularity = $scores['popularity'] ?? ['raw' => 0, 'percent' => 0];
        $trending   = $scores['trending'] ?? ['raw' => 0, 'percent' => 0];
        ?>
        <?php $stat = ['icon' => 'fa-fire', 'label' => 'Avg Popularity (0-100)', 'value' => (int) ($popularity['percent'] ?? 0), 'tone' => 'primary', 'trend' => 'raw ' . number_format((float) ($popularity['raw'] ?? 0), 2)]; ?>
        <?php require root_path('app/Views/components/stat-card.php'); ?>

        <?php $stat = ['icon' => 'fa-chart-line', 'label' => 'Avg Trending (0-100)', 'value' => (int) ($trending['percent'] ?? 0), 'tone' => 'info', 'trend' => 'raw ' . number_format((float) ($trending['raw'] ?? 0), 2)]; ?>
        <?php require root_path('app/Views/components/stat-card.php'); ?>

        <?php $stat = ['icon' => 'fa-magnifying-glass-chart', 'label' => 'Books Sampled', 'value' => (int) ($scores['sampleSize'] ?? 0), 'tone' => 'success', 'trend' => 'active catalogue']; ?>
        <?php require root_path('app/Views/components/stat-card.php'); ?>
    </div>
</div>
