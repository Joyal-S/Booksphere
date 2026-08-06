<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

/**
 * CircuitBreaker
 *
 * The failure guard of the Google Books module (Phase 10.1 strategy):
 * when the provider fails too many times in a row, the breaker OPENS
 * and the module switches to cache-only mode for the recovery window -
 * no further HTTP attempts are made until the window elapses.
 *
 * Why a breaker at all: Google Books can stay down (or rate-limited)
 * for minutes. Without one, every search would burn the full retry
 * budget and hammer a sick provider. With one, at most
 * circuit_breaker.max_failures requests are wasted, then the module
 * answers from its cache for circuit_breaker.recovery_seconds.
 *
 * State machine:
 *     - closed   : failures < max_failures; requests flow normally
 *     - open     : max_failures reached; requests are refused
 *                  (GoogleBooksService serves stale cache instead)
 *     - half-open: the recovery window elapsed; the next request is a
 *                  PROBE - success closes the breaker, failure re-opens
 *                  it for a fresh window (implemented lazily in
 *                  isOpen(): an expired window reads as closed and the
 *                  next failure trips it again)
 *
 * Storage: one JSON state file next to the response cache (no table,
 * no daemon - the same file-cache decision as the response cache
 * itself). The write is atomic (temp + rename), so concurrent
 * requests can never observe a torn state file.
 *
 * Every successful provider response also passes recordSuccess() -
 * one good request heals the breaker, which is what keeps a
 * recovering provider from being starved.
 */
final class CircuitBreaker
{
    public const STATE_FILE = 'breaker.json';

    /**
     * @param array<string, mixed> $config the circuit_breaker config block
     */
    public function __construct(
        private readonly string $directory,
        private readonly array $config = [],
    ) {}

    /**
     * Whether the breaker is currently open (refuse live requests).
     */
    public function isOpen(): bool
    {
        $state = $this->state();

        if ($state['opened_until'] === null) {
            return false;
        }

        if (time() < $state['opened_until']) {
            return true;
        }

        // The recovery window elapsed: half-open. The next attempt is
        // a probe, so the breaker reads as closed and the service will
        // record the outcome.
        $this->reset();

        return false;
    }

    /**
     * Register one failed provider request.
     */
    public function recordFailure(): void
    {
        $state            = $this->state();
        $state['failures']++;

        if ($state['failures'] >= $this->maxFailures()) {
            $state['failures']    = $this->maxFailures();
            $state['opened_at']   = time();
            $state['opened_until'] = time() + $this->recoverySeconds();
        }

        $this->persist($state);
    }

    /**
     * Register one successful provider request (heals the breaker).
     */
    public function recordSuccess(): void
    {
        $state             = $this->state();
        $state['failures'] = 0;
        $state['opened_at']   = null;
        $state['opened_until'] = null;

        $this->persist($state);
    }

    /**
     * The human-readable health picture (admin page + tests):
     * state (closed/open/half-open), failures in the current streak,
     * the max_failures threshold, the recovery window, the time the
     * breaker last tripped and how long until it recovers.
     */
    public function stats(): array
    {
        $state    = $this->state();
        $openedAt = $state['opened_at'];
        $until    = $state['opened_until'];

        if ($until !== null && time() < $until) {
            $name = 'open';
        } elseif ($openedAt !== null) {
            $name = 'half-open';
        } else {
            $name = 'closed';
        }

        return [
            'state'            => $name,
            'failures'         => (int) $state['failures'],
            'max_failures'     => $this->maxFailures(),
            'recovery_seconds' => $this->recoverySeconds(),
            'opened_at'        => $openedAt,
            'recovers_at'      => $until,
        ];
    }

    /**
     * Drop the state file (tests + the admin flush tool).
     */
    public function reset(): void
    {
        if (is_file($this->file())) {
            @unlink($this->file());
        }
    }

    private function maxFailures(): int
    {
        return max(1, (int) ($this->config['max_failures'] ?? 3));
    }

    private function recoverySeconds(): int
    {
        return max(5, (int) ($this->config['recovery_seconds'] ?? 300));
    }

    /**
     * @return array{failures: int, opened_at: ?int, opened_until: ?int}
     */
    private function state(): array
    {
        if (!is_file($this->file())) {
            return ['failures' => 0, 'opened_at' => null, 'opened_until' => null];
        }

        $decoded = json_decode((string) file_get_contents($this->file()), true);

        if (!is_array($decoded)) {
            return ['failures' => 0, 'opened_at' => null, 'opened_until' => null];
        }

        return [
            'failures'      => (int) ($decoded['failures'] ?? 0),
            'opened_at'     => isset($decoded['opened_at']) ? (int) $decoded['opened_at'] : null,
            'opened_until'  => isset($decoded['opened_until']) ? (int) $decoded['opened_until'] : null,
        ];
    }

    private function persist(array $state): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0755, true)) {
            return;
        }

        $file = $this->file();
        $temp = $file . '.' . uniqid('', true) . '.tmp';

        file_put_contents($temp, (string) json_encode($state));

        if (!rename($temp, $file)) {
            @unlink($temp);
        }
    }

    private function file(): string
    {
        return rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . self::STATE_FILE;
    }
}