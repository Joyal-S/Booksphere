<?php

declare(strict_types=1);

namespace BookSphere\App\Core;

/**
 * RateLimiter
 *
 * Sliding-window rate limiter supporting both session-backed throttling
 * AND persistent database-backed (IP / Account / User ID) throttling (Phase 13.4).
 *
 * Prevents session-clearing / cookie-rotation attacks while preserving legitimate
 * user experience.
 */
final class RateLimiter
{
    /** The session key holding session-level buckets. */
    private const STORAGE_KEY = '_rate_limit';

    public function __construct(
        private readonly Session $session,
        private readonly ?Database $db = null,
    ) {}

    /**
     * Report whether an action is allowed for the session (and optional persistent key).
     */
    public function allow(string $bucket, int $limit, int $windowSeconds, ?string $persistentKey = null): bool
    {
        if ($limit < 1 || $windowSeconds < 1) {
            return true;
        }

        // 1. Check Session Throttle
        $buckets = $this->session->get(self::STORAGE_KEY, []);
        $now     = time();
        $state   = $buckets[$bucket] ?? ['starts' => $now, 'count' => 0];

        if ($state['starts'] + $windowSeconds <= $now) {
            $state = ['starts' => $now, 'count' => 0];
        }

        if ($state['count'] >= $limit) {
            return false;
        }

        // 2. Check Persistent DB Throttle if persistent key provided
        if ($persistentKey !== null && !$this->checkDbLimit($persistentKey, $bucket, $limit)) {
            return false;
        }

        // Increment session count
        $state['count']++;
        $buckets[$bucket] = $state;
        $this->session->put(self::STORAGE_KEY, $buckets);

        // Increment persistent DB count if key provided
        if ($persistentKey !== null) {
            $this->recordDbAttempt($persistentKey, $bucket, $windowSeconds);
        }

        return true;
    }

    /**
     * Check if limit is exhausted without recording an attempt.
     */
    public function tooManyAttempts(string $bucket, int $limit, int $windowSeconds, ?string $persistentKey = null): bool
    {
        if ($limit < 1 || $windowSeconds < 1) {
            return false;
        }

        $buckets = $this->session->get(self::STORAGE_KEY, []);
        $now     = time();
        $state   = $buckets[$bucket] ?? ['starts' => $now, 'count' => 0];

        if ($state['starts'] + $windowSeconds > $now && $state['count'] >= $limit) {
            return true;
        }

        if ($persistentKey !== null && !$this->checkDbLimit($persistentKey, $bucket, $limit)) {
            return true;
        }

        return false;
    }

    /**
     * Get remaining seconds until rate limit window resets.
     */
    public function remainingSeconds(string $bucket, int $windowSeconds, ?string $persistentKey = null): int
    {
        $now = time();
        $sessionRemaining = 0;
        $dbRemaining = 0;

        $buckets = $this->session->get(self::STORAGE_KEY, []);
        if (isset($buckets[$bucket])) {
            $sessionRemaining = max(0, ($buckets[$bucket]['starts'] + $windowSeconds) - $now);
        }

        if ($persistentKey !== null) {
            $db = $this->getDb();
            if ($db !== null) {
                try {
                    $rows = $db->query(
                        'SELECT expires_at FROM rate_limits WHERE key = ? AND action = ?',
                        [$persistentKey, $bucket]
                    );
                    if ($rows !== []) {
                        $dbRemaining = max(0, (int) $rows[0]['expires_at'] - $now);
                    }
                } catch (\Throwable) {
                }
            }
        }

        return max($sessionRemaining, $dbRemaining, 1);
    }

    /**
     * Clear persistent rate limit record for a key (e.g. after successful login).
     */
    public function clearPersistent(string $bucket, string $persistentKey): void
    {
        try {
            $db = $this->getDb();
            $db?->execute('DELETE FROM rate_limits WHERE key = ? AND action = ?', [$persistentKey, $bucket]);
        } catch (\Throwable) {
        }
    }

    /**
     * Delete expired rate limit records from database.
     */
    public function pruneExpired(): int
    {
        try {
            $db = $this->getDb();
            return $db ? $db->execute('DELETE FROM rate_limits WHERE expires_at < ?', [time()]) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Reset session buckets.
     */
    public function reset(): void
    {
        $this->session->forget(self::STORAGE_KEY);
    }

    private function checkDbLimit(string $key, string $action, int $limit): bool
    {
        $now = time();
        $db = $this->getDb();
        if ($db === null) {
            return true;
        }

        try {
            $rows = $db->query(
                'SELECT attempts, expires_at FROM rate_limits WHERE key = ? AND action = ?',
                [$key, $action]
            );

            if ($rows === []) {
                return true;
            }

            $row = $rows[0];
            if ($now >= (int) $row['expires_at']) {
                return true;
            }

            return (int) $row['attempts'] < $limit;
        } catch (\Throwable) {
            return true;
        }
    }

    private function recordDbAttempt(string $key, string $action, int $windowSeconds): void
    {
        $now = time();
        $expiresAt = $now + $windowSeconds;
        $db = $this->getDb();
        if ($db === null) {
            return;
        }

        try {
            $rows = $db->query(
                'SELECT attempts, expires_at FROM rate_limits WHERE key = ? AND action = ?',
                [$key, $action]
            );

            if ($rows === []) {
                $db->execute(
                    'INSERT INTO rate_limits (key, action, attempts, starts_at, expires_at) VALUES (?, ?, 1, ?, ?)',
                    [$key, $action, $now, $expiresAt]
                );
            } else {
                $row = $rows[0];
                if ($now >= (int) $row['expires_at']) {
                    $db->execute(
                        'UPDATE rate_limits SET attempts = 1, starts_at = ?, expires_at = ? WHERE key = ? AND action = ?',
                        [$now, $expiresAt, $key, $action]
                    );
                } else {
                    $db->execute(
                        'UPDATE rate_limits SET attempts = attempts + 1 WHERE key = ? AND action = ?',
                        [$key, $action]
                    );
                }
            }
        } catch (\Throwable) {
        }
    }

    private function getDb(): ?Database
    {
        if ($this->db !== null) {
            return $this->db;
        }

        try {
            return Database::instance();
        } catch (\Throwable) {
            return null;
        }
    }
}
