<?php

declare(strict_types=1);

use BookSphere\App\DTO\ProviderBookDTO;
use BookSphere\App\DTO\ProviderSearchResult;

/**
 * admin/google-books/partials/_results.php
 *
 * The RESULTS REGION of the Google Books search page. Rendered by
 * TWO routes from the SAME file, so the page and the live endpoint
 * can never disagree about markup (the browse module's pattern):
 *
 *     - /admin/google-books (index)    -> inline require
 *     - /admin/google-books/search     -> View::fragment() -> JSON
 *
 * States:
 *     $result === null                -> nothing searched yet
 *     $result->error !== ''           -> the provider failed / is
 *                                        disabled / circuit open
 *     $result->ok() && totalItems 0   -> no matches
 *     else                            -> the result grid + pagination
 *
 * Available variables:
 *     $result   - ?ProviderSearchResult
 *     $query    - the display search term
 *     $existing - [google_book_id => local book id] (Phase 10.3); the
 *                 card buttons use it to show "In library" on records
 *                 that are already in the local catalogue.
 *
 * The grid cards are provider records (ProviderBookDTO) until the
 * admin imports them: the title links OUT to the Google Books detail
 * page, and a POST form per card (import button) brings ONE record
 * into the local catalogue.
 */

$result   = $result ?? null;
$query    = (string) ($query ?? '');
$existing = (array) ($existing ?? []);

$book = null;

?>
<div class="google-books-results" data-gb-results aria-busy="false">

    <?php if ($result === null): ?>
        <div class="card-base">
            <?php $empty = [
                'icon'    => 'fa-magnifying-glass',
                'title'   => 'Search Google Books',
                'message' => 'Enter a title, author, ISBN, publisher or subject above. Results come from the Google Books catalogue - import the ones you want into your library.',
                'class'   => 'empty-state--search',
            ]; ?>
            <?php require root_path('app/Views/components/empty-state.php'); ?>
        </div>

    <?php elseif (!$result->ok()): ?>
        <div class="card-base">
            <?php $alert = ['type' => 'warning', 'message' => $result->error]; ?>
            <?php require root_path('app/Views/components/alert.php'); ?>
            <?php $empty = [
                'icon'    => 'fa-cloud-bolt',
                'title'   => 'No results right now',
                'message' => 'The provider did not answer. Try again in a moment - your search will be retried automatically.',
                'class'   => 'empty-state--search',
            ]; ?>
            <?php require root_path('app/Views/components/empty-state.php'); ?>
        </div>

    <?php elseif ($result->totalItems === 0): ?>
        <div class="card-base">
            <?php $empty = [
                'icon'    => 'fa-magnifying-glass',
                'title'   => 'No results for "' . $query . '"',
                'message' => 'Nothing in Google Books matches that search. Check the spelling or try a broader term.',
                'class'   => 'empty-state--search',
            ]; ?>
            <?php require root_path('app/Views/components/empty-state.php'); ?>
        </div>

    <?php else: ?>

        <?php if ($result->stale || $result->cached): ?>
            <?php $alert = [
                'type'    => $result->stale ? 'warning' : 'info',
                'message' => $result->stale
                    ? 'The provider is unavailable right now - showing the last cached results for this search.'
                    : 'Showing cached results for this search (the provider was not contacted).',
            ]; ?>
            <?php require root_path('app/Views/components/alert.php'); ?>
        <?php endif; ?>

        <div class="google-books-grid" aria-label="Google Books results">
            <?php foreach ($result->items as $book): ?>
                <?php $gbBook = $book; ?>
                <?php require root_path('app/Views/admin/google-books/partials/_card.php'); ?>
            <?php endforeach; ?>
        </div>

        <?php if ($result->pages > 1): ?>
            <?php $pageUrl = fn (int $target): string => '/admin/google-books?type=' . urlencode((string) ($_GET['type'] ?? 'any')) . '&q=' . urlencode($query) . '&page=' . $target; ?>
            <?php $pagination = [
                'page'    => $result->page,
                'pages'   => $result->pages,
                'pageUrl' => $pageUrl,
                'summary' => 'Showing ' . $result->firstOnPage() . '–' . $result->lastOnPage() . ' of ' . $result->totalItems
                           . ' results &middot; Page ' . $result->page . ' of ' . $result->pages,
            ]; ?>
            <?php require root_path('app/Views/books/components/pagination.php'); ?>
        <?php endif; ?>

    <?php endif; ?>
</div>
