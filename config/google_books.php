<?php

declare(strict_types=1);

/**
 * config/google_books.php
 *
 * Google Books provider configuration (Phase 10.1 - architecture).
 *
 * The module is OPTIONAL by design: with GOOGLE_BOOKS_ENABLED=false
 * (the default) the application works exactly as before - no request
 * is made, no cache is written, and a missing API key can never break
 * a page. Enabling the module only turns on the provider layer; the
 * search / import / sync features themselves arrive in the Phase 10.2+
 * sub-phases and read everything they need from this one file.
 *
 * Every value can be overridden through environment variables in the
 * ".env" file, so the same code runs on any machine without edits.
 *
 * Note on the API key: it comes ONLY from the environment (never
 * hardcoded here) - the ".env" file is gitignored, so the key stays
 * out of the repository. The key is optional: the Google Books API
 * answers unauthenticated requests too, but with much harder
 * per-IP rate limits (Google Books API policies apply).
 *
 * Values are read with dot notation, e.g. config('google_books.client.timeout_seconds').
 */

return [
    // Master switch: false (default) = the whole provider layer is
    // a no-op; true = Google Books becomes the configured provider.
    'enabled' => (bool) env('GOOGLE_BOOKS_ENABLED', false),

    // The REST endpoint family of the provider (no trailing slash).
    // Kept configurable so a test double / local mock can be pointed
    // at without touching code.
    'base_url' => (string) env('GOOGLE_BOOKS_BASE_URL', 'https://www.googleapis.com/books/v1'),

    // Optional API key (server-side only, never exposed to views).
    'api_key' => env('GOOGLE_BOOKS_API_KEY', null),

    // --- HTTP client behaviour --------------------------------------
    'client' => [
        // Per-socket timeout in seconds (connection + reads).
        'timeout_seconds' => (int) env('GOOGLE_BOOKS_TIMEOUT', 10),
        // How many times a TRANSIENT failure (network error, HTTP 429,
        // 5xx) is retried before the request is reported as failed.
        'retry_attempts'  => (int) env('GOOGLE_BOOKS_RETRIES', 2),
        // Base delay between retries in milliseconds (exponential
        // backoff: base, base*2, ...).
        'retry_backoff_ms' => (int) env('GOOGLE_BOOKS_RETRY_BACKOFF_MS', 500),
        // The User-Agent header sent with every request.
        'user_agent'      => 'BookSphere/1.0 (+' . rtrim((string) env('APP_URL', ''), '/') . ')',
    ],

    // --- Search ------------------------------------------------------
    'search' => [
        // The hard cap on results the API may be asked for per call
        // (Google Books allows up to 40; the app keeps a lower ceiling
        // so a single search never bursts the rate budget).
        'max_results' => (int) env('GOOGLE_BOOKS_MAX_RESULTS', 20),
        // The maximum number of results a page may display.
        'display_limit' => (int) env('GOOGLE_BOOKS_DISPLAY_LIMIT', 10),
        // Maximum length of a submitted search term (input guard).
        'query_max_length' => 100,
    ],

    // --- Caching (Phase 10.1 strategy: file-based, like the
    // recommendation PersonalizationCache - no new table, no daemon).
    // The downloaded-cover settings moved to the "covers" section in
    // Phase 10.4; this section now only carries the metadata cache.
    'cache' => [
        // How long a SEARCH result set stays fresh (seconds).
        'search_ttl_seconds'  => (int) env('GOOGLE_BOOKS_SEARCH_CACHE_TTL', 900),
        // How long a per-volume metadata record stays fresh (seconds).
        'volume_ttl_seconds'  => (int) env('GOOGLE_BOOKS_VOLUME_CACHE_TTL', 86400),
        // Directory for the metadata response cache.
        'directory'           => root_path('database/cache/google_books'),
        // After this many consecutive provider failures the module
        // switches to cache-only mode for the backoff window below.
        'circuit_breaker' => [
            'max_failures'      => 3,
            'recovery_seconds'  => (int) env('GOOGLE_BOOKS_CIRCUIT_RECOVERY', 300),
        ],
    ],

    // --- Cover download & cache (Phase 10.4) --------------------------
    // Covers are downloaded once, optimized, stored under
    // public/assets/covers/google/ and served locally forever after -
    // a re-import never asks the provider again. Every value can be
    // overridden through the GOOGLE_BOOKS_COVER_* environment flags.
    'covers' => [
        // Whether the download pipeline runs at all (default true when
        // the module is enabled). The module's own "enabled" switch
        // above still gates the whole provider layer.
        'enabled' => (bool) env('GOOGLE_BOOKS_COVERS_ENABLED', true),

        // Storage folder (web-accessible, under public/) and its URL
        // prefix. Files are named <sha1(source url)>.<ext>, so the
        // same provider URL is cached once no matter how many books
        // reference it.
        'directory'      => root_path('public/assets/covers/google'),
        'public_prefix'  => '/assets/covers/google/',

        // How long a downloaded cover is considered fresh before a
        // re-fetch is attempted (future-ready TTL; Phase 10.5 sync).
        'ttl_seconds'    => (int) env('GOOGLE_BOOKS_COVER_CACHE_TTL', 30 * 86400),

        // HTTP client behaviour for image downloads (mirrors the
        // provider client's timeout/retry conventions).
        'timeout_seconds' => (int) env('GOOGLE_BOOKS_COVER_TIMEOUT', 10),
        'retry_attempts'  => (int) env('GOOGLE_BOOKS_COVER_RETRIES', 2),
        'retry_backoff_ms' => (int) env('GOOGLE_BOOKS_COVER_RETRY_BACKOFF_MS', 250),
        'max_redirects'   => 5,
        // Hard size limit for one downloaded cover (streaming abort -
        // the file never lands in memory in full).
        'max_bytes'       => (int) env('GOOGLE_BOOKS_COVER_MAX_BYTES', 5 * 1024 * 1024),

        // Dimension guards for the SOURCE image (validation, before
        // any decode): a cover below min or above max is rejected, so
        // the optimizer never decodes an absurdly large bitmap.
        'min_width'       => 50,
        'min_height'      => 50,
        'max_source_dimension' => 4000,

        // Image optimization (GD based). When GD is missing the
        // validated original is stored untouched instead of failing.
        'optimize' => [
            'enabled'        => function_exists('imagecreatetruecolor'),
            // Covers are downscaled to at most this many pixels on
            // their longest side (cards never display larger).
            'max_dimension'  => 800,
            // JPEG re-encode quality (0-100); 82 keeps visual parity
            // with the source while stripping metadata.
            'jpeg_quality'   => 82,
        ],
    ],

    // --- Images ------------------------------------------------------
    'images' => [
        // The preferred cover size requested from the provider.
        // Google Books returns the same thumbnail URL family; this
        // value is mapped by the provider when building the cover
        // request. Accepted values: "small", "thumbnail", "small_zoom",
        // "medium" (see the provider mapping in Phase 10.2).
        'size' => (string) env('GOOGLE_BOOKS_IMAGE_SIZE', 'thumbnail'),
    ],

    // --- Import (Phase 10.3+) ----------------------------------------
    'import' => [
        // The books.status assigned to freshly imported books.
        'default_status' => (string) env('GOOGLE_BOOKS_DEFAULT_STATUS', 'published'),
        // Whether covers are downloaded and stored locally on import
        // (true) or the remote provider URL is kept in cover_image
        // (false - lighter, but no offline cover).
        'fetch_covers' => (bool) env('GOOGLE_BOOKS_FETCH_COVERS', true),
    ],

    // --- Synchronization (Phase 10.5+) --------------------------------
    'sync' => [
        // Whether the periodic metadata re-fetch job is allowed to run.
        'enabled' => (bool) env('GOOGLE_BOOKS_SYNC_ENABLED', false),
        // How many books one sync job run processes per batch.
        'batch_size' => (int) env('GOOGLE_BOOKS_SYNC_BATCH', 25),
    ],
];
