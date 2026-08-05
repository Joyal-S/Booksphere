<?php

declare(strict_types=1);

namespace BookSphere\App\Controllers;

use BookSphere\App\Core\Controller;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Core\Validator;
use BookSphere\App\Models\User;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\LibraryService;
use BookSphere\App\Services\RecommendationService;
use BookSphere\App\Services\ReviewService;

/**
 * UserController
 *
 * Handles the logged-in user's own account area:
 *
 *     - profile          -> view the profile
 *     - profile/edit     -> change name and email
 *     - change-password  -> change the password
 *
 * Every action is protected by AuthMiddleware in the route table,
 * so only logged-in users reach this controller. The profile is
 * always the current session user (from the session id), never an
 * id taken from the URL, so users can only touch their own data.
 */
final class UserController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly User $users,
        private readonly ?ReviewService $reviews = null,
        // Phase 8.4: the profile's "My Library" block is read through
        // the SAME shared LibraryService - the single source of truth
        // for the user's personal library.
        private readonly ?LibraryService $library = null,
        // Phase 8.5: the "Reading Preferences & Recommendation
        // Insights" block (favourite categories/authors, the
        // Recommendation Accuracy figure and the books influencing
        // the shelves) comes from the SHARED RecommendationService.
        private readonly ?RecommendationService $recommendations = null,
    ) {}

    public function show(Request $request, array $params = []): void
    {
        $userId = (int) $this->auth->id();
        $user   = $this->users->findById($userId);

        // A session that outlived its user row (deleted account) must
        // never index a missing profile - answer a safe 404 instead.
        if ($user === null) {
            Response::error(404, 'Profile not found.');
        }

        $this->view('profile.show', [
            'title'       => 'My profile',
            'active'      => 'profile',
            'user'        => $user,
            'ratingStats' => $this->reviews?->profileStats($userId) ?? [],
            // Phase 7.4: the profile's "Recent Reviews" block, fed
            // by the same Reviews module the dashboard uses.
            'recentReviews' => $this->reviews?->reviewsByUser($userId, 3) ?? [],
            // Phase 7.5: the community reputation block (helpful
            // votes received, most helpful review) - the Helpful
            // Score; badge tiers arrive in a later phase.
            'reputation' => $this->reviews?->reviewReputation($userId) ?? [],
            // Phase 7.6: the enriched review statistics (Total
            // Reviews, Average Rating Given, Highest Rated Book,
            // Most Reviewed Category, Favourite Genres) and the
            // monthly Review Activity Timeline - both composed by
            // the Reviews module.
            'userReviewStats' => $this->reviews?->userReviewStatistics($userId) ?? [],
            'activityTimeline' => $this->reviews?->reviewActivityTimeline($userId) ?? [],
            // Phase 8.4: the "My Library" block - the personal library
            // summary, the favourite books and categories, and the
            // recently-added / recently-finished shelves, all read
            // through the shared LibraryService.
            'librarySummary'   => $this->library?->statusCounts($userId) ?? [],
            'favouriteBooks'   => $this->library?->favoriteBooks($userId, 4) ?? [],
            'favouriteCategories' => $this->library?->preferredGenres($userId, 5) ?? [],
            'recentlyAddedLib' => $this->library?->recentlyAdded($userId, 3) ?? [],
            'recentlyFinished' => $this->library?->finished($userId, 3) ?? [],
            // Phase 8.5: the reading preferences (top library
            // categories + authors), the Recommendation Accuracy
            // figure and the books influencing the shelves.
            'recommendationInsights' => $this->recommendations?->profileRecommendationInsights($userId) ?? [],
        ]);
    }

    public function showEdit(Request $request, array $params = []): void
    {
        $user = $this->users->findById((int) $this->auth->id());

        $this->view('profile.edit', [
            'title'  => 'Edit profile',
            'active' => 'profile',
            'user'   => $user,
            'old'    => ['full_name' => $user['full_name'], 'email' => $user['email']],
            'errors' => [],
        ]);
    }

    public function edit(Request $request, array $params = []): void
    {
        $user = $this->users->findById((int) $this->auth->id());

        $data = [
            'full_name' => (string) $request->input('full_name'),
            'email'     => (string) $request->input('email'),
        ];

        $validator = (new Validator($data))
            ->required('full_name', 'full name')
            ->max('full_name', 100, 'full name')
            ->required('email', 'email address')
            ->email('email');

        if (!$validator->passes()) {
        $this->view('profile.edit', [
            'title'  => 'Edit profile',
            'active' => 'profile',
            'user'   => $user,
            'old'    => $data,
            'errors' => $validator->errors(),
        ]);

        return;
    }

    $email = strtolower($data['email']);

        if ($this->users->emailExists($email, $user['id'])) {
            $this->view('profile.edit', [
                'title'  => 'Edit profile',
                'active' => 'profile',
                'user'   => $user,
                'old'    => $data,
                'errors' => ['email' => ['This email address is already in use.']],
            ]);

            return;
        }

        $this->users->updateProfile($user['id'], $data['full_name'], $email);
        $this->auth->refreshUser([
            'id'        => $user['id'],
            'full_name' => $data['full_name'],
            'email'     => $email,
            'role'      => $user['role'],
        ]);

        session()->flash('success', 'Your profile has been updated.');
        Response::redirect('/profile');
    }

    public function showChangePassword(Request $request, array $params = []): void
    {
        $this->view('profile.change-password', [
            'title'  => 'Change password',
            'active' => 'password',
            'errors' => [],
        ]);
    }

    public function changePassword(Request $request, array $params = []): void
    {
        $data = [
            'current_password'      => (string) $request->input('current_password'),
            'password'              => (string) $request->input('password'),
            'password_confirmation' => (string) $request->input('password_confirmation'),
        ];

        $validator = (new Validator($data))
            ->required('current_password', 'current password')
            ->required('password', 'new password')
            ->min('password', 8, 'new password')
            ->required('password_confirmation', 'password confirmation')
            ->same('password_confirmation', 'password', 'passwords');

        if (!$validator->passes()) {
            $this->view('profile.change-password', [
                'title'  => 'Change password',
                'errors' => $validator->errors(),
            ]);

            return;
        }

        $userId = (int) $this->auth->id();
        $currentHash = $this->users->findPasswordHash($userId);

        if (!password_verify($data['current_password'], $currentHash)) {
            $this->view('profile.change-password', [
                'title'  => 'Change password',
                'errors' => ['current_password' => ['The current password is incorrect.']],
            ]);

            return;
        }

        $this->users->updatePassword($userId, password_hash($data['password'], PASSWORD_DEFAULT));

        session()->flash('success', 'Your password has been changed.');
        Response::redirect('/profile');
    }
}
