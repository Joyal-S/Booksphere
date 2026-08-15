<?php

declare(strict_types=1);

namespace BookSphere\App\Core;

/**
 * Logger
 *
 * Robust, structured, single-line JSON log manager for Phase 13.5 (Logging & Observability).
 *
 * Features:
 *     - Single-line JSON log formatting with gmdate ISO 8601 UTC timestamps
 *     - Request Correlation ID tracking (`req_...`) attached to every entry
 *     - Log Injection protection (sanitizes control characters / newlines)
 *     - Automatic Sensitive Data Redaction (passwords, tokens, cookies, secrets)
 *     - Log File Rotation (5 MB threshold, 5 backup generations)
 *     - Fail-safe execution (write failures degrade gracefully without crashing requests)
 *     - Strict Web Root Separation (`storage/logs/` with 0750 permissions)
 */
final class Logger
{
    private static ?string $requestId = null;

    /** Keys matching sensitive data pattern that must be redacted. */
    private const SENSITIVE_KEYS = [
        'password',
        'password_hash',
        'password_confirmation',
        'token',
        'csrf',
        'csrf_token',
        'session',
        'session_id',
        'cookie',
        'authorization',
        'api_key',
        'secret',
        'remember_token',
        'reset_token',
        'access_token',
        'refresh_token',
    ];

    public function __construct(
        private string $file = '',
        private readonly int $maxSizeBytes = 5242880, // 5 MB
        private readonly int $maxFiles = 5,
    ) {
        if ($this->file === '') {
            $this->file = root_path('storage/logs/application.log');
        }
    }

    /**
     * Get or initialize the request correlation ID.
     */
    public static function getRequestId(): string
    {
        if (self::$requestId === null) {
            $headerReqId = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
            if (is_string($headerReqId) && preg_match('/^[a-zA-Z0-9_\-]+$/', $headerReqId) === 1) {
                self::$requestId = substr($headerReqId, 0, 64);
            } else {
                self::$requestId = 'req_' . bin2hex(random_bytes(8));
            }
        }

        return self::$requestId;
    }

    /**
     * Set a custom request correlation ID (e.g. in tests).
     */
    public static function setRequestId(?string $requestId): void
    {
        self::$requestId = $requestId;
    }

    public function debug(string $message, array $context = []): void
    {
        $this->write('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->write('notice', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->write('critical', $message, $context);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $this->write(strtolower($level), $message, $context);
    }

    /**
     * Append a structured single-line JSON log entry.
     */
    private function write(string $level, string $message, array $context): void
    {
        try {
            $directory = dirname($this->file);
            if (!is_dir($directory)) {
                @mkdir($directory, 0750, true);
            }

            $this->rotateLogsIfNeeded();

            $sanitizedMessage = $this->sanitizeString($message);
            $redactedContext  = $this->redactContext($context);

            $entry = json_encode(
                [
                    'time'       => gmdate('Y-m-d\TH:i:s.v\Z'),
                    'request_id' => self::getRequestId(),
                    'level'      => $level,
                    'message'    => $sanitizedMessage,
                    'context'    => $redactedContext,
                ],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );

            if ($entry !== false) {
                @file_put_contents($this->file, $entry . "\n", FILE_APPEND | LOCK_EX);
            }
        } catch (\Throwable) {
            // Fail safe: logging errors must never break user request processing
        }
    }

    /**
     * Sanitize strings to prevent log injection / log forging.
     */
    private function sanitizeString(string $value): string
    {
        // Replace CRLF / control characters with spaces to prevent line breaking
        return (string) preg_replace('/[\r\n\t\x00-\x1F\x7F]+/', ' ', $value);
    }

    /**
     * Recursively redact sensitive data from context array.
     */
    public function redactContext(array $context): array
    {
        $clean = [];

        foreach ($context as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if (in_array($normalizedKey, self::SENSITIVE_KEYS, true)) {
                $clean[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->redactContext($value);
            } elseif (is_string($value)) {
                $clean[$key] = $this->sanitizeString($value);
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /**
     * Rotate log files if file size exceeds maxSizeBytes.
     */
    private function rotateLogsIfNeeded(): void
    {
        if (!is_file($this->file) || filesize($this->file) < $this->maxSizeBytes) {
            return;
        }

        for ($i = $this->maxFiles - 1; $i >= 1; $i--) {
            $source = $this->file . '.' . $i;
            $target = $this->file . '.' . ($i + 1);

            if (is_file($source)) {
                if ($i + 1 > $this->maxFiles) {
                    @unlink($source);
                } else {
                    @rename($source, $target);
                }
            }
        }

        @rename($this->file, $this->file . '.1');
    }
}
