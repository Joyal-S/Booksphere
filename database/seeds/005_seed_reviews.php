<?php

declare(strict_types=1);

/**
 * Seed: reviews
 *
 * Sample ratings and reviews. Each entry references a user and a
 * book by EMAIL and TITLE, which are then resolved to their ids -
 * this keeps the seed readable instead of magic numbers.
 *
 * The UNIQUE (user_id, book_id) constraint guarantees no user
 * reviews the same book twice.
 */

return function (\BookSphere\App\Core\Database $database): void {
    // Resolve the id of a user by email, or a book by title.
    $userId = function (string $email) use ($database): int {
        return (int) $database->query('SELECT id FROM users WHERE email = ?', [$email])[0]['id'];
    };
    $bookId = function (string $title) use ($database): int {
        return (int) $database->query('SELECT id FROM books WHERE title = ?', [$title])[0]['id'];
    };

    $reviews = [
        ['riya@booksphere.test',  '1984',                            5, 'A chilling and timeless warning about surveillance and power.'],
        ['riya@booksphere.test',  'To Kill a Mockingbird',           4, 'Beautifully written, with a moral core that still matters today.'],
        ['riya@booksphere.test',  'The Martian',                     5, 'Smart, funny and impossible to put down.'],
        ['arjun@booksphere.test', 'Clean Code',                      4, 'A must-read for any programmer who wants to write maintainable code.'],
        ['arjun@booksphere.test', 'The Pragmatic Programmer',        5, 'Practical wisdom on every page; I re-read it every year.'],
        ['arjun@booksphere.test', 'Deep Work',                       3, 'Good ideas, though it gets repetitive towards the end.'],
        ['meera@booksphere.test', 'The God of Small Things',         5, 'Poetic and heartbreaking. A masterpiece of Indian English fiction.'],
        ['meera@booksphere.test', 'Malgudi Days',                    4, 'Warm, funny stories that capture small-town India beautifully.'],
        ['meera@booksphere.test', 'Wings of Fire',                   5, 'An inspiring autobiography about dreams, hard work and humility.'],
        ['admin@booksphere.test', 'Sapiens',                         4, 'A sweeping, thought-provoking tour of human history.'],
        ['admin@booksphere.test', 'Gone Girl',                       4, 'Twisty and gripping; the ending stays with you.'],
        ['admin@booksphere.test', 'Atomic Habits',                   5, 'Tiny changes, big results. The most practical habit book I have read.'],
    ];

    foreach ($reviews as [$email, $title, $rating, $review]) {
        $database->execute(
            'INSERT OR IGNORE INTO reviews (user_id, book_id, rating, review) VALUES (?, ?, ?, ?)',
            [$userId($email), $bookId($title), $rating, $review],
        );
    }
};
