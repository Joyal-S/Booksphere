<?php

declare(strict_types=1);

namespace BookSphere\App\Core;

/**
 * RateLimiter
 *
 * A tiny, session-backed sliding-window throttle for write endpoints
 * (Phase 6.5 security step).
 *
 * Why it exists:
 *     The recommendation dashboard has two write actions - the
 *     wishlist toggle and the cache refresh. They are CSRF-protected
 *     and login-gated already, but a logged-in user (or a bot with a
 *     hijacked session) could still hammer them. The limiter caps
 *     how many times one SESSION may call one action within a time
 *     window, so a single user can never flood the endpoint.
 *
 * How it works:
 *     - One bucket per (session, action name), stored in the session
 *       under the reserved key "_rate_limit".
 *     - Each bucket remembers when its window started and how many
 *       hits it has counted. A hit after the window expired starts a
 *       fresh window; a hit while the window is open increments the
 *       count.
 *     - allow() returns false (and records nothing) once the limit
 *       is reached; the controller answers with HTTP 429 in that
 *       case, exactly like the other polite failures of the module.
 *
 * Why the session (and not a database table or a file):
 *     - The throttle has to remember state BETWEEN requests of the
 *       same visitor. The session is the state the app already
 *       keeps, needs no new table and no daemon, and a per-user
 *       bucket is naturally wiped by logout.
 *     - A distributed deployment would move this to shared storage;
 *       the class documents that as the scale-up path.
 *
 * The limits and windows are CONFIGURATION
 * (config/recommendations.php -> security.rate_limit), so a future
 * developer tunes them without touching this class.
 */
final class RateLimiter
{
    /** The session key holding every bucket. */
    private const STORAGE_KEY = '_rate_limit';

    public function __construct(private readonly Session $session) {}

    /**
     * Count one hit of an action and report whether it is allowed.
     *
     * Input:  the action bucket name, the maximum hits per window
     *         and the window length in seconds
     * Output: true when the hit is within the limit (and was
     *         counted), false when the limit is exhausted
     *
     * Business responsibility: the single decision point of the
     * throttle - every write action asks allow() before it touches
     * the database, so the limit can never be exceeded by accident.
     */
    public function allow(string $bucket, int $limit, int $windowSeconds): bool
    {
        if ($limit < 1 || $windowSeconds < 1) {
            return true;
        }

        $buckets = $this->session->get(self::STORAGE_KEY, []);
        $now     = time();
        $state   = $buckets[$bucket] ?? ['starts' => $now, 'count' => 0];

        if ($state['starts'] + $windowSeconds <= $now) {
            $state = ['starts' => $now, 'count' => 0];
        }

        if ($state['count'] >= $limit) {
            return false;
        }

        $state['count']++;

        $buckets[$bucket] = $state;
        $this->session->put(self::STORAGE_KEY, $buckets);

        return true;
    }

    /**
     * Drop every bucket (used after logout, by maintenance and by
     * tests to reset a session between scenarios).
     */
    public function reset(): void
    {
        $this->session->forget(self::STORAGE_KEY);
    }
}
