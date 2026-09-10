<?php

declare(strict_types=1);

namespace BookSphere\App\Models;

/**
 * Author
 *
 * Data access for the authors table. Only the two queries the book
 * management forms and pages need: the full list (for the author
 * checkboxes) and a lookup by id.
 *
 * Like every model in this application it returns plain associative
 * arrays and always uses prepared statements.
 */
final class Author
{
    /**
     * Return every author, ordered by name.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return db()->query(
            'SELECT a.id, a.name
             FROM authors a
             JOIN book_authors ba ON ba.author_id = a.id
             JOIN books b ON b.id = ba.book_id
             WHERE b.status = ? AND b.deleted_at IS NULL
             GROUP BY a.id, a.name
             ORDER BY a.name ASC',
            ['published'],
        );
    }

    /**
     * Find an author by primary key.
     *
     * @return array<string, mixed>|null The author row, or null
     */
    public function findById(int $id): ?array
    {
        $rows = db()->query(
            'SELECT id, name, biography, photo, created_at FROM authors WHERE id = ?',
            [$id],
        );

        return $rows[0] ?? null;
    }

    /**
     * Browse, search, and paginate authors with their linked book counts.
     *
     * @param array<string, mixed> $options
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, perPage: int, pages: int}
     */
    public function browse(array $options = []): array
    {
        $q       = trim((string) ($options['q'] ?? ''));
        $sort    = (string) ($options['sort'] ?? 'name_asc');
        $page    = max(1, (int) ($options['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($options['perPage'] ?? 20)));
        $offset  = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        if ($q !== '') {
            $where[] = '(a.name LIKE ? OR a.biography LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) AS total FROM authors a {$whereSql}";
        $total = (int) (db()->query($countSql, $params)[0]['total'] ?? 0);

        $orderSql = match ($sort) {
            'name_desc'  => 'a.name DESC',
            'books_desc' => 'books_count DESC, a.name ASC',
            'books_asc'  => 'books_count ASC, a.name ASC',
            'newest'     => 'a.id DESC',
            'oldest'     => 'a.id ASC',
            default      => 'a.name ASC',
        };

        $itemsSql = "
            SELECT a.id, a.name, a.biography, a.photo, a.created_at,
                   COUNT(DISTINCT ba.book_id) AS books_count,
                   COUNT(DISTINCT CASE WHEN b.status = 'published' AND b.deleted_at IS NULL THEN b.id END) AS active_books_count
            FROM authors a
            LEFT JOIN book_authors ba ON ba.author_id = a.id
            LEFT JOIN books b ON b.id = ba.book_id
            {$whereSql}
            GROUP BY a.id, a.name, a.biography, a.photo, a.created_at
            ORDER BY {$orderSql}
            LIMIT ? OFFSET ?
        ";

        $items = db()->query($itemsSql, [...$params, $perPage, $offset]);
        $pages = max(1, (int) ceil($total / $perPage));

        return [
            'items'   => $items,
            'total'   => $total,
            'page'    => $page,
            'perPage' => $perPage,
            'pages'   => $pages,
        ];
    }

    /**
     * All books associated with an author, including total author counts per book.
     * Used for author inspection and safe deletion impact analysis.
     *
     * @return array<int, array<string, mixed>>
     */
    public function booksFor(int $authorId): array
    {
        return db()->query(
            'SELECT b.id, b.title, b.status, b.deleted_at, b.cover_image, b.average_rating,
                    (SELECT COUNT(*) FROM book_authors ba2 WHERE ba2.book_id = b.id) AS total_authors_count
             FROM books b
             JOIN book_authors ba ON ba.book_id = b.id
             WHERE ba.author_id = ?
             ORDER BY b.title ASC',
            [$authorId],
        );
    }

    /**
     * Update an author's name and biography.
     *
     * @param array<string, mixed> $data
     * @throws \InvalidArgumentException when the name is empty
     */
    public function update(int $id, array $data): bool
    {
        $name      = trim((string) ($data['name'] ?? ''));
        $biography = isset($data['biography']) && trim((string) $data['biography']) !== '' ? trim((string) $data['biography']) : null;

        if ($name === '') {
            throw new \InvalidArgumentException('Author name must not be empty.');
        }

        return db()->execute(
            'UPDATE authors SET name = ?, biography = ? WHERE id = ?',
            [$name, $biography, $id],
        ) >= 0;
    }

    /**
     * Transactionally delete an author record and remove its book_authors relationships.
     *
     * CRITICAL SAFETY:
     * - Only book_authors (and author_follows) relationship rows are removed.
     * - Associated books, reviews, ratings, library, and user data are NEVER touched.
     *
     * @throws \Throwable on transaction failure (rolls back completely)
     */
    public function deleteAuthorTransaction(int $authorId): bool
    {
        $pdo = db()->pdo();
        $wasInTransaction = $pdo->inTransaction();

        if (!$wasInTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $author = $this->findById($authorId);
            if ($author === null) {
                if (!$wasInTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return false;
            }

            // 1. Remove book_authors relationship records
            db()->execute('DELETE FROM book_authors WHERE author_id = ?', [$authorId]);

            // 2. Remove author_follows records
            db()->execute('DELETE FROM author_follows WHERE author_id = ?', [$authorId]);

            // 3. Delete author record
            $deleted = db()->execute('DELETE FROM authors WHERE id = ?', [$authorId]) > 0;

            if (!$wasInTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }

            return $deleted;
        } catch (\Throwable $e) {
            if (!$wasInTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Find an author by name, creating it when it does not exist yet.
     *
     * The importer's author staging uses this. authors.name is UNIQUE,
     * so the insert-or-ignore + read-back pattern is race-safe: two
     * imports of the same new author can never create a second row.
     *
     * @throws \InvalidArgumentException when the name is empty
     */
    public function findOrCreate(string $name): int
    {
        $name = trim($name);

        if ($name === '') {
            throw new \InvalidArgumentException('Author name must not be empty.');
        }

        $rows = db()->query('SELECT id FROM authors WHERE name = ?', [$name]);

        if ($rows !== []) {
            return (int) $rows[0]['id'];
        }

        db()->execute('INSERT OR IGNORE INTO authors (name, biography, photo) VALUES (?, NULL, NULL)', [$name]);
        $rows = db()->query('SELECT id FROM authors WHERE name = ?', [$name]);

        return (int) $rows[0]['id'];
    }
}
