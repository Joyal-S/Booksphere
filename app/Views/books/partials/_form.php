<?php

declare(strict_types=1);

/**
 * books/partials/_form.php
 *
 * The shared book form, included by books/create.php and
 * books/edit.php so every field is defined exactly once (DRY).
 * The fields are grouped into four sections, each headed by the
 * reusable form-section component:
 *
 *     Basic Information        title, subtitle, ISBN
 *     Publishing Information   publisher, published year, language, status
 *     Book Details             description, page count, authors, categories
 *     Media                    cover upload + live preview
 *
 * Fields are rendered through the reusable form-input component,
 * so labels, error messages and helper text stay consistent.
 * On the edit page the form is prefilled from $old (built by the
 * controller from the existing book row).
 *
 * Required variables:
 *     $isEdit     - true on the edit page
 *     $book       - the current book row (edit only; null on create)
 *     $old        - the values to display (submitted or database)
 *     $errors     - field -> error messages
 *     $authors    - all authors for the checkboxes
 *     $categories - all categories for the checkboxes
 *     $statuses   - status key -> label
 *     $languages  - language code -> label
 */

$selectedAuthors    = array_map('intval', (array) ($old['author_ids'] ?? []));
$selectedCategories = array_map('intval', (array) ($old['category_ids'] ?? []));
$formAction         = $isEdit ? '/books/' . (int) $book['id'] . '/edit' : '/books/create';
$cancelHref         = $isEdit ? '/books/' . (int) $book['id'] : '/books';
$currentYear        = (int) date('Y');
$currentCover       = $isEdit ? ($book['cover_image'] ?? '') : '';
?>
<div class="card-base book-form-card">
    <form method="post" action="<?= e($formAction) ?>" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

        <?php if ($errors !== []): ?>
            <div class="alert alert-danger d-flex align-items-center gap-2" role="alert">
                <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                <span>Please fix the highlighted fields below.</span>
            </div>
        <?php endif; ?>

        <?php /* ---------- 1. Basic Information ---------- */ ?>
        <?php $formSection = [
            'icon'  => 'fa-book-open',
            'title' => 'Basic Information',
            'text'  => 'The core identity of the book.',
        ]; ?>
        <?php require root_path('app/Views/books/components/form-section.php'); ?>

        <div class="row g-3 mb-2">
            <div class="col-12">
                <?php $field = [
                    'name'      => 'title',
                    'label'     => 'Title',
                    'type'      => 'text',
                    'value'     => $old['title'] ?? '',
                    'errors'    => $errors,
                    'required'  => true,
                    'maxlength' => 255,
                    'autofocus' => true,
                ]; ?>
                <?php require root_path('app/Views/books/components/form-input.php'); ?>
            </div>
            <div class="col-md-6">
                <?php $field = [
                    'name'      => 'subtitle',
                    'label'     => 'Subtitle',
                    'type'      => 'text',
                    'value'     => $old['subtitle'] ?? '',
                    'errors'    => $errors,
                    'maxlength' => 255,
                ]; ?>
                <?php require root_path('app/Views/books/components/form-input.php'); ?>
            </div>
            <div class="col-md-6">
                <?php $field = [
                    'name'        => 'isbn',
                    'label'       => 'ISBN',
                    'type'        => 'text',
                    'value'       => $old['isbn'] ?? '',
                    'errors'      => $errors,
                    'maxlength'   => 20,
                    'placeholder' => '9780000000000',
                    'help'        => 'Optional. Spaces and dashes are stripped.',
                ]; ?>
                <?php require root_path('app/Views/books/components/form-input.php'); ?>
            </div>
        </div>

        <?php /* ---------- 2. Publishing Information ---------- */ ?>
        <?php $formSection = [
            'icon'  => 'fa-building-columns',
            'title' => 'Publishing Information',
            'text'  => 'Who published it, when, and its current state.',
        ]; ?>
        <?php require root_path('app/Views/books/components/form-section.php'); ?>

        <div class="row g-3 mb-2">
            <div class="col-md-6">
                <?php $field = [
                    'name'      => 'publisher',
                    'label'     => 'Publisher',
                    'type'      => 'text',
                    'value'     => $old['publisher'] ?? '',
                    'errors'    => $errors,
                    'maxlength' => 255,
                ]; ?>
                <?php require root_path('app/Views/books/components/form-input.php'); ?>
            </div>
            <div class="col-md-3">
                <?php $field = [
                    'name'  => 'published_year',
                    'label' => 'Publication year',
                    'type'  => 'number',
                    'value' => $old['published_year'] ?? '',
                    'errors' => $errors,
                    'min'   => 1000,
                    'max'   => $currentYear,
                ]; ?>
                <?php require root_path('app/Views/books/components/form-input.php'); ?>
            </div>
            <div class="col-md-3">
                <?php $field = [
                    'name'     => 'language',
                    'label'    => 'Language',
                    'type'     => 'select',
                    'value'    => $old['language'] ?? 'en',
                    'errors'   => $errors,
                    'options'  => $languages,
                ]; ?>
                <?php require root_path('app/Views/books/components/form-input.php'); ?>
            </div>
            <div class="col-md-6">
                <?php $field = [
                    'name'    => 'status',
                    'label'   => 'Status',
                    'type'    => 'select',
                    'value'   => $old['status'] ?? 'draft',
                    'errors'  => $errors,
                    'options' => $statuses,
                ]; ?>
                <?php require root_path('app/Views/books/components/form-input.php'); ?>
            </div>
            <div class="col-md-6">
                <?php $field = [
                    'name'  => 'page_count',
                    'label' => 'Page count',
                    'type'  => 'number',
                    'value' => $old['page_count'] ?? '',
                    'errors' => $errors,
                    'min'   => 1,
                ]; ?>
                <?php require root_path('app/Views/books/components/form-input.php'); ?>
            </div>
        </div>

        <?php /* ---------- 3. Book Details ---------- */ ?>
        <?php $formSection = [
            'icon'  => 'fa-list-ul',
            'title' => 'Book Details',
            'text'  => 'The description and the related authors and categories.',
        ]; ?>
        <?php require root_path('app/Views/books/components/form-section.php'); ?>

        <div class="row g-3 mb-2">
            <div class="col-12">
                <?php $field = [
                    'name'      => 'description',
                    'label'     => 'Description',
                    'type'      => 'textarea',
                    'value'     => $old['description'] ?? '',
                    'errors'    => $errors,
                    'rows'      => 5,
                    'maxlength' => 5000,
                ]; ?>
                <?php require root_path('app/Views/books/components/form-input.php'); ?>
            </div>
            <div class="col-md-6">
                <fieldset>
                    <legend class="form-label mb-1">Authors</legend>
                    <div class="choice-group">
                        <?php foreach ($authors as $author): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox"
                                       name="author_ids[]" value="<?= (int) $author['id'] ?>"
                                       id="author_<?= (int) $author['id'] ?>"
                                       <?= in_array((int) $author['id'], $selectedAuthors, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="author_<?= (int) $author['id'] ?>">
                                    <?= e($author['name']) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            </div>
            <div class="col-md-6">
                <fieldset>
                    <legend class="form-label mb-1">Categories</legend>
                    <div class="choice-group">
                        <?php foreach ($categories as $category): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox"
                                       name="category_ids[]" value="<?= (int) $category['id'] ?>"
                                       id="category_<?= (int) $category['id'] ?>"
                                       <?= in_array((int) $category['id'], $selectedCategories, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="category_<?= (int) $category['id'] ?>">
                                    <?= e($category['name']) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            </div>
        </div>

        <?php /* ---------- 4. Media ---------- */ ?>
        <?php $formSection = [
            'icon'  => 'fa-image',
            'title' => 'Media',
            'text'  => $isEdit ? 'A new cover replaces the current one; leaving it empty keeps the existing image.' : 'The cover image of the book.',
        ]; ?>
        <?php require root_path('app/Views/books/components/form-section.php'); ?>

        <div class="row g-3 mb-3">
            <div class="col-12">
                <?php
                // The card enforces the same rules on the client as
                // MediaService does on the server; both read the size
                // limit from config/media.php (single source of truth).
                $coversConfig = config('media.covers', []);
                $upload = [
                    'name'        => 'cover',
                    'label'       => 'Cover image',
                    'value'       => $currentCover,
                    'errors'      => $errors,
                    'accept'      => '.jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp',
                    'accept_text' => 'JPG, PNG or WebP, up to 5 MB.',
                    'max_bytes'   => (int) ($coversConfig['max_bytes'] ?? 5 * 1024 * 1024),
                    'remove_name' => 'remove_cover',
                ];
                ?>
                <?php require root_path('app/Views/books/components/upload-card.php'); ?>
            </div>
        </div>

        <hr class="my-4">

        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-primary" type="submit">
                <i class="fa-solid fa-floppy-disk me-1" aria-hidden="true"></i>
                <?= $isEdit ? 'Save changes' : 'Add book' ?>
            </button>
            <a class="btn btn-outline-secondary" href="<?= e($cancelHref) ?>">Cancel</a>
        </div>
    </form>
</div>
