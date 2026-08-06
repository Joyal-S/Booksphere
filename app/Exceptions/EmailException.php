<?php

declare(strict_types=1);

namespace BookSphere\App\Exceptions;

use RuntimeException;

/**
 * EmailException
 *
 * The single exception type of the Email module (Phase 9.5),
 * mirroring NotificationException and the other module exceptions.
 * The module fails loudly only for PROGRAMMER errors - everything a
 * normal request can hit (a dead SMTP server, a malformed address,
 * a missing recipient) is handled gracefully by the service, which
 * logs and moves on instead of throwing.
 *
 *     - invalidEmail()        -> an invalid recipient address was
 *                                 used in an EmailMessage
 *     - invalidPreference()   -> an email preference toggle outside
 *                                 the five known keys was submitted
 *
 * How it is used:
 *     - EmailMessage validates its payload and throws invalidEmail()
 *       for a malformed / injectable address (a programmer error in
 *       the caller, not a user error).
 *     - EmailNotificationService::updatePreference() throws
 *       invalidPreference() for an unknown key; the settings
 *       controller catches it and answers a 422.
 *     - Delivery failures NEVER throw: the Mailer wraps SMTP errors
 *       and returns false, the service records the failure in
 *       email_logs and keeps the request unbroken.
 */
final class EmailException extends RuntimeException
{
    public static function invalidEmail(string $address): self
    {
        return new self("Invalid email address: {$address}.");
    }

    public static function invalidPreference(string $key): self
    {
        return new self("Unknown email preference: {$key}.");
    }
}