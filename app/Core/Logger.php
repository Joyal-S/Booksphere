<?php

declare(strict_types=1);

namespace BookSphere\App\Core;

/**
 * Logger
 *
 * Appends structured JSON lines to a log file, one entry per line.
 * JSON keeps every entry self-contained and easy to search.
 *
 * It exists so errors (and later other events) can be recorded
 * without cluttering the business code, and so the ErrorHandler
 * has one place to report what went wrong.
 */
final class Logger
{
    public function __construct(private readonly string $file) {}

    /**
     * Log an error-level entry (unexpected failures).
     */
    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    /**
     * Log a warning-level entry (suspicious or recoverable events).
     */
    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    /**
     * Log an info-level entry (normal application events).
     */
    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    /**
     * Append a single JSON log line to the log file.
     */
    private function write(string $level, string $message, array $context): void
    {
        $directory = dirname($this->file);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $entry = json_encode(
            [
                'time'    => gmdate('c'),
                'level'   => $level,
                'message' => $message,
                'context' => $context,
            ],
            JSON_UNESCAPED_SLASHES,
        );

        file_put_contents($this->file, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
