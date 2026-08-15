<?php

declare(strict_types=1);

namespace BookSphere\App\Controllers;

use BookSphere\App\Core\Controller;
use BookSphere\App\Core\RateLimiter;
use BookSphere\App\Core\Request;
use BookSphere\App\Core\Response;
use BookSphere\App\Exceptions\CommunityException;
use BookSphere\App\Models\Book;
use BookSphere\App\Policies\CommunityPolicy;
use BookSphere\App\Services\CommunityService;

/**
 * CommunityController
 *
 * The HTTP Controller layer of the Community module (Phases C3-B, C4-A & C4-B).
 * Translates HTTP requests, checks fine policy gates, invokes
 * CommunityService for business rules/persistence, and returns
 * HTML views for browser requests or structured JSON for fetch callers.
 */
final class CommunityController extends Controller
{
    public function __construct(
        private readonly CommunityService $service,
        private readonly CommunityPolicy $policy,
        private readonly ?RateLimiter $limiter = null,
    ) {}

    /**
     * Enforce rate limiting for write actions.
     */
    private function enforceRateLimit(Request $request, string $bucket, int $limit, int $window): void
    {
        if ($this->limiter === null || $limit < 1 || $window < 1) {
            return;
        }

        $ipKey = 'ip_' . md5($request->ip());
        $persistentKey = auth()?->id() !== null ? 'user_' . auth()->id() : $ipKey;

        if (!$this->limiter->allow($bucket, $limit, $window, $persistentKey)) {
            $seconds = max(1, $this->limiter->remainingSeconds($bucket, $window, $persistentKey));

            if ($this->wantsJson($request)) {
                http_response_code(429);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'ok'      => false,
                    'error'   => "You're doing that too quickly. Please try again in {$seconds} seconds.",
                    'message' => "You're doing that too quickly. Please try again in {$seconds} seconds.",
                    'errors'  => ['rate_limit' => ["Rate limit exceeded. Try again in {$seconds}s."]],
                ]);
                exit;
            }

            session()->flash('error', "You're doing that too quickly. Please try again shortly.");
            redirect_back();
        }
    }

    // ===================================================================
    // PUBLIC READ ENDPOINTS & VIEW RENDERING
    // ===================================================================

    /**
     * GET /community ? Community feed page with discovery, search & filtering.
     */
    public function index(Request $request): void
    {
        $page     = max(1, (int) $request->input('page', 1));
        $perPage  = min(100, max(1, (int) $request->input('per_page', 20)));
        $sort     = (string) $request->input('sort', 'recent');
        $feed     = (string) $request->input('feed', 'all');
        $bookId   = $request->input('book_id') !== null && (int) $request->input('book_id') > 0
            ? (int) $request->input('book_id')
            : null;
        $authorId = ($request->input('author_id') !== null && (int) $request->input('author_id') > 0)
            ? (int) $request->input('author_id')
            : (($request->input('user_id') !== null && (int) $request->input('user_id') > 0)
                ? (int) $request->input('user_id')
                : null);
        $query    = $request->input('q') !== null ? (string) $request->input('q') : null;

        $currentUserId = (int) (auth()?->id() ?? 0);
        $followerId    = ($feed === 'following' && $currentUserId > 0) ? $currentUserId : null;
        if ($feed === 'following' && $currentUserId <= 0) {
            $feed = 'all';
        }

        try {
            $pageData = $this->service->listDiscoveryPosts($sort, $bookId, $authorId, $query, $page, $perPage, $followerId);
        } catch (CommunityException $e) {
            if ($this->wantsJson($request)) {
                $this->handleException($e);
                return;
            }
            $pageData = $this->service->listDiscoveryPosts($sort, null, $authorId, $query, $page, $perPage, $followerId);
            $bookId = null;
        }

        if ($this->wantsJson($request)) {
            $pageData['feed'] = $feed;
            Response::json($pageData);
            return;
        }

        $books   = db()->query("SELECT id, title FROM books WHERE status = 'published' OR deleted_at IS NULL ORDER BY title ASC");
        $authors = db()->query("SELECT DISTINCT u.id, u.full_name FROM users u JOIN community_posts p ON p.user_id = u.id WHERE p.status = 'active' ORDER BY u.full_name ASC");

        $queryParams = [];
        if ($feed !== 'all') {
            $queryParams['feed'] = $feed;
        }
        if ($sort !== 'recent') {
            $queryParams['sort'] = $sort;
        }
        if ($bookId !== null) {
            $queryParams['book_id'] = $bookId;
        }
        if ($authorId !== null) {
            $queryParams['author_id'] = $authorId;
        }
        if ($pageData['query'] !== null && $pageData['query'] !== '') {
            $queryParams['q'] = $pageData['query'];
        }

        $this->view('community.index', [
            'title'          => 'BookSphere Community — Discovery & Search',
            'active'         => 'community',
            'posts'          => $pageData['items'],
            'total'          => (int) $pageData['total'],
            'page'           => (int) $pageData['page'],
            'pages'          => (int) $pageData['pages'],
            'perPage'        => (int) $pageData['per_page'],
            'currentSort'    => $pageData['sort'],
            'currentFeed'    => $feed,
            'selectedBook'   => $bookId,
            'selectedAuthor' => $authorId,
            'query'          => $pageData['query'],
            'books'          => $books,
            'authors'        => $authors,
            'pagination'     => [
                'base'       => '/community',
                'params'     => $queryParams,
                'page'       => (int) $pageData['page'],
                'pages'      => (int) $pageData['pages'],
                'total'      => (int) $pageData['total'],
                'perPage'    => (int) $pageData['per_page'],
                'perPages'   => [10, 20, 50],
                'label'      => 'discussion',
                'pagerLabel' => 'Discussion pages',
            ],
        ]);
    }

    /**
     * GET /community/create — Display discussion creation form (Auth Required).
     */
    public function create(Request $request): void
    {
        $books          = db()->query("SELECT id, title, publisher FROM books WHERE status = 'published' OR deleted_at IS NULL ORDER BY title ASC");
        $selectedBookId = $request->input('book_id') !== null && (int) $request->input('book_id') > 0
            ? (int) $request->input('book_id')
            : null;

        $this->view('community.create', [
            'title'        => 'Start a Discussion — Community',
            'active'       => 'community',
            'books'        => $books,
            'selectedBook' => $selectedBookId,
        ]);
    }

    /**
     * GET /community/post/{id} ? Single post detail page.
     */
    public function show(Request $request, array $params = []): void
    {
        $postId  = (int) ($params['id'] ?? 0);
        $actorId = (int) auth()->id();

        try {
            $post = $this->service->getPost($postId);

            if ($this->wantsJson($request)) {
                Response::json($post);
                return;
            }

            $comments  = $this->service->listComments($postId);
            $canEdit   = $this->policy->canEdit($post, $actorId);
            $canDelete = $this->policy->canDelete($post, $actorId);
            $hasLiked  = $actorId > 0 ? $this->service->hasUserLikedPost($actorId, $postId) : false;

            $bookDetails = null;
            if (isset($post['book_id']) && (int) $post['book_id'] > 0) {
                $bookDetails = (new Book())->findById((int) $post['book_id']);
            }

            $this->view('community.show', [
                'title'       => $post['title'] . ' — Community',
                'active'      => 'community',
                'post'        => $post,
                'comments'    => $comments,
                'canEdit'     => $canEdit,
                'canDelete'   => $canDelete,
                'bookDetails' => $bookDetails,
                'hasLiked'    => $hasLiked,
                'actorId'     => $actorId,
            ]);
        } catch (CommunityException $e) {
            if ($this->wantsJson($request)) {
                $this->handleException($e);
                return;
            }

            Response::error(404, 'Discussion not found.');
        }
    }

    /**
     * GET /community/post/{id}/edit ? Display discussion edit form (Auth + Owner Required).
     */
    public function edit(Request $request, array $params = []): void
    {
        $postId  = (int) ($params['id'] ?? 0);
        $actorId = (int) auth()->id();

        try {
            $post = $this->service->getPost($postId);

            if (!$this->policy->canEdit($post, $actorId)) {
                session()->flash('error', 'You are not authorized to edit this discussion.');
                Response::redirect('/community/post/' . $postId);
                return;
            }

            $books = db()->query("SELECT id, title, publisher FROM books WHERE status = 'published' OR deleted_at IS NULL ORDER BY title ASC");

            $this->view('community.edit', [
                'title'  => 'Edit Discussion ? Community',
                'active' => 'community',
                'post'   => $post,
                'books'  => $books,
            ]);
        } catch (CommunityException $e) {
            session()->flash('error', $e->getMessage());
            Response::redirect('/community');
        }
    }

    /**
     * GET /community/posts/{id}/comments ? Active comments for a post.
     */
    public function comments(Request $request, array $params = []): void
    {
        $postId = (int) ($params['id'] ?? 0);

        try {
            $comments = $this->service->listComments($postId);
            Response::json(['items' => $comments, 'count' => count($comments)]);
        } catch (CommunityException $e) {
            $this->handleException($e);
        }
    }

    /**
     * GET /community/book/{id} — Dedicated Discussion Hub for a specific book (Phase C7-C).
     */
    public function bookPosts(Request $request, array $params = []): void
    {
        $bookId   = (int) ($params['id'] ?? 0);
        $page     = max(1, (int) $request->input('page', 1));
        $perPage  = (int) $request->input('per_page', 20);
        $sort     = (string) $request->input('sort', 'recent');
        $query    = $request->input('q') !== null ? (string) $request->input('q') : null;

        $bookModel = new Book();
        $book = $bookModel->findWithRelations($bookId);
        if ($book === null) {
            if ($this->wantsJson($request)) {
                Response::json(['error' => 'Book not found.'], 404);
                return;
            }
            Response::error(404, 'Book not found.');
            return;
        }

        try {
            $pageData = $this->service->listDiscoveryPosts($sort, $bookId, null, $query, $page, $perPage);
            $stats    = $this->service->getBookDiscussionStats($bookId);

            if ($this->wantsJson($request)) {
                $pageData['book']  = $book;
                $pageData['stats'] = $stats;
                Response::json($pageData);
                return;
            }

            $queryParams = [];
            if ($sort !== 'recent') {
                $queryParams['sort'] = $sort;
            }
            if ($pageData['query'] !== null && $pageData['query'] !== '') {
                $queryParams['q'] = $pageData['query'];
            }

            $this->view('community.book', [
                'title'       => e($book['title']) . ' — Community Discussion Hub',
                'active'      => 'community',
                'book'        => $book,
                'posts'       => $pageData['items'],
                'total'       => (int) $pageData['total'],
                'page'        => (int) $pageData['page'],
                'pages'       => (int) $pageData['pages'],
                'perPage'     => (int) $pageData['per_page'],
                'currentSort' => $pageData['sort'],
                'query'       => $pageData['query'],
                'stats'       => $stats,
                'pagination'  => [
                    'base'       => '/community/book/' . $bookId,
                    'params'     => $queryParams,
                    'page'       => (int) $pageData['page'],
                    'pages'      => (int) $pageData['pages'],
                    'total'      => (int) $pageData['total'],
                    'perPage'    => (int) $pageData['per_page'],
                    'perPages'   => [10, 20, 50],
                    'label'      => 'discussion',
                    'pagerLabel' => 'Discussion pages',
                ],
            ]);
        } catch (CommunityException $e) {
            if ($this->wantsJson($request)) {
                $this->handleException($e);
                return;
            }

            Response::error(404, 'Book not found.');
        }
    }

    /**
     * GET /community/user/{id} — Public Community Profile for a user.
     */
    public function userPosts(Request $request, array $params = []): void
    {
        $userId        = (int) ($params['id'] ?? 0);
        $postPage      = max(1, (int) $request->input('page', 1));
        $commentPage   = max(1, (int) $request->input('comment_page', 1));
        $perPage       = (int) $request->input('per_page', 10);
        $tab           = (string) $request->input('tab', 'discussions');
        $currentUserId = (int) (auth()?->id() ?? 0);

        try {
            $profile = $this->service->getUserProfile($userId, $postPage, $commentPage, $perPage, $currentUserId);

            if ($this->wantsJson($request)) {
                Response::json($profile);
                return;
            }

            $isOwnProfile = $currentUserId > 0 && $currentUserId === $userId;

            $this->view('community.profile', [
                'title'        => e($profile['user']['full_name']) . ' — Community Profile',
                'active'       => 'community',
                'profileUser'  => $profile['user'],
                'stats'        => $profile['stats'],
                'posts'        => $profile['posts']['items'],
                'postTotal'    => $profile['posts']['total'],
                'postPage'     => $profile['posts']['page'],
                'postPages'    => $profile['posts']['pages'],
                'comments'     => $profile['comments']['items'],
                'commentTotal' => $profile['comments']['total'],
                'commentPage'  => $profile['comments']['page'],
                'commentPages' => $profile['comments']['pages'],
                'perPage'      => $profile['posts']['per_page'],
                'currentTab'   => in_array($tab, ['discussions', 'comments'], true) ? $tab : 'discussions',
                'isOwnProfile' => $isOwnProfile,
            ]);
        } catch (CommunityException $e) {
            if ($this->wantsJson($request)) {
                $this->handleException($e);
                return;
            }

            Response::error(404, 'User not found.');
        }
    }

    // ===================================================================
    // POST WRITE ENDPOINTS (AUTH REQUIRED)
    // ===================================================================

    /**
     * POST /community/posts ? Create a new community post.
     */
    public function storePost(Request $request): void
    {
        $this->enforceRateLimit($request, 'community_post', 20, 60);
        $actorId = (int) auth()->id();
        $data    = $this->readPayload($request);

        if (isset($data['book_id']) && (string) $data['book_id'] === '') {
            $data['book_id'] = null;
        }

        try {
            $postId = $this->service->createPost($actorId, $data);

            if ($this->wantsJson($request)) {
                Response::json(['success' => true, 'id' => $postId], 201);
                return;
            }

            session()->flash('success', 'Discussion published successfully.');
            Response::redirect('/community/post/' . $postId);
        } catch (CommunityException $e) {
            if ($this->wantsJson($request)) {
                $this->handleException($e);
                return;
            }

            session()->flash('error', $e->getMessage());
            Response::redirect('/community/create');
        }
    }

    /**
     * POST/PATCH /community/posts/{id} ? Update an existing post.
     */
    public function updatePost(Request $request, array $params = []): void
    {
        $postId  = (int) ($params['id'] ?? 0);
        $actorId = (int) auth()->id();
        $data    = $this->readPayload($request);

        if (isset($data['book_id']) && (string) $data['book_id'] === '') {
            $data['book_id'] = null;
        }

        try {
            $post = $this->service->getPost($postId);

            if (!$this->policy->canEdit($post, $actorId)) {
                if ($this->wantsJson($request)) {
                    Response::json(['error' => 'You are not allowed to edit this post.'], 403);
                    return;
                }
                session()->flash('error', 'You are not allowed to edit this discussion.');
                Response::redirect('/community/post/' . $postId);
                return;
            }

            $success = $this->service->updatePost($actorId, $postId, $data);

            if ($this->wantsJson($request)) {
                Response::json(['success' => $success]);
                return;
            }

            session()->flash('success', 'Discussion updated successfully.');
            Response::redirect('/community/post/' . $postId);
        } catch (CommunityException $e) {
            if ($this->wantsJson($request)) {
                $this->handleException($e);
                return;
            }

            session()->flash('error', $e->getMessage());
            Response::redirect('/community/post/' . $postId . '/edit');
        }
    }

    /**
     * DELETE /community/posts/{id} ? Hard delete a post.
     */
    public function destroyPost(Request $request, array $params = []): void
    {
        $postId  = (int) ($params['id'] ?? 0);
        $actorId = (int) auth()->id();

        try {
            $post = $this->service->getPost($postId);

            if (!$this->policy->canDelete($post, $actorId)) {
                if ($this->wantsJson($request)) {
                    Response::json(['error' => 'You are not allowed to delete this post.'], 403);
                    return;
                }
                session()->flash('error', 'You are not allowed to delete this discussion.');
                Response::redirect('/community/post/' . $postId);
                return;
            }

            $success = $this->service->deletePost($actorId, $postId);

            if ($this->wantsJson($request)) {
                Response::json(['success' => $success]);
                return;
            }

            session()->flash('success', 'Discussion deleted.');
            Response::redirect('/community');
        } catch (CommunityException $e) {
            if ($this->wantsJson($request)) {
                $this->handleException($e);
                return;
            }

            session()->flash('error', $e->getMessage());
            Response::redirect('/community/post/' . $postId);
        }
    }

    // ===================================================================
    // COMMENT WRITE ENDPOINTS (AUTH REQUIRED)
    // ===================================================================

    /**
     * POST /community/posts/{id}/comments — Create a comment on a post.
     */
    public function storeComment(Request $request, array $params = []): void
    {
        $this->enforceRateLimit($request, 'community_comment', 40, 60);
        $postId  = (int) ($params['id'] ?? 0);
        $actorId = (int) auth()->id();
        $data    = $this->readPayload($request);

        try {
            $commentId = $this->service->createComment($actorId, $postId, $data);
            if ($this->wantsJson($request)) {
                Response::json(['success' => true, 'id' => $commentId], 201);
                return;
            }

            session()->flash('success', 'Comment posted successfully.');
            Response::redirect('/community/post/' . $postId);
        } catch (CommunityException $e) {
            if ($this->wantsJson($request)) {
                $this->handleException($e);
                return;
            }

            session()->flash('error', $e->getMessage());
            Response::redirect('/community/post/' . $postId);
        }
    }

    /**
     * POST/PATCH /community/comments/{id} — Update a comment.
     */
    public function updateComment(Request $request, array $params = []): void
    {
        $commentId = (int) ($params['id'] ?? 0);
        $actorId   = (int) auth()->id();
        $data      = $this->readPayload($request);

        try {
            $comment = $this->service->getComment($commentId);
            $postId  = (int) ($comment['post_id'] ?? 0);

            $success = $this->service->updateComment($actorId, $commentId, $data);
            if ($this->wantsJson($request)) {
                Response::json(['success' => $success]);
                return;
            }

            session()->flash('success', 'Comment updated successfully.');
            Response::redirect('/community/post/' . $postId);
        } catch (CommunityException $e) {
            if ($this->wantsJson($request)) {
                $this->handleException($e);
                return;
            }

            session()->flash('error', $e->getMessage());
            $postId = (int) ($data['post_id'] ?? 0);
            Response::redirect($postId > 0 ? '/community/post/' . $postId : '/community');
        }
    }

    /**
     * DELETE /community/comments/{id} — Hard delete a comment.
     */
    public function destroyComment(Request $request, array $params = []): void
    {
        $commentId = (int) ($params['id'] ?? 0);
        $actorId   = (int) auth()->id();

        try {
            $comment = $this->service->getComment($commentId);
            $postId  = (int) ($comment['post_id'] ?? 0);

            $success = $this->service->deleteComment($actorId, $commentId);
            if ($this->wantsJson($request)) {
                Response::json(['success' => $success]);
                return;
            }

            session()->flash('success', 'Comment deleted.');
            Response::redirect('/community/post/' . $postId);
        } catch (CommunityException $e) {
            if ($this->wantsJson($request)) {
                $this->handleException($e);
                return;
            }

            session()->flash('error', $e->getMessage());
            Response::redirect('/community');
        }
    }

    // ===================================================================
    // LIKE ENDPOINTS (AUTH REQUIRED)
    // ===================================================================

    /**
     * POST /community/posts/{id}/like — Like a post.
     */
    public function like(Request $request, array $params = []): void
    {
        $this->enforceRateLimit($request, 'community_like', 60, 60);
        $postId  = (int) ($params['id'] ?? 0);
        $actorId = (int) auth()->id();

        try {
            $post = $this->service->getPost($postId);

            if (!$this->policy->canLike($post, $actorId)) {
                if ($this->wantsJson($request)) {
                    Response::json(['error' => 'You cannot like your own post.'], 403);
                    return;
                }
                session()->flash('error', 'You cannot like your own post.');
                Response::redirect('/community/post/' . $postId);
                return;
            }

            $likeId    = $this->service->likePost($actorId, $postId);
            $likeCount = $this->service->getLikeCount($postId);

            if ($this->wantsJson($request)) {
                Response::json([
                    'success'    => true,
                    'liked'      => true,
                    'like_count' => $likeCount,
                    'like_id'    => $likeId,
                ]);
                return;
            }

            Response::redirect('/community/post/' . $postId);
        } catch (CommunityException $e) {
            if ($this->wantsJson($request)) {
                $this->handleException($e);
                return;
            }

            session()->flash('error', $e->getMessage());
            Response::redirect('/community/post/' . $postId);
        }
    }

    /**
     * DELETE /community/posts/{id}/like — Unlike a post.
     */
    public function unlike(Request $request, array $params = []): void
    {
        $postId  = (int) ($params['id'] ?? 0);
        $actorId = (int) auth()->id();

        try {
            $removed   = $this->service->unlikePost($actorId, $postId);
            $likeCount = $this->service->getLikeCount($postId);

            if ($this->wantsJson($request)) {
                Response::json([
                    'success'    => true,
                    'liked'      => false,
                    'like_count' => $likeCount,
                    'removed'    => $removed,
                ]);
                return;
            }

            Response::redirect('/community/post/' . $postId);
        } catch (CommunityException $e) {
            if ($this->wantsJson($request)) {
                $this->handleException($e);
                return;
            }

            session()->flash('error', $e->getMessage());
            Response::redirect('/community/post/' . $postId);
        }
    }

    // ===================================================================
    // REPORT ENDPOINTS (AUTH REQUIRED)
    // ===================================================================

    /**
     * POST /community/posts/{id}/report — Report a post.
     */
    public function reportPost(Request $request, array $params = []): void
    {
        $this->enforceRateLimit($request, 'community_report', 10, 60);
        $postId  = (int) ($params['id'] ?? 0);
        $actorId = (int) auth()->id();
        $data    = $this->readPayload($request);

        try {
            $post = $this->service->getPost($postId);

            if (!$this->policy->canReport($post, $actorId)) {
                if ($this->wantsJson($request)) {
                    Response::json(['error' => 'You cannot report your own post.'], 403);
                    return;
                }
                session()->flash('error', 'You cannot report your own post.');
                Response::redirect("/community/post/{$postId}");
                return;
            }

            $reportId = $this->service->reportPost($actorId, $postId, $data);

            if ($this->wantsJson($request)) {
                Response::json(['success' => true, 'id' => $reportId], 201);
                return;
            }

            session()->flash('success', 'Thank you — your report has been submitted for review.');
            Response::redirect("/community/post/{$postId}");
        } catch (CommunityException $e) {
            if ($this->wantsJson($request)) {
                $this->handleException($e);
                return;
            }
            session()->flash('error', $e->getMessage());
            Response::redirect("/community/post/{$postId}");
        }
    }

    /**
     * POST /community/comments/{id}/report — Report a comment.
     */
    public function reportComment(Request $request, array $params = []): void
    {
        $this->enforceRateLimit($request, 'community_report', 10, 60);
        $commentId = (int) ($params['id'] ?? 0);
        $actorId   = (int) auth()->id();
        $data      = $this->readPayload($request);

        // Determine the post_id so we can redirect back to the post detail page.
        // It can come from the form field or be resolved from the comment.
        $postId = isset($data['post_id']) && (int) $data['post_id'] > 0
            ? (int) $data['post_id']
            : 0;

        try {
            $reportId = $this->service->reportComment($actorId, $commentId, $data);

            if ($this->wantsJson($request)) {
                Response::json(['success' => true, 'id' => $reportId], 201);
                return;
            }

            session()->flash('success', 'Thank you — your report has been submitted for review.');
            $redirect = $postId > 0 ? "/community/post/{$postId}" : '/community';
            Response::redirect($redirect);
        } catch (CommunityException $e) {
            if ($this->wantsJson($request)) {
                $this->handleException($e);
                return;
            }
            session()->flash('error', $e->getMessage());
            $redirect = $postId > 0 ? "/community/post/{$postId}" : '/community';
            Response::redirect($redirect);
        }
    }

    // ===================================================================
    // USER FOLLOW ENDPOINTS (Phase C7-B)
    // ===================================================================

    /**
     * POST /community/user/{id}/follow — Follow a user.
     */
    public function followUser(Request $request, array $params = []): void
    {
        if (!auth_check()) {
            if ($this->wantsJson($request)) {
                Response::json(['error' => 'Authentication required.'], 401);
                return;
            }
            session()->flash('error', 'Please log in to follow community members.');
            Response::redirect('/login');
            return;
        }

        $this->enforceRateLimit($request, 'community_follow', 60, 60);

        $targetUserId = (int) ($params['id'] ?? 0);
        $actorId      = (int) auth()->id();

        try {
            $followId = $this->service->followUser($actorId, $targetUserId);

            if ($this->wantsJson($request)) {
                Response::json(['success' => true, 'following' => true, 'id' => $followId]);
                return;
            }

            session()->flash('success', 'You are now following this member.');
            Response::redirect('/community/user/' . $targetUserId);
        } catch (CommunityException $e) {
            if ($this->wantsJson($request)) {
                $this->handleException($e);
                return;
            }

            session()->flash('error', $e->getMessage());
            Response::redirect('/community/user/' . $targetUserId);
        }
    }

    /**
     * DELETE /community/user/{id}/follow & POST /community/user/{id}/unfollow — Unfollow a user.
     */
    public function unfollowUser(Request $request, array $params = []): void
    {
        if (!auth_check()) {
            if ($this->wantsJson($request)) {
                Response::json(['error' => 'Authentication required.'], 401);
                return;
            }
            session()->flash('error', 'Please log in to unfollow community members.');
            Response::redirect('/login');
            return;
        }

        $this->enforceRateLimit($request, 'community_follow', 60, 60);

        $targetUserId = (int) ($params['id'] ?? 0);
        $actorId      = (int) auth()->id();

        try {
            $removed = $this->service->unfollowUser($actorId, $targetUserId);

            if ($this->wantsJson($request)) {
                Response::json(['success' => true, 'following' => false, 'removed' => $removed]);
                return;
            }

            session()->flash('success', 'You have unfollowed this member.');
            Response::redirect('/community/user/' . $targetUserId);
        } catch (CommunityException $e) {
            if ($this->wantsJson($request)) {
                $this->handleException($e);
                return;
            }

            session()->flash('error', $e->getMessage());
            Response::redirect('/community/user/' . $targetUserId);
        }
    }

    /**
     * GET /community/user/{id}/followers — Paginated followers list.
     */
    public function followers(Request $request, array $params = []): void
    {
        $userId  = (int) ($params['id'] ?? 0);
        $page    = max(1, (int) $request->input('page', 1));
        $perPage = (int) $request->input('per_page', 20);

        try {
            $data = $this->service->listFollowers($userId, $page, $perPage);
            $user = (new User())->findById($userId);

            if ($user === null) {
                Response::error(404, 'User not found.');
                return;
            }

            if ($this->wantsJson($request)) {
                Response::json($data);
                return;
            }

            $this->view('community.followers', [
                'title'       => e($user['full_name'] ?? '') . ' — Followers',
                'active'      => 'community',
                'profileUser' => $user,
                'followers'   => $data['items'],
                'total'       => $data['total'],
                'page'        => $data['page'],
                'pages'       => $data['pages'],
                'perPage'     => $data['per_page'],
            ]);
        } catch (CommunityException $e) {
            if ($this->wantsJson($request)) {
                $this->handleException($e);
                return;
            }

            Response::error(404, 'User not found.');
        }
    }

    /**
     * GET /community/user/{id}/following — Paginated following list.
     */
    public function following(Request $request, array $params = []): void
    {
        $userId  = (int) ($params['id'] ?? 0);
        $page    = max(1, (int) $request->input('page', 1));
        $perPage = (int) $request->input('per_page', 20);

        try {
            $data = $this->service->listFollowing($userId, $page, $perPage);
            $user = (new User())->findById($userId);

            if ($user === null) {
                Response::error(404, 'User not found.');
                return;
            }

            if ($this->wantsJson($request)) {
                Response::json($data);
                return;
            }

            $this->view('community.following', [
                'title'       => e($user['full_name'] ?? '') . ' — Following',
                'active'      => 'community',
                'profileUser' => $user,
                'following'   => $data['items'],
                'total'       => $data['total'],
                'page'        => $data['page'],
                'pages'       => $data['pages'],
                'perPage'     => $data['per_page'],
            ]);
        } catch (CommunityException $e) {
            if ($this->wantsJson($request)) {
                $this->handleException($e);
                return;
            }

            Response::error(404, 'User not found.');
        }
    }

    // ===================================================================
    // HELPERS & ERROR HANDLING
    // ===================================================================

    /**
     * Check if client explicitly wants a JSON response.
     */
    private function wantsJson(Request $request): bool
    {
        return $request->header('X-Requested-With') === 'fetch'
            || str_contains((string) $request->header('Accept'), 'application/json');
    }

    /**
     * Extract request payload from POST parameters or JSON body.
     *
     * @return array<string,mixed>
     */
    private function readPayload(Request $request): array
    {
        $data = [];
        $raw  = file_get_contents('php://input');

        if ($raw && str_contains((string) $request->header('Content-Type'), 'application/json')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        return array_merge($data, $_POST);
    }

    /**
     * Translate domain CommunityException into appropriate HTTP status & JSON payload.
     */
    private function handleException(CommunityException $e): void
    {
        $msg = $e->getMessage();
        $status = 400;

        if (str_contains($msg, 'not found')) {
            $status = 404;
        } elseif (str_contains($msg, 'not allowed') || str_contains($msg, 'Permission')) {
            $status = 403;
        } elseif (str_contains($msg, 'Invalid') || str_contains($msg, 'must')) {
            $status = 422;
        } elseif (str_contains($msg, 'already')) {
            $status = 409;
        }

        Response::json(['error' => $msg], $status);
    }
}
