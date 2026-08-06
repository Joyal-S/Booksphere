<?php

declare(strict_types=1);

namespace BookSphere\App\Mail;

/**
 * LogEmailTransport
 *
 * The development / default transport of the mail pipeline (Phase
 * 9.5): instead of touching the network it appends the full message
 * to a plain-text log file (storage/logs/email.log by default). A
 * message written here counts as SENT - the rest of the pipeline
 * (preferences, dedupe, templates, logging) runs exactly as with a
 * real server, which is what makes the whole module testable without
 * any SMTP setup.
 *
 * The entry is self-contained and greppable:
 *
 *     [2026-08-06T12:00:00+00:00] TO: Riya Sharma <riya@...>
 *     SUBJECT: Following Brandon Sanderson
 *     ---- HTML ----
 *     <!DOCTYPE html>...
 *     ---- TEXT ----
 *     ...
 *
 * Never throws: a write failure (unwritable directory) degrades to a
 * false + lastError, exactly like a refused SMTP connection.
 */
final class LogEmailTransport implements EmailTransport
{
    private ?string $lastError = null;

    public function __construct(private readonly string $file) {}

    public function send(EmailMessage $message): bool
    {
        $directory = dirname($this->file);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            $this->lastError = 'Unable to create the email log directory.';

            return false;
        }

        $block = '[' . gmdate('c') . '] TO: ' . $message->toLine() . PHP_EOL
            . 'SUBJECT: ' . $message->subject() . PHP_EOL
            . '---- HTML ----' . PHP_EOL
            . $message->html() . PHP_EOL
            . ($message->text() !== null
                ? '---- TEXT ----' . PHP_EOL . $message->text() . PHP_EOL
                : '')
            . str_repeat('-', 72) . PHP_EOL;

        $written = file_put_contents($this->file, $block, FILE_APPEND | LOCK_EX);

        if ($written === false) {
            $this->lastError = 'Unable to write the email log file.';

            return false;
        }

        $this->lastError = null;

        return true;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }
}