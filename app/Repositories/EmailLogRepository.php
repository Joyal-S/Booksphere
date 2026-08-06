<?php

declare(strict_types=1);

namespace BookSphere\App\Repositories;

/**
 * EmailLogRepository
 *
 * The data-access layer of the email audit trail (Phase 9.5,
 * migration 0027): one row per EMAIL ATTEMPT - sent, failed, skipped
 * or queued - with a snapshot of the recipient, the subject and the
 * error detail. Failures are recorded HERE (and in the application
 * log) but never shown to end-users.
 *
 * The UNIQUE(user_id, type, dedupe_key) index enforces the "prevent
 * duplicate sends" rule at the database level: record() uses
 * INSERT ... ON CONFLICT DO NOTHING, so a re-fired event (a retry, a
 * double dispatch) is silently dropped instead of double-sending.
 *
 * Dependencies:
 *     - db() helper (Core\Database singleton), exactly like the
 *       other repositories.
 */
final class EmailLogRepository
{
    /**
     * Record one attempt. Returns the new row id - or 0 when the
     * event was already logged (the dedupe key collided), in which
     * case NOTHING was sent and nothing is double-recorded.
     *
     * @param array<string, mixed> $row user_id, type, dedupe_key,
     *                                  to_address, subject, status,
     *                                  error
     */
    public function record(array $row): int
    {
        $inserted = db()->execute(
            'INSERT INTO email_logs (user_id, type, dedupe_key, to_address, subject, status, error)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (user_id, type, dedupe_key) DO NOTHING',
            [
                $row['user_id'],
                $row['type'],
                $row['dedupe_key'] ?? null,
                $row['to_address'],
                $row['subject'],
                $row['status'],
                $row['error'] ?? null,
            ],
        );

        // 1 = the row was freshly inserted; 0 = the dedupe key
        // collided and the row was NOT re-inserted.
        return $inserted > 0 ? (int) db()->lastInsertId() : 0;
    }

    /**
     * Flip the status of an EXISTING attempt (queued -> sent /
     * failed) when the worker delivers it. Matches the exact
     * (user_id, type, dedupe_key) triple so no attempt is ever
     * double-counted.
     */
    public function updateStatus(int $userId, string $type, string $dedupeKey, string $status, ?string $error = null): void
    {
        db()->execute(
            'UPDATE email_logs
             SET status = ?, error = ?
             WHERE user_id = ? AND type = ? AND dedupe_key = ?',
            [$status, $error, $userId, $type, $dedupeKey],
        );
    }

    /**
     * The number of attempts ever recorded for one user (the audit
     * count a settings page could show later).
     */
    public function countForUser(int $userId): int
    {
        $rows = db()->query(
            'SELECT COUNT(*) AS count FROM email_logs WHERE user_id = ?',
            [$userId],
        );

        return (int) ($rows[0]['count'] ?? 0);
    }
}