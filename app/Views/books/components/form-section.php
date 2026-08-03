<?php

declare(strict_types=1);

/**
 * books/components/form-section.php
 *
 * The reusable FORM SECTION header: a small icon tile, a section
 * title and an optional description. The book form is split into
 * logical groups (Basic Information, Publishing Information, Book
 * Details, Media); this component keeps every group visually
 * consistent.
 *
 * Usage (a view sets $formSection first):
 *
 *     $formSection = [
 *         'icon'    => 'fa-book-open',
 *         'title'   => 'Basic Information',
 *         'text'    => 'The core details of the book.',
 *     ];
 *     <?php require root_path('app/Views/books/components/form-section.php'); ?>
 */

$formSection = array_merge([
    'icon'  => 'fa-circle-info',
    'title' => '',
    'text'  => '',
], $formSection ?? []);

?>
<div class="form-section">
    <span class="form-section-icon" aria-hidden="true">
        <i class="fa-solid <?= e($formSection['icon']) ?>"></i>
    </span>
    <div class="form-section-body">
        <h2 class="form-section-title"><?= e($formSection['title']) ?></h2>
        <?php if ($formSection['text'] !== ''): ?>
            <p class="form-section-text"><?= e($formSection['text']) ?></p>
        <?php endif; ?>
    </div>
</div>
