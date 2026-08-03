<?php

declare(strict_types=1);

/**
 * Seed: categories
 *
 * The controlled genre list. Every book belongs to at least one of
 * these categories. INSERT OR IGNORE makes the seed safe to run
 * more than once: rows that already exist (matched by the UNIQUE
 * name) are simply skipped.
 */

return function (\BookSphere\App\Core\Database $database): void {
    $categories = [
        ['Fiction',            'fiction'],
        ['Classic Fiction',    'classic-fiction'],
        ['Science Fiction',    'science-fiction'],
        ['Fantasy',            'fantasy'],
        ['Mystery & Thriller', 'mystery-thriller'],
        ['Romance',            'romance'],
        ['Biography & Memoir', 'biography-memoir'],
        ['Self-Help',          'self-help'],
        ['History',            'history'],
        ['Technology',         'technology'],
        ['Short Stories',      'short-stories'],
        ['Psychology',         'psychology'],
    ];

    foreach ($categories as [$name, $slug]) {
        $database->execute(
            'INSERT OR IGNORE INTO categories (name, slug) VALUES (?, ?)',
            [$name, $slug],
        );
    }
};
