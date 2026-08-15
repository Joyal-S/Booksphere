<?php

declare(strict_types=1);

namespace BookSphere\App\Policies;

use BookSphere\App\Models\User;

/**
 * CommunityPolicy
 *
 * Authorization layer of the Community module (Phase C3-A).
 * Mirrors ReviewPolicy/LibraryPolicy: read-only gate, auth_check() and
 * auth_is_admin() helpers, explicit actorId parameter so the service
 * and tests can supply identity without a live session.
 */
final class CommunityPolicy
{
    /** Guests may browse the community feed. */
    public function canViewFeed(): bool
    {
        return true;
    }

    /** Only authenticated users may create posts. */
    public function canCreatePost(): bool
    {
        return auth_check();
    }

    /**
     * Only the post's author or an admin may edit it.
     *
     * @param array<string,mixed> $post
     */
    public function canEdit(array $post, ?int $actorId = null): bool
    {
        return $this->ownsPost($post, $actorId) || $this->isActorAdmin($actorId);
    }

    /**
     * Same as canEdit ? owners and admins may delete.
     *
     * @param array<string,mixed> $post
     */
    public function canDelete(array $post, ?int $actorId = null): bool
    {
        return $this->canEdit($post, $actorId);
    }

    /** Authenticated users may leave comments. */
    public function canComment(): bool
    {
        return auth_check();
    }

    /**
     * Only the comment's author or an admin may edit it.
     *
     * @param array<string,mixed> $comment
     */
    public function canEditComment(array $comment, ?int $actorId = null): bool
    {
        return $this->ownsComment($comment, $actorId) || $this->isActorAdmin($actorId);
    }

    /**
     * Same as canEditComment.
     *
     * @param array<string,mixed> $comment
     */
    public function canDeleteComment(array $comment, ?int $actorId = null): bool
    {
        return $this->canEditComment($comment, $actorId);
    }

    /**
     * Authenticated users may like a post, but not their own post.
     *
     * @param array<string,mixed> $post
     */
    public function canLike(array $post, ?int $actorId = null): bool
    {
        if (!auth_check() && $actorId === null) {
            return false;
        }

        return !$this->ownsPost($post, $actorId);
    }

    /**
     * Authenticated users may report content they do not own.
     *
     * @param array<string,mixed> $content  A post or comment row
     */
    public function canReport(array $content, ?int $actorId = null): bool
    {
        if (!auth_check() && $actorId === null) {
            return false;
        }

        $effective = $actorId ?? auth()?->id();

        return $effective !== null && $effective !== (int) ($content['user_id'] ?? 0);
    }

    /** Admins only for moderation queue and status updates. */
    public function canModerate(?int $actorId = null): bool
    {
        return $this->isActorAdmin($actorId);
    }

    /**
     * Authenticated users may follow other users, but cannot follow themselves.
     */
    public function canFollowUser(int $actorId, int $targetUserId): bool
    {
        if ($actorId <= 0 || $targetUserId <= 0 || $actorId === $targetUserId) {
            return false;
        }

        return true;
    }

    // ------------------------------------------------------------------ //
    // Internals                                                            //
    // ------------------------------------------------------------------ //

    /** @param array<string,mixed> $post */
    private function ownsPost(array $post, ?int $actorId): bool
    {
        $effective = $actorId ?? auth()?->id();

        return $effective !== null && (int) ($post['user_id'] ?? 0) === $effective;
    }

    /** @param array<string,mixed> $comment */
    private function ownsComment(array $comment, ?int $actorId): bool
    {
        $effective = $actorId ?? auth()?->id();

        return $effective !== null && (int) ($comment['user_id'] ?? 0) === $effective;
    }

    private function isActorAdmin(?int $actorId): bool
    {
        if (auth_is_admin()) {
            return true;
        }

        if ($actorId === null) {
            return false;
        }

        $user = (new User())->findById($actorId);

        return ($user['role'] ?? '') === 'admin';
    }
}
