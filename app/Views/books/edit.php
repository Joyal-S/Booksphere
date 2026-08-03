<?php

declare(strict_types=1);

/**
 * books/edit.php
 *
 * The "Edit book" page: renders the shared book form in edit
 * mode, prefilled from the database. On validation failure the
 * controller re-renders this view with $errors and the submitted
 * input in $old (so nothing the admin typed is lost).
 */

?>
<div class="page-intro">
    <p class="eyebrow">Administration</p>
    <h1>Edit Book</h1>
    <p class="lead">Update the details of &ldquo;<?= e($book['title']) ?>&rdquo;.</p>
</div>

<?php $isEdit = true; ?>
<?php require root_path('app/Views/books/partials/_form.php'); ?>
