<?php

declare(strict_types=1);

namespace BookSphere\App\Models;

use BookSphere\App\Repositories\CommunityLikeRepository;

/**
 * CommunityLike
 *
 * Thin facade over CommunityLikeRepository.
 *
 * Entity columns (community_likes, migration 0036):
 *   id         INTEGER PRIMARY KEY AUTOINCREMENT
 *   post_id    INTEGER NOT NULL FK community_posts(id) ON DELETE CASCADE
 *   user_id    INTEGER NOT NULL FK users(id) ON DELETE CASCADE
 *   created_at TEXT    NOT NULL
 *
 * UNIQUE (post_id, user_id) enforced at the database level.
 */
final class CommunityLike
{
    public function __construct(
        private readonly CommunityLikeRepository $repository = new CommunityLikeRepository(),
    ) {}

    public function create(int $postId, int $userId): int
    {
        return $this->repository->create($postId, $userId);
    }

    public function delete(int $postId, int $userId): bool
    {
        return $this->repository->delete($postId, $userId);
    }

    public function exists(int $postId, int $userId): bool
    {
        return $this->repository->exists($postId, $userId);
    }

    public function count(int $postId): int
    {
        return $this->repository->count($postId);
    }
}
