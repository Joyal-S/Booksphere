<?php

declare(strict_types=1);

namespace BookSphere\App\Middleware;

use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Services\AuthService;

/**
 * AuthMiddleware
 *
 * Protects pages that require a logged-in user (profile, change
 * password): guests are redirected to the login page with a notice.
 * Logged-in users pass through untouched.
 */
final class AuthMiddleware
{
    public function __construct(private readonly AuthService $auth) {}

    public function handle(Request $request, callable $next): mixed
    {
        if (!$this->auth->check()) {
            session()->flash('error', 'Please log in to continue.');

            Response::redirect('/login');
        }

        return $next();
    }
}
