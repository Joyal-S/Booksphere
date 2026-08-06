<?php

declare(strict_types=1);

namespace BookSphere\App\Models;

use BookSphere\App\Repositories\EmailQueueRepository;

/**
 * EmailQueue
 *
 * The thin facade of the email outbox data layer (Phase 9.5),
 * following the exact pattern of the Notification model: no business
 * logic, no SQL - just one predictable interface over
 * EmailQueueRepository for the service and the future worker.
 *
 * Dependencies:
 *     - EmailQueueRepository (the actual PDO/prepared SQL).
 */
final class EmailQueue
{
    public function __construct(private readonly EmailQueueRepository $repository = new EmailQueueRepository()) {}

    /**
     * Write one pending row and return its id.
     *
     * @param array<string, mixed> $row See EmailQueueRepository::enqueue()
     */
    public function enqueue(array $row): int
    {
        return $this->repository->enqueue($row);
    }

    /**
     * The oldest pending rows (the worker read).
     *
     * @return array<int, array<string, mixed>>
     */
    public function pending(int $limit): array
    {
        return $this->repository->pending($limit);
    }

    /**
     * Mark one row delivered.
     */
    public function markSent(int $id): void
    {
        $this->repository->markSent($id);
    }

    /**
     * Mark one row failed and bump its attempt count.
     */
    public function markFailed(int $id, string $error): void
    {
        $this->repository->markFailed($id, $error);
    }

    /**
     * The number of pending rows.
     */
    public function pendingCount(): int
    {
        return $this->repository->pendingCount();
    }
}