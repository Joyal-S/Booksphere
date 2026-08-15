<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\Core\Logger;
use BookSphere\App\Exceptions\CommunityException;
use BookSphere\App\Models\Book;
use BookSphere\App\Models\CommunityComment;
use BookSphere\App\Models\CommunityFollow;
use BookSphere\App\Models\CommunityLike;
use BookSphere\App\Models\CommunityPost;
use BookSphere\App\Models\CommunityReport;
use BookSphere\App\Models\CommunityReputation;
use BookSphere\App\Models\User;
use BookSphere\App\Services\NotificationDispatcher;
use PDOException;

/**
 * CommunityService
 *
 * Business logic of the Community module (Phase C3-A).
 * Controllers stay thin: they translate HTTP, ask the policy gate, then
 * hand validated data to this service. Every DECISION lives here.
 *
 * Mirrors the pattern of FollowService and ReviewService:
 *   - dependencies injected as model facades
 *   - acting user id always comes from the caller (never trusted from
 *     the request body)
 *   - PDO exceptions for UNIQUE races are remapped to domain exceptions
 *   - Logger is optional (defaults to application.log)
 *
 * -----------------------------------------------------------------------
 * VALIDATION CONSTANTS
 * -----------------------------------------------------------------------
 * TITLE_MAX        120  chars
 * BODY_MIN         10   chars
 * BODY_MAX         10000 chars
 * COMMENT_MIN      1    char
 * COMMENT_MAX      2000  chars
 * VALID_STATUSES   'active'|'hidden'|'deleted'
 * VALID_REASONS    matches the C2 migration CHECK constraint
 * VALID_RPT_STATUS 'pending'|'reviewed'|'dismissed'|'resolved'
 * -----------------------------------------------------------------------
 */
final class CommunityService
{
    // --- Validation constants -------------------------------------------

    public const TITLE_MAX     = 120;
    public const BODY_MIN      = 10;
    public const BODY_MAX      = 10_000;
    public const COMMENT_MIN   = 1;
    public const COMMENT_MAX   = 2_000;
    public const STATUSES      = ['active', 'hidden', 'deleted'];
    public const REPORT_REASONS = [
        'Spam',
        'Harassment',
        'Offensive Content',
        'False Information',
        'Duplicate',
        'Other',
    ];
    public const REPORT_STATUSES = ['pending', 'reviewed', 'dismissed', 'resolved'];

    private readonly Logger $logger;
    private readonly CommunityFollow $userFollows;
    private readonly CommunityReputation $reputationModel;

    public function __construct(
        private readonly CommunityPost    $posts,
        private readonly CommunityComment $comments,
        private readonly CommunityLike    $likes,
        private readonly CommunityReport  $reports,
        private readonly Book             $books,
        ?Logger $logger = null,
        ?CommunityFollow $userFollows = null,
        private readonly ?NotificationDispatcher $dispatcher = null,
        ?CommunityReputation $reputationModel = null,
    ) {
        $this->logger          = $logger ?? new Logger(root_path('storage/logs/application.log'));
        $this->userFollows     = $userFollows ?? new CommunityFollow();
        $this->reputationModel = $reputationModel ?? new CommunityReputation();
    }

    // ===================================================================
    // POSTS
    // ===================================================================

    /**
     * Create a new community post and return its id.
     *
     * @param array<string,mixed> $data  title, body, book_id (optional)
     * @throws CommunityException  on validation failure or invalid book
     */
    public function createPost(int $actorId, array $data): int
    {
        $title  = trim((string) ($data['title'] ?? ''));
        $body   = trim((string) ($data['body']  ?? ''));
        $bookId = isset($data['book_id']) && (int) $data['book_id'] > 0
            ? (int) $data['book_id']
            : null;

        $this->validatePostTitle($title);
        $this->validatePostBody($body);

        if ($bookId !== null && $this->books->findById($bookId) === null) {
            throw CommunityException::bookNotFound($bookId);
        }

        // Short-window duplicate post detection (60 seconds)
        $recentDuplicate = db()->query(
            "SELECT id FROM community_posts WHERE user_id = ? AND title = ? AND body = ? AND (book_id = ? OR (book_id IS NULL AND ? IS NULL)) AND created_at >= datetime('now', '-60 seconds') LIMIT 1",
            [$actorId, $title, $body, $bookId, $bookId]
        );
        if (!empty($recentDuplicate)) {
            throw CommunityException::invalidInput('post', 'A duplicate discussion was recently posted.');
        }

        $id = $this->posts->create([
            'user_id' => $actorId,
            'title'   => $title,
            'body'    => $body,
            'book_id' => $bookId,
            'status'  => 'active',
        ]);

        $this->logger->info('community.post.created', [
            'id'      => $id,
            'user_id' => $actorId,
        ]);

        return $id;
    }

    /**
     * Return a single post (joined with author name and book title).
     *
     * @return array<string,mixed>
     * @throws CommunityException  when the post does not exist
     */
    public function getPost(int $postId): array
    {
        $post = $this->posts->find($postId);

        if ($post === null) {
            throw CommunityException::postNotFound($postId);
        }

        return $post;
    }

    /**
     * Paginated feed of active posts, newest first.
     *
     * @return array<string,mixed>  items, total, page, pages, per_page
     */
    public function listPosts(int $page = 1, int $perPage = 20): array
    {
        $perPage = min(50, max(1, $perPage));
        $page    = max(1, $page);
        $total   = $this->posts->countActive();
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = min($page, $pages);

        return [
            'items'    => $this->posts->findActive($perPage, ($page - 1) * $perPage),
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * Paginated active posts linked to a specific book.
     *
     * @return array<string,mixed>  items, total, page, pages, per_page
     * @throws CommunityException  when the book does not exist
     */
    public function listPostsForBook(int $bookId, int $page = 1, int $perPage = 20): array
    {
        if ($this->books->findById($bookId) === null) {
            throw CommunityException::bookNotFound($bookId);
        }

        $perPage = min(50, max(1, $perPage));
        $page    = max(1, $page);
        $total   = $this->posts->countByBook($bookId);
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = min($page, $pages);

        return [
            'items'    => $this->posts->findByBook($bookId, $perPage, ($page - 1) * $perPage),
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * Discovery feed of active posts, supporting sort modes (recent, popular, trending),
     * optional book_id filter, optional author_id filter, search query, and pagination.
     * Compatible with both C6-B signature (sort, bookId, page, perPage)
     * and C6-C signature (sort, bookId, authorId, query, page, perPage).
     *
     * @return array<string,mixed>  items, total, page, pages, per_page, sort, book_id, author_id, query
     */
    public function listDiscoveryPosts(
        string $sort = 'recent',
        ?int $bookId = null,
        mixed $arg3 = null,
        mixed $arg4 = null,
        mixed $arg5 = null,
        mixed $arg6 = null,
        ?int $followerId = null
    ): array {
        $validSorts = ['recent', 'popular', 'trending'];
        if (!in_array($sort, $validSorts, true)) {
            $sort = 'recent';
        }

        $effectiveBookId = ($bookId !== null && $bookId > 0) ? $bookId : null;
        if ($effectiveBookId !== null && $this->books->findById($effectiveBookId) === null) {
            throw CommunityException::bookNotFound($effectiveBookId);
        }

        // Disambiguate between C6-B signature ($page, $perPage) and C6-C signature ($authorId, $query, $page, $perPage)
        if (is_int($arg3) && is_int($arg4) && $arg5 === null && $arg6 === null) {
            $authorId = null;
            $query    = null;
            $page     = $arg3;
            $perPage  = $arg4;
        } else {
            $authorId = is_numeric($arg3) && (int)$arg3 > 0 ? (int)$arg3 : null;
            $query    = is_string($arg4) ? $arg4 : null;
            $page     = is_numeric($arg5) ? (int)$arg5 : 1;
            $perPage  = is_numeric($arg6) ? (int)$arg6 : 20;
        }

        $effectiveAuthorId = ($authorId !== null && $authorId > 0) ? $authorId : null;

        $normalizedQuery = null;
        if ($query !== null) {
            $clean = trim(preg_replace('/\s+/', ' ', $query));
            if ($clean !== '') {
                $normalizedQuery = mb_substr($clean, 0, 100);
            }
        }

        $perPage = min(50, max(1, $perPage));
        $page    = max(1, $page);
        $total   = $this->posts->countDiscoveryPosts($effectiveBookId, $effectiveAuthorId, $normalizedQuery, $followerId);
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = min($page, $pages);

        $items   = $this->posts->findDiscoveryPosts(
            $sort,
            $effectiveBookId,
            $effectiveAuthorId,
            $normalizedQuery,
            $perPage,
            ($page - 1) * $perPage,
            $followerId
        );

        return [
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'pages'     => $pages,
            'per_page'  => $perPage,
            'sort'      => $sort,
            'book_id'   => $effectiveBookId,
            'author_id' => $effectiveAuthorId,
            'query'     => $normalizedQuery,
        ];
    }

    /**
     * Paginated posts by a specific user.
     *
     * @return array<string,mixed>  items, total, page, pages, per_page
     */
    public function listPostsByUser(int $userId, int $page = 1, int $perPage = 20): array
    {
        $perPage = min(50, max(1, $perPage));
        $page    = max(1, $page);
        $total   = $this->posts->countByUser($userId);
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = min($page, $pages);

        return [
            'items'    => $this->posts->findByUser($userId, $perPage, ($page - 1) * $perPage),
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * Return public Community Profile data for a specific user.
     * Includes user display info, active post & comment counts, and paginated active posts/comments.
     *
     * @return array<string,mixed>
     * @throws CommunityException  when user is not found
     */
    public function getUserProfile(int $userId, ?int $postPage = 1, ?int $commentPage = 1, int $perPage = 10, ?int $visitorId = null): array
    {
        $user = (new User())->findById($userId);
        if ($user === null) {
            throw CommunityException::userNotFound($userId);
        }

        $perPage       = min(50, max(1, $perPage));
        $postPage      = max(1, (int) ($postPage ?? 1));
        $commentPage   = max(1, (int) ($commentPage ?? 1));

        $totalPosts    = $this->posts->countActiveByUser($userId);
        $totalComments = $this->comments->countActiveByUser($userId);

        $postPages     = max(1, (int) ceil($totalPosts / $perPage));
        $commentPages  = max(1, (int) ceil($totalComments / $perPage));

        $postPage      = min($postPage, $postPages);
        $commentPage   = min($commentPage, $commentPages);

        $activePosts    = $this->posts->findActiveByUser($userId, $perPage, ($postPage - 1) * $perPage);
        $activeComments = $this->comments->findActiveByUser($userId, $perPage, ($commentPage - 1) * $perPage);

        $followerCount  = $this->userFollows->followerCount($userId);
        $followingCount = $this->userFollows->followingCount($userId);
        $isFollowing    = ($visitorId !== null && $visitorId > 0 && $visitorId !== $userId)
            ? $this->userFollows->isFollowing($visitorId, $userId)
            : false;

        $createdAt   = (string) ($user['created_at'] ?? '');
        $memberSince = $createdAt !== '' ? date('M Y', strtotime($createdAt)) : 'Member';

        $reputation = $this->reputationModel->getUserReputation($userId);

        return [
            'items'      => $activePosts,
            'total'      => $totalPosts,
            'page'       => $postPage,
            'pages'      => $postPages,
            'per_page'   => $perPage,
            'reputation' => $reputation,
            'user' => [
                'id'           => (int) $user['id'],
                'full_name'    => (string) $user['full_name'],
                'initial'      => mb_strtoupper(mb_substr((string) $user['full_name'], 0, 1)),
                'created_at'   => $createdAt,
                'member_since' => $memberSince,
            ],
            'stats' => [
                'posts'           => $totalPosts,
                'comments'        => $totalComments,
                'followers'       => $followerCount,
                'following'       => $followingCount,
                'follower_count'  => $followerCount,
                'following_count' => $followingCount,
                'is_following'    => $isFollowing,
                'reputation_score'=> $reputation['score'],
            ],
            'posts' => [
                'items'    => $activePosts,
                'total'    => $totalPosts,
                'page'     => $postPage,
                'pages'    => $postPages,
                'per_page' => $perPage,
            ],
            'comments' => [
                'items'    => $activeComments,
                'total'    => $totalComments,
                'page'     => $commentPage,
                'pages'    => $commentPages,
                'per_page' => $perPage,
            ],
        ];
    }

    /**
     * Update a post's title/body/status.
     *
     * @param array<string,mixed> $data  title, body, status (optional)
     * @throws CommunityException  on validation, not-found, or permission failure
     */
    public function updatePost(int $actorId, int $postId, array $data): bool
    {
        $post = $this->requirePost($postId);

        if ((int) $post['user_id'] !== $actorId && !$this->isActorAdmin($actorId)) {
            throw CommunityException::permissionDenied('edit');
        }

        $title  = trim((string) ($data['title'] ?? $post['title']));
        $body   = trim((string) ($data['body']  ?? $post['body']));
        $status = (string) ($data['status'] ?? $post['status']);

        $this->validatePostTitle($title);
        $this->validatePostBody($body);
        $this->validateStatus($status);

        $result = $this->posts->update($postId, [
            'title'  => $title,
            'body'   => $body,
            'status' => $status,
        ]);

        if ($result) {
            $this->logger->info('community.post.updated', [
                'id'      => $postId,
                'user_id' => $actorId,
            ]);
        }

        return $result;
    }

    /**
     * Delete a post (hard delete; cascades comments, likes, reports).
     *
     * @throws CommunityException  on not-found or permission failure
     */
    public function deletePost(int $actorId, int $postId): bool
    {
        $post = $this->requirePost($postId);

        if ((int) $post['user_id'] !== $actorId && !$this->isActorAdmin($actorId)) {
            throw CommunityException::permissionDenied('delete');
        }

        $result = $this->posts->delete($postId);

        if ($result) {
            $this->logger->info('community.post.deleted', [
                'id'      => $postId,
                'user_id' => $actorId,
            ]);
        }

        return $result;
    }

    // ===================================================================
    // COMMENTS
    // ===================================================================

    /**
     * Create a comment on a post and return its id.
     *
     * @param array<string,mixed> $data  body
     * @throws CommunityException  on validation or invalid post
     */
    public function createComment(int $actorId, int $postId, array $data): int
    {
        // Ensure post exists and is active
        $post = $this->posts->find($postId);
        if ($post === null || $post['status'] !== 'active') {
            throw CommunityException::postNotFound($postId);
        }

        $body = trim((string) ($data['body'] ?? ''));
        $this->validateCommentBody($body);

        // Short-window duplicate comment detection (60 seconds)
        $recentDuplicate = db()->query(
            "SELECT id FROM community_comments WHERE user_id = ? AND post_id = ? AND body = ? AND created_at >= datetime('now', '-60 seconds') LIMIT 1",
            [$actorId, $postId, $body]
        );
        if (!empty($recentDuplicate)) {
            throw CommunityException::invalidInput('comment', 'A duplicate comment was recently posted.');
        }

        $id = $this->comments->create([
            'post_id' => $postId,
            'user_id' => $actorId,
            'body'    => $body,
        ]);

        $this->logger->info('community.comment.created', [
            'id'      => $id,
            'post_id' => $postId,
            'user_id' => $actorId,
        ]);

        return $id;
    }

    /**
     * Return active comments for a post, oldest first.
     *
     * @return array<int,array<string,mixed>>
     * @throws CommunityException  when the post does not exist
     */
    public function listComments(int $postId, int $limit = 100): array
    {
        if ($this->posts->find($postId) === null) {
            throw CommunityException::postNotFound($postId);
        }

        return $this->comments->findByPost($postId, $limit);
    }

    /**
     * Return a single comment or throw exception.
     *
     * @return array<string,mixed>
     * @throws CommunityException when comment is not found
     */
    public function getComment(int $commentId): array
    {
        return $this->requireComment($commentId);
    }

    /**
     * Update a comment's body.
     *
     * @param array<string,mixed> $data  body
     * @throws CommunityException  on validation, not-found, or permission failure
     */
    public function updateComment(int $actorId, int $commentId, array $data): bool
    {
        $comment = $this->requireComment($commentId);

        if ((int) $comment['user_id'] !== $actorId && !$this->isActorAdmin($actorId)) {
            throw CommunityException::permissionDenied('edit');
        }

        $body = trim((string) ($data['body'] ?? $comment['body']));
        $this->validateCommentBody($body);

        $result = $this->comments->update($commentId, ['body' => $body]);

        if ($result) {
            $this->logger->info('community.comment.updated', [
                'id'      => $commentId,
                'user_id' => $actorId,
            ]);
        }

        return $result;
    }

    /**
     * Delete a comment (hard delete; cascades any reports).
     *
     * @throws CommunityException  on not-found or permission failure
     */
    public function deleteComment(int $actorId, int $commentId): bool
    {
        $comment = $this->requireComment($commentId);

        if ((int) $comment['user_id'] !== $actorId && !$this->isActorAdmin($actorId)) {
            throw CommunityException::permissionDenied('delete');
        }

        $result = $this->comments->delete($commentId);

        if ($result) {
            $this->logger->info('community.comment.deleted', [
                'id'      => $commentId,
                'user_id' => $actorId,
            ]);
        }

        return $result;
    }

    // ===================================================================
    // LIKES
    // ===================================================================

    /**
     * Like a post. Returns the like row id.
     * IDEMPOTENT: if the user already liked the post, returns 0 silently.
     *
     * @throws CommunityException  when the post does not exist
     */
    public function likePost(int $actorId, int $postId): int
    {
        $post = $this->posts->find($postId);
        if ($post === null || $post['status'] !== 'active') {
            throw CommunityException::postNotFound($postId);
        }

        if ($this->likes->exists($postId, $actorId)) {
            return 0; // already liked - silently idempotent
        }

        try {
            $id = $this->likes->create($postId, $actorId);
        } catch (PDOException $e) {
            // UNIQUE race between exists() check and INSERT
            if ((string) ($e->getCode() ?? '') === '23000') {
                return 0;
            }
            throw $e;
        }

        $this->logger->info('community.like.created', [
            'id'      => $id,
            'post_id' => $postId,
            'user_id' => $actorId,
        ]);

        return $id;
    }

    /**
     * Unlike a post. IDEMPOTENT: unliking a non-existent like returns false.
     *
     * @throws CommunityException  when the post does not exist
     */
    public function unlikePost(int $actorId, int $postId): bool
    {
        if ($this->posts->find($postId) === null) {
            throw CommunityException::postNotFound($postId);
        }

        $removed = $this->likes->delete($postId, $actorId);

        if ($removed) {
            $this->logger->info('community.like.deleted', [
                'post_id' => $postId,
                'user_id' => $actorId,
            ]);
        }

        return $removed;
    }

    /**
     * Whether the user has liked the post (button-state check).
     */
    public function hasUserLikedPost(int $actorId, int $postId): bool
    {
        return $this->likes->exists($postId, $actorId);
    }

    /**
     * Total like count for a post.
     */
    public function getLikeCount(int $postId): int
    {
        return $this->likes->count($postId);
    }

    // ===================================================================
    // USER FOLLOWS
    // ===================================================================

    /**
     * Follow a Community user. Returns the follow relationship id.
     *
     * @throws CommunityException  on self-follow or nonexistent target user
     */
    public function followUser(int $actorId, int $targetUserId): int
    {
        if ($actorId <= 0) {
            throw CommunityException::permissionDenied('follow user');
        }

        if ($targetUserId <= 0 || (new User())->findById($targetUserId) === null) {
            throw CommunityException::userNotFound($targetUserId);
        }

        if ($actorId === $targetUserId) {
            throw CommunityException::invalidInput('user_id', 'You cannot follow yourself.');
        }

        $id = $this->userFollows->follow($actorId, $targetUserId);

        if ($id > 0) {
            $this->logger->info('community.user_follow.created', [
                'follower_id'  => $actorId,
                'following_id' => $targetUserId,
            ]);

            // Dispatch notification to recipient
            if ($this->dispatcher !== null) {
                try {
                    $actorUser = (new User())->findById($actorId);
                    $actorName = (string) ($actorUser['full_name'] ?? 'A community member');
                    $this->dispatcher->notify('author_followed', [
                        'author'     => $actorName,
                        'author_id'  => $actorId,
                        'title'      => 'New Follower',
                        'message'    => "{$actorName} started following you in the community.",
                        'action_url' => "/community/user/{$actorId}",
                    ], $targetUserId);
                } catch (\Throwable $e) {
                    $this->logger->error('community.user_follow.notification_failed', [
                        'follower_id'  => $actorId,
                        'following_id' => $targetUserId,
                        'error'        => $e->getMessage(),
                    ]);
                }
            }
        }

        return $id;
    }

    /**
     * Unfollow a Community user.
     */
    public function unfollowUser(int $actorId, int $targetUserId): bool
    {
        if ($actorId <= 0 || $targetUserId <= 0) {
            return false;
        }

        $removed = $this->userFollows->unfollow($actorId, $targetUserId);

        if ($removed) {
            $this->logger->info('community.user_follow.deleted', [
                'follower_id'  => $actorId,
                'following_id' => $targetUserId,
            ]);
        }

        return $removed;
    }

    /**
     * Check if actorId is following targetUserId.
     */
    public function isFollowingUser(int $actorId, int $targetUserId): bool
    {
        return $this->userFollows->isFollowing($actorId, $targetUserId);
    }

    /**
     * Get follower and following counts for a user.
     *
     * @return array<string, int>
     */
    public function getUserFollowStats(int $userId): array
    {
        return [
            'followers' => $this->userFollows->followerCount($userId),
            'following' => $this->userFollows->followingCount($userId),
        ];
    }

    /**
     * Get aggregate discussion stats for a book (Phase C7-C).
     *
     * @return array<string, int>
     */
    public function getBookDiscussionStats(int $bookId): array
    {
        if ($bookId <= 0) {
            return ['posts' => 0, 'comments' => 0, 'likes' => 0];
        }

        $postsCount = $this->posts->countByBook($bookId);

        $row = db()->query(
            "SELECT
                COALESCE(COUNT(DISTINCT c.id), 0) AS comments_count,
                COALESCE(COUNT(DISTINCT l.id), 0) AS likes_count
             FROM community_posts p
             LEFT JOIN community_comments c ON c.post_id = p.id AND c.status = 'active'
             LEFT JOIN community_likes l ON l.post_id = p.id
             WHERE p.book_id = ? AND p.status = 'active'",
            [$bookId]
        )[0] ?? [];

        return [
            'posts'    => $postsCount,
            'comments' => (int) ($row['comments_count'] ?? 0),
            'likes'    => (int) ($row['likes_count'] ?? 0),
        ];
    }

    /**
     * Paginated list of followers for a user.
     *
     * @return array<string, mixed>
     */
    public function listFollowers(int $userId, int $page = 1, int $perPage = 20): array
    {
        if ((new User())->findById($userId) === null) {
            throw CommunityException::userNotFound($userId);
        }

        $perPage = min(50, max(1, $perPage));
        $page    = max(1, $page);
        $total   = $this->userFollows->followerCount($userId);
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = min($page, $pages);

        $items   = $this->userFollows->findFollowers($userId, $perPage, ($page - 1) * $perPage);

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * Paginated list of users a user is following.
     *
     * @return array<string, mixed>
     */
    public function listFollowing(int $userId, int $page = 1, int $perPage = 20): array
    {
        if ((new User())->findById($userId) === null) {
            throw CommunityException::userNotFound($userId);
        }

        $perPage = min(50, max(1, $perPage));
        $page    = max(1, $page);
        $total   = $this->userFollows->followingCount($userId);
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = min($page, $pages);

        $items   = $this->userFollows->findFollowing($userId, $perPage, ($page - 1) * $perPage);

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $perPage,
        ];
    }

    // ===================================================================
    // REPORTS
    // ===================================================================

    /**
     * Report a post. Returns the report id.
     *
     * @param array<string,mixed> $data  reason, description (optional)
     * @throws CommunityException  on validation or invalid post
     */
    public function reportPost(int $actorId, int $postId, array $data): int
    {
        $post = $this->posts->find($postId);
        if ($post === null || $post['status'] !== 'active') {
            throw CommunityException::postNotFound($postId);
        }

        $reason      = (string) ($data['reason']      ?? 'Other');
        $description = (string) ($data['description'] ?? '');

        $this->validateReason($reason);

        // Duplicate prevention: only one active (pending|reviewed) report per user per post.
        if ($this->reports->existsByReporter($actorId, $postId, null)) {
            throw CommunityException::alreadyReported();
        }

        $id = $this->reports->create([
            'post_id'     => $postId,
            'comment_id'  => null,
            'reported_by' => $actorId,
            'reason'      => $reason,
            'description' => $description,
        ]);

        $this->logger->info('community.report.created', [
            'id'         => $id,
            'post_id'    => $postId,
            'user_id'    => $actorId,
        ]);

        return $id;
    }

    /**
     * Report a comment. Returns the report id.
     *
     * @param array<string,mixed> $data  reason, description (optional)
     * @throws CommunityException  on validation or invalid comment
     */
    public function reportComment(int $actorId, int $commentId, array $data): int
    {
        $comment = $this->comments->find($commentId);
        if ($comment === null || $comment['status'] !== 'active') {
            throw CommunityException::commentNotFound($commentId);
        }

        $reason      = (string) ($data['reason']      ?? 'Other');
        $description = (string) ($data['description'] ?? '');

        $this->validateReason($reason);

        // Duplicate prevention: only one active (pending|reviewed) report per user per comment.
        if ($this->reports->existsByReporter($actorId, null, $commentId)) {
            throw CommunityException::alreadyReported();
        }

        $id = $this->reports->create([
            'post_id'     => null,
            'comment_id'  => $commentId,
            'reported_by' => $actorId,
            'reason'      => $reason,
            'description' => $description,
        ]);

        $this->logger->info('community.report.created', [
            'id'         => $id,
            'comment_id' => $commentId,
            'user_id'    => $actorId,
        ]);

        return $id;
    }

    /**
     * Pending reports for the moderation queue (admin only).
     *
     * @return array<int,array<string,mixed>>
     */
    public function pendingReports(int $limit = 50): array
    {
        return $this->reports->findPending($limit);
    }

    /**
     * Count of pending moderation reports.
     */
    public function pendingReportCount(): int
    {
        return $this->reports->countPending();
    }

    /**
     * Paginated report queue for the admin moderation view.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listReports(int $page = 1, int $perPage = 30, string $status = 'pending'): array
    {
        $validStatuses = array_merge(['all'], self::REPORT_STATUSES);
        if (!in_array($status, $validStatuses, true)) {
            $status = 'pending';
        }

        $offset = max(0, ($page - 1) * $perPage);
        $items  = $this->reports->findAll($perPage, $offset, $status);
        $total  = $this->reports->countAll($status);

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) max(1, ceil($total / $perPage)),
            'status'   => $status,
        ];
    }

    /**
     * Get aggregate report metrics by status for the admin queue dashboard (0 N+1 queries).
     *
     * @return array<string,int> Map of status => count, plus 'total'
     */
    public function getReportStatistics(): array
    {
        $rows = db()->query("
            SELECT status, COUNT(*) AS cnt
            FROM community_reports
            GROUP BY status
        ");

        $stats = [
            'pending'   => 0,
            'reviewed'  => 0,
            'dismissed' => 0,
            'resolved'  => 0,
            'total'     => 0,
        ];

        foreach ($rows as $row) {
            $st  = (string) ($row['status'] ?? '');
            $cnt = (int) ($row['cnt'] ?? 0);
            if (array_key_exists($st, $stats)) {
                $stats[$st] = $cnt;
            }
            $stats['total'] += $cnt;
        }

        return $stats;
    }

    /**
     * Get focused Community Analytics metrics for the Admin dashboard (Phase C8-D).
     *
     * @param string $range '7d'|'30d'|'90d'|'all'
     * @return array<string,mixed>
     */
    public function getCommunityAnalytics(string $range = '30d'): array
    {
        $validRanges = ['7d', '30d', '90d', 'all'];
        if (!in_array($range, $validRanges, true)) {
            $range = '30d';
        }

        $dateClause = match ($range) {
            '7d'  => "datetime('now', '-7 days')",
            '30d' => "datetime('now', '-30 days')",
            '90d' => "datetime('now', '-90 days')",
            default => null,
        };

        $wherePost    = $dateClause ? "WHERE status = 'active' AND created_at >= {$dateClause}" : "WHERE status = 'active'";
        $whereComment = $dateClause ? "WHERE status = 'active' AND created_at >= {$dateClause}" : "WHERE status = 'active'";
        $whereLike    = $dateClause ? "WHERE created_at >= {$dateClause}" : "";
        $whereReport  = $dateClause ? "WHERE created_at >= {$dateClause}" : "";

        // 1. KPI Summaries
        $postCount    = (int) (db()->query("SELECT COUNT(*) AS n FROM community_posts {$wherePost}")[0]['n'] ?? 0);
        $commentCount = (int) (db()->query("SELECT COUNT(*) AS n FROM community_comments {$whereComment}")[0]['n'] ?? 0);
        $likeCount    = (int) (db()->query("SELECT COUNT(*) AS n FROM community_likes {$whereLike}")[0]['n'] ?? 0);
        $reportCount  = (int) (db()->query("SELECT COUNT(*) AS n FROM community_reports {$whereReport}")[0]['n'] ?? 0);

        // Active Users: distinct authors of active posts or active comments in range
        $activeUserSql = $dateClause
            ? "SELECT COUNT(DISTINCT user_id) AS n FROM (
                    SELECT user_id FROM community_posts WHERE status = 'active' AND created_at >= {$dateClause}
                    UNION
                    SELECT user_id FROM community_comments WHERE status = 'active' AND created_at >= {$dateClause}
               )"
            : "SELECT COUNT(DISTINCT user_id) AS n FROM (
                    SELECT user_id FROM community_posts WHERE status = 'active'
                    UNION
                    SELECT user_id FROM community_comments WHERE status = 'active'
               )";
        $activeUserCount = (int) (db()->query($activeUserSql)[0]['n'] ?? 0);

        // 2. Top Discussed Books
        $bookDateWhere = $dateClause ? "AND p.created_at >= {$dateClause}" : "";
        $topBooks = db()->query("
            SELECT b.id, b.title, b.cover_image,
                   COUNT(DISTINCT p.id) AS discussion_count,
                   COUNT(DISTINCT c.id) AS comment_count,
                   COUNT(DISTINCT l.id) AS like_count
            FROM books b
            JOIN community_posts p ON p.book_id = b.id AND p.status = 'active' {$bookDateWhere}
            LEFT JOIN community_comments c ON c.post_id = p.id AND c.status = 'active'
            LEFT JOIN community_likes l ON l.post_id = p.id
            GROUP BY b.id, b.title, b.cover_image
            ORDER BY discussion_count DESC, comment_count DESC
            LIMIT 5
        ");

        // 3. Top Engaged Discussions
        $postDateWhere = $dateClause ? "AND p.created_at >= {$dateClause}" : "";
        $topPosts = db()->query("
            SELECT p.id, p.title, p.created_at, p.user_id,
                   u.full_name AS author_name,
                   b.title AS book_title, b.id AS book_id,
                   COUNT(DISTINCT l.id) AS like_count,
                   COUNT(DISTINCT c.id) AS comment_count
            FROM community_posts p
            JOIN users u ON u.id = p.user_id
            LEFT JOIN books b ON b.id = p.book_id
            LEFT JOIN community_likes l ON l.post_id = p.id
            LEFT JOIN community_comments c ON c.post_id = p.id AND c.status = 'active'
            WHERE p.status = 'active' {$postDateWhere}
            GROUP BY p.id, p.title, p.created_at, p.user_id, u.full_name, b.title, b.id
            ORDER BY (COUNT(DISTINCT l.id) + COUNT(DISTINCT c.id)) DESC, p.id DESC
            LIMIT 5
        ");

        // 4. Moderation Breakdown by Reason & Status
        $reportDateWhere = $dateClause ? "WHERE created_at >= {$dateClause}" : "";
        $reportsByReason = db()->query("
            SELECT reason, COUNT(*) AS count
            FROM community_reports
            {$reportDateWhere}
            GROUP BY reason
            ORDER BY count DESC
        ");

        $reportsByStatus = db()->query("
            SELECT status, COUNT(*) AS count
            FROM community_reports
            {$reportDateWhere}
            GROUP BY status
        ");

        $moderationStats = [
            'pending'   => 0,
            'reviewed'  => 0,
            'dismissed' => 0,
            'resolved'  => 0,
            'total'     => $reportCount,
        ];
        foreach ($reportsByStatus as $row) {
            $st = (string) ($row['status'] ?? '');
            if (array_key_exists($st, $moderationStats)) {
                $moderationStats[$st] = (int) ($row['count'] ?? 0);
            }
        }

        // 5. Activity Timeline (Daily counts for last 14 entries)
        $postActivity = $dateClause ? "AND created_at >= {$dateClause}" : "";
        $dailyActivity = db()->query("
            SELECT DATE(created_at) AS date_str, COUNT(*) AS post_count
            FROM community_posts
            WHERE status = 'active' {$postActivity}
            GROUP BY DATE(created_at)
            ORDER BY date_str DESC
            LIMIT 14
        ");

        return [
            'range'           => $range,
            'posts'           => $postCount,
            'comments'        => $commentCount,
            'likes'           => $likeCount,
            'reports'         => $reportCount,
            'activeUsers'     => $activeUserCount,
            'topBooks'        => $topBooks,
            'topPosts'        => $topPosts,
            'reportsByReason' => $reportsByReason,
            'moderationStats' => $moderationStats,
            'dailyActivity'   => array_reverse($dailyActivity),
        ];
    }

    /**
     * Return a report with full content context for the admin detail view.
     *
     * @return array<string,mixed>
     * @throws CommunityException  when not found
     */
    public function getReportWithContext(int $reportId): array
    {
        $report = $this->reports->findWithContext($reportId);

        if ($report === null) {
            throw CommunityException::invalidInput('report_id', "Report #{$reportId} not found.");
        }

        return $report;
    }

    // ===================================================================
    // ADMIN MODERATION ACTIONS
    // ===================================================================

    /**
     * Advance a report's status (reviewed | dismissed | resolved).
     * Admin only — caller must verify canModerate() before calling.
     *
     * @throws CommunityException  on not-found or invalid status
     */
    public function moderateReport(int $actorId, int $reportId, string $status): bool
    {
        if (!in_array($status, self::REPORT_STATUSES, true)) {
            throw CommunityException::invalidInput('status', "Unknown status '{$status}'.");
        }

        $report = $this->reports->find($reportId);
        if ($report === null) {
            throw CommunityException::invalidInput('report_id', "Report #{$reportId} not found.");
        }

        $result = $this->reports->updateStatus($reportId, $status);

        if ($result) {
            $this->logger->info('community.report.moderated', [
                'id'       => $reportId,
                'status'   => $status,
                'admin_id' => $actorId,
            ]);
        }

        return $result;
    }

    /**
     * Hide a post (admin action). Sets status to 'hidden'.
     *
     * @throws CommunityException  when not found
     */
    public function hidePost(int $actorId, int $postId): bool
    {
        $post = $this->requirePost($postId);

        $result = $this->posts->updateStatus($postId, 'hidden');

        if ($result) {
            $this->logger->info('community.post.hidden', [
                'id'       => $postId,
                'admin_id' => $actorId,
            ]);
        }

        return $result;
    }

    /**
     * Restore a hidden post to 'active' (admin action).
     *
     * @throws CommunityException  when not found
     */
    public function unhidePost(int $actorId, int $postId): bool
    {
        $this->requirePost($postId);

        $result = $this->posts->updateStatus($postId, 'active');

        if ($result) {
            $this->logger->info('community.post.unhidden', [
                'id'       => $postId,
                'admin_id' => $actorId,
            ]);
        }

        return $result;
    }

    /**
     * Hide a comment (admin action). Sets status to 'hidden'.
     *
     * @throws CommunityException  when not found
     */
    public function hideComment(int $actorId, int $commentId): bool
    {
        $this->requireComment($commentId);

        $result = $this->comments->updateStatus($commentId, 'hidden');

        if ($result) {
            $this->logger->info('community.comment.hidden', [
                'id'       => $commentId,
                'admin_id' => $actorId,
            ]);
        }

        return $result;
    }

    /**
     * Restore a hidden comment to 'active' (admin action).
     *
     * @throws CommunityException  when not found
     */
    public function unhideComment(int $actorId, int $commentId): bool
    {
        $this->requireComment($commentId);

        $result = $this->comments->updateStatus($commentId, 'active');

        if ($result) {
            $this->logger->info('community.comment.unhidden', [
                'id'       => $commentId,
                'admin_id' => $actorId,
            ]);
        }

        return $result;
    }

    // ===================================================================
    // VALIDATION (private)
    // ===================================================================

    private function validatePostTitle(string $title): void
    {
        if ($title === '') {
            throw CommunityException::invalidInput('title', 'Title is required.');
        }

        if (mb_strlen($title) > self::TITLE_MAX) {
            throw CommunityException::invalidInput(
                'title',
                'Title must not exceed ' . self::TITLE_MAX . ' characters.',
            );
        }
    }

    private function validatePostBody(string $body): void
    {
        if (mb_strlen($body) < self::BODY_MIN) {
            throw CommunityException::invalidInput(
                'body',
                'Body must be at least ' . self::BODY_MIN . ' characters.',
            );
        }

        if (mb_strlen($body) > self::BODY_MAX) {
            throw CommunityException::invalidInput(
                'body',
                'Body must not exceed ' . self::BODY_MAX . ' characters.',
            );
        }
    }

    private function validateCommentBody(string $body): void
    {
        if (mb_strlen($body) < self::COMMENT_MIN) {
            throw CommunityException::invalidInput(
                'body',
                'Comment must not be empty.',
            );
        }

        if (mb_strlen($body) > self::COMMENT_MAX) {
            throw CommunityException::invalidInput(
                'body',
                'Comment must not exceed ' . self::COMMENT_MAX . ' characters.',
            );
        }
    }

    private function validateStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw CommunityException::invalidInput('status', "Unknown status: '{$status}'.");
        }
    }

    private function validateReason(string $reason): void
    {
        if (!in_array($reason, self::REPORT_REASONS, true)) {
            throw CommunityException::invalidReason($reason);
        }
    }

    // ===================================================================
    // PRIVATE GUARDS
    // ===================================================================

    /**
     * Return a post or throw CommunityException::postNotFound.
     *
     * @return array<string,mixed>
     */
    private function requirePost(int $postId): array
    {
        $post = $this->posts->find($postId);

        if ($post === null) {
            throw CommunityException::postNotFound($postId);
        }

        return $post;
    }

    /**
     * Return a comment or throw CommunityException::commentNotFound.
     *
     * @return array<string,mixed>
     */
    private function requireComment(int $commentId): array
    {
        $comment = $this->comments->find($commentId);

        if ($comment === null) {
            throw CommunityException::commentNotFound($commentId);
        }

        return $comment;
    }

    private function isActorAdmin(int $actorId): bool
    {
        if (auth_is_admin()) {
            return true;
        }

        $user = (new User())->findById($actorId);

        return ($user['role'] ?? '') === 'admin';
    }
}
