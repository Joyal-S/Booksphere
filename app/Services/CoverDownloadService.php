<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\Exceptions\GoogleBooksException;
use BookSphere\App\Models\Book;
use RuntimeException;
use Throwable;

/**
 * CoverDownloadService
 *
 * The Phase 10.4 cover pipeline: download a provider cover image,
 * validate it, optimize it, store it under the public cache folder
 * and attach it to the imported book - in ONE call (attach()).
 *
 * Orchestration (controller stays thin - the importer forwards to
 * this service, this service decides everything):
 *
 *     1. cache hit    -> a fresh local copy of the same provider URL
 *                        already exists -> reuse it, zero network
 *     2. download     -> streamed to a temp file (never loaded into
 *                        memory whole), with transient-failure retries
 *     3. validate     -> MediaService::validateFile(): MIME sniffed
 *                        from content, size cap, decodable dimensions,
 *                        structural check (PNG/JPEG/WebP integrity)
 *     4. optimize     -> GD: oversized covers downscaled, everything
 *                        re-encoded (metadata stripped). Without GD
 *                        the validated original is stored untouched
 *     5. store        -> deterministic name sha1(source url).jpg under
 *                        public/assets/covers/google/, atomic rename
 *     6. the book row -> cover_image = local path, source url, download
 *                        timestamp and status recorded - so every page
 *                        shows the cached local file, never the provider
 *
 * Failure policy: attach() NEVER throws. A download that fails (404,
 * timeout, oversized, corrupt, unwritable) degrades to the BookSphere
 * placeholder: cover_image is cleared and cover_status = 'failed', so
 * no view ever hits the provider for this book again and an import
 * that succeeds can never be turned into a failure by its cover.
 *
 * Security (Task 9): only http(s) URLs are accepted, the stored file
 * name is sha1(source_url) - never user input -, the MIME type is
 * sniffed from the CONTENT, a streaming byte cap stops oversized
 * payloads, and the cache writes are atomic renames.
 *
 * Config (config/google_books.php): the "covers" section holds
 * directory / public_prefix / ttl_seconds / timeout / retries /
 * max_bytes / dimension guards / optimize (<- GD based).
 */
class CoverDownloadService
{
    /** A usable cover was freshly cached (or reused). */
    public const STATUS_DOWNLOADED = 'downloaded';

    /** A download failed; cover_image is cleared (placeholder shows). */
    public const STATUS_FAILED = 'failed';

    /** The provider record had no cover to begin with. */
    public const STATUS_NONE = 'none';

    /** The stored file extensions this cache ever produces. */
    private const CACHE_EXTENSIONS = ['jpg', 'png', 'webp'];

    /** Failure reasons that deserve a retry (everything else fails fast). */
    private const RETRYABLE_REASONS = ['network', 'timeout', 'rate_limited'];

    public function __construct(
        private readonly Book $books,
        private readonly MediaService $media,
        private readonly array $config = [],
    ) {}

    /**
     * Whether the download pipeline may run. Follows the module's own
     * master switch AND the covers.enabled flag.
     */
    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false)
            && (bool) ($this->config['covers']['enabled'] ?? true);
    }

    /**
     * Attach a cover to an imported book. ONE call does the cache
     * look-up, download, validation, optimization, storage and the
     * books-row update - and NEVER throws.
     *
     * @param string      $bookId    The local books.id
     * @param string|null $sourceUrl The provider's cover URL (e.g.
     *                               ProviderBookDTO->thumbnail), or null
     *                               when the record has no cover
     * @return string One of the STATUS_* constants (stored to cover_status)
     */
    public function attach(string $bookId, ?string $sourceUrl): string
    {
        $url = trim((string) $sourceUrl);

        if ($url === '') {
            return $this->recordNone($bookId);
        }

        try {
            // 1. Fresh local copy exists for this exact URL -> reuse it.
            if (($cached = $this->findCached($url)) !== null && $this->isFreshFile($cached['file'])) {
                $this->recordDownloaded($bookId, $url, $cached['publicUrl'], (int) $cached['mtime']);

                return self::STATUS_DOWNLOADED;
            }

            // 2. Download (retry loop) to a temp file.
            $temp = $this->download($url);

            // 3. Validate the BYTES, not the URL/extension.
            $error = $this->media->validateFile($temp);

            if ($error !== null) {
                @unlink($temp);

                return $this->recordFailed($bookId, $url);
            }

            // 4. Optimize (resize + normalize + strip metadata) or keep
            //    the validated original when GD is unavailable. NOTE:
            //    the passthrough path returns the SAME path as $temp,
            //    so the source may only be cleaned up AFTER storage.
            $optimized = $this->optimize($temp);

            // 5. Atomic store under the deterministic name.
            $publicUrl = $this->store($optimized, $url);

            // 6. Attach to the book.
            $this->recordDownloaded($bookId, $url, $publicUrl, time());

            // The GD output (when produced) and the download temp file
            // are no longer needed - the cache copy is the survivor.
            if ($optimized !== $temp) {
                @unlink($temp);
            }
            @unlink($optimized);

            return self::STATUS_DOWNLOADED;
        } catch (Throwable $error) {
            // One defensive net: no matter what broke (disk, rename,
            // DB), the book keeps its placeholder and 'failed' status.
            try {
                $this->recordFailed($bookId, $url);
            } catch (Throwable) {
                // Even the status update failed - nothing left to do.
            }

            return self::STATUS_FAILED;
        }
    }

    /**
     * Drop the cached file(s) of a source URL. Used by tests and the
     * Phase 10.5 sync to force a clean re-download.
     *
     * @return bool Whether anything was actually deleted
     */
    public function invalidate(string $sourceUrl): bool
    {
        $deleted = false;

        foreach ($this->candidateFiles($sourceUrl) as $file) {
            if (is_file($file) && @unlink($file)) {
                $deleted = true;
            }
        }

        return $deleted;
    }

    /**
     * Delete a stored cover by its PUBLIC url - but only when it lives
     * inside THIS cache (no path traversal: only the basename is ever
     * touched). Called when an admin removes/replaces a book cover.
     */
    public function deleteLocal(?string $publicUrl): void
    {
        if (!is_string($publicUrl) || !str_starts_with($publicUrl, $this->publicPrefix())) {
            return;
        }

        $file = $this->directory() . DIRECTORY_SEPARATOR . basename($publicUrl);

        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Whether a cached copy of the source URL is still fresh (Task 5:
     * cache expiration is readable by the Phase 10.5 sync). A ttl of
     * 0 means "never expires". Fresh copies are reused by attach();
     * stale ones are re-fetched.
     */
    public function isFresh(string $sourceUrl): bool
    {
        foreach ($this->candidateFiles($sourceUrl) as $file) {
            if (is_file($file)) {
                return $this->isFreshFile($file);
            }
        }

        return false;
    }

    /**
     * The cache health picture (admin page + tests).
     */
    public function stats(): array
    {
        $files = [];
        $bytes = 0;

        foreach (self::CACHE_EXTENSIONS as $ext) {
            foreach (glob($this->directory() . '/*.' . $ext) ?: [] as $file) {
                $files[] = $file;
                $bytes  += (int) @filesize($file);
            }
        }

        return [
            'enabled'       => $this->isEnabled(),
            'directory'     => $this->directory(),
            'public_prefix' => $this->publicPrefix(),
            'files'         => count($files),
            'bytes'         => $bytes,
            'writable'      => is_writable($this->directory()) || !is_dir($this->directory()),
            'ttl_seconds'   => $this->ttl(),
        ];
    }

    // -----------------------------------------------------------------
    // Download
    // -----------------------------------------------------------------

    /**
     * ONE HTTP attempt at the cover URL. Protected so a test double
     * can answer with canned bytes (the same seam as
     * GoogleBooksClient::send()).
     *
     * The body is STREAMED into a temp file: a WRITEFUNCTION counts
     * the bytes and aborts the transfer once max_bytes is exceeded,
     * so a huge payload never fills memory nor disk.
     *
     * Transient failures throw the matching GoogleBooksException
     * (timeout / network / rate_limited / "HTTP 5xx"); permanent ones
     * (404, other 4xx, wrong scheme) throw not_found / invalid_response.
     *
     * @throws GoogleBooksException
     */
    protected function attempt(string $url): string
    {
        $timeout      = (int) ($this->config['covers']['timeout_seconds'] ?? 10);
        $maxBytes     = (int) ($this->config['covers']['max_bytes'] ?? 5 * 1024 * 1024);
        $maxRedirects = max(0, (int) ($this->config['covers']['max_redirects'] ?? 5));

        $temp   = $this->tempPath();
        $handle = @fopen($temp, 'wb');

        if ($handle === false) {
            throw GoogleBooksException::networkFailure('could not open a temp file');
        }

        $bytes   = 0;
        $aborted = false;

        // Follow redirects MANUALLY so every hop re-passes the SSRF
        // guard: an attacker can point the first URL anywhere, but a
        // redirect into a private network is stopped here. The number
        // of hops is still capped by covers.max_redirects.
        $current  = $url;
        $redirects = 0;

        while (true) {
            if (!$this->validSourceUrl($current)) {
                @fclose($handle);
                @unlink($temp);
                throw GoogleBooksException::invalidResponse('invalid cover URL');
            }

            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL            => $current,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => (string) ($this->config['client']['user_agent'] ?? 'BookSphere/1.0'),
                CURLOPT_HEADER         => false,
                CURLOPT_WRITEFUNCTION  => function ($ch, string $data) use (&$bytes, &$aborted, $handle, $maxBytes): int {
                    $bytes += strlen($data);

                    if ($bytes > $maxBytes) {
                        $aborted = true;

                        return 0; // curl stops the transfer (CURLE_WRITE_ERROR)
                    }

                    return (int) fwrite($handle, $data);
                },
            ]);

            $body   = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $errno  = curl_errno($ch);
            $error  = curl_error($ch);

            $location = trim((string) curl_getinfo($ch, CURLINFO_REDIRECT_URL));

            curl_close($ch);

            if ($aborted) {
                @fclose($handle);
                @unlink($temp);
                throw GoogleBooksException::invalidResponse('cover exceeds the size limit');
            }

            // A redirect (301/302/303/307/308) with a Location hops to
            // the next URL and is re-validated; relative targets are
            // resolved against the previous hop.
            if (in_array($status, [301, 302, 303, 307, 308], true) && $location !== '') {
                if ($redirects >= $maxRedirects) {
                    @fclose($handle);
                    @unlink($temp);
                    throw GoogleBooksException::invalidResponse('too many redirects');
                }

                $redirects++;

                $next = $this->resolveLocation($current, $location);

                if ($next === '') {
                    @fclose($handle);
                    @unlink($temp);
                    throw GoogleBooksException::invalidResponse('invalid redirect URL');
                }

                $current = $next;

                continue;
            }

            if ($body === false) {
                @fclose($handle);
                @unlink($temp);
                throw $errno === CURLE_OPERATION_TIMEOUTED
                    ? GoogleBooksException::timeout($timeout)
                    : GoogleBooksException::networkFailure($error !== '' ? $error : null);
            }

            if ($status === 404) {
                @fclose($handle);
                @unlink($temp);
                throw GoogleBooksException::notFound();
            }

            if ($status === 429) {
                @fclose($handle);
                @unlink($temp);
                throw GoogleBooksException::rateLimited();
            }

            if ($status >= 400 || $status === 0) {
                @fclose($handle);
                @unlink($temp);
                throw GoogleBooksException::invalidResponse("HTTP {$status}");
            }

            @fclose($handle);

            return $temp;
        }
    }

    /**
     * Resolve a Location header against the URL it came from (relative
     * redirects included); '' when the result is unusable.
     */
    private function resolveLocation(string $base, string $location): string
    {
        if ($location !== '' && preg_match('~^[a-z][a-z0-9+.\-]*://~i', $location)) {
            return $location;
        }

        $parts = (array) parse_url($base);

        if (empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $leads = str_starts_with($location, '/');

        $path = $location !== '' && !$leads ? rtrim((string) ($parts['path'] ?? ''), '/') . '/' . ltrim($location, '/') : ($location !== '' ? $location : (string) ($parts['path'] ?? '/'));

        $scheme   = isset($parts['scheme']) ? $parts['scheme'] . '://' : '//';
        $append   = isset($parts['port']) && $parts['port'] !== '' ? ':' . $parts['port'] : '';

        return $scheme . $parts['host'] . $append . $path . (isset($parts['query']) && $parts['query'] !== '' && $path === (string) ($parts['path'] ?? '/') ? '?' . $parts['query'] : '');
    }

    /**
     * The retry loop around attempt(): transient failures (network,
     * timeout, rate-limited, 5xx) are retried with backoff up to
     * covers.retry_attempts; permanent ones fail fast. Returns the
     * path of the downloaded temp file.
     *
     * @throws GoogleBooksException
     */
    private function download(string $url): string
    {
        if (!$this->validSourceUrl($url)) {
            throw GoogleBooksException::invalidResponse('invalid cover URL');
        }

        $attempts = max(0, (int) ($this->config['covers']['retry_attempts'] ?? 2));
        $backoff  = max(50, (int) ($this->config['covers']['retry_backoff_ms'] ?? 250));
        $last     = null;

        for ($i = 0; $i <= $attempts; $i++) {
            try {
                return $this->attempt($url);
            } catch (GoogleBooksException $error) {
                $last = $error;

                if (!in_array($error->reason(), self::RETRYABLE_REASONS, true)) {
                    throw $error;
                }
            }

            if ($i < $attempts) {
                usleep($backoff * (2 ** $i) * 1000);
            }
        }

        throw $last ?? GoogleBooksException::networkFailure('retries exhausted');
    }

    // ---------------------------------------------------------------------
    // Validate + optimize + store
    // ---------------------------------------------------------------------

    /**
     * Optimization step (Task 4). GD rescues oversized covers to at
     * most optimize.max_dimension on the longest side and re-encodes
     * every format: JPEG (quality from config) for opaque images, PNG
     * preserved only when the source carries transparency. Re-encoding
     * strips EXIF profiles/comments in one go ("remove unnecessary
     * metadata"). Without GD the validated original is passed through
     * untouched - performance over pixel-perfection.
     */
    private function optimize(string $path): string
    {
        $optimize = (array) ($this->config['covers']['optimize'] ?? []);

        if (empty($optimize['enabled']) || !function_exists('imagecreatetruecolor')) {
            return $path;
        }

        $maxDimension = max(1, (int) ($optimize['max_dimension'] ?? 800));
        $quality      = max(50, min(100, (int) ($optimize['jpeg_quality'] ?? 82)));

        try {
            $mime  = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            $image = match ($mime) {
                'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : false,
                'image/png'  => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : false,
                'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
                default      => false,
            };

            if ($image === false) {
                return $path; // undecodable here -> keep the validated bytes
            }

            if (!imageistruecolor($image)) {
                $width  = imagesx($image);
                $height = imagesy($image);
                $true   = imagecreatetruecolor($width, $height);
                imagecopy($true, $image, 0, 0, 0, 0, $width, $height);
                imagedestroy($image);
                $image = $true;
            }

            $width  = imagesx($image);
            $height = imagesy($image);

            if (max($width, $height) > $maxDimension) {
                $ratio = $maxDimension / (float) max($width, $height);
                $newW  = max(1, (int) round($width * $ratio));
                $newH  = max(1, (int) round($height * $ratio));
                $scaled = imagecreatetruecolor($newW, $newH);
                imagecopyresampled($scaled, $image, 0, 0, 0, 0, $newW, $newH, $width, $height);
                imagedestroy($image);
                $image = $scaled;
            }

            $output = $this->tempPath();

            if ($mime === 'image/png' && $this->hasAlpha($image)) {
                imagesavealpha($image, true);
                imagepng($image, $output, 7);
            } else {
                // Normalized format: opaque covers become JPEG.
                imagejpeg($image, $output, $quality);
            }

            imagedestroy($image);

            return $output;
        } catch (Throwable) {
            return $path; // every GD fringe case falls back to original
        }
    }

    /**
     * Move the (optimized) temp file into the cache folder under the
     * deterministic name and return its public URL path. The write is
     * atomic (temp file + rename), so concurrent imports of the same
     * URL can never observe a half-written cover.
     *
     * @throws RuntimeException when the directory/file cannot be written
     */
    private function store(string $tempPath, string $url): string
    {
        $directory = $this->directory();

        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new RuntimeException('The cover directory is not writable.');
        }

        $extension = $this->extensionOf($tempPath);
        $final     = $directory . DIRECTORY_SEPARATOR . sha1($url) . '.' . $extension;
        $staging   = $final . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (!@copy($tempPath, $staging)) {
            throw new RuntimeException('The cover could not be saved.');
        }

        @chmod($staging, 0644);

        if (!@rename($staging, $final)) {
            @unlink($staging);
            throw new RuntimeException('The cover could not be saved.');
        }

        return $this->publicPrefix() . basename($final);
    }

    // ---------------------------------------------------------------------
    // Cache helpers
    // ---------------------------------------------------------------------

    /**
     * An existing cached copy of a source URL, or null. The filename
     * is deterministic (sha1 of the source URL) but the extension
     * depends on the stored format, so every candidate is checked.
     *
     * @return array{file: string, publicUrl: string, mtime: int}|null
     */
    private function findCached(string $url): ?array
    {
        foreach ($this->candidateFiles($url) as $file) {
            if (is_file($file)) {
                return [
                    'file'      => $file,
                    'publicUrl' => $this->publicPrefix() . basename($file),
                    'mtime'     => (int) filemtime($file),
                ];
            }
        }

        return null;
    }

    /**
     * The full filesystem path of every candidate cache file for a
     * source URL (one per possible extension). The sha1 of the URL is
     * the entire filename - no user input is ever interpolated, so
     * directory traversal is impossible by construction.
     *
     * @return array<int, string>
     */
    private function candidateFiles(string $url): array
    {
        $paths = [];

        foreach (self::CACHE_EXTENSIONS as $ext) {
            $paths[] = $this->directory() . DIRECTORY_SEPARATOR . sha1($url) . '.' . $ext;
        }

        return $paths;
    }

    /** Whether a cached file is still inside the TTL window. */
    private function isFreshFile(string $file): bool
    {
        // The stat cache would otherwise serve the mtime captured at
        // download time - a file touched (or re-cached) later must be
        // judged by its REAL age.
        clearstatcache(true, $file);

        $ttl = $this->ttl();
        $age = (int) (time() - (int) @filemtime($file));

        return $ttl <= 0 || $age <= $ttl;
    }

    // ---------------------------------------------------------------------
    // Books-row records
    // ---------------------------------------------------------------------

    private function recordDownloaded(string $bookId, string $url, string $publicUrl, int $timestamp): void
    {
        $this->books->updateCover((int) $bookId, [
            'cover_image'         => $publicUrl,
            'cover_source_url'    => $url,
            'cover_downloaded_at' => gmdate('Y-m-d\TH:i:s\Z', $timestamp),
            'cover_status'        => self::STATUS_DOWNLOADED,
        ]);
    }

    private function recordFailed(string $bookId, string $url): string
    {
        $this->books->updateCover((int) $bookId, [
            'cover_image'      => null,
            'cover_source_url' => $url,
            'cover_status'     => self::STATUS_FAILED,
        ]);

        return self::STATUS_FAILED;
    }

    private function recordNone(string $bookId): string
    {
        $this->books->updateCover((int) $bookId, [
            'cover_image'      => null,
            'cover_source_url' => null,
            'cover_status'     => self::STATUS_NONE,
        ]);

        return self::STATUS_NONE;
    }

    // -----------------------------------------------------------------
    // Small config/format helpers
    // -----------------------------------------------------------------

    /**
     * Whether a URL may be fetched at all. http/https ONLY, and never
     * a loopback / private / link-local / CGNAT / reserved address or
     * an internal hostname - the cover URL travels straight from the
     * provider, so an SSRF guard must sit at the fetch gate (Task 11:
     * security). Pure string/IP math keeps this warning-free and
     * deterministic - no DNS, no sockets.
     */
    private function validSourceUrl(string $url): bool
    {
        $parts  = (array) parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower((string) ($parts['host'] ?? ''));

        if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
            return false;
        }

        // Literal addresses get the numeric range check (an attacker
        // cannot smuggle a private network address past it with a
        // TLD trick). Non-literal hostnames are matched by name.
        return $this->isIpLiteral($host)
            ? !$this->isBlockedIpv4($host) && !$this->isBlockedIpv6($host)
            : !$this->isBlockedHostname($host);
    }

    private function isIpLiteral(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Blocked hostnames: the loopback name, DNS suffixes reserved for
     * internal use, and the cloud-metadata host the server itself
     * would resolve to.
     */
    private function isBlockedHostname(string $host): bool
    {
        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
            || $host === 'metadata.google.internal'
            || $host === 'metadata';
    }

    /**
     * Blocked IPv4 ranges: 0/8, loopback 127/8, RFC1918 (10/8,
     * 172.16/12, 192.168/16), link-local 169.254/16, CGNAT 100.64/10,
     * benchmark/docs 192.0.2/24 198.51.100/24 203.0.113/24,
     * multicast 224/4 and reserved 240/4.
     */
    private function isBlockedIpv4(string $host): bool
    {
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $long = (int) sprintf('%u', (int) ip2long($host));

        return $long < 0x01000000                                  // 0.0.0.0/8
            || $long >= 0x0A000000 && $long <= 0x0AFFFFFF          // 10.0.0.0/8
            || $long >= 0x64400000 && $long <= 0x647FFFFF          // 100.64.0.0/10
            || $long >= 0x7F000000 && $long <= 0x7FFFFFFF          // 127.0.0.0/8
            || $long >= 0xA9FE0000 && $long <= 0xA9FEFFFF          // 169.254.0.0/16
            || $long >= 0xAC100000 && $long <= 0xAC1FFFFF          // 172.16.0.0/12
            || $long >= 0xC0000000 && $long <= 0xC00000FF          // 192.0.0.0/24
            || $long >= 0xC0A80000 && $long <= 0xC0A8FFFF          // 192.168.0.0/16
            || $long >= 0xC6120000 && $long <= 0xC633FFFF          // 198.18.0.0/15
            || $long >= 0xC6336400 && $long <= 0xC63364FF          // 198.51.100.0/24
            || $long >= 0xCB007100 && $long <= 0xCB0071FF          // 203.0.113.0/24
            || $long >= 0xE0000000                                 // 224.0.0.0/4 + 240/4
        ;
    }

    /**
     * Blocked IPv6: :: and ::1, IPv4-mapped (::ffff:a.b.c.d),
     * link-local fe80::/10 and ULA fc00::/7.
     */
    private function isBlockedIpv6(string $host): bool
    {
        $addr = @inet_pton($host);

        if ($addr === false || strlen($addr) !== 16) {
            return false;
        }

        // ::1 / :: (unspecified / loopback).
        if (strspn($addr, "\x00") >= 15) {
            return true;
        }

        // ::ffff:0:0/96 - run the embedded v4 through the v4 checks.
        if (strncmp($addr, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff", 12) === 0) {
            return $this->isBlockedIpv4(inet_ntop((string) substr($addr, 12, 4)));
        }

        // fe80::/10 (link-local) and fc00::/7 (unique local).
        return (ord($addr[0]) & 0xFE) === 0xFC
            || (ord($addr[0]) === 0xFE && (ord($addr[1]) & 0xC0) === 0x80);
    }

    /** The MIME type -> extension map for what the downloader stores. */
    private function extensionOf(string $path): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        return match ($mime) {
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => 'jpg',
        };
    }

    /** A unique temp file outside the webroot (streaming target). */
    private function tempPath(): string
    {
        return rtrim((string) sys_get_temp_dir(), '/\\')
            . DIRECTORY_SEPARATOR
            . 'booksphere_cover_' . bin2hex(random_bytes(8)) . '.img';
    }

    private function directory(): string
    {
        return (string) ($this->config['covers']['directory'] ?? root_path('public/assets/covers/google'));
    }

    private function publicPrefix(): string
    {
        return (string) ($this->config['covers']['public_prefix'] ?? '/assets/covers/google/');
    }

    private function ttl(): int
    {
        return max(0, (int) ($this->config['covers']['ttl_seconds'] ?? 30 * 86400));
    }

    private function hasAlpha(\GdImage $image): bool
    {
        $width  = imagesx($image);
        $height = imagesy($image);
        $points = [[0, 0], [$width - 1, 0], [0, $height - 1], [$width - 1, $height - 1], [intdiv($width, 2), intdiv($height, 2)]];

        foreach ($points as [$x, $y]) {
            if (((imagecolorat($image, (int) $x, (int) $y) & 0x7F000000) >> 24) > 0) {
                return true;
            }
        }

        return false;
    }
}