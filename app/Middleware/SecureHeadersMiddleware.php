<?php

declare(strict_types=1);

namespace BookSphere\App\Middleware;

use BookSphere\App\Core\Request;

/**
 * SecureHeadersMiddleware
 *
 * Adds browser security headers to every response. These headers
 * tell the browser how it is allowed to handle our page:
 *
 *     - X-Content-Type-Options  -> do not guess file types
 *     - X-Frame-Options         -> do not allow embedding in frames
 *     - Referrer-Policy         -> send limited referrer information
 *     - Permissions-Policy      -> block camera/microphone/geolocation
 *
 * It exists so basic hardening is applied automatically instead of
 * being repeated in every controller.
 */
final class SecureHeadersMiddleware
{
    /**
     * Send the security headers, then continue the request pipeline.
     */
    public function handle(Request $request, callable $next): mixed
    {
        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

            $csp = "default-src 'self'; "
                . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
                . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; "
                . "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; "
                . "img-src 'self' data: https://*.google.com https://*.google.co.in https://*.ggpht.com; "
                . "connect-src 'self';";

            header('Content-Security-Policy: ' . $csp);

            if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
            }
        }

        return $next();
    }
}
