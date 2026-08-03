<?php

declare(strict_types=1);

namespace BookSphere\App\Controllers;

use BookSphere\App\Core\Controller;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Core\Validator;
use BookSphere\App\Models\User;
use BookSphere\App\Services\AuthService;

/**
 * AuthController
 *
 * Handles account creation and sign-in:
 *
 *     - register        -> create an account
 *     - login           -> verify credentials and start a session
 *     - logout          -> end the session
 *     - forgot-password -> reset password structure (no email yet)
 *
 * POST actions rely on the routes carrying CsrfMiddleware, which
 * rejects requests without a valid token before this controller
 * is even reached. Validation failures re-render the form with the
 * errors; successes follow the PRG pattern (redirect) so a page
 * refresh cannot submit the form twice.
 */
final class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly User $users,
    ) {}

    public function showRegister(Request $request, array $params = []): void
    {
        $this->view('auth.register', [
            'title'  => 'Create an account',
            'active' => 'register',
            'old'    => [],
            'errors' => [],
        ]);
    }

    public function register(Request $request, array $params = []): void
    {
        $data = [
            'full_name'             => $request->input('full_name'),
            'email'                 => $request->input('email'),
            'password'              => $request->input('password'),
            'password_confirmation' => $request->input('password_confirmation'),
        ];

        $validator = (new Validator($data))
            ->required('full_name', 'full name')
            ->max('full_name', 100, 'full name')
            ->required('email', 'email address')
            ->email('email')
            ->required('password', 'password')
            ->min('password', 8, 'password')
            ->required('password_confirmation', 'password confirmation')
            ->same('password_confirmation', 'password', 'passwords');

        if (!$validator->passes()) {
            $this->view('auth.register', [
                'title'  => 'Create an account',
                'old'    => $data,
                'errors' => $validator->errors(),
            ]);

            return;
        }

        $email = strtolower($data['email']);

        if ($this->users->emailExists($email)) {
            $this->view('auth.register', [
                'title'  => 'Create an account',
                'old'    => $data,
                'errors' => ['email' => ['An account with this email address already exists.']],
            ]);

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

    public function showLogin(Request $request, array $params = []): void
    {
        $this->view('auth.login', [
            'title'  => 'Log in',
            'active' => 'login',
            'old'    => ['email' => ''],
            'errors' => [],
        ]);
    }

    public function login(Request $request, array $params = []): void
    {
        $email = (string) $request->input('email');

        $data = [
            'title'  => 'Log in',
            'active' => 'login',
            'old'    => ['email' => $email],
            'errors' => [],
        ];

        if ($this->auth->tooManyAttempts()) {
            $minutes = (int) ceil($this->auth->lockoutRemainingSeconds() / 60);

            $data['errors'] = ['email' => [
                "Too many failed attempts. Please try again in $minutes minute(s).",
            ]];

            $this->view('auth.login', $data);

            return;
        }

        $password = (string) $request->input('password');

        if ($email === '' || $password === '') {
            $data['errors'] = ['email' => ['Enter your email address and password.']];

            $this->view('auth.login', $data);

            return;
        }

        if (!$this->auth->attempt($email, $password)) {
            $data['errors'] = ['password' => ['Invalid email address or password.']];

            $this->view('auth.login', $data);

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

    public function showForgotPassword(Request $request, array $params = []): void
    {
        $this->view('auth.forgot-password', [
            'title' => 'Reset password',
            'old'   => ['email' => ''],
            'errors' => [],
        ]);
    }

    public function forgotPassword(Request $request, array $params = []): void
    {
        $email = (string) $request->input('email');

        // Structure only: the reset email is not actually sent in
        // this phase. The controller validates the input and shows
        // a neutral confirmation so account addresses cannot be
        // probed.
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->view('auth.forgot-password', [
                'title'  => 'Reset password',
                'old'    => ['email' => $email],
                'errors' => ['email' => ['Enter a valid email address.']],
            ]);

            return;
        }

        session()->flash('success', 'If an account exists for that address, a password reset link would be sent there.');
        Response::redirect('/login');
    }
}
