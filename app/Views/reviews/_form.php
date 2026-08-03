<?php

declare(strict_types=1);

/**
 * reviews/_form.php
 *
 * The shared REVIEW FORM: star rating (1-5 select), review title
 * and review body. Included from reviews/edit.php and from the
 * book-facing pages (via partials/_write-section.php), which set:
 *
 *     $action      - the form's POST target (URL)
 *     $submitLabel - the submit button text
 *     $old         - the values to display (['rating', 'title', 'review'])
 *     $errors      - field -> error messages
 *     $backHref    - optional: where the back link goes
 *                    (defaults to /reviews)
 *     $backLabel   - optional: the back link label
 *                    (defaults to "Back to my reviews")
 *
 * The fields reuse the book module's form-input component, so the
 * markup and the error rendering are identical to the rest of the
 * application.
 */

$old       = array_merge(['rating' => '5', 'title' => '', 'review' => ''], $old ?? []);
$errors    = $errors ?? [];
$backHref  = $backHref ?? '/reviews';
$backLabel = $backLabel ?? 'Back to my reviews';
?>
<form method="post" action="<?= e($action) ?>" class="card-base p-4" novalidate>
    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

    <?php $field = [
        'name'     => 'rating',
        'label'    => 'Your rating',
        'type'     => 'select',
        'value'    => $old['rating'],
        'errors'   => $errors,
        'required' => true,
        'options'  => ['5' => '★★★★★ — Excellent', '4' => '★★★★ — Good', '3' => '★★★ — Average', '2' => '★★ — Poor', '1' => '★ — Terrible'],
        'help'     => 'How many stars would you give this book?',
    ]; ?>
    <?php require root_path('app/Views/books/components/form-input.php'); ?>

    <?php $field = [
        'name'      => 'title',
        'label'     => 'Review title',
        'type'      => 'text',
        'value'     => $old['title'],
        'errors'    => $errors,
        'required'  => true,
        'maxlength' => 120,
        'placeholder' => 'e.g. "A modern classic"',
    ]; ?>
    <?php require root_path('app/Views/books/components/form-input.php'); ?>

    <?php $field = [
        'name'      => 'review',
        'label'     => 'Your review',
        'type'      => 'textarea',
        'value'     => $old['review'],
        'errors'    => $errors,
        'required'  => true,
        'min'       => 20,
        'maxlength' => 2000,
        'rows'      => 6,
        'help'      => 'Between 20 and 2000 characters.',
        'placeholder' => 'What did you think about the story, the writing, the ideas...?',
    ]; ?>
    <?php require root_path('app/Views/books/components/form-input.php'); ?>

    <div class="d-flex flex-wrap gap-2 mt-3">
        <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-star me-1" aria-hidden="true"></i><?= e($submitLabel) ?>
        </button>
        <a class="btn btn-outline-secondary" href="<?= e($backHref) ?>">
            <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i><?= e($backLabel) ?>
        </a>
    </div>
</form>
