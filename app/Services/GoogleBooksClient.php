<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\Exceptions\GoogleBooksException;

/**
 * GoogleBooksClient
 *
 * The HTTP TRANSPORT of the Google Books provider (Phase 10.1 Task 1).
 * It owns ONLY the mechanics of talking to the API:
 *
 *     - the base URL, the optional API key, the timeout
 *     - the retry loop (transient failures + exponential backoff)
 *     - JSON decoding and the typed GoogleBooksException mapping
 *     - the small response-shape normalizer (items list / single volume)
 *
 * It contains NO business logic, NO caching and NO field mapping -
 * GoogleBooksProvider decides what the payload MEANS. Every network
 * failure becomes a typed exception, so the provider/service can
 * classify and degrade gracefully (retry-worthy, rate limited, bad
 * response, ...) without parsing curl errors.
 *
 * Testability seam: `send()` performs ONE HTTP attempt. A test double
 * subclass overrides send() to return canned responses, which keeps
 * the retry/backoff/exception logic fully exercised without a network.
 *
 * Configuration comes from config/google_books.php (env-driven):
 * base_url, api_key, client.timeout_seconds, client.retry_attempts,
 * client.retry_backoff_ms, client.user_agent.
 */
class GoogleBooksClient
{
    /**
     * The search endpoint path (relative to the base URL).
     */
    public const PATH_SEARCH = '/volumes';

    /** Google's hard ceiling for the maxResults query parameter. */
    private const MAX_RESULTS_CEILING = 40;

    /** The longest acceptable sleep before a retry (seconds). */
    private const MAX_BACKOFF_SECONDS = 5;

    public function __construct(private readonly array $config = [])
    {
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? 'https://www.googleapis.com/books/v1'), '/');
    }

    private readonly string $baseUrl;

    /**
     * Perform ONE provider search and return the normalized payload.
     *
     * @return array{items: array<int, array<string, mixed>>, totalItems: int}
     */
    public function search(string $query, int $maxResults, int $startIndex): array
    {
        $payload = $this->request(self::PATH_SEARCH, [
            'q'          => $query,
            'maxResults' => $this->clampMaxResults($maxResults),
            'startIndex' => max(0, $startIndex),
            'country'    => 'US',
        ]);

        return [
            'items'      => array_values(is_array($payload['items'] ?? null) ? $payload['items'] : []),
            'totalItems' => (int) ($payload['totalItems'] ?? 0),
        ];
    }

    /**
     * Perform a single-volume lookup, or null when it was not found.
     *
     * @return array<string, mixed>|null
     */
    public function lookup(string $id): ?array
    {
        return $this->request('/volumes/' . rawurlencode($id));
    }

    /**
     * ONE network attempt over the configured base URL. Protected so a
     * test double can substitute the transport and drive the retry
     * logic in request() with canned responses.
     *
     * @return array{status: int, headers: array<int, string>, body: string}
     */
    protected function send(string $url): array
    {
        $ch = curl_init();

        $timeout = (int) ($this->config['client']['timeout_seconds'] ?? 10);

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => (string) ($this->config['client']['user_agent'] ?? 'BookSphere/1.0'),
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($ch);
        $errno  = curl_errno($ch);

        curl_close($ch);

        if ($body === false) {
            // curl level failure: a timeout is its own failure class,
            // everything else is a network failure.
            throw $errno === CURLE_OPERATION_TIMEOUTED
                ? GoogleBooksException::timeout($timeout)
                : GoogleBooksException::networkFailure($error !== '' ? $error : null);
        }

        return [
            'status'  => $status,
            'headers' => [],
            'body'    => (string) $body,
        ];
    }

    /**
     * The full URL for a relative path + query parameters. The API key
     * is appended as a query parameter ONLY when one is configured, so
     * unauthenticated requests (and test runs) work unchanged.
     */
    protected function url(string $path, array $params = []): string
    {
        $params['key'] = $this->apiKey();

        return $this->baseUrl . $path . '?' . http_build_query(array_filter($params));
    }

    /**
     * Issue one request with the retry loop.
     *
     * Retries ONLY transient failures: curl transport errors (network
     * / timeout), HTTP 408, HTTP 429 (honouring the provider's
     * Retry-After) and HTTP 5xx - up to client.retry_attempts tries
     * with exponential backoff. Everything else (400/401/403/404)
     * fails fast with the matching typed exception, so a broken
     * request never burns the retry budget.
     *
     * @return array<string, mixed> The decoded JSON body
     */
    private function request(string $path, array $params = []): array
    {
        $attempts      = max(0, (int) ($this->config['client']['retry_attempts'] ?? 2));
        $backoffMs     = max(50, (int) ($this->config['client']['retry_backoff_ms'] ?? 500));
        $lastTransport = null;

        for ($attempt = 0; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->send($this->url($path, $params));
            } catch (GoogleBooksException $error) {
                // Transport errors are transient by nature: retry after
                // the backoff, and remember the last one in case the
                // budget runs out.
                $lastTransport = $error;
                usleep($this->backoffMicroseconds($attempt, $backoffMs));
                continue;
            }

            $status = (int) $response['status'];

            if ($status === 429 && $attempt < $attempts) {
                // Rate limited: honour Retry-After (or a small default).
                $retryAfter = $this->retryAfterFrom($response['headers'] ?? []) ?? 1;
                usleep((int) min(self::MAX_BACKOFF_SECONDS, max(1, $retryAfter)) * 1_000_000);
                continue;
            }

            if (($status >= 500 || $status === 408) && $attempt < $attempts) {
                usleep($this->backoffMicroseconds($attempt, $backoffMs));
                continue;
            }

            return $this->parseResponse($status, $response['body']);
        }

        // The retry budget is exhausted: surface the last transport
        // error (if there was one) so the caller still gets a typed,
        // meaningful failure.
        throw $lastTransport ?? GoogleBooksException::invalidResponse('retries exhausted');
    }

    /**
     * The exponential backoff for one retry slot, in microseconds
     * (base * 2^attempt, capped at MAX_BACKOFF_SECONDS).
     */
    private function backoffMicroseconds(int $attempt, int $baseMs): int
    {
        return (int) min(self::MAX_BACKOFF_SECONDS * 1_000_000, $baseMs * (2 ** max(0, $attempt)) * 1000);
    }

    /**
     * Parse the last Retry-After header value (seconds), if present.
     */
    private function retryAfterFrom(array $headers): ?int
    {
        foreach ($headers as $header) {
            if (stripos((string) $header, 'Retry-After:') === 0) {
                $value = trim(substr((string) $header, 12));

                return (int) $value > 0 ? (int) $value : null;
            }
        }

        return null;
    }

    /**
     * Turn an HTTP status + body into the decoded array, or the
     * matching typed exception.
     *
     * @return array<string, mixed>
     */
    private function parseResponse(int $status, string $body): array
    {
        if ($status === 404) {
            throw GoogleBooksException::notFound();
        }

        if ($status === 429) {
            throw GoogleBooksException::rateLimited();
        }

        if ($status >= 400) {
            throw GoogleBooksException::invalidResponse("HTTP {$status}");
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw GoogleBooksException::invalidResponse('malformed JSON');
        }

        return $decoded;
    }

    /**
     * The query 'maxResults' parameter, clamped to Google's ceiling.
     */
    private function clampMaxResults(int $maxResults): int
    {
        return max(1, min(self::MAX_RESULTS_CEILING, $maxResults));
    }

    /**
     * The configured API key, or null when none was set.
     */
    private function apiKey(): ?string
    {
        $key = $this->config['api_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }
}