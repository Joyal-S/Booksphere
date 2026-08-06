<?php

declare(strict_types=1);

namespace BookSphere\App\Exceptions;

use RuntimeException;

/**
 * GoogleBooksException
 *
 * The single exception type of the Google Books provider module
 * (Phase 10.2), mirroring ReviewException and LibraryException of
 * the other modules.
 *
 * Every failure of the provider layer is thrown with a REASON - a
 * stable, machine-readable string - so the presentation layer (and
 * the tests) can map it to a friendly message and an HTTP status
 * without string-matching exception messages:
 *
 *     - network          -> curl could not connect / DNS / TLS
 *     - timeout          -> the request outlived the configured timeout
 *     - rate_limited     -> HTTP 429 (carries the provider's Retry-After)
 *     - invalid_response -> HTTP 200/4xx/5xx payload that is not a
 *                           valid volume search response
 *     - not_found        -> a single-volume lookup returned HTTP 404
 *     - invalid_isbn     -> the checksum rejected the ISBN before any
 *                           request was made
 *     - duplicate        -> the book already exists in the catalogue
 *     - unavailable      -> the provider is disabled or the circuit
 *                           breaker is open (cache-only mode)
 *
 * Automatic recovery is the norm: the SERVICE layer catches these,
 * records the failure for the circuit breaker, logs once and answers
 * with a graceful ProviderSearchResult - a broken request never takes
 * a page down. Only the raw transport exceptions ("we could not even
 * talk to the API") bubble here; the database layer's PDO errors are
 * deliberately NOT wrapped (they follow the shared ErrorHandler path
 * exactly like every other module).
 *
 * Future extension: a second provider (Open Library, ISBNdb) throws
 * through the same class, so the error handling never forks.
 */
final class GoogleBooksException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $reason,
        int $statusCode = 502,
        private readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message, $statusCode);
    }

    /** The reason label of this failure ("network", "rate_limited", ...). */
    public function reason(): string
    {
        return $this->reason;
    }

    /** The provider's Retry-After value, or null (rate_limited only). */
    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    public static function networkFailure(string $detail = ''): self
    {
        return new self(
            'Could not reach the Google Books API' . ($detail !== '' ? ": $detail" : '') . '.',
            'network',
        );
    }

    public static function timeout(int $seconds): self
    {
        return new self(
            "The Google Books API request timed out after {$seconds} seconds.",
            'timeout',
        );
    }

    public static function rateLimited(?int $retryAfterSeconds = null): self
    {
        return new self(
            'Google Books is rate-limiting requests right now. Please wait a moment and try again.',
            'rate_limited',
            503,
            $retryAfterSeconds,
        );
    }

    public static function invalidResponse(string $detail = ''): self
    {
        return new self(
            'Google Books returned an unexpected response.' . ($detail !== '' ? " ({$detail})" : ''),
            'invalid_response',
        );
    }

    public static function notFound(): self
    {
        return new self('The requested Google Books volume was not found.', 'not_found', 404);
    }

    public static function invalidIsbn(string $isbn): self
    {
        return new self("The ISBN \"{$isbn}\" does not pass the checksum", 'invalid_isbn', 422);
    }

    public static function duplicateBook(): self
    {
        return new self('This book already exists in the catalogue.', 'duplicate', 409);
    }

    public static function unavailable(): self
    {
        return new self('The Google Books service is currently unavailable. Please try again later.', 'unavailable');
    }
}