<?php

declare(strict_types=1);

namespace BookSphere\App\Models;

use BookSphere\App\Repositories\AuthorFollowRepository;

/**
 * AuthorFollow
 *
 * The domain representation of one follow relationship and the
 * public API of the Follow Authors module's data layer - a THIN
 * FACADE over AuthorFollowRepository, following the exact pattern of
 * the UserLibrary and Review models: no business logic, no SQL, just
 * one predictable interface for the service and the views.
 *
 * Entity columns (the author_follows table, migration 0022):
 *
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT
 *     user_id     INTEGER NOT NULL  (FK users.id, ON DELETE CASCADE)
 *     author_id   INTEGER NOT NULL  (FK authors.id, ON DELETE CASCADE)
 *     created_at  TEXT    NOT NULL  (UTC ISO-8601)
 *
 * The UNIQUE (user_id, author_id) index enforces the "one follow per
 * user per author" rule at the database level; the "cannot follow
 * yourself" rule is a service rule (FollowService::follow()).
 *
 * Relationships (established by the foreign keys):
 *     author_follows n---1 users   (a follow belongs to a user)
 *     author_follows n---1 authors (a follow points at an author)
 *     The relationship METHODS below (author(), user()) resolve the
 *     related row on demand - the project has no lazy-loading magic,
 *     so they are explicit helpers.
 *
 * Dependencies:
 *     - AuthorFollowRepository (the actual PDO/prepared-statement SQL).
 *     - Author + User models (for the relationship lookups).
 *
 * How it fits inside MVC:
 *     Controller -> Service (business rules) -> AuthorFollow (facade)
 *     -> AuthorFollowRepository (SQL) -> PDO -> SQLite.
 */
final class AuthorFollow
{
    public function __construct(private readonly AuthorFollowRepository $repository = new AuthorFollowRepository()) {}

    // --- CRUD ---------------------------------------------------------

    /**
     * Create a follow row and return its id.
     *
     * @param array<string, mixed> $data Normalized column values:
     *                                    user_id, author_id
     */
    public function create(array $data): int
    {
        return $this->repository->create($data);
    }

    /**
     * Delete a follow row by its id.
     */
    public function delete(int $id): bool
    {
        return $this->repository->delete($id);
    }

    /**
     * Remove the follow row of one user for one author - the unfollow
     * of the module (idempotent: a non-existent pair deletes nothing
     * and answers false).
     */
    public function deleteForPair(int $userId, int $authorId): bool
    {
        return $this->repository->deleteForPair($userId, $authorId);
    }

    /**
     * Find a single follow row by id.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->repository->find($id);
    }

    /**
     * Whether a user already follows an author (the duplicate and
     * button-state check).
     */
    public function exists(int $userId, int $authorId): bool
    {
        return $this->repository->exists($userId, $authorId);
    }

    /**
     * The follow row of one user for one author (the row the
     * FollowPolicy::canUnfollow gate reads).
     *
     * @return array<string, mixed>|null
     */
    public function findForPair(int $userId, int $authorId): ?array
    {
        return $this->repository->findForPair($userId, $authorId);
    }

    /**
     * Whether a user already follows an author - the name the author
     * page reads (an alias of exists() under the domain wording).
     */
    public function isFollowing(int $userId, int $authorId): bool
    {
        return $this->exists($userId, $authorId);
    }

    // --- Reads --------------------------------------------------------

    /**
     * The user's followed authors, newest first, joined with the
     * author display columns. The optional offset serves the
     * followed-authors pages (Phase 9.6).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findForUser(int $userId, int $limit = 50, int $offset = 0): array
    {
        return $this->repository->findForUser($userId, $limit, $offset);
    }

    /**
     * The followers of one author, newest first, joined with the
     * user display columns. The optional offset serves the
     * followers page (Phase 9.6).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findFollowersOf(int $authorId, int $limit = 50, int $offset = 0): array
    {
        return $this->repository->findFollowersOf($authorId, $limit, $offset);
    }

    /**
     * The total number of authors a user follows - the honest
     * denominator of the "Authors I follow" page (Phase 9.6).
     */
    public function countForUser(int $userId): int
    {
        return $this->repository->countForUser($userId);
    }

    /**
     * The total number of followers an author has - the lead figure
     * of the followers page (Phase 9.6) and the pagination total.
     */
    public function countFollowersOf(int $authorId): int
    {
        return $this->repository->countFollowersOf($authorId);
    }

    /**
     * The follower count of one author (the author-page statistic).
     */
    public function followerCount(int $authorId): int
    {
        return $this->repository->followerCount($authorId);
    }

    // --- Relationships ------------------------------------------------

    /**
     * The author a follow row points at (belongsTo).
     *
     * @param array<string, mixed> $follow A follow row
     * @return array<string, mixed>|null
     */
    public function author(array $follow): ?array
    {
        return (new Author())->findById((int) ($follow['author_id'] ?? 0));
    }

    /**
     * The user who follows (belongsTo).
     *
     * @param array<string, mixed> $follow A follow row
     * @return array<string, mixed>|null
     */
    public function user(array $follow): ?array
    {
        return (new User())->findById((int) ($follow['user_id'] ?? 0));
    }
}
