<?php

declare(strict_types=1);

/**
 * library/partials/_continue-card.php
 *
 * The CONTINUE READING card of the dashboard's Phase 8.2 shelf: one
 * card per book the user is currently reading. It shows everything
 * the "resume your reading" workflow needs at a glance:
 *
 *     - the book cover (the shared book-cover component, 16:9 strip)
 *     - the title and the authors
 *     - the reading progress bar with its percentage
 *     - a Resume button that opens the book's detail page (the same
 *       page that hosts the update-progress panel)
 *
 * The card is presentation only - every value was read through the
 * shared LibraryService, and the "Resume" link lands on the book
 * detail page where the progress / status / favourite controls live
 * (no duplicate SQL or logic here).
 *
 * Included from a view that sets $record (a LibraryRepository row with
 * the book display columns attached - the currentlyReading() payload):
 *
 *     $record = [
 *         'id'                  => 12,            // library record id
 *         'book_id'             => 7,
 *         'library_status'      => 'currently_reading',
 *         'progress_percentage' => 40,
 *         'book_title'          => 'The Hobbit',
 *         'book_authors'        => 'J.R.R. Tolkien',
 *         'book_cover'          => 'https://...',
 *     ];
 */

$record   = $record ?? [];
$bookId   = (int) ($record['book_id'] ?? 0);
$title    = (string) ($record['book_title'] ?? 'Book');
$authors  = (string) ($record['book_authors'] ?? '');
$progress = max(0, min(100, (int) ($record['progress_percentage'] ?? 0)));

?>
<div class="continue-card">
    <a class="continue-cover" href="/books/<?= $bookId ?>" title="Resume <?= e($title) ?>">
        <?php $cover = [
            'src'   => (string) ($record['book_cover'] ?? ''),
            'alt'   => 'Cover of ' . $title,
            'class' => 'continue-cover-img',
        ]; ?>
        <?php require root_path('app/Views/books/components/book-cover.php'); ?>
    </a>

    <div class="continue-body">
        <h3 class="continue-title">
            <a href="/books/<?= $bookId ?>"><?= e($title) ?></a>
        </h3>
        <?php if ($authors !== ''): ?>
            <p class="continue-authors"><?= e($authors) ?></p>
        <?php endif; ?>

        <div class="continue-progress">
            <div class="progress library-progress"
                 role="progressbar"
                 aria-label="Reading progress of <?= e($title) ?>"
                 aria-valuenow="<?= $progress ?>"
                 aria-valuemin="0"
                 aria-valuemax="100">
                <div class="progress-bar" style="width: <?= $progress ?>%"></div>
            </div>
            <div class="continue-progress-meta">
                <span><?= $progress ?>% complete</span>
                <a class="btn btn-sm btn-primary continue-resume" href="/books/<?= $bookId ?>">
                    <i class="fa-solid fa-book-open-reader me-1" aria-hidden="true"></i>Resume
                </a>
            </div>
        </div>
    </div>
</div>