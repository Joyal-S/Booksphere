<?php

declare(strict_types=1);

namespace BookSphere\App\Models;

use BookSphere\App\Repositories\CommunityCommentRepository;

/**
 * CommunityComment
 *
 * Thin facade over CommunityCommentRepository.
 *
 * Entity columns (community_comments, migration 0036):
 *   id         INTEGER PRIMARY KEY AUTOINCREMENT
 *   post_id    INTEGER NOT NULL FK community_posts(id) ON DELETE CASCADE
 *   user_id    INTEGER NOT NULL FK users(id) ON DELETE CASCADE
 *   body       TEXT    NOT NULL
 *   status     TEXT    NOT NULL DEFAULT 'active' CHECK ('active'|'hidden'|'deleted')
 *   created_at TEXT    NOT NULL
 *   updated_at TEXT    NOT NULL
 */
final class CommunityComment
{
    public function __construct(
        private readonly CommunityCommentRepository $repository = new CommunityCommentRepository(),
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
    public function findByPost(int $postId, int $limit = 100, int $offset = 0): array
    {
        return $this->repository->findByPost($postId, $limit, $offset);
    }

    public function countByPost(int $postId): int
    {
        return $this->repository->countByPost($postId);
    }

    /** @return array<int,array<string,mixed>> */
    public function findActiveByUser(int $userId, int $limit = 20, int $offset = 0): array
    {
        return $this->repository->findActiveByUser($userId, $limit, $offset);
    }

    public function countActiveByUser(int $userId): int
    {
        return $this->repository->countActiveByUser($userId);
    }

    // --- Relationships --------------------------------------------------

    /** The comment's author (belongsTo users). */
    public function author(array $comment): ?array
    {
        return (new User())->findById((int) ($comment['user_id'] ?? 0));
    }

    /** The parent post (belongsTo community_posts). */
    public function post(array $comment): ?array
    {
        return (new CommunityPost())->find((int) ($comment['post_id'] ?? 0));
    }
}
