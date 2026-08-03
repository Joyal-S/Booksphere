<?php

declare(strict_types=1);

namespace BookSphere\App\Policies;

/**
 * ReviewPolicy
 *
 * The authorization layer of the Reviews & Ratings module (Phase
 * 7.1) - the FINE gate the controller asks before running an
 * action, exactly like RecommendationPolicy in the engine module.
 * The route table already provides the coarse gate (AuthMiddleware
 * on every review route); this class owns the per-request rules:
 *
 *     - canReview()  -> guests can never write a review; any
 *                       authenticated user may review books they
 *                       have not reviewed yet (the "already
 *                       reviewed" rule is the service's job)
 *     - canEdit()    -> only the review's OWNER or an admin may
 *                       edit a review
 *     - canDelete()  -> only the owner or an admin may delete a
 *                       review
 *
 * Admin override: auth_is_admin() short-circuits the ownership
 * check, so administrators can edit/delete any review - the same
 * rule the brief asks for, and the foundation the future
 * moderation screens (Phase 7.4+) will build on.
 *
 * The policy stays read-only: it answers "may this actor do X?"
 * and never mutates state.
 */
final class ReviewPolicy
{
    /**
     * Whether the current user may write a review.
     */
    public function canReview(): bool
    {
        return auth_check();
    }

    /**
     * Whether an actor may edit a review: they wrote it, or they
     * are an admin.
     *
     * @param array<string, mixed> $review  The review row
     * @param int|null             $actorId The acting user id
     *                                      (falls back to the
     *                                      session user)
     */
    public function canEdit(array $review, ?int $actorId = null): bool
    {
        return $this->owns($review, $actorId) || auth_is_admin();
    }

    /**
     * Whether an actor may delete a review - same rule as editing.
     *
     * @param array<string, mixed> $review  The review row
     * @param int|null             $actorId The acting user id
     */
    public function canDelete(array $review, ?int $actorId = null): bool
    {
        return $this->canEdit($review, $actorId);
    }

    /**
     * Whether the acting user wrote the review.
     *
     * @param array<string, mixed> $review  The review row
     * @param int|null             $actorId The acting user id
     */
    private function owns(array $review, ?int $actorId): bool
    {
        $actorId = $actorId ?? auth()?->id();

        return $actorId !== null && (int) ($review['user_id'] ?? 0) === $actorId;
    }
}
