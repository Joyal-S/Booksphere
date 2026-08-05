<?php

declare(strict_types=1);

/**
 * library/partials/_continue-grid.php
 *
 * The CONTINUE READING shelf fragment of the library dashboard (Phase
 * 8.3) - the SHARED partial behind both rendering paths:
 *
 *     - the no-JS page: library/index.php includes this fragment with
 *       the server-rendered shelf
 *     - the live refresh: GET /library/continue-reading ships this
 *       exact fragment (View::fragment), which library.js swaps into
 *       the same region after a write that may have moved a book off
 *       the shelf
 *
 * Because both paths render the same file, the shelf can never drift.
 * Included from a view that sets $continue (the continueReading()
 * rows) and $statusLabels (for the resume cards).
 */

$continue     = $continue ?? [];
$statusLabels = $statusLabels ?? [];

?>
<?php if ($continue === []): ?>
    <div class="card-base p-4 text-center text-muted" data-library-continue-empty>
        <i class="fa-solid fa-book-open fa-lg me-2" aria-hidden="true"></i>
        You are not reading anything right now.
        <a class="btn btn-sm btn-primary ms-2" href="/books">Browse books</a>
    </div>
<?php else: ?>
    <div class="row g-3 g-xl-4 row-cols-1 row-cols-sm-2 row-cols-xl-3 row-cols-xxl-4">
        <?php foreach ($continue as $record): ?>
            <div class="col"><?php require root_path('app/Views/library/partials/_continue-card.php'); ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>