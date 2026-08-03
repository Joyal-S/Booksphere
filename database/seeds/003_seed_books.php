<?php

declare(strict_types=1);

/**
 * Seed: books (demo catalogue)
 *
 * Inserts 20 well-known demo books and links each one to its
 * authors and categories (the many-to-many junction tables).
 *
 * How it works:
 *     1. The book row is inserted with INSERT OR IGNORE, so the
 *        UNIQUE google_book_id prevents duplicates on re-run.
 *     2. The book's id is read back from the database.
 *     3. Author ids and category ids are looked up by name and
 *        linked through book_authors / book_categories.
 *
 * Design notes:
 *     - google_book_id values (GB001...GB020) are placeholders;
 *        real ids come from the Google Books import in a later phase.
 *     - cover_image uses the OpenLibrary ISBN cover service, so
 *        most real covers resolve automatically.
 *     - average_rating / ratings_count are sample values; a later
 *        phase recomputes them from the reviews table.
 */

return function (\BookSphere\App\Core\Database $database): void {
    // Small helper: insert a book and link it to authors + categories.
    $insertBook = function (
        array $book,
        array $authorNames,
        array $categoryNames,
    ) use ($database): void {
        // 1. Insert the book itself.
        $database->execute(
            'INSERT OR IGNORE INTO books
                (google_book_id, isbn, title, subtitle, description, publisher,
                 published_year, language, page_count, cover_image,
                 average_rating, ratings_count)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $book['google_book_id'],
                $book['isbn'],
                $book['title'],
                $book['subtitle'] ?? null,
                $book['description'],
                $book['publisher'],
                $book['year'],
                'en',
                $book['pages'],
                $book['cover'],
                $book['rating'],
                $book['count'],
            ],
        );

        // 2. Read the id back (needed for the junction tables).
        $bookRow = $database->query(
            'SELECT id FROM books WHERE google_book_id = ?',
            [$book['google_book_id']],
        )[0];
        $bookId = (int) $bookRow['id'];

        // 3. Link authors (many-to-many).
        foreach ($authorNames as $name) {
            $author = $database->query('SELECT id FROM authors WHERE name = ?', [$name])[0] ?? null;
            if ($author === null) {
                continue;
            }
            $database->execute(
                'INSERT OR IGNORE INTO book_authors (book_id, author_id) VALUES (?, ?)',
                [$bookId, (int) $author['id']],
            );
        }

        // 4. Link categories (many-to-many).
        foreach ($categoryNames as $name) {
            $category = $database->query('SELECT id FROM categories WHERE name = ?', [$name])[0] ?? null;
            if ($category === null) {
                continue;
            }
            $database->execute(
                'INSERT OR IGNORE INTO book_categories (book_id, category_id) VALUES (?, ?)',
                [$bookId, (int) $category['id']],
            );
        }
    };

    $cover = fn (string $isbn): string => 'https://covers.openlibrary.org/b/isbn/' . $isbn . '-M.jpg';

    $insertBook([
        'google_book_id' => 'GB001',
        'isbn'           => '9780061120084',
        'title'          => 'To Kill a Mockingbird',
        'description'    => 'A young girl grows up in the American South while her lawyer father defends a wrongly accused man.',
        'publisher'      => 'HarperCollins',
        'year'           => 1960,
        'pages'          => 336,
        'cover'          => $cover('9780061120084'),
        'rating'         => 4.3,
        'count'          => 3100,
    ], ['Harper Lee'], ['Classic Fiction']);

    $insertBook([
        'google_book_id' => 'GB002',
        'isbn'           => '9780451524935',
        'title'          => '1984',
        'description'    => 'A totalitarian state watches every citizen as one man begins to question the truth he is told.',
        'publisher'      => 'Signet Classics',
        'year'           => 1949,
        'pages'          => 328,
        'cover'          => $cover('9780451524935'),
        'rating'         => 4.2,
        'count'          => 4200,
    ], ['George Orwell'], ['Science Fiction', 'Classic Fiction']);

    $insertBook([
        'google_book_id' => 'GB003',
        'isbn'           => '9780006550686',
        'title'          => 'The God of Small Things',
        'description'    => 'A family tragedy in Kerala unfolds through the eyes of twins, told in a poetic, non-linear style.',
        'publisher'      => 'Flamingo',
        'year'           => 1997,
        'pages'          => 340,
        'cover'          => $cover('9780006550686'),
        'rating'         => 4.0,
        'count'          => 1500,
    ], ['Arundhati Roy'], ['Fiction']);

    $insertBook([
        'google_book_id' => 'GB004',
        'isbn'           => '9780590353427',
        'title'          => 'Harry Potter and the Philosopher\'s Stone',
        'subtitle'       => 'The boy who lived discovers a school of magic',
        'description'    => 'An orphaned boy discovers he is a wizard and begins his first year at Hogwarts School of Witchcraft and Wizardry.',
        'publisher'      => 'Scholastic',
        'year'           => 1997,
        'pages'          => 309,
        'cover'          => $cover('9780590353427'),
        'rating'         => 4.5,
        'count'          => 5000,
    ], ['J.K. Rowling'], ['Fantasy']);

    $insertBook([
        'google_book_id' => 'GB005',
        'isbn'           => '9780547928227',
        'title'          => 'The Hobbit',
        'description'    => 'Bilbo Baggins joins a company of dwarves on a quest to reclaim a mountain kingdom from a dragon.',
        'publisher'      => 'Houghton Mifflin',
        'year'           => 1937,
        'pages'          => 300,
        'cover'          => $cover('9780547928227'),
        'rating'         => 4.3,
        'count'          => 2800,
    ], ['J.R.R. Tolkien'], ['Fantasy']);

    $insertBook([
        'google_book_id' => 'GB006',
        'isbn'           => '9780143039655',
        'title'          => 'Malgudi Days',
        'description'    => 'Warm and humorous short stories set in the fictional South Indian town of Malgudi.',
        'publisher'      => 'Penguin Books',
        'year'           => 1943,
        'pages'          => 256,
        'cover'          => $cover('9780143039655'),
        'rating'         => 4.2,
        'count'          => 900,
    ], ['R.K. Narayan'], ['Fiction', 'Short Stories']);

    $insertBook([
        'google_book_id' => 'GB007',
        'isbn'           => '9780735211292',
        'title'          => 'Atomic Habits',
        'subtitle'       => 'An Easy and Proven Way to Build Good Habits and Break Bad Ones',
        'description'    => 'A practical framework for changing your life through tiny, repeatable habits.',
        'publisher'      => 'Avery',
        'year'           => 2018,
        'pages'          => 320,
        'cover'          => $cover('9780735211292'),
        'rating'         => 4.4,
        'count'          => 6100,
    ], ['James Clear'], ['Self-Help']);

    $insertBook([
        'google_book_id' => 'GB008',
        'isbn'           => '9780374533557',
        'title'          => 'Thinking, Fast and Slow',
        'description'    => 'A Nobel laureate explains the two systems that drive how we think and why we make the choices we do.',
        'publisher'      => 'Farrar, Straus and Giroux',
        'year'           => 2011,
        'pages'          => 499,
        'cover'          => $cover('9780374533557'),
        'rating'         => 4.2,
        'count'          => 3900,
    ], ['Daniel Kahneman'], ['Psychology']);

    $insertBook([
        'google_book_id' => 'GB009',
        'isbn'           => '9780062316097',
        'title'          => 'Sapiens',
        'subtitle'       => 'A Brief History of Humankind',
        'description'    => 'A sweeping history of how Homo sapiens came to dominate the planet through imagination and cooperation.',
        'publisher'      => 'Harper',
        'year'           => 2011,
        'pages'          => 443,
        'cover'          => $cover('9780062316097'),
        'rating'         => 4.4,
        'count'          => 5800,
    ], ['Yuval Noah Harari'], ['History']);

    $insertBook([
        'google_book_id' => 'GB010',
        'isbn'           => '9780062315007',
        'title'          => 'The Alchemist',
        'description'    => 'A shepherd boy travels to the pyramids in search of treasure and discovers the importance of following his dreams.',
        'publisher'      => 'HarperOne',
        'year'           => 1988,
        'pages'          => 208,
        'cover'          => $cover('9780062315007'),
        'rating'         => 3.9,
        'count'          => 4100,
    ], ['Paulo Coelho'], ['Fiction']);

    $insertBook([
        'google_book_id' => 'GB011',
        'isbn'           => '9781840224166',
        'title'          => 'Sherlock Holmes: The Complete Novels',
        'description'    => 'Four full-length novels starring the world\'s most famous detective and his friend Dr Watson.',
        'publisher'      => 'Wordsworth Editions',
        'year'           => 1892,
        'pages'          => 560,
        'cover'          => $cover('9781840224166'),
        'rating'         => 4.4,
        'count'          => 1900,
    ], ['Arthur Conan Doyle'], ['Mystery & Thriller']);

    $insertBook([
        'google_book_id' => 'GB012',
        'isbn'           => '9780307588371',
        'title'          => 'Gone Girl',
        'description'    => 'A wife disappears and her husband becomes the prime suspect in this twist-filled psychological thriller.',
        'publisher'      => 'Crown',
        'year'           => 2012,
        'pages'          => 432,
        'cover'          => $cover('9780307588371'),
        'rating'         => 4.0,
        'count'          => 3100,
    ], ['Gillian Flynn'], ['Mystery & Thriller']);

    $insertBook([
        'google_book_id' => 'GB013',
        'isbn'           => '9780439023481',
        'title'          => 'The Hunger Games',
        'description'    => 'In a dystopian nation, teenagers fight to the death on live television until one girl volunteers in place of her sister.',
        'publisher'      => 'Scholastic',
        'year'           => 2008,
        'pages'          => 384,
        'cover'          => $cover('9780439023481'),
        'rating'         => 4.3,
        'count'          => 4600,
    ], ['Suzanne Collins'], ['Science Fiction']);

    $insertBook([
        'google_book_id' => 'GB014',
        'isbn'           => '9780141439518',
        'title'          => 'Pride and Prejudice',
        'description'    => 'Elizabeth Bennet and Mr Darcy navigate family, class and first impressions in this classic of English literature.',
        'publisher'      => 'Penguin Classics',
        'year'           => 1813,
        'pages'          => 480,
        'cover'          => $cover('9780141439518'),
        'rating'         => 4.3,
        'count'          => 3400,
    ], ['Jane Austen'], ['Romance', 'Classic Fiction']);

    $insertBook([
        'google_book_id' => 'GB015',
        'isbn'           => '9780553418026',
        'title'          => 'The Martian',
        'description'    => 'An astronaut is left behind on Mars and must use science and humour to survive until rescue.',
        'publisher'      => 'Crown',
        'year'           => 2014,
        'pages'          => 384,
        'cover'          => $cover('9780553418026'),
        'rating'         => 4.4,
        'count'          => 3800,
    ], ['Andy Weir'], ['Science Fiction']);

    $insertBook([
        'google_book_id' => 'GB016',
        'isbn'           => '9788173711466',
        'title'          => 'Wings of Fire',
        'subtitle'       => 'An Autobiography of A.P.J. Abdul Kalam',
        'description'    => 'The inspiring story of India\'s Missile Man, from a small town in Tamil Nadu to the Presidency.',
        'publisher'      => 'Universities Press',
        'year'           => 1999,
        'pages'          => 180,
        'cover'          => $cover('9788173711466'),
        'rating'         => 4.3,
        'count'          => 2200,
    ], ['A.P.J. Abdul Kalam'], ['Biography & Memoir']);

    $insertBook([
        'google_book_id' => 'GB017',
        'isbn'           => '9780132350884',
        'title'          => 'Clean Code',
        'subtitle'       => 'A Handbook of Agile Software Craftsmanship',
        'description'    => 'Practical principles and patterns for writing readable, maintainable code.',
        'publisher'      => 'Prentice Hall',
        'year'           => 2008,
        'pages'          => 464,
        'cover'          => $cover('9780132350884'),
        'rating'         => 4.3,
        'count'          => 2700,
    ], ['Robert C. Martin'], ['Technology']);

    $insertBook([
        'google_book_id' => 'GB018',
        'isbn'           => '9780135957059',
        'title'          => 'The Pragmatic Programmer',
        'subtitle'       => 'Your Journey to Mastery',
        'description'    => 'Timeless advice on the craft of software development, from debugging to career mastery.',
        'publisher'      => 'Addison-Wesley',
        'year'           => 2019,
        'pages'          => 352,
        'cover'          => $cover('9780135957059'),
        'rating'         => 4.4,
        'count'          => 2400,
    ], ['Andrew Hunt', 'David Thomas'], ['Technology']);

    $insertBook([
        'google_book_id' => 'GB019',
        'isbn'           => '9781455586691',
        'title'          => 'Deep Work',
        'subtitle'       => 'Rules for Focused Success in a Distracted World',
        'description'    => 'How to cultivate the ability to focus without distraction on cognitively demanding work.',
        'publisher'      => 'Grand Central Publishing',
        'year'           => 2016,
        'pages'          => 304,
        'cover'          => $cover('9781455586691'),
        'rating'         => 4.2,
        'count'          => 2900,
    ], ['Cal Newport'], ['Self-Help', 'Technology']);

    $insertBook([
        'google_book_id' => 'GB020',
        'isbn'           => '9780060883287',
        'title'          => 'One Hundred Years of Solitude',
        'description'    => 'The multi-generational story of the Buendia family and the mythical town of Macondo.',
        'publisher'      => 'Harper Perennial',
        'year'           => 1967,
        'pages'          => 417,
        'cover'          => $cover('9780060883287'),
        'rating'         => 4.2,
        'count'          => 2500,
    ], ['Gabriel Garcia Marquez'], ['Classic Fiction']);
};
