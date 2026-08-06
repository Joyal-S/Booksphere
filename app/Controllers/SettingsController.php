<?php

declare(strict_types=1);

namespace BookSphere\App\Controllers;

use BookSphere\App\Core\Controller;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Exceptions\EmailException;
use BookSphere\App\Services\EmailNotificationService;

/**
 * SettingsController
 *
 * The REAL Settings page (Phase 9.5) - the successor of the "coming
 * soon" placeholder. Its first (and so far only) section is Email
 * notifications: the five per-user toggles a reader can silence
 * without touching their in-app notification preferences.
 *
 *     GET  /settings                   the page
 *     POST /settings/email-preferences the five toggles
 *
 * The write endpoint follows the dual-answer convention of every
 * other write route in the application: a fetch caller
 * (X-Requested-With: fetch) gets JSON back, a plain form gets a
 * redirect + flash. The toggle keys are validated through the
 * service's PREFERENCE_KEYS allowlist (unknown keys are rejected
 * with a 422), and the session user id is the only identity ever
 * used - there is no user id in the URL to tamper with.
 *
 * The whole module is OPTIONAL: with EMAIL_ENABLED=false (the
 * default) the page still renders - it shows the toggles and an
 * informational banner - and saving them is harmless, because the
 * email pipeline itself is off.
 */
final class SettingsController extends Controller
{
    public function __construct(
        private readonly EmailNotificationService $email,
    ) {}

    /**
     * The Settings page: the email notification toggles with their
     * current values. The page never reveals email internals - it
     * only shows the five switches and (when email is disabled) a
     * quiet server-side notice.
     */
    public function show(Request $request, array $params = []): void
    {
        $this->view('pages.settings', [
            'title'        => 'Settings',
            'active'       => 'settings',
            'preferences'  => $this->email->preferences((int) auth()->id()),
            'emailEnabled' => (bool) config('email.enabled', false),
        ]);
    }

    /**
     * Save the five email toggles. The keys are iterated from the
     * service's allowlist - a posted key outside it can never reach
     * the SQL (the repository's column allowlist is the second line
     * of defence). An unchecked box simply does not appear in the
     * form data, so "checked = present" is the exact truth of a
     * checkbox form.
     */
    public function emailPreferences(Request $request): void
    {
        $userId = (int) auth()->id();

        try {
            foreach (EmailNotificationService::PREFERENCE_KEYS as $key) {
                $this->email->updatePreference($userId, $key, $request->input($key) !== null);
            }
        } catch (EmailException $e) {
            $this->failure($request, 422, $e->getMessage());

            return;
        }

        if ($request->header('X-Requested-With') === 'fetch') {
            Response::json([
                'ok'          => true,
                'preferences' => $this->email->preferences($userId),
            ]);

            return;
        }

        session()->flash('success', 'Email preferences saved.');
        Response::redirect('/settings');
    }

    /**
     * The error answer: JSON {error, message} for fetch callers, a
     * redirect + flash for the no-JS form.
     */
    private function failure(Request $request, int $status, string $message): void
    {
        if ($request->header('X-Requested-With') === 'fetch') {
            Response::json(['error' => $message], $status);

            return;
        }

        session()->flash('error', $message);
        Response::redirect('/settings');
    }
}