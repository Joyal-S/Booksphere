<?php

declare(strict_types=1);

/**
 * admin/google-books/partials/_card.php
 *
 * ONE Google Books result card. Public late-bound variables:
 *     $gbBook   - ProviderBookDTO
 *     $existing - [google_book_id => local book id] map for the whole
 *                 page (Phase 10.3), so an already-imported record
 *                 renders "In library" instead of "Import"
 *
 * The card is a PROVIDER record until the admin imports it: the title
 * links to the Google Books detail page (previewLink / infoLink) and
 * the Import button carries the volume id to /admin/google-books/import
 * - a POST form with its own CSRF token, so it works with JavaScript
 * (intercepted by google-books.js) AND without it (plain submit ->
 * redirect + flash). Fields are escaped with e() because they arrive
 * from a third-party API.
 *
 * Phase 10.5 (bulk import): every card carries a checkbox that belongs
 * to the page's bulk form via the form="google-books-bulk-form"
 * attribute - the notification center's pattern - so the no-JavaScript
 * form natively collects the checked ids.
 *
 * Phase 10.6 (synchronization): the checkbox is open to imported rows
 * TOO (flagged data-gb-in-library) - the "Sync providers" bulk submit
 * targets the library subset of the selection. An imported card also
 * gains a "Sync now" affordance: the last-sync status chip comes from
 * $syncInfo and the button posts its own form to /admin/google-books/sync,
 * the same dual answer as every other data change.
 */

$book     = $gbBook;
$existing = (array) ($existing ?? []);
$syncInfo = (array) ($syncInfo ?? []);

$inLibrary = isset($existing[(string) $book->externalId]);

$syncState  = (array) ($syncInfo[(string) $book->externalId] ?? []);
$syncStatus = (string) ($syncState['sync_status'] ?? 'pending');
$syncedAt   = $syncState['synced_at'] ?? null;
$syncTone   = match ($syncStatus) {
    'in_sync'                                                      => 'success',
    'updated'                                                      => 'info',
    'failed'                                                       => 'danger',
    default                                                        => 'secondary',
};
$syncLabel = match ($syncStatus) {
    'in_sync'  => 'In sync'  . ($syncedAt !== null ? ' &middot; ' . date('M j, Y H:i', strtotime((string) $syncedAt)) : ''),
    'updated'  => 'Updated'  . ($syncedAt !== null ? ' &middot; ' . date('M j, Y H:i', strtotime((string) $syncedAt)) : ''),
    'failed'   => 'Last sync failed',
    default    => 'Not synchronized yet',
};

$hasCover  = $book->thumbnail !== null && $book->thumbnail !== '';
$year      = $book->publishedYear !== null ? (string) $book->publishedYear : ($book->publishedDate ?? '');
$subtitle  = $book->subtitle !== null ? $book->subtitle : '';
$blurb     = $book->description !== null ? $book->description : '';
$detailUrl = (string) ($book->previewLink ?? $book->infoLink ?? '#');

?>
<article class="gb-card" data-gb-card>
    <label class="gb-card-select" data-gb-card-select>
        <input type="checkbox"
               class="form-check-input"
               name="google_book_id[]"
               value="<?= e($book->externalId) ?>"
               form="google-books-bulk-form"
               data-gb-check
               data-gb-check-id="<?= e($book->externalId) ?>"
               <?= $inLibrary ? 'data-gb-in-library="true"' : '' ?>>
        <span class="visually-hidden"><?= $inLibrary ? e('Select "' . $book->title . '" for synchronization') : e('Select "' . $book->title . '" for bulk import') ?></span>
    </label>

    <div class="gb-card-cover">
        <?php $cover = [
            'src'   => $hasCover ? $book->thumbnail : '',
            'alt'   => $book->title,
            'class' => 'gb-cover',
        ]; ?>
        <?php require root_path('app/Views/books/components/book-cover.php'); ?>
    </div>

    <div class="gb-card-body">
        <h3 class="gb-card-title">
            <a href="<?= e($detailUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e($book->title) ?></a>
        </h3>

        <?php if ($subtitle !== ''): ?>
            <p class="gb-card-subtitle"><?= e($subtitle) ?></p>
        <?php endif; ?>

        <?php if ($book->authors !== []): ?>
            <p class="gb-card-authors"><?= e($book->authorsList()) ?></p>
        <?php endif; ?>

        <dl class="gb-card-meta">
            <?php if ($year !== ''): ?>
                <div><dt><?= $book->publisher !== null ? 'Published' : 'Year' ?></dt><dd><?= e($year) ?><?= $book->publisher !== null ? ' &middot; ' . e($book->publisher) : '' ?></dd></div>
            <?php endif; ?>

            <?php if ($book->isbn() !== null): ?>
                <div><dt>ISBN</dt><dd><?= e((string) $book->isbn()) ?></dd></div>
            <?php endif; ?>

            <?php if ($book->pageCount !== null): ?>
                <div><dt>Pages</dt><dd><?= (int) $book->pageCount ?></dd></div>
            <?php endif; ?>

            <?php if ($book->language !== null): ?>
                <div><dt>Language</dt><dd><?= e($book->language) ?></dd></div>
            <?php endif; ?>
        </dl>

        <?php if ($blurb !== ''): ?>
            <p class="gb-card-blurb"><?= e($blurb) ?></p>
        <?php endif; ?>

        <?php if ($book->averageRating !== null): ?>
            <?php $starRating = [
                'rating'   => (float) $book->averageRating,
                'readOnly' => true,
                'size'     => 'sm',
                'count'    => $book->ratingsCount,
                'tooltip'  => false,
            ]; ?>
            <?php require root_path('app/Views/components/star-rating.php'); ?>
        <?php endif; ?>

        <div class="gb-card-actions">
            <form class="gb-import-form" method="post" action="/admin/google-books/import" data-gb-import-form>
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="google_book_id" value="<?= e($book->externalId) ?>">
                <button type="submit"
                        class="btn btn-sm <?= $inLibrary ? 'btn-outline-secondary' : 'btn-success' ?>"
                        data-gb-import
                        data-gb-import-id="<?= e($book->externalId) ?>"
                        <?= $inLibrary ? 'disabled' : '' ?>>
                    <i class="fa-solid <?= $inLibrary ? 'fa-circle-check' : 'fa-download' ?> me-1" aria-hidden="true"></i>
                    <span data-gb-import-label><?= $inLibrary ? 'In library' : 'Import' ?></span>
                </button>
            </form>

            <?php if ($inLibrary): ?>
                <form class="gb-sync-form" method="post" action="/admin/google-books/sync" data-gb-sync-form>
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="google_book_id" value="<?= e($book->externalId) ?>">
                    <button type="submit" class="btn btn-sm btn-outline-info" data-gb-sync
                            data-gb-sync-id="<?= e($book->externalId) ?>"
                            title="Refresh this book's metadata from Google Books">
                        <i class="fa-solid fa-rotate me-1" aria-hidden="true"></i>Sync now
                    </button>
                </form>
            <?php endif; ?>

            <?php if ($book->previewLink !== null): ?>
                <a class="btn btn-sm btn-primary" href="<?= e($book->previewLink) ?>" target="_blank" rel="noopener noreferrer">
                    <i class="fa-solid fa-book-open me-1" aria-hidden="true"></i>Preview
                </a>
            <?php endif; ?>

            <a class="btn btn-sm btn-outline-secondary" href="<?= e($book->infoLink ?? $detailUrl) ?>" target="_blank" rel="noopener noreferrer">
                <i class="fa-solid fa-up-right-from-square me-1" aria-hidden="true"></i>Google Books
            </a>
        </div>

        <?php if ($inLibrary): ?>
            <p class="gb-card-sync-status" data-gb-sync-status>
                <span class="gb-sync-chip gb-sync-chip--<?= e($syncStatus) ?>" data-gb-sync-chip>
                    <i class="fa-solid <?= $syncStatus === 'failed' ? 'fa-triangle-exclamation' : 'fa-rotate' ?> me-1" aria-hidden="true"></i><?= $syncLabel ?>
                </span>
            </p>
        <?php endif; ?>

        <div class="gb-card-feedback" data-gb-feedback aria-live="polite"></div>
    </div>
</article>