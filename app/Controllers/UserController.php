<?php

declare(strict_types=1);

namespace BookSphere\App\Controllers;

use BookSphere\App\Core\Controller;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Core\Validator;
use BookSphere\App\Models\User;
use BookSphere\App\Policies\FollowPolicy;
use BookSphere\App\Services\AuthService;
use BookSphere\App\Services\FollowService;
use BookSphere\App\Services\LibraryService;
use BookSphere\App\Services\MediaService;
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
        // Phase 9.2: the Follow Authors module - the following list
        // reads through the SAME shared FollowService, and the fine
        // owner-or-admin gate through FollowPolicy.
        private readonly ?FollowService $follows = null,
        private readonly ?FollowPolicy $followPolicy = null,
        private readonly ?MediaService $media = null,
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
        $this->auth->updateUser([
            'id'        => $user['id'],
            'full_name' => $data['full_name'],
            'email'     => $email,
            'role'        => $user['role'],
            'avatar_path' => $user['avatar_path'] ?? null,
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

    /**
     * "My Followed Authors" (GET /profile/following, Phase 9.2):
     * the signed-in user's followed authors, newest first, rendered
     * from the SHARED FollowService (the same instance the author
     * page uses, so the list and the follow buttons can never
     * disagree).
     *
     * The list is always the session user's own (the user id comes
     * from the session, never the URL); the fine owner-or-admin gate
     * runs here through FollowPolicy as defence in depth.
     */
    public function following(Request $request, array $params = []): void
    {
        $userId = (int) $this->auth->id();

        if ($this->followPolicy !== null && !$this->followPolicy->canViewList($userId, $userId)) {
            Response::error(403, 'You are not allowed to view this list.');
        }

        // Phase 9.6: the list is PAGINATED (it used to truncate
        // silently at 50 rows while the lead text under-counted), so
        // the lead shows the honest total and every row is reachable.
        $page    = max(1, (int) $request->input('page', 1));
        $perPage = (int) $request->input('per_page', 20);
        $pageData = $this->follows !== null
            ? $this->follows->followingPage($userId, $page, $perPage)
            : ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => 20];

        $this->view('profile.following', [
            'title'    => 'Authors I follow',
            'active'   => 'profile',
            'authors'  => $pageData['items'],
            'total'    => (int) $pageData['total'],
            // The per-row follow button state (the whole list belongs
            // to the session user, so every row is "following").
            'followed' => true,
            'pagination' => [
                'base'       => '/profile/following',
                'params'     => [],
                'page'       => (int) $pageData['page'],
                'pages'      => (int) $pageData['pages'],
                'total'      => (int) $pageData['total'],
                'perPage'    => (int) $pageData['per_page'],
                'perPages'   => [10, 20, 50],
                'label'      => 'author',
                'pagerLabel' => 'Following pages',
            ],
        ]);
    }

    /**
     * POST /profile/avatar — Upload or replace profile avatar image.
     */
    public function uploadAvatar(Request $request): void
    {
        $userId = (int) $this->auth->id();
        $user   = $this->users->findById($userId);

        if ($user === null) {
            Response::error(404, 'Profile not found.');
            return;
        }

        $file = $request->file('avatar');

        if ($file === null) {
            $this->avatarFailure($request, 'Please select an image to upload.');
            return;
        }

        // Upload error validation
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                $this->avatarFailure($request, 'Image must be 5 MB or smaller.');
                return;
            }
            $this->avatarFailure($request, 'The file could not be uploaded.');
            return;
        }

        // Size limit: 5 MB
        if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
            $this->avatarFailure($request, 'Image must be 5 MB or smaller.');
            return;
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_file($tmpPath)) {
            $this->avatarFailure($request, 'The file could not be read.');
            return;
        }

        // Sniff MIME type strictly from content
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmpPath);
        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ];

        if (!isset($allowedMimes[$mime])) {
            $this->avatarFailure($request, 'Please upload a JPG, PNG, or WebP image.');
            return;
        }

        // Verify client extension matches allowed formats
        $clientName = (string) ($file['name'] ?? '');
        $clientExt  = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($clientExt, $allowedExts, true)) {
            $this->avatarFailure($request, 'Please upload a JPG, PNG, or WebP image.');
            return;
        }

        // Validate image decodability & dimensions
        $imageInfo = @getimagesize($tmpPath);
        if ($imageInfo === false) {
            $this->avatarFailure($request, 'That file is not a valid image.');
            return;
        }

        // Validate structural integrity of container
        $structuralError = $this->mediaService()->validateFile($tmpPath);
        if ($structuralError !== null) {
            $this->avatarFailure($request, 'That file is not a valid image.');
            return;
        }

        // Store file with safe unique filename: user_<id>_<token>.<ext>
        try {
            $storedPath = $this->mediaService()->store($file, 'user_' . $userId);
        } catch (\Throwable $e) {
            $this->avatarFailure($request, 'The file could not be saved.');
            return;
        }

        // Update database (relative path only)
        try {
            $updated = $this->users->updateAvatar($userId, $storedPath);
        } catch (\Throwable $e) {
            // Failure-safe: clean up newly written file if database update failed
            $this->mediaService()->delete($storedPath);
            $this->avatarFailure($request, 'Could not update profile picture.');
            return;
        }

        if (!$updated) {
            // Failure-safe: clean up newly written file if database update failed
            $this->mediaService()->delete($storedPath);
            $this->avatarFailure($request, 'Could not update profile picture.');
            return;
        }

        // Clean up previous image if it was local and different
        $oldAvatar = $user['avatar_path'] ?? null;
        if ($oldAvatar !== null && $oldAvatar !== $storedPath) {
            try {
                $this->mediaService()->delete($oldAvatar);
            } catch (\Throwable) {
                // Non-fatal: deleting old image failure must not corrupt profile
            }
        }

        // Update auth session so UI updates immediately
        $user['avatar_path'] = $storedPath;
        $this->auth->updateUser($user);

        if ($request->header('X-Requested-With') === 'fetch') {
            Response::json([
                'ok'          => true,
                'avatar_path' => $storedPath,
                'message'     => 'Profile picture updated.',
            ]);
            return;
        }

        session()->flash('success', 'Profile picture updated.');
        Response::redirect('/profile/edit');
    }

    /**
     * POST /profile/avatar/remove — Remove current profile avatar image.
     */
    public function removeAvatar(Request $request): void
    {
        $userId = (int) $this->auth->id();
        $user   = $this->users->findById($userId);

        if ($user === null) {
            Response::error(404, 'Profile not found.');
            return;
        }

        $oldAvatar = $user['avatar_path'] ?? null;

        if ($oldAvatar !== null) {
            try {
                $this->users->updateAvatar($userId, null);
            } catch (\Throwable $e) {
                $this->avatarFailure($request, 'Could not remove profile picture.');
                return;
            }

            // Delete old file safely if it belongs to this user in the uploads directory
            try {
                $this->mediaService()->delete($oldAvatar);
            } catch (\Throwable) {
                // Non-fatal: database already cleared, file removal failure does not corrupt record
            }

            $user['avatar_path'] = null;
            $this->auth->updateUser($user);
        }

        if ($request->header('X-Requested-With') === 'fetch') {
            Response::json([
                'ok'      => true,
                'message' => 'Profile picture removed.',
            ]);
            return;
        }

        session()->flash('success', 'Profile picture removed.');
        Response::redirect('/profile/edit');
    }

    private function mediaService(): MediaService
    {
        return $this->media ?? new MediaService((array) (config('media.profiles') ?? []));
    }

    private function avatarFailure(Request $request, string $message): void
    {
        if ($request->header('X-Requested-With') === 'fetch') {
            Response::json(['error' => $message], 422);
            return;
        }

        session()->flash('error', $message);
        Response::redirect('/profile/edit');
    }
}
