<?php

declare(strict_types=1);

namespace BookSphere\App\Core;

/**
 * Session
 *
 * A small wrapper around PHP's native session functions.
 * It starts the session once with secure cookie settings and
 * provides put()/get() for storing and reading values.
 *
 * It exists so the rest of the application never calls the
 * session_* functions directly and every session starts with
 * the same security configuration.
 */
final class Session
{
    public function __construct(private readonly string $name) {}

    /**
     * Start the application session with hardened cookie settings.
     *
     * The cookie is HttpOnly (not readable by JavaScript) and
     * SameSite=Lax (helps prevent CSRF). The "Secure" flag is
     * enabled automatically when the site runs over HTTPS.
     */
    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name($this->name);

        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);

        session_start();
    }

    /**
     * Regenerate the session identifier.
     *
     * Call this after a privilege change (e.g. after login)
     * to protect against session fixation attacks.
     */
    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    /**
     * Store a value in the session.
     */
    public function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Read a value from the session.
     *
     * @param mixed $default Value returned when the key does not exist
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Remove a value from the session.
     */
    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Store a one-time flash message (shown on the next page load).
     *
     * Flash messages survive exactly one request: a view reads them
     * with getFlash() and clears them with clearFlash(). They are
     * used for "Your profile has been updated." style feedback after
     * a redirect (PRG pattern).
     */
    public function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    /**
     * Read a flash message without removing it.
     *
     * @param mixed $default Value returned when the key does not exist
     */
    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $_SESSION['_flash'][$key] ?? $default;
    }

    /**
     * Remove every flash message (called by the flash partial after
     * the messages have been displayed).
     */
    public function clearFlash(): void
    {
        unset($_SESSION['_flash']);
    }
}
