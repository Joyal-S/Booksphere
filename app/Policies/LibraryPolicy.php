<?php

declare(strict_types=1);

namespace BookSphere\App\Policies;

/**
 * LibraryPolicy
 *
 * The authorization layer of the Wishlist & Personal Reading Library
 * module (Phase 8.1) - the FINE gate the controller asks before
 * running an action, exactly like ReviewPolicy and
 * RecommendationPolicy. The route table already provides the coarse
 * gate (AuthMiddleware on every library route); this class owns the
 * per-request rules:
 *
 *     - canAccess()   -> guests can NEVER reach the library; every
 *                        authenticated user may use their own
 *     - canManage()   -> an authenticated user may manage only their
 *                        OWN library records. Admins are NOT exempt:
 *                        the brief is explicit - an admin cannot
 *                        modify another user's library (a personal
 *                        library is private, unlike reviews)
 *     - canView()     -> the owner may view their record; admins may
 *                        VIEW any record (read-only oversight) but
 *                        still not modify it
 *
 * The user id of a library record always comes from the SESSION (the
 * controller never trusts a submitted user id), so an IDOR attempt
 * cannot re-point a write at another user's record - this policy is
 * the second line of defence.
 *
 * The policy stays read-only: it answers "may this actor do X?" and
 * never mutates state.
 */
final class LibraryPolicy
{
    /**
     * Whether the current user may use the library at all. Guests can
     * never access a library - every library route also sits behind
     * AuthMiddleware, this is the in-controller gate.
     */
    public function canAccess(): bool
    {
        return auth_check();
    }

    /**
     * Whether an actor may manage a library record: ONLY the owner.
     * The admin override other policies grant is deliberately ABSENT
     * here - a personal library is private, so even an admin cannot
     * change another user's record (they may only view it).
     *
     * @param array<string, mixed> $record  The library row
     * @param int|null             $actorId The acting user id
     *                                      (falls back to the
     *                                      session user)
     */
    public function canManage(array $record, ?int $actorId = null): bool
    {
        $actorId = $actorId ?? auth()?->id();

        return $actorId !== null && (int) ($record['user_id'] ?? 0) === $actorId;
    }

    /**
     * Whether an actor may VIEW a library record: the owner, or an
     * administrator (read-only oversight - viewing never grants
     * modification).
     *
     * @param array<string, mixed> $record  The library row
     * @param int|null             $actorId The acting user id
     */
    public function canView(array $record, ?int $actorId = null): bool
    {
        return $this->canManage($record, $actorId) || auth_is_admin();
    }
}