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
 */

$book     = $gbBook;
$existing = (array) ($existing ?? []);

$inLibrary = isset($existing[(string) $book->externalId]);

$hasCover  = $book->thumbnail !== null && $book->thumbnail !== '';
$year      = $book->publishedYear !== null ? (string) $book->publishedYear : ($book->publishedDate ?? '');
$subtitle  = $book->subtitle !== null ? $book->subtitle : '';
$blurb     = $book->description !== null ? $book->description : '';
$detailUrl = (string) ($book->previewLink ?? $book->infoLink ?? '#');

?>
<article class="gb-card">
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

            <?php if ($book->previewLink !== null): ?>
                <a class="btn btn-sm btn-primary" href="<?= e($book->previewLink) ?>" target="_blank" rel="noopener noreferrer">
                    <i class="fa-solid fa-book-open me-1" aria-hidden="true"></i>Preview
                </a>
            <?php endif; ?>

            <a class="btn btn-sm btn-outline-secondary" href="<?= e($book->infoLink ?? $detailUrl) ?>" target="_blank" rel="noopener noreferrer">
                <i class="fa-solid fa-up-right-from-square me-1" aria-hidden="true"></i>Google Books
            </a>
        </div>

        <div class="gb-card-feedback" data-gb-feedback aria-live="polite"></div>
    </div>
</article>