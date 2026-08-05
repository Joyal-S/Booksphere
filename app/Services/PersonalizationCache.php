<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use RuntimeException;

/**
 * PersonalizationCache
 *
 * The per-user result cache of the personalized recommendations
 * (Phase 6.3).
 *
 * Why a cache:
 *     - Building one user's personalized shelf needs several
 *       aggregate queries (profile signals, candidate pool, batch
 *       relations). The result is per-user and changes only when the
 *       user's signals change, so it is cached for
 *       config('recommendations.cache.ttl_seconds') (30 minutes by
 *       default).
 *     - Cache key = user id. The payload is the serialized
 *       RecommendationResult of getPersonalizedRecommendations().
 *
 * Storage:
 *     - One JSON file per user (config('recommendations.cache.directory')).
 *       A file cache needs no extra table, no daemon and no library -
 *       the brief allows "incremental migrations only" if a table is
 *       needed; none is.
 *
 * Invalidation:
 *     - PersonalizationCache::invalidate($userId) deletes the user's
 *       file. The RecommendationService exposes
 *       invalidatePersonalization() so the future wishlist / rating /
 *       review write-controllers can drop a user's cache the moment
 *       a signal changes, exactly as the brief requires.
 *     - TTL is a safety net: even without explicit invalidation a
 *       stale shelf can never live longer than ttl_seconds.
 *
 * Thread safety: writes go to a temp file that is renamed into place
 * (atomic on the same volume), so two concurrent requests can never
 * observe a half-written cache file.
 */
final class PersonalizationCache
{
    public function __construct(
        private readonly string $directory,
        private readonly int $ttlSeconds,
        private readonly bool $enabled = true,
    ) {}

    /**
     * Read the cached payload of one user, or null on a miss.
     *
     * Input:  the user id
     * Output: the cached payload array, or null when there is no
     *         fresh file (never written, expired, or disabled)
     *
     * Business responsibility: one read per user per request at most;
     * a hit turns a multi-query pipeline into a single file read.
     *
     * @return array<string, mixed>|null
     */
    public function get(int $userId): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        $file = $this->fileFor($userId);

        if (!is_file($file)) {
            return null;
        }

        // The TTL is the freshness check: a file older than
        // ttl_seconds is stale and treated as a miss.
        if ($this->ttlSeconds >= 0 && time() - filemtime($file) > $this->ttlSeconds) {
            return null;
        }

        $payload = json_decode((string) file_get_contents($file), true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * Store the payload of one user.
     *
     * Input:  the user id and the serializable payload
     * Output: nothing (the file is written atomically)
     *
     * Business responsibility: called after the shelf was computed
     * without a cache hit, so the next request skips the pipeline.
     */
    public function put(int $userId, array $payload): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!is_dir($this->directory) && !mkdir($this->directory, 0755, true)) {
            throw new RuntimeException('Cache directory is not writable: ' . $this->directory);
        }

        $file = $this->fileFor($userId);
        $temp = $file . '.' . uniqid('', true) . '.tmp';

        file_put_contents($temp, (string) json_encode($payload, JSON_UNESCAPED_SLASHES));

        if (!rename($temp, $file)) {
            @unlink($temp);
        }
    }

    /**
     * Drop the cached payload of one user.
     *
     * Input:  the user id
     * Output: nothing
     *
     * Business responsibility: the explicit invalidation hook the
     * brief demands - call it whenever a wishlist, rating or review
     * of the user changes so the next shelf reflects the new signal.
     * Phase 8.5: the user's per-section library shelves
     * (section_{user}_{section}.json) are dropped too - a library /
     * rating / review change alters every library-derived shelf.
     */
    public function invalidate(int $userId): void
    {
        // The app's error handler turns every PHP error into an
        // exception, so the file is only unlinked when it really
        // exists - invalidating a user with no cached shelf (or a
        // second time in a row, e.g. refresh + wishlist toggle) is
        // a quiet no-op, never a crash.
        $file = $this->fileFor($userId);

        if (is_file($file)) {
            unlink($file);
        }

        foreach (glob($this->directory . '/section_' . $userId . '_*.json') ?: [] as $sectionFile) {
            @unlink($sectionFile);
        }
    }

    /**
     * Drop every cached payload (used by tests and maintenance).
     */
    public function flush(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        foreach (glob($this->directory . '/user_*.json') ?: [] as $file) {
            @unlink($file);
        }

        foreach (glob($this->directory . '/section_*.json') ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * The health picture of the cache (Phase 6.5 metrics).
     *
     * Input:  nothing
     * Output: files (how many user shelves are stored), bytes (their
     *         total size), stale (how many have outlived the TTL and
     *         will be treated as misses), users (the cached user ids),
     *         newest / oldest (file modification times), writable
     *         (whether a new file can be created) and directory
     *
     * Business responsibility: the read-only surface the admin
     * monitoring page shows - it lets an administrator see at a
     * glance whether the cache is doing its job or needs a flush.
     */
    public function stats(): array
    {
        $files = is_dir($this->directory)
            ? (glob($this->directory . '/user_*.json') ?: [])
            : [];

        $bytes   = 0;
        $stale   = 0;
        $users   = [];
        $newest  = 0;
        $oldest  = PHP_INT_MAX;

        foreach ($files as $file) {
            $size  = (int) @filesize($file);
            $mtime = (int) @filemtime($file);

            $bytes += $size;
            $users[] = (int) (preg_replace('/\D/', '', basename($file)) ?: 0);

            $expired = $this->ttlSeconds >= 0 && time() - $mtime > $this->ttlSeconds;

            if ($expired) {
                $stale++;
            }

            $newest = max($newest, $mtime);
            $oldest = min($oldest, $mtime);
        }

        return [
            'enabled'   => $this->enabled,
            'files'     => count($files),
            'bytes'     => $bytes,
            'stale'     => $stale,
            'users'     => array_values(array_unique(array_filter($users))),
            'newest'    => $newest === 0 ? null : $newest,
            'oldest'    => $oldest === PHP_INT_MAX ? null : $oldest,
            'writable'  => is_dir($this->directory) ? is_writable($this->directory) : is_writable(dirname($this->directory)),
            'directory' => $this->directory,
            'ttl'       => $this->ttlSeconds,
        ];
    }

    /**
     * The cache file of one user.
     */
    private function fileFor(int $userId): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . 'user_' . $userId . '.json';
    }

    // -----------------------------------------------------------------
    // Phase 8.5: per-section library shelves (separate cache files)
    // -----------------------------------------------------------------

    /**
     * Read the cached library-section shelf of one user, or null on a
     * miss.
     *
     * Input:  the user id and the section key
     * Output: the cached payload array, or null when there is no
     *         fresh file (never written, expired, or disabled)
     *
     * Business responsibility: the library sections of Phase 8.5
     * (because_you_read, similar_favourites, ...) are cached per user
     * PER SECTION under their own files, so the pages never re-run
     * the candidate pipeline on every request. The cache obeys the
     * same TTL as the hybrid shelf, and invalidate()/flush() drop the
     * section files together with the hybrid file - one signal change
     * refreshes every shelf of the user.
     *
     * @return array<string, mixed>|null
     */
    public function getSection(int $userId, string $section): ?array
    {
        if (!$this->enabled || !$this->validSectionKey($section)) {
            return null;
        }

        $file = $this->sectionFileFor($userId, $section);

        if (!is_file($file)) {
            return null;
        }

        if ($this->ttlSeconds >= 0 && time() - filemtime($file) > $this->ttlSeconds) {
            return null;
        }

        $payload = json_decode((string) file_get_contents($file), true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * Store the library-section shelf of one user.
     *
     * Input:  the user id, the section key and the payload
     * Output: nothing (the file is written atomically, like the
     *         hybrid shelf)
     */
    public function putSection(int $userId, string $section, array $payload): void
    {
        if (!$this->enabled || !$this->validSectionKey($section)) {
            return;
        }

        if (!is_dir($this->directory) && !mkdir($this->directory, 0755, true)) {
            throw new RuntimeException('Cache directory is not writable: ' . $this->directory);
        }

        $file = $this->sectionFileFor($userId, $section);
        $temp = $file . '.' . uniqid('', true) . '.tmp';

        file_put_contents($temp, (string) json_encode($payload, JSON_UNESCAPED_SLASHES));

        if (!rename($temp, $file)) {
            @unlink($temp);
        }
    }

    /**
     * A section key may only contain lowercase letters and
     * underscores - the key becomes part of a file name, so anything
     * else is rejected (never sanitized into place).
     */
    private function validSectionKey(string $section): bool
    {
        return preg_match('/^[a-z_]+$/', $section) === 1;
    }

    /**
     * The section cache file of one user.
     */
    private function sectionFileFor(int $userId, string $section): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . 'section_' . $userId . '_' . $section . '.json';
    }
}
