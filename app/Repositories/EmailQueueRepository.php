<?php

declare(strict_types=1);

namespace BookSphere\App\Repositories;

/**
 * EmailQueueRepository
 *
 * The data-access layer of the email OUTBOX (Phase 9.5, migration
 * 0028): the queue-ready half of the module. When queueing is
 * enabled, a notification only WRITES a pending row here - the
 * delivery happens later, in a worker call
 * (EmailNotificationService::processQueue), so a slow SMTP server
 * can never hold up the request that triggered the email.
 *
 *     enqueue     -> write one pending row (the payload is fully
 *                    generated: subject and HTML are snapshots)
 *     pending     -> the oldest pending rows (the (status,
 *                    created_at) index covers the read)
 *     markSent    -> status = sent, stamp sent_at
 *     markFailed  -> status = failed, bump attempts, keep the error
 *
 * Dependencies:
 *     - db() helper (Core\Database singleton), exactly like the
 *       other repositories.
 */
final class EmailQueueRepository
{
    /**
     * Write one pending row and return its id.
     *
     * The UNIQUE(user_id, type, dedupe_key) index (migration 0029)
     * mirrors the email_logs dedupe index: a re-fire that slips past
     * the service's audit-slot gate cannot insert a second pending
     * row, so the worker can never deliver the same event twice.
     *
     * @param array<string, mixed> $row user_id, type, to_address,
     *                                  to_name, subject, html,
     *                                  dedupe_key
     */
    public function enqueue(array $row): int
    {
        db()->execute(
            'INSERT INTO email_queue (user_id, type, to_address, to_name, subject, html, dedupe_key)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (user_id, type, dedupe_key) DO NOTHING',
            [
                $row['user_id'],
                $row['type'],
                $row['to_address'],
                $row['to_name'],
                $row['subject'],
                $row['html'],
                $row['dedupe_key'] ?? null,
            ],
        );

        return (int) db()->lastInsertId();
    }

    /**
     * The oldest $limit pending rows, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pending(int $limit): array
    {
        return db()->query(
            'SELECT id, user_id, type, to_address, to_name, subject, html, dedupe_key,
                    attempts, error, created_at
             FROM email_queue
             WHERE status = \'pending\'
             ORDER BY created_at ASC, id ASC
             LIMIT ?',
            [max(1, $limit)],
        );
    }

    /**
     * Mark one row delivered: status = sent, stamp sent_at.
     */
    public function markSent(int $id): void
    {
        db()->execute(
            'UPDATE email_queue
             SET status = \'sent\', sent_at = ?, error = NULL
             WHERE id = ? AND status = \'pending\'',
            [$this->now(), $id],
        );
    }

    /**
     * Mark one row failed: status = failed, bump the attempt count
     * and keep the error detail for the logs.
     */
    public function markFailed(int $id, string $error): void
    {
        db()->execute(
            'UPDATE email_queue
             SET status = \'failed\', attempts = attempts + 1, error = ?
             WHERE id = ? AND status = \'pending\'',
            [$error, $id],
        );
    }

    /**
     * The number of pending rows (a worker status read).
     */
    public function pendingCount(): int
    {
        $rows = db()->query(
            'SELECT COUNT(*) AS count FROM email_queue WHERE status = \'pending\'',
        );

        return (int) ($rows[0]['count'] ?? 0);
    }

    /**
     * The current UTC timestamp in the database's stored format.
     */
    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}