<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\Core\Logger;
use BookSphere\App\Exceptions\FollowException;
use BookSphere\App\Models\Author;
use BookSphere\App\Models\AuthorFollow;
use PDOException;

/**
 * FollowService
 *
 * The business logic of the Follow Authors module (Phase 9.2,
 * blueprint Task 3). Controllers stay thin: they translate the
 * request, ask the policy for permission and hand the ids to this
 * service. Every DECISION lives here:
 *
 *     - the "author must exist" rule (FollowException::authorNotFound)
 *     - the "cannot follow yourself" rule (FollowException::
 *       cannotFollowSelf - a service rule because a CHECK across the
 *       users and authors tables is impossible)
 *     - duplicate prevention - one follow per user per author
 *       (FollowException::duplicateFollow, backed by the UNIQUE
 *       (user_id, author_id) index as the last line of defence)
 *     - unfollow IDEMPOTENCE - removing a non-existent follow is a
 *       silent false, safe for double-clicks
 *     - the follower statistics the author page reads
 *       (followerCount / followersList) and the user's followed
 *       authors (followingList)
 *     - the notification hook: follow() also tells the dispatcher to
 *       create the actor's 'author_followed' confirmation ping (if
 *       not opted out of the author_followed category)
 *
 * A follow is PRIVATE data like the library: the acting user id
 * always comes from the session (the controller never trusts a
 * submitted id), and the FollowPolicy gate runs in the controller
 * before this service - the service rules are defence in depth.
 *
 * Dependencies:
 *     - AuthorFollow model (facade) for the author_follows table.
 *     - Author model (facade) for existence checks and names.
 *     - NotificationDispatcher (optional - the follow notification
 *       hook; an absent dispatcher means no confirmation ping,
 *       never an error).
 *     - Logger (optional, defaults to the application log).
 *
 * How it fits inside MVC:
 *     Controller -> FollowService (rules) -> AuthorFollow/Author
 *     models -> AuthorFollowRepository (SQL) -> PDO -> SQLite.
 */
final class FollowService
{
    private readonly Logger $logger;

    public function __construct(
        private readonly AuthorFollow $follows,
        private readonly Author $authors,
        private readonly ?NotificationDispatcher $dispatcher = null,
        ?Logger $logger = null,
        private readonly ?RecommendationService $recommendations = null,
    ) {
        $this->logger = $logger ?? new Logger(root_path('storage/logs/application.log'));
    }

    /**
     * Follow an author. Creates the follow row and returns its id,
     * then fires the actor's 'author_followed' confirmation ping
     * through the dispatcher.
     *
     * The actor's id comes from the caller (the controller's session
     * user) - never from the request body.
     */
    public function follow(int $userId, int $authorId): int
    {
        // The author row is fetched ONCE and reused: the existence
        // guard, the "cannot follow yourself" rule (the author id and
        // the user id share the same id space) and the notification
        // hook all read the same row - two identical queries used to
        // run on every follow.
        $author = $this->authors->findById($authorId);

        if ($author === null) {
            throw FollowException::authorNotFound($authorId);
        }

        if ($userId === $authorId) {
            throw FollowException::cannotFollowSelf($userId);
        }

        if ($this->follows->isFollowing($userId, $authorId)) {
            throw FollowException::duplicateFollow($userId, $authorId);
        }

        try {
            $id = $this->follows->create([
                'user_id'   => $userId,
                'author_id' => $authorId,
            ]);
        } catch (PDOException $exception) {
            // The UNIQUE (user_id, author_id) index is the last line
            // of defence: a double-submit that slips past the
            // isFollowing check (two requests racing between the
            // check and the insert) surfaces here as a constraint
            // violation. It is mapped to the module's duplicate
            // answer (409) instead of an uncaught SQL error (500).
            if ((string) ($exception->getCode() ?? '') === '23000') {
                throw FollowException::duplicateFollow($userId, $authorId);
            }

            throw $exception;
        }

        $this->logger->info('follow.created', [
            'id'      => $id,
            'user_id' => $userId,
            'author_id' => $authorId,
        ]);

        // The actor's confirmation ping (the basic follow
        // notification): formatted at write time with the author's
        // name, gated by the author_followed preference.
        $this->dispatcher?->notify('author_followed', [
            'author'    => (string) ($author['name'] ?? ''),
            'author_id' => $authorId,
        ], $userId);

        $this->recommendations?->invalidatePersonalization($userId);

        return $id;
    }

    /**
     * Unfollow an author. IDEMPOTENT: removing a follow that does
     * not exist is a silent false (double-clicks and races are
     * safe). Logs only real removals.
     */
    public function unfollow(int $userId, int $authorId): bool
    {
        $removed = $this->follows->deleteForPair($userId, $authorId);

        if ($removed) {
            $this->logger->info('follow.deleted', [
                'user_id'   => $userId,
                'author_id' => $authorId,
            ]);

            $this->recommendations?->invalidatePersonalization($userId);
        }

        return $removed;
    }

    /**
     * Whether the user already follows the author - the button state
     * of the author page.
     */
    public function isFollowing(int $userId, int $authorId): bool
    {
        return $this->follows->isFollowing($userId, $authorId);
    }

    /**
     * The follow row of one user for one author - the row the
     * FollowPolicy::canUnfollow gate reads before an unfollow.
     *
     * @return array<string, mixed>|null
     */
    public function followRow(int $userId, int $authorId): ?array
    {
        return $this->follows->findForPair($userId, $authorId);
    }

    /**
     * The user's followed authors, newest first (the following list).
     *
     * @return array<int, array<string, mixed>>
     */
    public function followingList(int $userId, int $limit = 50): array
    {
        return $this->follows->findForUser($userId, $limit);
    }

    /**
     * One PAGE of the user's followed authors (Phase 9.6 - the page
     * that replaces the silently-truncated LIMIT-50 list): the honest
     * total, the page's rows and the pager math, so the view can say
     * "showing 11-20 of 137 authors" and link between pages.
     *
     * @return array<string, mixed> items, total, page, pages, per_page
     */
    public function followingPage(int $userId, int $page = 1, int $perPage = 20): array
    {
        $perPage = min(50, max(1, $perPage));
        $page    = max(1, $page);

        $total = $this->follows->countForUser($userId);
        $pages = max(1, (int) ceil($total / $perPage));
        $page  = min($page, $pages);

        return [
            'items'    => $this->follows->findForUser($userId, $perPage, ($page - 1) * $perPage),
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * The followers of one author, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function followersList(int $authorId, int $limit = 50): array
    {
        return $this->follows->findFollowersOf($authorId, $limit);
    }

    /**
     * One PAGE of an author's followers (Phase 9.6), shaped like
     * followingPage() so both pages share one pager contract.
     *
     * @return array<string, mixed> items, total, page, pages, per_page
     */
    public function followersPage(int $authorId, int $page = 1, int $perPage = 20): array
    {
        $perPage = min(50, max(1, $perPage));
        $page    = max(1, $page);

        $total = $this->follows->countFollowersOf($authorId);
        $pages = max(1, (int) ceil($total / $perPage));
        $page  = min($page, $pages);

        return [
            'items'    => $this->follows->findFollowersOf($authorId, $perPage, ($page - 1) * $perPage),
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * The follower count of one author - the statistic shown on the
     * author page (a COUNT over the (author_id) index).
     */
    public function followerCount(int $authorId): int
    {
        return $this->follows->followerCount($authorId);
    }

    /**
     * Whether an author exists - the shared guard every follow write
     * runs first.
     */
    public function authorExists(int $authorId): bool
    {
        return $this->authors->findById($authorId) !== null;
    }
}