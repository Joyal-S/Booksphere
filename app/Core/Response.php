<?php

declare(strict_types=1);

namespace BookSphere\App\Core;

/**
 * Response
 *
 * Builds the HTTP response that is sent back to the browser.
 * It offers three common kinds of responses:
 *
 *     - Response::view()    -> render an HTML page
 *     - Response::redirect()-> send the browser to another URL
 *     - Response::error()   -> send a plain-text error page
 *
 * Controllers should always end by calling one of these methods.
 */
final class Response
{
    /**
     * Render a view with an HTTP status code.
     *
     * @param string      $view   Dot notation, e.g. "foundation.index"
     * @param array       $data   Key/value pairs made available to the template
     * @param int         $status HTTP status code, defaults to 200 OK
     * @param string|null $layout Layout name, e.g. "layouts.auth".
     *                            Defaults to the master layout.
     */
    public static function view(string $view, array $data = [], int $status = 200, ?string $layout = null): void
    {
        http_response_code($status);

        View::render($view, $data, $layout);
    }

    /**
     * Redirect the browser to another application path.
     *
     * @param string $path Absolute path, e.g. "/login"
     */
    public static function redirect(string $path): never
    {
        header('Location: ' . $path, true, 302);
        exit;
    }

    /**
     * Send a small plain-text error response and stop execution.
     */
    public static function error(int $status, string $message): never
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=UTF-8');
        exit($message);
    }

    /**
     * Send a JSON response (used by the live-search endpoint).
     *
     * The live search (/books/search) fetches fresh results with
     * JavaScript, so this method exists to give controllers one
     * standard way to answer with structured JSON instead of HTML.
     * The headers are only set while that is still possible, so a
     * caller that already emitted output degrades gracefully
     * instead of crashing the response with PHP warnings.
     */
    public static function json(array $data, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=UTF-8');
        }

        echo json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    }
}
