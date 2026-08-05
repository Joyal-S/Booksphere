<?php

declare(strict_types=1);

namespace BookSphere\App\Models;

/**
 * PasswordResetToken
 *
 * Data access for the password_resets table: issuing, validating,
 * redeeming and pruning single-use password reset tokens.
 *
 * Security model:
 *     - only the SHA-256 hash of a token is ever stored, never the
 *       raw token (a leaked database cannot be replayed);
 *     - a token is single-use: consume() stamps used_at and every
 *       lookup excludes used rows, so the same link can never
 *       reset a password twice;
 *     - tokens expire after TTL_SECONDS (60 minutes) - findValid()
 *       refuses anything older, even if the row still exists.
 */
final class PasswordResetToken
{
    /** How long a reset token stays valid, in seconds (60 minutes). */
    public const TTL_SECONDS = 3600;

    /**
     * Issue a new token row for a user.
     *
     * A user holds ONE live token at a time: issuing a new token
     * supersedes (revokes) whatever they had before, so stale reset
     * links die the moment a fresh request is made.
     *
     * @param int    $userId    The account the token belongs to
     * @param string $tokenHash sha256() of the raw token
     *
     * @return int The row id of the new token
     */
    public function create(int $userId, string $tokenHash): int
    {
        $this->deleteForUser($userId);

        db()->execute(
            'INSERT INTO password_resets (user_id, token_hash, expires_at)
             VALUES (?, ?, ?)',
            [$userId, $tokenHash, $this->now(time() + self::TTL_SECONDS)],
        );

        return (int) db()->lastInsertId();
    }

    /**
     * Find an unexpired, unredeemed token row by its hash.
     *
     * @return array<string, mixed>|null The token row, or null when
     *                                   unknown / expired / used
     */
    public function findValid(string $tokenHash): ?array
    {
        $rows = db()->query(
            'SELECT id, user_id, token_hash, expires_at, used_at
             FROM password_resets
             WHERE token_hash = ?
               AND used_at IS NULL
               AND expires_at > ?',
            [$tokenHash, $this->now()],
        );

        return $rows[0] ?? null;
    }

    /**
     * Redeem a token (single-use): stamp it used so every later
     * lookup refuses it.
     */
    public function consume(int $id): bool
    {
        return db()->execute(
            'UPDATE password_resets
             SET used_at = ?
             WHERE id = ? AND used_at IS NULL',
            [$this->now(), $id],
        ) > 0;
    }

    /**
     * Remove every outstanding token of a user.
     *
     * Called before a new token is issued, so one account can only
     * ever hold a single live reset link, and after a password has
     * been reset, so stale links die instantly.
     */
    public function deleteForUser(int $userId): void
    {
        db()->execute(
            'DELETE FROM password_resets WHERE user_id = ?',
            [$userId],
        );
    }

    /**
     * Current UTC time in the database's ISO-8601 format.
     *
     * @param int|null $timestamp Unix timestamp, defaults to now
     */
    private function now(?int $timestamp = null): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp ?? time());
    }
}
