<?php

declare(strict_types=1);

namespace BookSphere\App\Core;

/**
 * MiddlewarePipeline
 *
 * Runs a list of middleware pieces in order, then finally the
 * route action. Each middleware can inspect the request and decide
 * to stop the chain (e.g. reject a forged CSRF token) or pass the
 * request on to the next step.
 *
 * Think of it as a line of security guards: every request must get
 * past each guard before the controller is reached.
 */
final class MiddlewarePipeline
{
    /**
     * Execute every middleware, then the destination action.
     *
     * @param Request        $request     The current HTTP request
     * @param object[]       $middleware  Middleware instances with a handle() method
     * @param callable       $destination The final action (the controller call)
     */
    public function handle(Request $request, array $middleware, callable $destination): mixed
    {
        // Build the chain backwards so the first middleware in the
        // array runs first, wrapping every next step in a closure.
        $next = $destination;

        foreach (array_reverse($middleware) as $handler) {
            $next = fn () => $handler->handle($request, $next);
        }

        return $next();
    }
}
