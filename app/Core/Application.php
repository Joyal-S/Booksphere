<?php

declare(strict_types=1);

namespace BookSphere\App\Core;

/**
 * Application
 *
 * The heart of the application. It receives the configuration
 * object built by bootstrap/app.php and runs the full request
 * lifecycle:
 *
 *     1. Apply the configured timezone
 *     2. Start the session
 *     3. Register error handling and logging
 *     4. Create the request, router and middleware pipeline
 *     5. Load the route definitions
 *     6. Dispatch the request to the matching controller
 *
 * It exists so that the entry point (public/index.php) stays tiny
 * and every request follows the exact same bootstrap process.
 */
final class Application
{
    public function __construct(private readonly Config $config) {}

    /**
     * Execute the request lifecycle for this HTTP request.
     */
    public function run(): void
    {
        $this->applyTimezone();

        $this->startSession();

        $this->registerErrorHandling();

        $router = new Router(new Request(), new MiddlewarePipeline());

        require root_path('routes/web.php');

        $router->dispatch();
    }

    /**
     * Set the default timezone for all date/time functions.
     */
    private function applyTimezone(): void
    {
        date_default_timezone_set($this->config->get('app.timezone', 'UTC'));
    }

    /**
     * Start a secure session for the application.
     */
    private function startSession(): void
    {
        $session = new Session($this->config->get('app.session_name', 'booksphere_session'));
        $session->start();
    }

    /**
     * Register the central error and exception handler so that
     * every failure is logged and turned into a safe response.
     */
    private function registerErrorHandling(): void
    {
        $logger = new Logger(root_path('storage/logs/application.log'));

        $handler = new ErrorHandler($logger, (bool) $this->config->get('app.debug', false));
        $handler->register();
    }
}
