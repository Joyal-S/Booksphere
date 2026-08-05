<?php

declare(strict_types=1);

/**
 * components/review-search.php
 *
 * The reusable REVIEW SEARCH box (Phase 7.4): a labelled search
 * input with the "Search" submit button, submitted as a GET form so
 * the results (and every toolbar state) stay shareable URLs. Works
 * inside the toolbar form of a review list or standalone on the
 * search page.
 *
 * Included from a view (or the toolbar partial) that sets $search:
 *
 *     $search = [
 *         'q'           => 'mars',            // the current term
 *         'placeholder' => 'Search reviews…', // optional
 *         'name'        => 'q',               // optional input name
 *     ];
 *
 * reviews.js shows the loading skeletons around the review list
 * while the form navigates (data-review-toolbar).
 */

$search = array_merge([
    'q'           => '',
    'placeholder' => 'Search reviews by title, body or reviewer&hellip;',
    'name'        => 'q',
], $search ?? []);

$searchId = 'review-search-' . md5((string) $search['name']);
?>
<div class="review-search" role="search">
    <label class="visually-hidden" for="<?= e($searchId) ?>">Search reviews</label>
    <div class="input-group">
        <span class="input-group-text review-search-icon" aria-hidden="true">
            <i class="fa-solid fa-magnifying-glass"></i>
        </span>
        <input class="form-control review-search-input" id="<?= e($searchId) ?>" type="search"
               name="<?= e($search['name']) ?>" value="<?= e($search['q']) ?>"
               placeholder="<?= e($search['placeholder']) ?>" autocomplete="off">
        <button class="btn btn-primary review-search-submit" type="submit">
            <i class="fa-solid fa-magnifying-glass me-1" aria-hidden="true"></i>Search
        </button>
    </div>
</div>
