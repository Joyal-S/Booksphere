<?php

declare(strict_types=1);

namespace BookSphere\App\Controllers;

use BookSphere\App\Core\Controller;
use BookSphere\App\Core\RateLimiter;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Core\Validator;
use BookSphere\App\Models\PasswordResetToken;
use BookSphere\App\Models\User;
use BookSphere\App\Services\AuthService;

/**
 * AuthController
 *
 * Handles the whole account lifecycle:
 *
 *     - register        -> create an account
 *     - login           -> verify credentials, start a session and
 *                          (optionally) remember the login
 *     - logout          -> end the session and revoke the remember
 *                          token
 *     - forgot-password -> issue a single-use reset token
 *     - reset-password  -> redeem the token and change the password
 *
 * Every page renders inside the standalone layouts.auth shell (the
 * split brand panel) without the application chrome. POST actions are
 * protected by CsrfMiddleware at the route level; validation failures
 * re-render the form with errors + previous input, successes follow
 * the PRG pattern (redirect + flash), so a refresh cannot resubmit.
 *
 * Security notes:
 *     - login attempts are rate limited (AuthService lockout),
 *     - reset tokens are stored hashed, expire after 60 minutes and
 *       are single-use,
 *     - the forgot step never reveals whether an email exists: the
 *       neutral success screen is shown either way.
 */
final class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly User $users,
        private readonly PasswordResetToken $resetTokens,
        private readonly ?RateLimiter $limiter = null,
    ) {}

    // -----------------------------------------------------------------
    // Register
    // -----------------------------------------------------------------

    public function showRegister(Request $request, array $params = []): void
    {
        $this->view('auth.register', [
            'title'  => 'Create an account',
            'active' => 'register',
            'tabs'   => true,
            'old'    => [],
            'errors' => [],
        ], 'layouts.auth');
    }

    public function register(Request $request, array $params = []): void
    {
        $data = [
            'full_name'             => $request->input('full_name'),
            'email'                 => $request->input('email'),
            'password'              => $request->input('password'),
            'password_confirmation' => $request->input('password_confirmation'),
            'terms'                 => $request->input('terms') !== null,
        ];

        $errors = $this->validateRegistration($data);

        if ($errors !== []) {
            $this->view('auth.register', [
                'title'  => 'Create an account',
                'active' => 'register',
                'tabs'   => true,
                'old'    => $data,
                'errors' => $errors,
            ], 'layouts.auth');

            return;
        }

        $email = strtolower($data['email']);

        if ($this->users->emailExists($email)) {
            $this->view('auth.register', [
                'title'  => 'Create an account',
                'active' => 'register',
                'tabs'   => true,
                'old'    => $data,
                'errors' => ['email' => ['An account with this email address already exists.']],
            ], 'layouts.auth');

            return;
        }

        $this->users->create(
            $data['full_name'],
            $email,
            password_hash($data['password'], PASSWORD_DEFAULT),
        );

        session()->flash('success', 'Your account has been created. Please log in.');
        Response::redirect('/login');
    }

    // -----------------------------------------------------------------
    // Login
    // -----------------------------------------------------------------

    public function showLogin(Request $request, array $params = []): void
    {
        $this->view('auth.login', [
            'title'  => 'Log in',
            'active' => 'login',
            'tabs'   => true,
            'old'    => ['email' => ''],
            'errors' => [],
        ], 'layouts.auth');
    }

    public function login(Request $request, array $params = []): void
    {
        $email    = (string) $request->input('email');
        $remember = $request->input('remember') !== null;
        $password = (string) $request->input('password');

        $data = [
            'title'  => 'Log in',
            'active' => 'login',
            'tabs'   => true,
            'old'    => ['email' => $email, 'remember' => $remember],
            'errors' => [],
        ];

        if ($email === '' || $password === '') {
            $data['errors'] = ['email' => ['Invalid email or password.']];

            $this->view('auth.login', $data, 'layouts.auth');

            return;
        }

        if (!$this->auth->attempt($email, $password, $remember)) {
            $data['errors'] = ['password' => ['Invalid email or password.']];

            $this->view('auth.login', $data, 'layouts.auth');

            return;
        }

        $name = $this->auth->user()['full_name'] ?? '';

        session()->flash('success', "Welcome back, $name!");
        Response::redirect('/');
    }

    public function logout(Request $request, array $params = []): void
    {
        $this->auth->logout();

        session()->flash('success', 'You have been logged out.');
        Response::redirect('/login');
    }

    // -----------------------------------------------------------------
    // Forgot password (issue a reset token)
    // -----------------------------------------------------------------

    public function showForgotPassword(Request $request, array $params = []): void
    {
        $this->view('auth.forgot-password', [
            'title'      => 'Reset your password',
            'active'     => 'forgot',
            'tabs'       => false,
            'old'        => ['email' => ''],
            'errors'     => [],
            'sent'       => false,
            'sent_to'    => '',
            'reset_link' => null,
        ], 'layouts.auth');
    }

    public function forgotPassword(Request $request, array $params = []): void
    {
        $email = (string) $request->input('email');
        $data  = [
            'title'      => 'Reset your password',
            'active'     => 'forgot',
            'tabs'       => false,
            'old'        => ['email' => $email],
            'errors'     => [],
            'sent'       => false,
            'sent_to'    => '',
            'reset_link' => null,
        ];

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $data['errors'] = ['email' => ['Enter a valid email address.']];

            $this->view('auth.forgot-password', $data, 'layouts.auth');

            return;
        }

        if ($this->limiter !== null) {
            $ipKey = 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            $resetKey = 'reset:' . strtolower(trim($email));

            if (!$this->limiter->allow('forgot_password', 3, 900, $ipKey) || !$this->limiter->allow('forgot_password_email', 3, 900, $resetKey)) {
                $seconds = max(1, $this->limiter->remainingSeconds('forgot_password', 900, $ipKey));

                if (!headers_sent()) {
                    header('Retry-After: ' . $seconds);
                    http_response_code(429);
                }

                $data['sent'] = true;
                $data['sent_to'] = $email;

                $this->view('auth.forgot-password', $data, 'layouts.auth');

                return;
            }
        }

        $user = $this->users->findByEmail($email);

        // Issue a token only for real accounts, but ALWAYS show the
        // neutral summary screen so the form cannot be used to probe
        // which addresses have accounts.
        if ($user !== null) {
            $token = bin2hex(random_bytes(32));
            $this->resetTokens->create((int) $user['id'], hash('sha256', $token));

            // Demo mode: there is no mailer yet, so the reset link is
            // surfaced here so the flow can be exercised end to end.
            // When a mail service is added, this becomes the email body.
            $data['reset_link'] = '/reset-password?token=' . $token;
        }

        $data['sent']    = true;
        $data['sent_to'] = $email;

        $this->view('auth.forgot-password', $data, 'layouts.auth');
    }

    // -----------------------------------------------------------------
    // Reset password (redeem a token)
    // -----------------------------------------------------------------

    public function showResetPassword(Request $request, array $params = []): void
    {
        $token = (string) $request->input('token');

        $data = [
            'title'   => 'Choose a new password',
            'active'  => 'reset',
            'tabs'    => false,
            'token'   => $token,
            'old'     => [],
            'errors'  => [],
            'invalid' => $token === '' || $this->resetTokens->findValid($this->hash($token)) === null,
        ];

        $this->view('auth.reset-password', $data, 'layouts.auth');
    }

    public function resetPassword(Request $request, array $params = []): void
    {
        $token = (string) $request->input('token');
        $data  = [
            'title'   => 'Choose a new password',
            'active'  => 'reset',
            'tabs'    => false,
            'token'   => $token,
            'old'     => [],
            'errors'  => [],
            'invalid' => false,
        ];

        $record = $this->resetTokens->findValid($this->hash($token));

        if ($record === null) {
            $data['invalid'] = true;
            $this->view('auth.reset-password', $data, 'layouts.auth');

            return;
        }

        $data['old'] = ['password' => $request->input('password')];

        $password = (string) $request->input('password');
        $confirmation = (string) $request->input('password_confirmation');

        $validator = (new Validator([
            'password'              => $password,
            'password_confirmation' => $confirmation,
        ]))
        ->required('password', 'password')
        ->min('password', 8, 'password')
        ->required('password_confirmation', 'password confirmation')
        ->same('password_confirmation', 'password', 'passwords');

        if (!$validator->passes()) {
            $data['errors'] = $validator->errors();
            $this->view('auth.reset-password', $data, 'layouts.auth');

            return;
        }

        // Single-use: the token can never reset again, even if the
        // hash update below were interrupted.
        $this->resetTokens->consume((int) $record['id']);
        $this->resetTokens->deleteForUser((int) $record['user_id']);

        $this->users->updatePassword((int) $record['user_id'], password_hash($password, PASSWORD_DEFAULT));

        session()->flash('success', 'Your password has been updated. Please log in.');
        Response::redirect('/login');
    }

    // -----------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------

    private function validateRegistration(array $data): array
    {
        $validator = (new Validator($data))
        ->required('full_name', 'full name')
        ->max('full_name', 100, 'full name')
        ->required('email', 'email address')
        ->email('email')
        ->required('password', 'password')
        ->min('password', 8, 'password')
        ->required('password_confirmation', 'password confirmation')
        ->same('password_confirmation', 'password', 'passwords');

        $errors = $validator->passes() ? [] : $validator->errors();

        if (empty($data['terms'])) {
            $errors['terms'] = ['You must accept the Terms of Service to continue.'];
        }

        return $errors;
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}