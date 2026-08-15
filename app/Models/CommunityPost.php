<?php

declare(strict_types=1);

namespace BookSphere\App\Models;

use BookSphere\App\Repositories\CommunityPostRepository;

/**
 * CommunityPost
 *
 * Thin facade over CommunityPostRepository, following the identical
 * pattern of AuthorFollow, Review and UserLibrary: no SQL, no business
 * logic ? just a predictable interface the service and controllers use.
 *
 * Entity columns (community_posts, migration 0036):
 *   id         INTEGER PRIMARY KEY AUTOINCREMENT
 *   user_id    INTEGER NOT NULL FK users(id) ON DELETE CASCADE
 *   book_id    INTEGER NULL     FK books(id) ON DELETE SET NULL
 *   title      TEXT    NOT NULL
 *   body       TEXT    NOT NULL
 *   status     TEXT    NOT NULL DEFAULT 'active'
 *                      CHECK ('active'|'hidden'|'deleted')
 *   created_at TEXT    NOT NULL  ISO-8601 UTC
 *   updated_at TEXT    NOT NULL  ISO-8601 UTC
 *
 * Relationships (resolved on demand - no lazy-loading magic):
 *   author()  -> users row (belongsTo)
 *   book()    -> books row (belongsTo, nullable)
 *
 * MVC chain:
 *   Controller -> CommunityService -> CommunityPost
 *   -> CommunityPostRepository -> PDO -> SQLite
 */
final class CommunityPost
{
    public function __construct(
        private readonly CommunityPostRepository $repository = new CommunityPostRepository(),
    ) {}

    // --- CRUD -----------------------------------------------------------

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->repository->create($data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): bool
    {
        return $this->repository->update($id, $data);
    }

    public function updateStatus(int $id, string $status): bool
    {
        return $this->repository->updateStatus($id, $status);
    }

    public function delete(int $id): bool
    {
        return $this->repository->delete($id);
    }

    // --- Reads ----------------------------------------------------------

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->repository->find($id);
    }

    /** @return array<int,array<string,mixed>> */
    public function findActive(int $limit = 20, int $offset = 0): array
    {
        return $this->repository->findActive($limit, $offset);
    }

    public function countActive(): int
    {
        return $this->repository->countActive();
    }

    /** @return array<int,array<string,mixed>> */
    public function findDiscoveryPosts(
        string $sort = 'recent',
        ?int $bookId = null,
        ?int $authorId = null,
        ?string $query = null,
        int $limit = 20,
        int $offset = 0,
        ?int $followerId = null
    ): array {
        return $this->repository->findDiscoveryPosts($sort, $bookId, $authorId, $query, $limit, $offset, $followerId);
    }

    public function countDiscoveryPosts(?int $bookId = null, ?int $authorId = null, ?string $query = null, ?int $followerId = null): int
    {
        return $this->repository->countDiscoveryPosts($bookId, $authorId, $query, $followerId);
    }

    /** @return array<int,array<string,mixed>> */
    public function findByBook(int $bookId, int $limit = 20, int $offset = 0): array
    {
        return $this->repository->findByBook($bookId, $limit, $offset);
    }

    public function countByBook(int $bookId): int
    {
        return $this->repository->countByBook($bookId);
    }

    /** @return array<int,array<string,mixed>> */
    public function findByUser(int $userId, int $limit = 20, int $offset = 0): array
    {
        return $this->repository->findByUser($userId, $limit, $offset);
    }

    /** @return array<int,array<string,mixed>> */
    public function findActiveByUser(int $userId, int $limit = 20, int $offset = 0): array
    {
        return $this->repository->findActiveByUser($userId, $limit, $offset);
    }

    public function countByUser(int $userId): int
    {
        return $this->repository->countByUser($userId);
    }

    public function countActiveByUser(int $userId): int
    {
        return $this->repository->countActiveByUser($userId);
    }

    // --- Relationships --------------------------------------------------

    /** The post's author (belongsTo users). */
    public function author(array $post): ?array
    {
        return (new User())->findById((int) ($post['user_id'] ?? 0));
    }

    /** The optional linked book (belongsTo books, nullable). */
    public function book(array $post): ?array
    {
        $bookId = isset($post['book_id']) ? (int) $post['book_id'] : 0;

        return $bookId > 0 ? (new Book())->findById($bookId) : null;
    }
}
