<?php

declare(strict_types=1);

namespace BookSphere\App\Mail;

/**
 * EmailType
 *
 * The closed catalog of EMAIL delivery types (Phase 9.5) - the
 * constants the module writes into email_logs.type and email_queue.type,
 * used instead of bare strings everywhere so a misspelled key is a
 * compile-time error, never a silent runtime mismatch.
 *
 * A type here is a DELIVERY subject, not a notification type: several
 * in-app notification types can map onto one email type (the follow
 * email), and transactional emails (password reset, verification,
 * welcome) have no in-app counterpart at all.
 *
 * Types without an in-app source (WELCOME, PASSWORD_RESET,
 * EMAIL_VERIFICATION, NEWSLETTER) are future-ready: their templates
 * exist now, the dispatch path that produces them arrives with the
 * feature they belong to.
 */
final class EmailType
{
    /** Follows / new releases of a followed author. */
    public const FOLLOW = 'follow';

    /** Someone found the user's review helpful. */
    public const REVIEW = 'review';

    /** Someone replied to the user's review. */
    public const REPLY = 'reply';

    /** A fresh personalized recommendation shelf. */
    public const RECOMMENDATION = 'recommendation';

    /** An author the user follows published a new book. */
    public const AUTHOR_RELEASE = 'author_release';

    /** Periodic digests (reserved). */
    public const NEWSLETTER = 'newsletter';

    /** Account creation welcome (reserved). */
    public const WELCOME = 'welcome';

    /** Password reset with a single-use link (reserved). */
    public const PASSWORD_RESET = 'password_reset';

    /** Email address verification (reserved). */
    public const EMAIL_VERIFICATION = 'email_verification';

    /**
     * Every type the module knows how to render. Extra transactional
     * types may come to the catalog with the feature they belong to.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::FOLLOW,
            self::REVIEW,
            self::REPLY,
            self::RECOMMENDATION,
            self::AUTHOR_RELEASE,
            self::NEWSLETTER,
            self::WELCOME,
            self::PASSWORD_RESET,
            self::EMAIL_VERIFICATION,
        ];
    }
}