<?php

declare(strict_types=1);

/**
 * config/app.php
 *
 * General application configuration. Every setting can be
 * overridden through environment variables in the ".env" file,
 * so the same code runs on any machine without edits.
 *
 * Example: APP_DEBUG=true in .env -> 'debug' => true
 *
 * Values are read with dot notation, e.g. Config::get('app.debug').
 */

return [
    // Display name of the application.
    'name' => env('APP_NAME', 'BookSphere'),

    // Current environment: "development", "testing" or "production".
    'environment' => env('APP_ENV', 'production'),

    // When true, detailed error messages are shown in the browser.
    // env() already converts the literal "true"/"false" to booleans.
    'debug' => env('APP_DEBUG', false),

    // Base URL of the application (no trailing slash).
    'url' => rtrim((string) env('APP_URL', ''), '/'),

    // Default timezone for all PHP date/time functions.
    'timezone' => env('APP_TIMEZONE', 'UTC'),

    // Name of the session cookie.
    'session_name' => env('SESSION_NAME', 'booksphere_session'),
];
