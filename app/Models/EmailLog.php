<?php

declare(strict_types=1);

namespace BookSphere\App\Models;

use BookSphere\App\Repositories\EmailLogRepository;

/**
 * EmailLog
 *
 * The thin facade of the email audit-trail data layer (Phase 9.5),
 * following the exact pattern of the Notification model: no business
 * logic, no SQL - just one predictable interface over
 * EmailLogRepository for the service.
 *
 * Dependencies:
 *     - EmailLogRepository (the actual PDO/prepared SQL).
 */
final class EmailLog
{
    public function __construct(private readonly EmailLogRepository $repository = new EmailLogRepository()) {}

    /**
     * Record one email attempt (sent / failed / skipped / queued).
     * Returns the new row id - or 0 when the dedupe key collided and
     * the event was already logged (nothing is double-sent).
     *
     * @param array<string, mixed> $row See EmailLogRepository::record()
     */
    public function record(array $row): int
    {
        return $this->repository->record($row);
    }

    /**
     * Flip the status of an existing attempt (queued -> sent /
     * failed) when a queue worker delivers it.
     */
    public function updateStatus(int $userId, string $type, string $dedupeKey, string $status, ?string $error = null): void
    {
        $this->repository->updateStatus($userId, $type, $dedupeKey, $status, $error);
    }

    /**
     * The number of attempts recorded for one user.
     */
    public function countForUser(int $userId): int
    {
        return $this->repository->countForUser($userId);
    }
}