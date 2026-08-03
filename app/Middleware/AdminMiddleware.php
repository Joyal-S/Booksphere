<?php

declare(strict_types=1);

namespace BookSphere\App\Middleware;

use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Services\AuthService;

/**
 * AdminMiddleware
 *
 * Protects administrator-only pages: guests are sent to the login
 * page and logged-in non-admin users receive a 403 Forbidden
 * response. It is the role-based authorization gate of the
 * application.
 */
final class AdminMiddleware
{
    public function __construct(private readonly AuthService $auth) {}

    public function handle(Request $request, callable $next): mixed
    {
        if (!$this->auth->check()) {
            session()->flash('error', 'Please log in to continue.');

            Response::redirect('/login');
        }

        if (!$this->auth->isAdmin()) {
            Response::error(403, 'Forbidden: this area is restricted to administrators.');
        }

        return $next();
    }
}
