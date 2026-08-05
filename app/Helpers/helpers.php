<?php

declare(strict_types=1);

/**
 * Global helper functions.
 *
 * These small functions are available everywhere in the application
 * because composer.json loads this file automatically (the "files"
 * entry in the autoload section).
 *
 * They exist for two reasons:
 *     - avoid repeating the same tiny logic in many places (DRY)
 *     - give templates and config files safe, short access to
 *       escaping, asset URLs and project paths
 */

/**
 * Build an absolute path inside the project.
 *
 * Examples:
 *     root_path('config/app.php')  -> "D:/PROJECTS/booksphere/config/app.php"
 *     root_path()                  -> "D:/PROJECTS/booksphere"
 */
function root_path(string $path = ''): string
{
    return BOOKSPHERE_ROOT . ($path === '' ? '' : DIRECTORY_SEPARATOR . ltrim($path, '/\\'));
}

/**
 * Read a configuration value from the loaded environment.
 *
 * The value is looked up in $_ENV (the .env file) with a fallback
 * to real environment variables. The literals "true", "false" and
 * "null" are returned as their real PHP types.
 *
 * @param string $key     Variable name, e.g. "APP_DEBUG"
 * @param mixed  $default Fallback when the variable is not set
 */
function env(string $key, mixed $default = null): mixed
{
    return \BookSphere\App\Core\Environment::get($key, $default);
}

/**
 * Read the application configuration (config/*.php files).
 *
 * The config directory is parsed ONCE per request (the result is
 * cached in a static variable) and reused by every caller, so a
 * page that needs "app", "database" and "media" settings does not
 * read the files three times.
 *
 * Dot notation ("media.covers.max_bytes") walks into the loaded
 * groups; with no key the whole Config object is returned.
 *
 * @param string|null $key     Dot notation, e.g. "media.covers"
 * @param mixed       $default Fallback when the key does not exist
 */
function config(?string $key = null, mixed $default = null): mixed
{
    static $config = null;

    if ($config === null) {
        $config = \BookSphere\App\Core\Config::loadFromDirectory(root_path('config'));
    }

    return $key === null ? $config : $config->get($key, $default);
}

/**
 * Escape a value for safe HTML output.
 *
 * Use this around EVERY value printed in a view (e.g. <?= e($title) ?>).
 * It stops user input from being executed as HTML or JavaScript
 * (XSS protection).
 */
function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Build a URL for a file inside public/assets/.
 *
 * Example: asset('css/app.css') -> "/assets/css/app.css"
 */
function asset(string $path): string
{
    return '/assets/' . ltrim($path, '/');
}

/**
 * Return the shared database connection.
 *
 * Shortcut for Database::instance(): the connection is created
 * once, on first use, and reused for every later call. The
 * database path comes from config/database.php.
 */
function db(): \BookSphere\App\Core\Database
{
    return \BookSphere\App\Core\Database::instance();
}

/**
 * Return the shared session.
 *
 * Shortcut that mirrors db(): one Session instance per request,
 * reused everywhere. The session itself is already started by
 * Application::run(), so start() here is a safe no-op.
 */
function session(): \BookSphere\App\Core\Session
{
    static $session = null;

    if ($session === null) {
        $name = (string) config('app.session_name', 'booksphere_session');

        $session = new \BookSphere\App\Core\Session($name);
    }

    $session->start();

    return $session;
}

/**
 * Return the shared authentication service, or null when it has
 * not been wired up yet (routes/web.php calls setInstance()).
 */
function auth(): ?\BookSphere\App\Services\AuthService
{
    return \BookSphere\App\Services\AuthService::current();
}

/**
 * Return the currently logged-in user, or null for guests.
 *
 * The user array holds id, full_name, email and role and is stored
 * in the session at login time. Views use it to personalize the
 * navigation without a database query on every request.
 */
function auth_user(): ?array
{
    return auth()?->user();
}

/**
 * Whether a user is currently logged in.
 */
function auth_check(): bool
{
    return auth()?->check() ?? false;
}

/**
 * Whether the logged-in user has the administrator role.
 */
function auth_is_admin(): bool
{
    return auth()?->isAdmin() ?? false;
}

/**
 * Format a stored UTC timestamp as the short display date of the
 * review lists ("M j, Y" - e.g. "Dec 31, 2026").
 *
 * The database stores timestamps in UTC; this helper only formats,
 * it does not convert timezones (the app deliberately shows UTC
 * dates). Empty or invalid values render as an empty string, so
 * views never print the 1970 default date.
 */
function format_review_date(?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    $stamp = strtotime($value);

    return $stamp === false ? '' : date('M j, Y', $stamp);
}

/**
 * Return the current CSRF token for forms.
 *
 * Every form that changes data must include this as a hidden
 * "_token" field so CsrfMiddleware can verify the request.
 */
function csrf_token(): string
{
    static $csrf = null;

    if ($csrf === null) {
        $csrf = new \BookSphere\App\Core\Csrf(session());
    }

    return $csrf->token();
}
