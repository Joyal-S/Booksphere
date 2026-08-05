<?php

declare(strict_types=1);

/**
 * library/partials/_library-row.php
 *
 * The LIBRARY ROW - the list-view counterpart of the library card
 * (Phase 8.3): the same record rendered as a dense horizontal row
 * (cover thumbnail left, the reading facts in the middle, the
 * actions right) so the dashboard can switch between the card grid
 * and an at-a-glance list. Shows:
 *
 *     - the cover (shared book-cover component, compact thumbnail)
 *     - title, author(s), category
 *     - the status badge, the average rating and the progress bar
 *     - the favourite heart (fetch toggle) and the view / remove
 *       actions (the progress/status editors stay on the grid card
 *       and the book detail page - the row is an overview)
 *
 * Progressive enhancement matches the card: every control is a real
 * CSRF-protected <form> that works without JavaScript; library.js
 * upgrades them (the delegated submit handlers already match
 * [data-library-*] controls, so a row behaves exactly like a card).
 *
 * Included from a view that sets $record (a LibraryRepository row),
 * $statusLabels (status key -> display label) and $recommended (the
 * book-id set of the recommendation badge - may be empty).
 */

$statusLabels = $statusLabels ?? [];
$recommended  = $recommended ?? [];
$record       = $record ?? [];

$recordId = (int) ($record['id'] ?? 0);
$bookId   = (int) ($record['book_id'] ?? 0);
$status   = (string) ($record['library_status'] ?? 'want_to_read');
$favorite = (int) ($record['is_favorite'] ?? 0) === 1;
$progress = max(0, min(100, (int) ($record['progress_percentage'] ?? 0)));
$title    = (string) ($record['book_title'] ?? 'Book');
$authors  = (string) ($record['book_authors'] ?? '');
$isRecommended = isset($recommended[$bookId]);

// The Phase 8.4 quick-menu status icons (mirrors the shelf icons of
// the collections rail).
$statusIcons = [
    'want_to_read'      => 'fa-bookmark',
    'currently_reading' => 'fa-book-open-reader',
    'finished'          => 'fa-circle-check',
    'on_hold'           => 'fa-pause',
    'dropped'           => 'fa-ban',
];

?>
<div class="library-row" data-library-card data-record-id="<?= $recordId ?>">
    <label class="library-select-label" data-library-select-label title="Select <?= e($title) ?>">
        <input type="checkbox" class="form-check-input library-select-input" form="library-bulk-form"
               name="ids[]" value="<?= $recordId ?>" data-library-select-input
               aria-label="Select <?= e($title) ?>">
    </label>

    <a class="library-row-cover" href="/books/<?= $bookId ?>" title="View <?= e($title) ?>">
        <?php $cover = [
            'src'   => (string) ($record['book_cover'] ?? ''),
            'alt'   => 'Cover of ' . $title,
            'class' => 'library-row-cover-img',
        ]; ?>
        <?php require root_path('app/Views/books/components/book-cover.php'); ?>
    </a>

    <div class="library-row-body">
        <div class="library-row-titles">
            <h3 class="library-row-title">
                <a href="/books/<?= $bookId ?>"><?= e($title) ?></a>
                <?php if ($isRecommended): ?>
                    <span class="library-recommended-badge" title="Recommended for you">
                        <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i>Recommended
                    </span>
                <?php endif; ?>
            </h3>
            <?php if ($authors !== ''): ?>
                <p class="library-row-authors"><?= e($authors) ?></p>
            <?php endif; ?>
        </div>

        <div class="library-row-facts">
            <span class="status-badge status-<?= e($status) ?>">
                <?= e($statusLabels[$status] ?? $status) ?>
            </span>
            <?php $starRating = [
                'rating' => (float) ($record['book_average_rating'] ?? 0),
                'count'  => (int) ($record['book_ratings_count'] ?? 0) > 0 ? (int) $record['book_ratings_count'] : null,
                'size'   => 'sm',
                'tooltip'=> false,
            ]; ?>
            <?php require root_path('app/Views/components/star-rating.php'); ?>
            <span class="library-review-count" title="Reviews on BookSphere">
                <i class="fa-solid fa-comment-dots" aria-hidden="true"></i>
                <?= (int) ($record['book_review_count'] ?? 0) ?>
            </span>
            <span class="library-row-progress">
                <span class="progress library-progress" role="progressbar"
                      aria-label="Reading progress of <?= e($title) ?>"
                      aria-valuenow="<?= $progress ?>" aria-valuemin="0" aria-valuemax="100">
                    <span class="progress-bar" data-library-progress-bar style="width: <?= $progress ?>%"></span>
                </span>
                <span class="library-progress-value" data-library-progress-value><?= $progress ?>%</span>
            </span>
        </div>
    </div>

    <div class="library-row-actions">
        <form method="post" action="/library/<?= $recordId ?>/favorite" data-library-favorite-form>
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <button type="submit"
                    class="library-fav-btn<?= $favorite ? ' is-favorite' : '' ?>"
                    aria-pressed="<?= $favorite ? 'true' : 'false' ?>"
                    aria-label="<?= $favorite ? 'Remove ' . e($title) . ' from your favourites' : 'Add ' . e($title) . ' to your favourites' ?>"
                    title="<?= $favorite ? 'In your favourites' : 'Add to favourites' ?>">
                <i class="fa-solid fa-heart" aria-hidden="true"></i>
            </button>
        </form>
        <a class="btn btn-sm btn-outline-secondary" href="/books/<?= $bookId ?>">
            <i class="fa-solid fa-eye me-1" aria-hidden="true"></i>Details
        </a>
        <button class="btn btn-sm btn-outline-danger" type="button"
                data-bs-toggle="modal" data-bs-target="#libraryDeleteModal"
                data-delete-url="/library/<?= $recordId ?>/delete"
                data-delete-title="<?= e($title) ?>">
            <i class="fa-solid fa-trash" aria-hidden="true"></i>
            <span class="visually-hidden">Remove <?= e($title) ?> from your library</span>
        </button>

        <!-- The Phase 8.4 quick action menu -->
        <div class="dropdown library-quick">
            <button class="btn btn-sm library-quick-toggle" type="button" data-bs-toggle="dropdown"
                    aria-expanded="false" aria-label="Actions for <?= e($title) ?>">
                <i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end library-quick-menu" data-library-quick-menu>
                <li><a class="dropdown-item" href="/books/<?= $bookId ?>">
                    <i class="fa-solid fa-eye me-2" aria-hidden="true"></i>View Details</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><h6 class="dropdown-header">Move to</h6></li>
                <?php foreach ($statusLabels as $key => $label): ?>
                    <li>
                        <button class="dropdown-item<?= $status === $key ? ' is-current' : '' ?>" type="button"
                                data-quick-status="<?= e($key) ?>">
                            <i class="fa-solid <?= e($statusIcons[$key] ?? 'fa-bookmark') ?> me-2" aria-hidden="true"></i>
                            <?= e($label) ?>
                            <?php if ($status === $key): ?>
                                <i class="fa-solid fa-check quick-current-check" aria-hidden="true"></i>
                            <?php endif; ?>
                        </button>
                    </li>
                <?php endforeach; ?>
                <li><hr class="dropdown-divider"></li>
                <li><button class="dropdown-item" type="button" data-quick-favorite="<?= $favorite ? '0' : '1' ?>">
                    <i class="fa-solid fa-heart me-2" aria-hidden="true"></i>
                    <span data-quick-fav-label><?= $favorite ? 'Un-favourite' : 'Mark as Favourite' ?></span></button></li>
                <li><button class="dropdown-item" type="button" data-quick-share>
                    <i class="fa-solid fa-share-nodes me-2" aria-hidden="true"></i>Share
                    <span class="text-muted small">soon</span></button></li>
                <li><hr class="dropdown-divider"></li>
                <li><button class="dropdown-item text-danger" type="button" data-quick-remove
                            data-delete-url="/library/<?= $recordId ?>/delete" data-delete-title="<?= e($title) ?>">
                    <i class="fa-solid fa-trash me-2" aria-hidden="true"></i>Remove from Library</button></li>
            </ul>
        </div>
    </div>
</div>