<?php

declare(strict_types=1);

namespace BookSphere\App\Exceptions;

use RuntimeException;

/**
 * SearchException
 *
 * The single exception type of the global search module (Phase 11.2),
 * mirroring GoogleBooksException / ReviewException of the other
 * modules. Every failure of the search layer is thrown with a REASON
 * - a stable, machine-readable string - so the presentation layer can
 * map it to an HTTP status and a friendly message without string
 * matching:
 *
 *     - disabled    the search module is switched off (config)
 *     - invalid     a query rejected after the request gate
 *     - unsupported a scope/provider the module does not know
 *     - timed_out   the search budget was exceeded
 *
 * Recovery: the search never 500s the page. The SERVICE layer catches
 * these, logs once, and answers a graceful SearchResult with an error
 * message; the controller turns disabled/invalid into the matching
 * HTTP status when it must. Database (PDO) errors are deliberately
 * NOT wrapped - they bubble to the shared ErrorHandler exactly like
 * every other module.
 */
final class SearchException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $reason,
        int $statusCode,
    ) {
        parent::__construct($message, $statusCode);
    }

    /** The stable reason label of this failure. */
    public function reason(): string
    {
        return $this->reason;
    }

    public static function disabled(): self
    {
        return new self(
            'Search is currently disabled.',
            'disabled',
            503,
        );
    }

    public static function invalid(string $detail = ''): self
    {
        return new self(
            'Invalid search query.' . ($detail !== '' ? " ($detail)" : ''),
            'invalid',
            422,
        );
    }

    public static function unsupported(string $detail = ''): self
    {
        return new self(
            'This search is not supported.' . ($detail !== '' ? " ($detail)" : ''),
            'unsupported',
            422,
        );
    }

    public static function timeout(): self
    {
        return new self(
            'The search timed out - please try again.',
            'timeout',
            500,
        );
    }
}