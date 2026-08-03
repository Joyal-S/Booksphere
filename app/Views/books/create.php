<?php

declare(strict_types=1);

/**
 * books/create.php
 *
 * The "Add book" page: renders the shared book form in create
 * mode. On validation failure the controller re-renders this view
 * with $errors and the previous input in $old.
 */

?>
<div class="page-intro">
    <p class="eyebrow">Administration</p>
    <h1>Add Book</h1>
    <p class="lead">Add a new book to the catalogue.</p>
</div>

<?php $isEdit = false; ?>
<?php require root_path('app/Views/books/partials/_form.php'); ?>
