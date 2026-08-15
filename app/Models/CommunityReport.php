<?php

declare(strict_types=1);

namespace BookSphere\App\Models;

use BookSphere\App\Repositories\CommunityReportRepository;

/**
 * CommunityReport
 *
 * Thin facade over CommunityReportRepository.
 *
 * Entity columns (community_reports, migration 0036):
 *   id           INTEGER PRIMARY KEY AUTOINCREMENT
 *   post_id      INTEGER NULL FK community_posts(id) ON DELETE CASCADE
 *   comment_id   INTEGER NULL FK community_comments(id) ON DELETE CASCADE
 *   reported_by  INTEGER NOT NULL FK users(id) ON DELETE CASCADE
 *   reason       TEXT NOT NULL DEFAULT 'Other'
 *                CHECK('Spam'|'Harassment'|'Offensive Content'|
 *                       'False Information'|'Duplicate'|'Other')
 *   description  TEXT NOT NULL DEFAULT ''
 *   status       TEXT NOT NULL DEFAULT 'pending'
 *                CHECK('pending'|'reviewed'|'dismissed'|'resolved')
 *   created_at   TEXT NOT NULL
 *   updated_at   TEXT NOT NULL
 */
final class CommunityReport
{
    public function __construct(
        private readonly CommunityReportRepository $repository = new CommunityReportRepository(),
    ) {}

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->repository->create($data);
    }

    public function updateStatus(int $id, string $status): bool
    {
        return $this->repository->updateStatus($id, $status);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->repository->find($id);
    }

    /** @return array<int,array<string,mixed>> */
    public function findPending(int $limit = 50, int $offset = 0): array
    {
        return $this->repository->findPending($limit, $offset);
    }

    public function countPending(): int
    {
        return $this->repository->countPending();
    }

    /** @return array<int,array<string,mixed>> */
    public function findByPost(int $postId): array
    {
        return $this->repository->findByPost($postId);
    }

    /** @return array<int,array<string,mixed>> */
    public function findByComment(int $commentId): array
    {
        return $this->repository->findByComment($commentId);
    }

    /**
     * Check whether a user already has an active report for a given target.
     */
    public function existsByReporter(int $reportedBy, ?int $postId, ?int $commentId): bool
    {
        return $this->repository->existsByReporter($reportedBy, $postId, $commentId);
    }

    /**
     * All reports of a given status for the admin queue.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findAll(int $limit = 50, int $offset = 0, string $status = 'pending'): array
    {
        return $this->repository->findAll($limit, $offset, $status);
    }

    public function countAll(string $status = 'pending'): int
    {
        return $this->repository->countAll($status);
    }

    /** @return array<string,mixed>|null */
    public function findWithContext(int $id): ?array
    {
        return $this->repository->findWithContext($id);
    }
}
