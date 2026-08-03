<?php

declare(strict_types=1);

namespace BookSphere\App\Middleware;

use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Services\AuthService;

/**
 * GuestMiddleware
 *
 * Protects guest-only pages (login, register, forgot password):
 * when a logged-in user visits one of them, they are sent back to
 * the home page instead. Guests pass through untouched.
 */
final class GuestMiddleware
{
    public function __construct(private readonly AuthService $auth) {}

    public function handle(Request $request, callable $next): mixed
    {
        if ($this->auth->check()) {
            Response::redirect('/');
        }

        return $next();
    }
}
