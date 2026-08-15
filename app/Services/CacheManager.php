<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use RuntimeException;

/**
 * CacheManager
 *
 * The response cache of the Google Books module (Phase 10.1 strategy):
 * a namespaced, file-based JSON cache that stores RAW provider payloads
 * (not DTOs) so the cache stays provider-agnostic and re-mapping stays
 * cheap.
 *
 * Design decisions (mirroring PersonalizationCache):
 *     - One JSON file per (namespace, key) under config('google_books.cache.directory')
 *       - no table, no daemon, no library.
 *     - Namespaces isolate concerns: 'search' (TTL 900s) and 'volume'
 *       (TTL 86400s) will never collide, and the Phase 10.5 sync can
 *       invalidate one namespace without touching the other.
 *     - Writes are atomic (temp file + rename), so concurrent requests
 *       can never observe a half-written entry.
 *     - TTLs come from config('google_books.cache.*'): search_ttl_seconds
 *       / volume_ttl_seconds.
 *
 * The cache is written BEFORE the service returns, so a subsequent
 * identical search is served without touching the provider.
 *
 * Graceful degradation: get() only returns FRESH entries; stale() also
 * returns EXPIRED ones - the circuit-open / failure path of
 * GoogleBooksService serves stale data instead of an empty page.
 */
final class CacheManager
{
    /** Namespace for search result sets. */
    public const NS_SEARCH = 'search';

    /** Namespace for single-volume records. */
    public const NS_VOLUME = 'volume';

    /**
     * @param array<string, int> $ttls  namespace => seconds (0 disables)
     */
    public function __construct(
        private readonly string $directory,
        private readonly array $ttls = [],
        private readonly bool $enabled = true,
    ) {}

    /**
     * A FRESH cached payload, or null on a miss (never written,
     * expired, or disabled).
     *
     * @return array<string, mixed>|null
     */
    public function get(string $namespace, string $key): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        $file = $this->fileFor($namespace, $key);

        if (!is_file($file)) {
            return null;
        }

        $ttl = $this->ttl($namespace);

        if ($ttl > 0 && time() - filemtime($file) > $ttl) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $payload = json_decode($raw, true);

        if (!is_array($payload)) {
            @unlink($file);
            return null;
        }

        return $payload;
    }

    /**
     * ANY cached payload, fresh or expired (the circuit-open / failure
     * fallback), or null when nothing was ever stored.
     *
     * @return array<string, mixed>|null
     */
    public function stale(string $namespace, string $key): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        $file = $this->fileFor($namespace, $key);

        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $payload = json_decode($raw, true);

        if (!is_array($payload)) {
            @unlink($file);
            return null;
        }

        return $payload;
    }

    /**
     * Store a payload (atomically and gracefully).
     *
     * @param array<string, mixed> $payload
     */
    public function put(string $namespace, string $key, array $payload): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $directory = $this->directoryFor($namespace);

            if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
                return;
            }

            $file = $this->fileFor($namespace, $key);
            $temp = $file . '.' . uniqid('', true) . '.tmp';

            if (@file_put_contents($temp, (string) json_encode($payload, JSON_UNESCAPED_SLASHES)) === false) {
                return;
            }

            if (!@rename($temp, $file)) {
                @unlink($temp);
            }
        } catch (\Throwable) {
            // Silently ignore cache write failures so core application request pipeline never crashes
        }
    }

    /**
     * Drop one entry, or a whole namespace when no key is given.
     */
    public function invalidate(string $namespace, ?string $key = null): void
    {
        if ($key !== null) {
            $file = $this->fileFor($namespace, $key);

            if (is_file($file)) {
                unlink($file);
            }

            return;
        }

        $directory = $this->directoryFor($namespace);

        foreach (glob($directory . '/*.json') ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * Drop every cached entry (tests + maintenance).
     */
    public function flush(): void
    {
        foreach ([self::NS_SEARCH, self::NS_VOLUME] as $namespace) {
            $this->invalidate($namespace);
        }
    }

    /**
     * Remove stale/expired cache files across all namespaces.
     */
    public function pruneStale(): int
    {
        $pruned = 0;

        foreach ([self::NS_SEARCH, self::NS_VOLUME] as $namespace) {
            $directory = $this->directoryFor($namespace);
            $ttl = $this->ttl($namespace);

            if ($ttl <= 0 || !is_dir($directory)) {
                continue;
            }

            foreach (glob($directory . '/*.json') ?: [] as $file) {
                if (time() - (int) @filemtime($file) > $ttl) {
                    if (@unlink($file)) {
                        $pruned++;
                    }
                }
            }
        }

        return $pruned;
    }

    /**
     * The health picture (admin page + tests): per namespace the entry
     * count, total bytes, stale count, ttl and directory - plus the
     * global enabled flag and writability.
     */
    public function stats(): array
    {
        $namespaces = [];

        foreach ([self::NS_SEARCH, self::NS_VOLUME] as $namespace) {
            $directory = $this->directoryFor($namespace);
            $files     = is_dir($directory) ? (glob($directory . '/*.json') ?: []) : [];

            $bytes = 0;
            $stale = 0;

            foreach ($files as $file) {
                $bytes += (int) @filesize($file);

                $ttl = $this->ttl($namespace);

                if ($ttl > 0 && time() - (int) @filemtime($file) > $ttl) {
                    $stale++;
                }
            }

            $namespaces[$namespace] = [
                'files'     => count($files),
                'bytes'     => $bytes,
                'stale'     => $stale,
                'ttl'       => $this->ttl($namespace),
                'directory' => $directory,
            ];
        }

        return [
            'enabled'   => $this->enabled,
            'directory' => $this->directory,
            'writable'  => is_writable($this->directory) || !is_dir($this->directory),
            'namespaces' => $namespaces,
        ];
    }

    private function ttl(string $namespace): int
    {
        return max(0, (int) ($this->ttls[$namespace] ?? 0));
    }

    private function fileFor(string $namespace, string $key): string
    {
        return $this->directoryFor($namespace) . DIRECTORY_SEPARATOR . sha1($key) . '.json';
    }

    private function directoryFor(string $namespace): string
    {
        return rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . $namespace;
    }
}