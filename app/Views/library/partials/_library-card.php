<?php

declare(strict_types=1);

/**
 * library/partials/_library-card.php
 *
 * The LIBRARY CARD - the reusable unit of the "My Library" page
 * (Phase 8.2). One card renders one library record (a user_library
 * row joined with its book display columns) and shows everything the
 * brief asks a library entry to display:
 *
 *     - book cover (the shared book-cover component)
 *     - title, author(s), category
 *     - reading progress bar with its percentage
 *     - the status badge
 *     - the average rating (the stored catalogue rating)
 *     - the favourite heart (fetch toggle, repaints in place)
 *     - a status select (fetch update via POST /library/{id})
 *     - a progress slider (fetch update via POST /library/{id}/progress)
 *     - View Details and Remove buttons
 *
 * The card is a progressive enhancement: every interactive control
 * is a real <form> that posts with the CSRF token, so with no
 * JavaScript the page still works (the no-JS fallbacks redirect with
 * a flash). library.js upgrades the forms to fetch calls and swaps
 * the DOM in place.
 *
 * The status select and the favourite toggle work on the RECORD id
 * (the route is /library/{id}), while the links target the book.
 *
 * Included from a view that sets $record (a LibraryRepository row)
 * and $statusLabels (status key -> display label):
 *
 *     $record = [
 *         'id'                  => 12,          // the library record id
 *         'book_id'             => 7,
 *         'library_status'      => 'currently_reading',
 *         'is_favorite'         => 1,
 *         'progress_percentage' => 40,
 *         'book_title'          => 'The Hobbit',
 *         'book_authors'        => 'J.R.R. Tolkien',
 *         'book_categories'     => 'Fantasy, Adventure',
 *         'book_cover'          => 'https://...',
 *         'book_average_rating' => 4.5,
 *         'book_ratings_count'  => 12,
 *     ];
 */

$statusLabels = $statusLabels ?? [];
$recommended  = $recommended ?? [];
$record       = $record ?? [];

$recordId   = (int) ($record['id'] ?? 0);
$bookId     = (int) ($record['book_id'] ?? 0);
$status     = (string) ($record['library_status'] ?? 'want_to_read');
$favorite   = (int) ($record['is_favorite'] ?? 0) === 1;
$progress   = max(0, min(100, (int) ($record['progress_percentage'] ?? 0)));
$title      = (string) ($record['book_title'] ?? 'Book');
$authors    = (string) ($record['book_authors'] ?? '');
$categories = (string) ($record['book_categories'] ?? '');
$isRecommended = isset($recommended[$bookId]);

// The last-touched stamp (a status / progress / favourite change
// refreshes updated_at) - rendered as a short date when present.
$updatedAt  = (string) ($record['updated_at'] ?? '');
$updatedOn  = $updatedAt !== '' ? gmdate('M j', max(0, (int) strtotime($updatedAt))) : '';

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
<div class="library-card" data-library-card data-record-id="<?= $recordId ?>">
    <label class="library-select-label" data-library-select-label title="Select <?= e($title) ?>">
        <input type="checkbox" class="form-check-input library-select-input" form="library-bulk-form"
               name="ids[]" value="<?= $recordId ?>" data-library-select-input
               aria-label="Select <?= e($title) ?>">
    </label>

    <div class="library-card-cover">
        <a class="library-card-cover-link" href="/books/<?= $bookId ?>" title="View <?= e($title) ?>">
            <?php $cover = [
                'src'   => (string) ($record['book_cover'] ?? ''),
                'alt'   => 'Cover of ' . $title,
                'class' => 'library-card-cover-img',
            ]; ?>
            <?php require root_path('app/Views/books/components/book-cover.php'); ?>
        </a>
        <?php if ($isRecommended): ?>
            <span class="library-recommended-badge" title="Recommended for you">
                <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i>Recommended
            </span>
        <?php endif; ?>
    </div>

    <div class="library-card-body">
        <div class="library-card-head">
            <div class="library-card-titles">
                <h3 class="library-card-title">
                    <a href="/books/<?= $bookId ?>"><?= e($title) ?></a>
                </h3>
                <?php if ($authors !== ''): ?>
                    <p class="library-card-authors"><?= e($authors) ?></p>
                <?php endif; ?>
            </div>

            <div class="library-card-actions-head">
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

        <?php if ($categories !== ''): ?>
            <p class="library-card-cats">
                <i class="fa-solid fa-tags" aria-hidden="true"></i><?= e($categories) ?>
            </p>
        <?php endif; ?>

        <?php if ($updatedOn !== ''): ?>
            <p class="library-card-updated">
                <i class="fa-regular fa-clock" aria-hidden="true"></i>Updated <?= e($updatedOn) ?>
            </p>
        <?php endif; ?>

        <div class="library-card-strip">
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
            <?php if ($status === 'currently_reading'): ?>
                <a class="btn btn-sm btn-primary library-card-resume" href="/books/<?= $bookId ?>">
                    <i class="fa-solid fa-book-open-reader me-1" aria-hidden="true"></i>Resume
                </a>
            <?php endif; ?>
        </div>

        <div class="library-card-progress" data-library-progress>
            <div class="progress library-progress"
                 role="progressbar"
                 aria-label="Reading progress of <?= e($title) ?>"
                 aria-valuenow="<?= $progress ?>"
                 aria-valuemin="0"
                 aria-valuemax="100">
                <div class="progress-bar" data-library-progress-bar style="width: <?= $progress ?>%"></div>
            </div>
            <span class="library-progress-value" data-library-progress-value><?= $progress ?>%</span>
        </div>

        <form method="post" action="/library/<?= $recordId ?>/progress" data-library-progress-form>
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <label class="visually-hidden" for="library-progress-<?= $recordId ?>">Update reading progress of <?= e($title) ?></label>
            <input class="library-progress-input" type="range" id="library-progress-<?= $recordId ?>"
                   name="progress" min="0" max="100" step="1" value="<?= $progress ?>"
                   data-library-progress-input aria-label="Update reading progress of <?= e($title) ?>">
            <button type="submit" class="btn btn-sm btn-outline-secondary library-progress-save">
                <i class="fa-solid fa-check me-1" aria-hidden="true"></i>Save progress
            </button>
        </form>

        <div class="library-card-actions">
            <form method="post" action="/library/<?= $recordId ?>" data-library-status-form>
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <label class="visually-hidden" for="library-status-<?= $recordId ?>">Change status of <?= e($title) ?></label>
                <select class="form-select form-select-sm library-status-select" id="library-status-<?= $recordId ?>"
                        name="status" data-library-status-select>
                    <?php foreach ($statusLabels as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $status === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <a class="btn btn-sm btn-outline-secondary" href="/books/<?= $bookId ?>">
                <i class="fa-solid fa-eye me-1" aria-hidden="true"></i>Details
            </a>
            <!-- A real CSRF-protected form, so the removal works with
                 JavaScript disabled (native POST + flash redirect).
                 library.js opens the shared confirmation modal from it
                 when scripts are running (bindInlineDeleteForms) -->
            <form method="post" action="/library/<?= $recordId ?>/delete" data-library-delete-form>
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"
                        data-delete-url="/library/<?= $recordId ?>/delete" data-delete-title="<?= e($title) ?>">
                    <i class="fa-solid fa-trash" aria-hidden="true"></i>
                    <span class="visually-hidden">Remove <?= e($title) ?> from your library</span>
                </button>
            </form>
        </div>
    </div>
</div>
