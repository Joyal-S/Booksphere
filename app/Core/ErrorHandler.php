<?php

declare(strict_types=1);

namespace BookSphere\App\Core;

use ErrorException;
use Throwable;

/**
 * ErrorHandler
 *
 * Registers PHP's central error and exception handlers so that
 * every failure in the application is:
 *
 *     1. Logged to the log file (with details)
 *     2. Turned into a safe HTTP 500 response
 *
 * In debug mode the error message is shown in the browser to help
 * during development. In production only a generic message is
 * shown, so no internal details leak to visitors.
 */
final class ErrorHandler
{
    public function __construct(
        private readonly Logger $logger,
        private readonly bool $debug,
    ) {}

    /**
     * Install the application's error and exception handlers.
     */
    public function register(): void
    {
        // Every PHP warning/notice becomes an exception, so a single
        // handling path (the exception handler) covers all failures.
        set_error_handler(function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(function (Throwable $exception): never {
            $this->logger->error($exception->getMessage(), [
                'type'  => $exception::class,
                'file'  => $exception->getFile(),
                'line'  => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);

            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');

            if ($this->debug) {
                exit('<h1>Application error</h1><p>' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>');
            }

            exit('<h1>Something went wrong.</h1>');
        });
    }
}
