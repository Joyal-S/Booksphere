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
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

        return $next();
    }
}
