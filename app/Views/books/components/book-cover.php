<?php

declare(strict_types=1);

/**
 * books/components/book-cover.php
 *
 * The reusable BOOK COVER display. A single place that decides
 * how a cover is rendered everywhere in the book module:
 *
 *     - with an image  -> <img> with lazy loading
 *     - without image  -> a stylised placeholder tile (book icon)
 *
 * Why it exists:
 *     - Every book screen (table row, detail page, delete modal,
 *       cards) shows a cover; this component keeps them identical.
 *     - The placeholder is generated here, so no view has to write
 *       its own "no cover" markup.
 *     - Broken images (e.g. a remote OpenLibrary cover that is
 *       temporarily down) fall back to the placeholder via the
 *       onerror attribute, so the layout never breaks.
 *
 * Usage (a view sets $cover first):
 *
 *     $cover = [
 *         'src'    => $book['cover_image'] ?? '',   // URL or empty
 *         'alt'    => $book['title'],               // accessibility
 *         'class'  => 'table-cover',                // size/shape modifier
 *     ];
 *     <?php require root_path('app/Views/books/components/book-cover.php'); ?>
 *
 * CSS classes available: table-cover (list), book-detail-cover (detail),
 * modal-cover (delete modal), cover-preview (form). The component appends
 * a "book-cover-fallback" tile when no image exists.
 */

$cover = array_merge([
    'src'   => '',
    'alt'   => 'Book cover',
    'class' => '',
], $cover ?? []);

$classAttr = trim('book-cover-component ' . $cover['class']);

?>
<?php if ($cover['src'] !== ''): ?>
    <img class="<?= e($classAttr) ?>" src="<?= e($cover['src']) ?>"
         alt="<?= e($cover['alt']) ?>" loading="lazy"
         onerror="this.classList.add('book-cover-broken');this.classList.remove('book-cover-has-image');"
         data-book-cover>
<?php else: ?>
    <span class="<?= e($classAttr) ?> book-cover-fallback" role="img" aria-label="<?= e($cover['alt']) ?>">
        <i class="fa-solid fa-book" aria-hidden="true"></i>
    </span>
<?php endif; ?>
