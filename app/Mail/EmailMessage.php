<?php

declare(strict_types=1);

namespace BookSphere\App\Mail;

use BookSphere\App\Exceptions\EmailException;

/**
 * EmailMessage
 *
 * The immutable value object of the mail pipeline (Phase 9.5): one
 * ready-to-send message. It carries the recipient, the subject and
 * the rendered HTML body (plus an optional plain-text version), and
 * it VALIDATES them at construction time:
 *
 *     - the recipient address must pass FILTER_VALIDATE_EMAIL
 *     - NO field may contain a CR or LF - the classic SMTP header
 *       injection ("victim@x.com\r\nBcc: attacker@evil.com") is
 *       rejected outright, so a user-supplied name can never smuggle
 *       extra headers into the envelope
 *     - the subject is capped at 200 characters
 *
 * The module NEVER sends anything but an EmailMessage through the
 * transports, so validation happens exactly once, in one place.
 */
final class EmailMessage
{
    /** The longest subject line the module ever sends. */
    public const MAX_SUBJECT_LENGTH = 200;

    public function __construct(
        private readonly string $to,
        private readonly string $toName,
        private readonly string $subject,
        private readonly string $html,
        private readonly ?string $text = null,
    ) {
        $this->validate();
    }

    public function to(): string
    {
        return $this->to;
    }

    public function toName(): string
    {
        return $this->toName;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function html(): string
    {
        return $this->html;
    }

    public function text(): ?string
    {
        return $this->text;
    }

    /**
     * The recipient displayed as "Name <address>", or the bare
     * address when no name is known.
     */
    public function toLine(): string
    {
        return $this->toName !== '' ? "{$this->toName} <{$this->to}>" : $this->to;
    }

    /**
     * Validate the payload. Throws EmailException::invalidEmail() for
     * an address that is not a valid email or contains a newline.
     */
    private function validate(): void
    {
        // The CR/LF check runs on EVERY field: a newline in any of
        // them is the injection vector into the SMTP DATA headers.
        foreach (['to' => $this->to, 'toName' => $this->toName, 'subject' => $this->subject] as $field => $value) {
            if (preg_match('/[\r\n]/', $value) === 1) {
                throw EmailException::invalidEmail("Header injection detected in {$field}.");
            }
        }

        if (filter_var($this->to, FILTER_VALIDATE_EMAIL) === false) {
            throw EmailException::invalidEmail($this->to);
        }

        if (mb_strlen($this->subject) > self::MAX_SUBJECT_LENGTH) {
            throw EmailException::invalidEmail('Subject exceeds ' . self::MAX_SUBJECT_LENGTH . ' characters.');
        }
    }
}