<?php

declare(strict_types=1);

namespace BookSphere\App\Policies;

/**
 * FollowPolicy
 *
 * The authorization layer of the Follow Authors module (Phase 9.2) -
 * the FINE gate the controller asks before running an action, exactly
 * like LibraryPolicy and ReviewPolicy. The route table already
 * provides the coarse gate (AuthMiddleware on every follow route);
 * this class owns the per-request rules:
 *
 *     - canFollow()    -> any authenticated user may follow an author
 *                         (the service additionally rejects a missing
 *                         author, a self-follow and a duplicate)
 *     - canUnfollow()  -> ONLY the owner of the follow row. Admins
 *                         are NOT exempt for writes: a follow is
 *                         private data, like the library - an admin
 *                         can never unfollow on behalf of a user
 *     - canViewAuthor() -> any authenticated user may read the public
 *                         author follower statistic (the count on the
 *                         author page)
 *     - canViewList()  -> a user's following / followers list belongs
 *                         to the owner, or an admin for read-only
 *                         oversight (the same stance as
 *                         LibraryPolicy::canView)
 *
 * The user id of a follow always comes from the SESSION (the
 * controller never trusts a submitted user id), so an IDOR attempt
 * cannot re-point a write at another user's follow - this policy is
 * the second line of defence.
 *
 * The policy stays read-only: it answers "may this actor do X?" and
 * never mutates state.
 */
final class FollowPolicy
{
    /**
     * Whether the current user may follow an author at all. Guests
     * may never follow - every follow route also sits behind
     * AuthMiddleware, this is the in-controller gate.
     */
    public function canFollow(): bool
    {
        return auth_check();
    }

    /**
     * Whether an actor may UNfollow: ONLY the owner of the follow
     * row. The admin override other policies grant is deliberately
     * ABSENT here - a follow is private, so even an admin cannot
     * write another user's follow (they may only view lists).
     *
     * @param array<string, mixed>|null $follow The follow row
     * @param int|null                  $actorId The acting user id
     *                                           (falls back to the
     *                                           session user)
     */
    public function canUnfollow(?array $follow, ?int $actorId = null): bool
    {
        if ($follow === null) {
            return false;
        }

        $actorId = $actorId ?? auth()?->id();

        return $actorId !== null && (int) ($follow['user_id'] ?? 0) === $actorId;
    }

    /**
     * Whether an actor may view an author's follower statistic (the
     * count on the author page). Any authenticated user may - the
     * count is public catalogue data.
     */
    public function canViewFollowerCount(): bool
    {
        return auth_check();
    }

    /**
     * Whether an actor may view a user's following / followers list:
     * the owner, or an administrator (read-only oversight - viewing
     * never grants modification).
     *
     * @param int|null $ownerId The list's owner
     * @param int|null $actorId The acting user id
     */
    public function canViewList(?int $ownerId, ?int $actorId = null): bool
    {
        $actorId = $actorId ?? auth()?->id();

        return ($actorId !== null && $ownerId !== null && $actorId === $ownerId) || auth_is_admin();
    }
}