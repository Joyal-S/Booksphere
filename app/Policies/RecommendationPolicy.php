<?php

declare(strict_types=1);

namespace BookSphere\App\Policies;

/**
 * RecommendationPolicy
 *
 * The authorization layer of the recommendations module. The route
 * table already protects every page with AuthMiddleware (the coarse
 * gate); this policy is the FINE gate the controller asks for
 * before running a strategy, so authorization rules live in one
 * obvious place and can be extended without touching the routes.
 *
 * Phase 6.1:
 *     Only view() exists: every signed-in user may open any
 *     recommendations page. Later phases add the fine-grained
 *     checks here when the features arrive (e.g. an admin-only
 *     "regenerate stored recommendations" action), keeping the
 *     controller and the routes untouched.
 */
final class RecommendationPolicy
{
    /**
     * Whether the current user may view recommendation pages.
     */
    public function view(): bool
    {
        return auth_check();
    }
}
