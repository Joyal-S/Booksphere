<?php

declare(strict_types=1);

namespace BookSphere\App\Core;

/**
 * Csrf
 *
 * Protects state-changing requests (POST forms) against CSRF
 * attacks. A CSRF attack happens when a malicious website tricks
 * a logged-in user's browser into sending a request to our site.
 *
 * The protection works like this:
 *
 *     1. token() generates a random token, stored in the session
 *        and placed inside every form (hidden field "_token")
 *     2. validate() compares the submitted token with the stored
 *        one, using hash_equals() which is safe against timing
 *        attacks
 */
final class Csrf
{
    public function __construct(private readonly Session $session) {}

    /**
     * Return the current CSRF token, creating one when necessary.
     */
    public function token(): string
    {
        $token = $this->session->get('_csrf');

        if (!is_string($token)) {
            $token = bin2hex(random_bytes(32));
            $this->session->put('_csrf', $token);
        }

        return $token;
    }

    /**
     * Check whether a submitted token matches the stored one.
     *
     * @param string|null $token The token from the form
     */
    public function validate(?string $token): bool
    {
        return is_string($token) && hash_equals($this->token(), $token);
    }
}
