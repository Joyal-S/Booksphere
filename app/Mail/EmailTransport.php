<?php

declare(strict_types=1);

namespace BookSphere\App\Mail;

/**
 * EmailTransport
 *
 * The sending contract of the mail pipeline (Phase 9.5). A transport
 * takes ONE EmailMessage and either sends it or fails - it NEVER
 * throws for a delivery problem, it returns false and records what
 * went wrong in lastError() so the caller (the Mailer facade) can log
 * it and keep the requesting code unbroken.
 *
 * The two concrete transports live next to this interface:
 *
 *     LogEmailTransport - writes the message to a file (the default
 *                         transport when no SMTP server is set up);
 *                         a message written here counts as SENT.
 *     SmtpTransport     - a dependency-free SMTP client (PHP streams
 *                         only, no composer packages) for real
 *                         delivery.
 */
interface EmailTransport
{
    /**
     * Deliver a message. Returns true when the message was accepted
     * for delivery, false on any failure (connection refused, bad
     * credentials, a rejected recipient, ...).
     */
    public function send(EmailMessage $message): bool;

    /**
     * A human-readable description of the last send failure, or null
     * when the last send succeeded (or nothing has been sent yet).
     */
    public function lastError(): ?string;
}