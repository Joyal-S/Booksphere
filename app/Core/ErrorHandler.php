<?php

declare(strict_types=1);

namespace BookSphere\App\Core;

use ErrorException;
use Throwable;

/**
 * ErrorHandler
 *
 * Central error and exception handler for Phase 13.5 (Logging & Observability).
 *
 *     1. Logs full exception details (request_id, route, method, class, file, line, trace)
 *     2. Returns safe HTTP 500 response with X-Request-ID correlation header
 *     3. Prevents internal path/SQL leakage in production mode
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
        set_error_handler(function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(function (Throwable $exception): never {
            $reqId = Logger::getRequestId();

            $this->logger->error($exception->getMessage(), [
                'type'       => $exception::class,
                'request_id' => $reqId,
                'route'      => $_SERVER['REQUEST_URI'] ?? 'CLI',
                'method'     => $_SERVER['REQUEST_METHOD'] ?? 'GET',
                'file'       => $exception->getFile(),
                'line'       => $exception->getLine(),
                'trace'      => $exception->getTraceAsString(),
            ]);

            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=UTF-8');
                header('X-Request-ID: ' . $reqId);
            }

            if ($this->debug) {
                exit('<h1>Application error</h1><p>' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>');
            }

            exit('<h1>Something went wrong.</h1>');
        });
    }
}
