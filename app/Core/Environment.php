<?php

declare(strict_types=1);

namespace BookSphere\App\Core;

use RuntimeException;

/**
 * Environment
 *
 * Loads the application's configuration from a simple ".env"
 * file (KEY=VALUE lines) into the $_ENV superglobal, and offers
 * a typed get() to read the values back.
 *
 * It exists so machine-specific settings (database path, debug
 * flag, ...) live in one file that is NOT committed to Git, while
 * the code reads them through the env() helper.
 */
final class Environment
{
    public function __construct(private readonly string $file) {}

    /**
     * Parse the .env file and store every key/value pair in $_ENV.
     *
     * Empty lines and lines starting with "#" (comments) are skipped.
     * The file is optional: if it does not exist, the defaults in
     * the configuration files are used instead.
     */
    public function load(): void
    {
        if (!is_file($this->file)) {
            return;
        }

        $lines = file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            throw new RuntimeException('Unable to read environment configuration.');
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Split "KEY=VALUE" into two parts; missing value becomes "".
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');

            $_ENV[trim($key)] = trim($value, " \t\n\r\0\x0B\"");
        }
    }

    /**
     * Read an environment value with automatic type conversion.
     *
     * Values are looked up in $_ENV first (the .env file), then in
     * the real environment variables as a fallback. The literals
     * "true", "false" and "null" are converted to their real PHP
     * types, so "APP_DEBUG=true" can be read as a boolean.
     *
     * Static, because reading an environment value needs no state:
     * the values live in $_ENV after load() has run.
     *
     * @param mixed $default Returned when the variable does not exist
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $raw = $_ENV[$key] ?? getenv($key);

        // getenv() returns false when the variable is not set.
        if ($raw === false) {
            return $default;
        }

        return self::convertValue((string) $raw);
    }

    /**
     * Convert a raw string value into a typed PHP value.
     *
     * Surrounding whitespace and quotes are removed first, so
     * APP_DEBUG="false" and APP_DEBUG=false behave the same way.
     */
    private static function convertValue(string $value): mixed
    {
        $cleaned = trim($value, " \t\n\r\0\x0B\"");

        return match (strtolower($cleaned)) {
            'true'  => true,
            'false' => false,
            'null'  => null,
            default => $cleaned,
        };
    }
}
