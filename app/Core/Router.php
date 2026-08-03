<?php

declare(strict_types=1);

namespace BookSphere\App\Core;

/**
 * Router
 *
 * Receives every incoming request, reads the HTTP method and URL
 * path, and looks up the matching route so the correct controller
 * action can be executed.
 *
 * Think of this as the traffic controller of the application:
 * every request passes through here before anything else happens.
 *
 * Two kinds of routes are supported:
 *
 *     1. Exact routes         -> "/books" matches only "/books"
 *     2. Parameterized routes -> "/books/{id}" matches "/books/7"
 *                                and "7" is delivered to the action
 *                                as $params['id']
 *
 * Exact routes are checked first because a plain array lookup is
 * faster than pattern matching. Parameterized routes are only
 * tried when no exact route fits.
 */
final class Router
{
    /**
     * Exact-match routes: method -> path -> route.
     *
     * @var array<string, array<string, array{action: callable|array, middleware: array}>
     */
    private array $routes = [];

    /**
     * Parameterized routes: method -> list of compiled routes.
     * Each compiled route carries its regex pattern and the names
     * of its placeholders.
     *
     * @var array<string, array<int, array{action: callable|array, middleware: array, pattern: string, names: array<int, string>}>>
     */
    private array $parameterRoutes = [];

    public function __construct(
        private readonly Request $request,
        private readonly MiddlewarePipeline $pipeline,
    ) {}

    /**
     * Register a GET route.
     *
     * @param string          $path       URL path, e.g. "/books" or "/books/{id}"
     * @param callable|array  $action     Controller callable, e.g. [new BookController(), 'show']
     * @param array           $middleware Middleware instances that run before the action
     */
    public function get(string $path, callable|array $action, array $middleware = []): void
    {
        $this->register('GET', $path, $action, $middleware);
    }

    /**
     * Register a POST route (used for forms and state-changing actions).
     *
     * @param string          $path       URL path, e.g. "/books/store"
     * @param callable|array  $action     Controller callable
     * @param array           $middleware Middleware instances that run before the action
     */
    public function post(string $path, callable|array $action, array $middleware = []): void
    {
        $this->register('POST', $path, $action, $middleware);
    }

    /**
     * Dispatch the current request to its controller action.
     *
     * Resolution order:
     *     1. exact match in the route table (fast path)
     *     2. parameterized pattern match (slow path)
     *
     * If neither matches, a 404 response is sent. The matched
     * route then runs through the middleware pipeline before the
     * action receives the request and the extracted parameters.
     */
    public function dispatch(): void
    {
        $method = $this->request->method();
        $path   = $this->request->path();

        // 1. Fast path: exact lookup in the route table.
        $route  = $this->routes[$method][$path] ?? null;
        $params = [];

        // 2. Slow path: compare against parameterized patterns.
        if ($route === null) {
            [$route, $params] = $this->matchParameterizedRoute($method, $path);
        }

        if ($route === null) {
            Response::error(404, 'Page not found.');
        }

        $this->pipeline->handle(
            $this->request,
            $route['middleware'],
            fn () => call_user_func($route['action'], $this->request, $params),
        );
    }

    /**
     * Store a route in the correct table.
     *
     * Paths containing "{name}" placeholders are compiled into a
     * regular expression and stored separately; all other paths
     * go into the exact-match table.
     */
    private function register(string $method, string $path, callable|array $action, array $middleware): void
    {
        $route = compact('action', 'middleware');

        if (str_contains($path, '{')) {
            $route['pattern'] = $this->compilePattern($path);
            $route['names']   = $this->extractParameterNames($path);

            $this->parameterRoutes[$method][] = $route;

            return;
        }

        $this->routes[$method][$path] = $route;
    }

    /**
     * Try to match the path against every parameterized route
     * of the given HTTP method.
     *
     * @return array{0: array|null, 1: array} The matched route (or null)
     *                                        and its extracted parameters
     */
    private function matchParameterizedRoute(string $method, string $path): array
    {
        foreach ($this->parameterRoutes[$method] ?? [] as $route) {
            if (preg_match($route['pattern'], $path, $matches) !== 1) {
                continue;
            }

            // Collect every named placeholder value from the match.
            // rawurldecode() restores characters like spaces and
            // slashes that were percent-encoded in the URL.
            $params = [];

            foreach ($route['names'] as $name) {
                $params[$name] = rawurldecode($matches[$name]);
            }

            return [$route, $params];
        }

        return [null, []];
    }

    /**
     * Turn a route path into a regular expression.
     *
     * "/books/{id}" becomes "~^/books/(?P<id>[^/]+)$~"
     *
     * Every {placeholder} becomes a named capture group that
     * matches any single path segment (one or more characters
     * that are not a slash).
     */
    private function compilePattern(string $path): string
    {
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            fn (array $match): string => '(?P<' . $match[1] . '>[^/]+)',
            $path,
        );

        return '~^' . $pattern . '$~';
    }

    /**
     * Collect the placeholder names of a route path.
     *
     * "/books/{id}/reviews/{page}" -> ["id", "page"]
     *
     * @return array<int, string>
     */
    private function extractParameterNames(string $path): array
    {
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $path, $matches);

        return $matches[1];
    }
}
