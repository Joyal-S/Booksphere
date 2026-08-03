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
     * public user data is stored in the session.
     */
    public function attempt(string $email, string $password): bool
    {
        $user = $this->users->findByEmail($email);

        if ($user === null || !password_verify($password, $user['password'])) {
            $this->registerFailedAttempt();

            return false;
        }

        $this->login($user);

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
        $this->session->forget('auth_user_id');
        $this->session->forget('auth_user');

        // New session id again, so the "guest" session cannot be
        // linked back to the authenticated one.
        $this->session->regenerate();
    }

    /**
     * Whether a user is currently logged in.
     */
    public function check(): bool
    {
        return $this->session->get('auth_user') !== null;
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
