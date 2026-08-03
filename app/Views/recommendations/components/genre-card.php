<?php

declare(strict_types=1);

/**
 * recommendations/components/genre-card.php
 *
 * The GENRE CARD of the "Explore New Genres" section (Phase 6.4).
 *
 * Purpose:
 *     One tap in - a compact tile that tells the user which genre
 *     is calling and why: the "Recommended in N books" line shows
 *     how strongly that genre shows up in their current
 *     recommendation batch, which is the explainable hook of the
 *     whole section. The Explore button jumps to the real book
 *     listing for that category.
 *
 * Usage (a view sets the $genre array first):
 *
 *     $genre = [
 *         'name'  => 'Fantasy',
 *         'count' => 3,                  // books in the batch
 *         'href'  => '/books?category=5', // category listing
 *         'icon'  => 'fa-wand-sparkles',  // optional icon
 *     ];
 *
 * Accessibility:
 *     The card is a plain <article>; the real navigation happens
 *     through the labelled Explore link, so screen-reader users
 *     never tab into dead weight.
 */

$genre = array_merge([
    'name'  => '',
    'count' => 0,
    'href'  => '/books',
    'icon'  => 'fa-tags',
], $genre ?? []);

// A valid HTML id from the genre name ("Science Fiction" -> "science-fiction").
$genreId = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', (string) $genre['name']));

?>
<article class="genre-card" aria-labelledby="genre-title-<?= e($genreId) ?>">
    <span class="genre-card-icon" aria-hidden="true">
        <i class="fa-solid <?= e($genre['icon']) ?>"></i>
    </span>
    <h3 class="genre-card-title" id="genre-title-<?= e($genreId) ?>"><?= e($genre['name']) ?></h3>
    <p class="genre-card-count"><?= (int) $genre['count'] > 0 ? 'Recommended in ' . (int) $genre['count'] . ' books' : 'A fresh shelf to explore' ?></p>
    <a class="genre-card-link" href="<?= e($genre['href']) ?>">
        Explore
        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
        <span class="visually-hidden"> books in <?= e($genre['name']) ?></span>
    </a>
</article>
