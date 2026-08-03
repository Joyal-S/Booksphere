<?php

declare(strict_types=1);

/**
 * Seed: authors
 *
 * The sample author list used by the demo books. Biographies are
 * short summaries; photos are left NULL on purpose (photo uploads
 * belong to a later phase).
 *
 * INSERT OR IGNORE keeps the seed idempotent: UNIQUE(name) makes
 * re-runs a no-op for authors that already exist.
 */

return function (\BookSphere\App\Core\Database $database): void {
    $authors = [
        ['Harper Lee',              'American author best known for To Kill a Mockingbird.'],
        ['George Orwell',           'English novelist and essayist, author of 1984 and Animal Farm.'],
        ['Arundhati Roy',           'Indian author and activist; won the Booker Prize for The God of Small Things.'],
        ['J.K. Rowling',            'British author of the Harry Potter fantasy series.'],
        ['J.R.R. Tolkien',          'English writer and philologist, creator of Middle-earth.'],
        ['R.K. Narayan',            'Indian writer who brought small-town South India to life in Malgudi Days.'],
        ['James Clear',             'American author and speaker, best known for Atomic Habits.'],
        ['Daniel Kahneman',         'Israeli-American psychologist and Nobel laureate, author of Thinking, Fast and Slow.'],
        ['Yuval Noah Harari',       'Israeli historian, author of Sapiens: A Brief History of Humankind.'],
        ['Paulo Coelho',            'Brazilian novelist, best known for The Alchemist.'],
        ['Arthur Conan Doyle',      'Scottish writer who created the detective Sherlock Holmes.'],
        ['Gillian Flynn',           'American author of psychological thrillers, best known for Gone Girl.'],
        ['Suzanne Collins',         'American author of The Hunger Games trilogy.'],
        ['Jane Austen',             'English novelist of the late 18th and early 19th centuries.'],
        ['Andy Weir',               'American novelist, author of The Martian.'],
        ['A.P.J. Abdul Kalam',      'Indian scientist and 11th President of India, author of Wings of Fire.'],
        ['Robert C. Martin',        'American software engineer, author of Clean Code.'],
        ['Andrew Hunt',             'American author and software developer, co-author of The Pragmatic Programmer.'],
        ['David Thomas',            'American author and programmer, co-author of The Pragmatic Programmer.'],
        ['Cal Newport',             'American computer scientist, author of Deep Work.'],
        ['Gabriel Garcia Marquez',  'Colombian novelist and Nobel laureate, author of One Hundred Years of Solitude.'],
    ];

    foreach ($authors as [$name, $biography]) {
        $database->execute(
            'INSERT OR IGNORE INTO authors (name, biography, photo) VALUES (?, ?, ?)',
            [$name, $biography, null],
        );
    }
};
