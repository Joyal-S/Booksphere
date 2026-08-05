<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\Core\Session;
use BookSphere\App\Models\User;

/**
 * AuthService
 *
 * The single place that manages "who is logged in". It verifies
 * credentials against the users table, stores the logged-in user
 * in the session, and answers the questions the rest of the
 * application asks: is someone logged in, who is it, and does
 * that person have the admin role?
 *
 * Security measures it implements:
 *
 *     - passwords are verified with password_verify() against the
 *       stored bcrypt hash, never compared as plain text
 *     - the session id is regenerated on login and logout to
 *       prevent session fixation
 *     - login attempts are rate limited per session: after a few
 *       wrong passwords the login is locked for a cooling-off
 *       period, which slows down brute-force guessing
 *
 * The service is shared: routes/web.php creates one instance and
 * registers it with setInstance() so middleware and views reach it
 * through the auth() / auth_user() helpers.
 */
final class AuthService
{
    /** Wrong password attempts before the login is locked. */
    private const MAX_LOGIN_ATTEMPTS = 5;

    /** How long the login stays locked, in seconds (15 minutes). */
    private const LOCKOUT_SECONDS = 900;

    /** Name of the "remember me" cookie (persistent login token). */
    public const REMEMBER_COOKIE = 'booksphere_remember';

    /** How long the "remember me" cookie stays valid, in seconds (30 days). */
    private const REMEMBER_SECONDS = 2592000;

    /** The single shared instance (service locator for views). */
    private static ?self $instance = null;

    public function __construct(
        private readonly Session $session,
        private readonly User $users,
    ) {}

    /**
     * Register the shared instance so helpers can reach it.
     */
    public static function setInstance(self $service): void
    {
        self::$instance = $service;
    }

    /**
     * Return the shared instance, or null before it is wired up.
     */
    public static function current(): ?self
    {
        return self::$instance;
    }

    /**
     * Verify credentials and log the user in on success.
     *
     * On failure the attempt counter is increased (for rate
     * limiting). On success the session is regenerated and the
     * public user data is stored in the session; when $remember is
     * true a persistent "remember me" cookie is also issued.
     */
    public function attempt(string $email, string $password, bool $remember = false): bool
    {
        $user = $this->users->findByEmail($email);

        if ($user === null || !password_verify($password, $user['password'])) {
            $this->registerFailedAttempt();

            return false;
        }

        $this->login($user);

        if ($remember) {
            $this->rememberUser($user);
        }

        return true;
    }

    /**
     * Start an authenticated session for a user row.
     */
    public function login(array $user): void
    {
        // New session id: an attacker who knew the old one loses it.
        $this->session->regenerate();

        $this->session->put('auth_user_id', (int) $user['id']);
        $this->session->put('auth_user', $this->publicUser($user));

        $this->session->forget('login_attempts');
        $this->session->forget('login_locked_until');
    }

    /**
     * End the authenticated session.
     */
    public function logout(): void
    {
        $user = $this->user();

        $this->session->forget('auth_user_id');
        $this->session->forget('auth_user');

        if ($user !== null) {
            $this->forgetRememberUser($user);
        }

        // New session id again, so the "guest" session cannot be
        // linked back to the authenticated one.
        $this->session->regenerate();
    }

    /**
     * Whether a user is currently logged in.
     *
     * When there is no session user but a valid "remember me"
     * cookie, the cookie is silently exchanged for a real session
     * first. This is the hook every AuthMiddleware check and every
     * view reaches, so a remembered visitor is authenticated on the
     * very first request after returning.
     */
    public function check(): bool
    {
        return $this->session->get('auth_user') !== null
            || $this->restoreFromRememberCookie();
    }

    /**
     * The id of the logged-in user, or null for guests.
     */
    public function id(): ?int
    {
        $id = $this->session->get('auth_user_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * The logged-in user's public data, or null for guests.
     *
     * @return array{id: int, full_name: string, email: string, role: string}|null
     */
    public function user(): ?array
    {
        $user = $this->session->get('auth_user');

        return is_array($user) ? $user : null;
    }

    /**
     * Whether the logged-in user has the administrator role.
     */
    public function isAdmin(): bool
    {
        return ($this->user()['role'] ?? null) === 'admin';
    }

    /**
     * Replace the stored user data with fresh values.
     *
     * Called after a profile update so the session reflects the
     * new name/email without a logout.
     */
    public function refreshUser(array $user): void
    {
        $this->session->put('auth_user', $this->publicUser($user));
    }

    /**
     * Whether the login is currently locked because of too many
     * failed attempts.
     */
    public function tooManyAttempts(): bool
    {
        return $this->lockoutRemainingSeconds() > 0;
    }

    /**
     * How many seconds remain until the login lock expires.
     */
    public function lockoutRemainingSeconds(): int
    {
        $lockedUntil = (int) $this->session->get('login_locked_until', 0);

        return max(0, $lockedUntil - time());
    }

    /**
     * Count a failed login attempt and lock the login when the
     * limit is reached.
     */
    private function registerFailedAttempt(): void
    {
        $attempts = (int) $this->session->get('login_attempts', 0) + 1;

        $this->session->put('login_attempts', $attempts);

        if ($attempts >= self::MAX_LOGIN_ATTEMPTS) {
            $this->session->put('login_locked_until', time() + self::LOCKOUT_SECONDS);
        }
    }

    // -----------------------------------------------------------------
    // "Remember me"
    // -----------------------------------------------------------------

    /**
     * Issue a persistent login token for a remembered user.
     *
     * The raw token travels in an HttpOnly 30-day cookie
     * ("id:token"); only its SHA-256 hash is stored, so a leaked
     * database cannot be turned back into working cookies.
     *
     * @return string The raw token (what the cookie holds)
     */
    public function rememberUser(array $user): string
    {
        $token = bin2hex(random_bytes(32));

        $this->users->setRememberToken((int) $user['id'], hash('sha256', $token));
        $this->issueRememberCookie((int) $user['id'], $token);

        return $token;
    }

    /**
     * Revoke a user's remembered login: clear the stored hash (which
     * kills every device holding a cookie) and expire the cookie.
     */
    public function forgetRememberUser(array $user): void
    {
        $this->users->setRememberToken((int) $user['id'], null);
        $this->expireRememberCookie();
    }

    /**
     * Try to restore a session from the "remember me" cookie.
     *
     * Returns true (and logs the user in) only when the cookie holds
     * a token whose hash matches the stored one. The token is
     * ROTATED on every successful use, so a replayed cookie stops
     * working after the first restore. An unmatched token just
     * expires the cookie - no error is surfaced.
     */
    public function restoreFromRememberCookie(): bool
    {
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? null;

        if (!is_string($cookie) || $cookie === '') {
            return false;
        }

        $parts = explode(':', $cookie, 2);

        if (count($parts) !== 2 || !ctype_digit($parts[0])) {
            $this->expireRememberCookie();

            return false;
        }

        $user = $this->users->findById((int) $parts[0]);

        if (
            $user === null
            || $user['remember_token'] === null
            || !hash_equals((string) $user['remember_token'], hash('sha256', $parts[1]))
        ) {
            $this->expireRememberCookie();

            return false;
        }

        $this->login($user);

        // Single-use rotation: the restored cookie is immediately
        // replaced with a fresh token, so a stolen/replayed cookie is
        // worthless after the very first use.
        $this->rememberUser($user);

        return true;
    }

    /**
     * Set the "remember me" cookie (HttpOnly, SameSite=Lax, 30 days).
     */
    private function issueRememberCookie(int $userId, string $token): void
    {
        $this->setRememberCookie($userId . ':' . $token, time() + self::REMEMBER_SECONDS);
    }

    /**
     * Expire the "remember me" cookie in the browser.
     */
    private function expireRememberCookie(): void
    {
        $this->setRememberCookie('', time() - 3600);
    }

    /**
     * Write the cookie with the application's security parameters.
     */
    private function setRememberCookie(string $value, int $expires): void
    {
        setcookie(
            self::REMEMBER_COOKIE,
            $value,
            [
                'expires'  => $expires,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            ],
        );
    }

    /**
     * Reduce a user row to the fields that may live in the session.
     * The password hash must never be stored there.
     */
    private function publicUser(array $user): array
    {
        return [
            'id'        => (int) $user['id'],
            'full_name' => (string) $user['full_name'],
            'email'     => (string) $user['email'],
            'role'      => (string) $user['role'],
        ];
    }
}
