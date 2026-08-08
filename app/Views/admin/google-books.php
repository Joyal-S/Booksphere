<?php

declare(strict_types=1);

/**
 * admin/google-books.php
 *
 * The Google Books search + import page (Phase 10.2 + 10.3 + 10.5, admin).
 *
 * Layout:
 *     1. Intro        - title + one-line purpose
 *     2. Status strip - provider on/off, base URL, cache TTL and the
 *                       circuit breaker state, so an admin can SEE
 *                       the module health at a glance (mirrors the
 *                       recommendation monitoring page's intent)
 *     3. Search form  - scope selector (any/title/author/isbn/
 *                       publisher/subject) + term input; submits as
 *                       a plain GET so it works WITHOUT JavaScript
 *                       (the page re-renders with the results)
 *     4. Bulk bar     - Phase 10.5: the selection toolbar (Select all,
 *                       the selection count, Import selected). Every
 *                       result card carries a checkbox that references
 *                       THIS form (form="google-books-bulk-form"), so
 *                       the no-JS form collects the checked ids natively.
 *                       With JavaScript the bar is upgraded by
 *                       google-books.js: the selection is remembered
 *                       across result re-renders and the Import runs
 *                       over a server-sent stream with live progress.
 *                       Phase 10.6: a second "Sync from providers" submit
 *                       (formaction=/admin/google-books/sync-bulk) runs
 *                       the same selection, and the status strip gains a
 *                       "Sync all" action for the whole imported set.
 *                       The card checkbox is now open to imported AND
 *                       non-imported records (marked data-gb-in-library);
 *                       the client filters the set per action - import
 *                       takes the non-library ids, sync takes the library
 *                       ones. No-JavaScript submits still work: import
 *                       de-duplicates, sync skips non-imported ids.
 *     5. Progress     - Phase 10.5/10.6: the shared real-time run panel
 *                       (bar + running counts + current book + cancel).
 *                       Its stats are re-labelled per run type (imported
 *                       vs updated, duplicates vs unchanged).
 *     6. Results: admin/google-books/partials/_results.php
 *                       shared verbatim with the live JSON endpoint;
 *                       each result card carries an Import or Sync
 *                       button (Phase 10.3 + 10.6), the "last
 *                       synchronized" status line and the bulk checkbox
 *     7. Summary modal - Phase 10.5/10.6: the final run dialog (import
 *                       report or sync report, switched by the client)
 *
 * Available variables (GoogleBooksController::index()):
 *     $result    - ?ProviderSearchResult (null = nothing searched yet)
 *     $request   - the SearchBooksRequest (form state)
 *     $enabled   - whether the Google Books module is switched on
 *     $breaker   - circuit breaker stats array
 *     $cache     - response cache stats array
 *     $existing  - [google_book_id => local book id] for the cards
 *     $syncInfo  - [google_book_id => {book_id, synced_at, sync_status,
 *                  sync_message}] for the imported cards (Phase 10.6)
 *     $syncEnabled - whether the synchronization feature is on
 *     $config    - safe config values (base_url, display limit, TTLs)
 */

$req       = $request;
$result    = $result;
$breaker   = $breaker;
$cache     = $cache;
$config    = $config;
$searchTtl = (int) ($config['search_ttl_seconds'] ?? 900);

$typeLabels = [
    'any'       => 'Everything',
    'title'     => 'Title',
    'author'    => 'Author',
    'isbn'      => 'ISBN',
    'publisher' => 'Publisher',
    'subject'   => 'Subject',
];

$selected = fn (string $value): string => $req->type() === $value ? ' selected' : '';

$breakerState = (string) ($breaker['state'] ?? 'closed');
$breakerTone  = ['open' => 'danger', 'half-open' => 'warning', 'closed' => 'success'][$breakerState] ?? 'secondary';

$cacheStats = $cache['namespaces']['search'] ?? [];
$cacheFiles = (int) ($cacheStats['files'] ?? 0);
$cacheStale = (int) ($cacheStats['stale'] ?? 0);

?>
<div class="page-intro">
    <p class="eyebrow">Administration &middot; Google Books</p>
    <h1>Google Books Search</h1>
    <p class="lead">
        Search the Google Books catalogue directly - results come from the provider, not the
        local library. The <strong>Import</strong> button on a result brings that book into
        your catalogue instantly, or <strong>tick several results</strong> and import them
        all at once.
    </p>
</div>

<!-- 1. Status strip --------------------------------------------------- -->
<div class="d-flex flex-wrap align-items-center gap-2 mb-3" data-animate>
    <span class="badge <?= $enabled ? 'text-bg-success' : 'text-bg-secondary' ?>">
        <i class="fa-solid fa-plug me-1" aria-hidden="true"></i>
        Provider <?= $enabled ? 'enabled' : 'disabled' ?>
    </span>
    <span class="badge text-bg-light" title="Google Books API endpoint">
        <i class="fa-solid fa-globe me-1" aria-hidden="true"></i>
        <?= e((string) $config['base_url']) ?>
    </span>
    <span class="badge text-bg-light" title="How long a search result set stays cached">
        <i class="fa-solid fa-clock me-1" aria-hidden="true"></i>
        Cache <?= (int) ($searchTtl / 60) ?> min &middot; <?= $cacheFiles ?> entries<?= $cacheStale > 0 ? ' &middot; ' . $cacheStale . ' stale' : '' ?>
    </span>
    <span class="badge text-bg-<?= e($breakerTone) ?>" title="Circuit breaker state (cache-only mode when open)">
        <i class="fa-solid fa-heart-circle-bolt me-1" aria-hidden="true"></i>
        Breaker <?= e($breakerState) ?><?= $breakerState !== 'closed' ? ' &middot; ' . (int) ($breaker['failures'] ?? 0) . '/' . (int) ($breaker['max_failures'] ?? 3) . ' failures' : '' ?>
    </span>
    <span class="badge <?= $syncEnabled ? 'text-bg-info' : 'text-bg-secondary' ?>" title="Google Books metadata synchronization (Phase 10.6)">
        <i class="fa-solid fa-rotate me-1" aria-hidden="true"></i>
        Sync <?= $syncEnabled ? 'on' : 'off' ?><?= $syncEnabled ? ' &middot; max ' . (int) ($config['bulk']['max_batch'] ?? 200) . ' per run' : '' ?>
    </span>
</div>

<?php if (!$enabled): ?>
    <?php $alert = ['type' => 'info', 'message' => 'The Google Books provider is disabled. Set GOOGLE_BOOKS_ENABLED=true in .env to turn search on (an API key in GOOGLE_BOOKS_API_KEY is optional but recommended).']; ?>
    <?php require root_path('app/Views/components/alert.php'); ?>
<?php endif; ?>

<!-- 2. Search form ----------------------------------------------------- -->
<form class="card-base google-books-toolbar mb-3" method="get" action="/admin/google-books" role="search"
      data-gb-form data-search-endpoint="/admin/google-books/search">
    <div class="row g-2 align-items-end">
        <div class="col-sm-4 col-lg-3">
            <label class="form-label" for="gb-type">Search by</label>
            <select class="form-select" id="gb-type" name="type" data-gb-type>
                <?php foreach ($typeLabels as $typeKey => $label): ?>
                    <option value="<?= e($typeKey) ?>"<?= $selected($typeKey) ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-8 col-lg-7">
            <label class="form-label" for="gb-q">Search term</label>
            <div class="gb-search-wrap">
                <i class="fa-solid fa-magnifying-glass gb-search-icon" aria-hidden="true"></i>
                <input class="form-control gb-search-input" type="search" id="gb-q" name="q"
                       data-gb-search-input placeholder="e.g. Harry Potter, J.K. Rowling, 9780439064873..."
                       value="<?= e($req->query()) ?>" autocomplete="off" maxlength="100">
            </div>
            <p class="form-text mb-0" id="gb-q-hint" data-gb-hint hidden></p>
        </div>
        <div class="col-auto">
            <button class="btn btn-primary" type="submit">
                <i class="fa-solid fa-magnifying-glass me-1" aria-hidden="true"></i>Search
            </button>
        </div>
        <div class="col-auto">
            <a class="btn btn-outline-secondary" href="/admin/google-books" title="Clear the search">
                <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                <span class="visually-hidden">Clear the search</span>
            </a>
        </div>
    </div>
</form>

<div class="visually-hidden" role="status" data-gb-status aria-live="polite"></div>

<!-- 3. Bulk selection + sync bar (Phase 10.5 + 10.6) ---------------- -->
<!-- One form collects every checked card (form="google-books-bulk-form"
     on the card checkboxes - the notification center's pattern). With
     JavaScript the google-books.js selection remembers the set across
     result re-renders and the submit streams live progress.
     Phase 10.6: the checkboxes are open to imported AND non-imported
     records; "Import selected" sends the non-library ids to
     /bulk-import, "Sync providers" (an HTML formaction on the SAME
     form) sends the library ids to /sync-bulk. Without JavaScript
     both are plain POSTs - import de-duplicates already-imported ids
     and sync skips the not-imported ones, so nothing is ever wrong. -->
<form method="post" action="/admin/google-books/bulk-import" id="google-books-bulk-form"
      class="gb-bulk" data-gb-bulk-form>
    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
    <label class="gb-bulk-select-all" data-gb-bulk-select-all-wrap>
        <input type="checkbox" class="form-check-input" data-gb-select-all aria-label="Select all books on this page">
        <span>Select all</span>
    </label>
    <span class="gb-bulk-count" data-gb-bulk-count role="status">0 selected</span>
    <button type="submit" class="btn btn-primary btn-sm gb-bulk-import" data-gb-bulk-button disabled>
        <i class="fa-solid fa-layer-group me-1" aria-hidden="true"></i>Import selected
    </button>
    <button type="submit" class="btn btn-outline-primary btn-sm gb-bulk-sync" data-gb-bulk-sync disabled
            formaction="/admin/google-books/sync-bulk"
            title="Refresh the imported records in your selection from Google Books">
        <i class="fa-solid fa-rotate me-1" aria-hidden="true"></i>Sync providers
    </button>
</form>

<!-- Phase 10.6: the Sync All action - a tiny form of its own so the
     no-JavaScript branch gets a plain POST -> flash + redirect. The
     confirmation dialog is progressive enhancement; without JS the
     button is just click-and-go. -->
<form method="post" action="/admin/google-books/sync-all" class="gb-sync-all" data-gb-sync-all-form>
    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
    <button type="submit" class="btn btn-outline-info btn-sm" data-gb-sync-all>
        <i class="fa-solid fa-arrows-rotate me-1" aria-hidden="true"></i>Sync all imported books
    </button>
    <span class="gb-sync-all-note">Refresh every imported record's metadata from Google Books</span>
</form>

<!-- 4. Real-time run progress (Phase 10.5 import + 10.6 sync) --------- -->
<section class="gb-progress card-base" data-gb-progress hidden aria-label="Import or sync progress">
    <div class="gb-progress-head">
        <h2 class="h5 mb-0" data-gb-progress-title>Importing books…</h2>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-gb-progress-cancel>
            <i class="fa-solid fa-ban me-1" aria-hidden="true"></i>Cancel
        </button>
    </div>
    <div class="progress gb-progress-track" role="progressbar"
         aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-gb-progress-track>
        <div class="progress-bar progress-bar-striped progress-bar-animated" data-gb-progress-bar style="width: 0%"></div>
    </div>
    <div class="gb-progress-count" data-gb-progress-count aria-live="polite">0 of 0 books</div>
    <p class="gb-progress-current" data-gb-progress-current></p>
    <div class="gb-progress-stats">
        <span class="gb-stat gb-stat--imported">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <strong data-gb-progress-imported>0</strong> <span data-gb-stat-imported-label>imported</span>
        </span>
        <span class="gb-stat gb-stat--dup">
            <i class="fa-solid fa-copy" aria-hidden="true"></i>
            <strong data-gb-progress-duplicates>0</strong> <span data-gb-stat-duplicates-label>duplicates</span>
        </span>
        <span class="gb-stat gb-stat--fail">
            <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
            <strong data-gb-progress-failed>0</strong> failed
        </span>
        <span class="gb-stat gb-stat--remaining">
            <i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>
            <strong data-gb-progress-remaining>0</strong> remaining
        </span>
    </div>
</section>

<!-- 5. Results --------------------------------------------------------- -->
<?php require root_path('app/Views/admin/google-books/partials/_results.php'); ?>

<!-- 6. The import/sync summary dialog (Phase 10.5 + 10.6) ----------------- -->
<?php require root_path('app/Views/admin/google-books/partials/_summary-modal.php'); ?>

<!-- 7. The Sync All confirmation (Phase 10.6, progressive enhancement) ------ -->
<div class="modal fade" id="gbSyncAllModal" tabindex="-1" role="dialog" aria-hidden="true" data-gb-sync-all-modal>
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5">Sync all imported books?</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>
                    Every imported Google Books record will be checked against the provider and refreshed
                    where it changed. Only provider-owned metadata and covers are touched - your library's
                    status, ratings, reviews and user data are never overwritten.
                </p>
                <p class="mb-0">
                    The run streams live progress and you can cancel it at any time. Books already up to
                    date are answered with <strong>zero</strong> writes.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Not now</button>
                <button type="button" class="btn btn-primary" data-gb-sync-all-confirm>
                    <i class="fa-solid fa-arrows-rotate me-1" aria-hidden="true"></i>Sync everything
                </button>
            </div>
        </div>
    </div>
</div>
