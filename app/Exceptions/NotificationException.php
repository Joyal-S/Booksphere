<?php

declare(strict_types=1);

namespace BookSphere\App\Exceptions;

use RuntimeException;

/**
 * NotificationException
 *
 * The single exception type of the Notification module (Phase 9.2),
 * mirroring FollowException and the other module exceptions. The
 * module fails loudly with meaningful, user-friendly messages:
 *
 *     - notificationNotFound()  -> the row is missing OR not owned
 *                                   by the actor (the same 404 for
 *                                   both - no existence leak, the
 *                                   IDOR guard)
 *     - invalidType()           -> an unknown type key reaches the
 *                                   dispatcher / formatter (the
 *                                   catalog is the single source of
 *                                   truth)
 *     - invalidPreference()     -> a preference toggle outside the
 *                                   seven categories was submitted
 *     - permissionDenied()      -> a broadcast / write attempted by
 *                                   someone without the ability
 *
 * How it is used:
 *     - The dispatcher / formatter / service throw these when a rule
 *       fails; the controller catches and answers with the correct
 *       HTTP status (404 / 422 / 403) and a plain, safe message.
 *     - PDO database errors are deliberately NOT wrapped: they
 *       bubble up to the application ErrorHandler, which logs and
 *       reports them once, in one place (the documented project rule).
 */
final class NotificationException extends RuntimeException
{
    public static function notificationNotFound(int $id): self
    {
        return new self("Notification not found: {$id}.");
    }

    public static function invalidType(string $type): self
    {
        return new self("Unknown notification type: {$type}.");
    }

    public static function invalidPreference(string $category): self
    {
        return new self("Unknown notification preference: {$category}.");
    }

    public static function permissionDenied(string $action): self
    {
        return new self("You are not allowed to {$action} this notification.");
    }
}
