<?php

declare(strict_types=1);

namespace BookSphere\App\Core;

/**
 * Request
 *
 * A small, safe wrapper around the PHP superglobals ($_SERVER,
 * $_GET, $_POST). Controllers ask the Request object for the
 * HTTP method, the URL path, and form input values instead of
 * touching the superglobals directly.
 *
 * It exists so the rest of the application has one predictable
 * way to read what the client asked for.
 */
final class Request
{
    /**
     * Return the current HTTP method (e.g. "GET", "POST").
     *
     * Phase 9.2: the _method override - a plain HTML form can only
     * submit GET or POST, so a hidden "_method" field with the value
     * PATCH or DELETE rewrites a POST into that verb here. This lets
     * every state-changing action (unfollow, mark-read) run with its
     * true HTTP semantics while the no-JavaScript fallback keeps
     * posting the form. The value is checked against the closed
     * allowlist, so a tampered field can never produce another verb.
     */
    public function method(): string
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if ($method === 'POST') {
            $override = strtoupper((string) ($_POST['_method'] ?? ''));

            if (in_array($override, ['PATCH', 'DELETE'], true)) {
                return $override;
            }
        }

        return $method;
    }

    /**
     * Return the URL path without the query string.
     *
     * "https://example.com/books?q=php" becomes "/books".
     * A trailing slash is removed so "/books/" and "/books" match
     * the same route.
     */
    public function path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        return rtrim($path, '/') ?: '/';
    }

    /**
     * Return a sanitized input value from POST first, then GET.
     *
     * @param mixed $default Value returned when the key does not exist
     */
    public function input(string $key, mixed $default = null): mixed
    {
        $value = $_POST[$key] ?? $_GET[$key] ?? $default;

        // Trim surrounding whitespace from text fields, keep other types untouched.
        return is_string($value) ? trim($value) : $value;
    }

    /**
     * Return a sanitized value from the POST BODY ONLY (Phase 9.6:
     * the CSRF token is read through this - a token in the query
     * string is never accepted, so a stray ?_token=... on a GET link
     * can neither validate a forged request nor leak a live token).
     *
     * @param mixed $default Value returned when the key does not exist
     */
    public function post(string $key, mixed $default = null): mixed
    {
        $value = $_POST[$key] ?? $default;

        return is_string($value) ? trim($value) : $value;
    }

    /**
     * Return a request header value, or null when it was not sent.
     *
     * The header name is case-insensitive ("X-Requested-With" and
     * "x-requested-with" are the same header). Used by controllers
     * that answer the same route with JSON for fetch() calls and a
     * redirect for plain form submissions.
     */
    public function header(string $name): ?string
    {
        $name = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return isset($_SERVER[$name]) ? (string) $_SERVER[$name] : null;
    }

    /**
     * Check whether the request used a given HTTP method.
     */
    public function isMethod(string $method): bool
    {
        return $this->method() === strtoupper($method);
    }

    /**
     * Return an uploaded file entry, or null when no file was sent.
     *
     * PHP stores uploads in $_FILES as arrays with the keys
     * name, full_path, type, tmp_name, error and size. Only the
     * raw entry is returned here - the type/size/name are NOT
     * trusted, so validation (mime sniffing, size limit) is done
     * by the service layer before the file is stored.
     *
     * @param string $key The "name" attribute of the file input
     * @return array<string, mixed>|null
     */
    public function file(string $key): ?array
    {
        $file = $_FILES[$key] ?? null;

        // UPLOAD_ERR_NO_FILE means the input was present but empty,
        // which is treated exactly like "no file sent".
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return $file;
    }
}
