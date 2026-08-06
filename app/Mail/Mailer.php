<?php

declare(strict_types=1);

namespace BookSphere\App\Mail;

use BookSphere\App\Core\Logger;

/**
 * Mailer
 *
 * The single delivery door of the email pipeline (Phase 9.5): the
 * rest of the module (EmailNotificationService) sends through this
 * facade, never through a transport directly. It owns the two
 * cross-cutting rules:
 *
 *     - transport selection: "log" (default, writes to the email log
 *       file, no network) or "smtp" (the dependency-free SMTP client)
 *     - the never-throw contract: send() wraps EVERYTHING - the
 *       message construction, the transport call, even a fatal-style
 *       connection error - in a try/catch, logs the failure through
 *       the application Logger and returns false. A dead SMTP server,
 *       an invalid address, a timeout ... none of them can ever break
 *       the request that triggered the notification.
 *
 * Successes and failures are logged with the recipient, the subject
 * and the transport name; the raw details (SMTP replies) live in the
 * transport's lastError() and are recorded by the caller.
 */
final class Mailer
{
    private readonly EmailTransport $transport;
    private readonly Logger $logger;

    /**
     * @param array<string, mixed>|null $config The config('email') group
     *                                          (defaults to the loaded config)
     */
    public function __construct(?array $config = null, ?Logger $logger = null)
    {
        $config = $config ?? (array) config('email', []);

        $this->logger = $logger ?? new Logger(root_path('storage/logs/application.log'));

        $transport = (string) ($config['transport'] ?? 'log');

        $this->transport = $transport === 'smtp'
            ? new SmtpTransport((array) ($config['smtp'] ?? []), (array) ($config['from'] ?? []))
            : new LogEmailTransport((string) ($config['log_file'] ?? root_path('storage/logs/email.log')));
    }

    /**
     * Send a message through the configured transport. Returns true
     * when the transport accepted it, false on any failure - and it
     * NEVER throws, whatever happens underneath.
     */
    public function send(EmailMessage $message): bool
    {
        try {
            $sent = $this->transport->send($message);

            if ($sent) {
                $this->logger->info('email.sent', [
                    'transport' => $this->transportName(),
                    'to'        => $message->to(),
                    'subject'   => $message->subject(),
                ]);
            } else {
                $this->logger->error('email.send_failed', [
                    'transport' => $this->transportName(),
                    'to'        => $message->to(),
                    'subject'   => $message->subject(),
                    'error'     => $this->transport->lastError() ?? 'unknown transport error',
                ]);
            }

            return $sent;
        } catch (\Throwable $e) {
            // The last line of defence: even an unexpected exception
            // (a broken stream, a bad socket) is contained here.
            $this->logger->error('email.send_exception', [
                'transport' => $this->transportName(),
                'to'        => $message->to(),
                'subject'   => $message->subject(),
                'error'     => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * The transport's display name for logs ("smtp" | "log").
     */
    public function transportName(): string
    {
        return $this->transport instanceof SmtpTransport ? 'smtp' : 'log';
    }

    /**
     * The last delivery error reported by the transport (null when
     * the last send succeeded). Used by the caller to fill the
     * error column of the audit trail.
     */
    public function lastError(): ?string
    {
        return $this->transport->lastError();
    }
}