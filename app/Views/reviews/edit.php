<?php

declare(strict_types=1);

/**
 * reviews/edit.php
 *
 * The "Edit Review" page: the shared review form in edit mode,
 * prefilled from the stored row. On validation failure the
 * controller re-renders this view with $errors and the previous
 * input in $old.
 *
 * Available variables (from ReviewController::edit/update):
 *     $book   - the reviewed book row (for the header)
 *     $review - the stored review row
 *     $old    - the values to display
 *     $errors - field -> error messages
 */

$isEdited = (int) ($review['is_edited'] ?? 0) === 1;
?>
<div class="page-intro">
    <p class="eyebrow">Reviews &middot; Edit</p>
    <h1>Edit Your Review</h1>
    <p class="lead">
        <?= e($book['title'] ?? 'Book') ?> &mdash;
        <?= e(number_format((float) ($review['rating'] ?? 0), 0)) ?> star<?= (int) ($review['rating'] ?? 0) === 1 ? '' : 's' ?>
        <?php if ($isEdited): ?>
            &middot; <span class="text-muted">Edited</span>
        <?php endif; ?>
    </p>
</div>

<?php $action = '/reviews/' . (int) $review['id'] . '/edit'; ?>
<?php $submitLabel = 'Save changes'; ?>
<?php require root_path('app/Views/reviews/_form.php'); ?>
