<?php

declare(strict_types=1);

namespace BookSphere\App\Mail;

/**
 * SmtpTransport
 *
 * A minimal, dependency-free SMTP client (Phase 9.5) built on PHP
 * streams only - no composer packages. It speaks the small subset of
 * SMTP every real server supports:
 *
 *     220 greeting -> EHLO -> [STARTTLS] -> [AUTH LOGIN]
 *     -> MAIL FROM -> RCPT TO -> DATA -> QUIT
 *
 * Supported security modes (config('email.smtp.encryption')):
 *     "none"     - plain text connection
 *     "tls"      - implicit TLS (connect directly with encryption)
 *     "starttls" - upgrade the plain connection with STARTTLS (the
 *                  standard for port 587)
 *
 * Failure behavior (the transport contract): EVERY delivery problem -
 * connection refused, timeout, a rejected command, bad credentials -
 * degrades to a false return with a human-readable lastError(). It
 * never throws, so a dead SMTP server can never break the request
 * that triggered the notification.
 *
 * Note: the SMTP envelope and the DATA headers carry only values that
 * EmailMessage already validated (addresses filter-validated, no CR/LF
 * anywhere), so no header can smuggle itself into the message.
 */
final class SmtpTransport implements EmailTransport
{
    /** @var resource|null */
    private $socket = null;

    private ?string $lastError = null;

    /**
     * @param array<string, mixed> $config The config('email.smtp') group
     * @param array<string, string> $from   The config('email.from') group
     */
    public function __construct(
        private readonly array $config,
        private readonly array $from,
    ) {}

    public function send(EmailMessage $message): bool
    {
        if (!$this->connect()) {
            return false;
        }

        if (!$this->expect(220, 'server greeting')) {
            return false;
        }

        if (!$this->command('EHLO booksphere.local', 250, 'EHLO')) {
            return false;
        }

        $encryption = strtolower((string) ($this->config['encryption'] ?? 'starttls'));

        if ($encryption === 'starttls') {
            if (!$this->command('STARTTLS', 220, 'STARTTLS')) {
                return false;
            }

            if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                return $this->fail('STARTTLS handshake failed.');
            }
        }

        if ((bool) ($this->config['auth'] ?? false)) {
            if (!$this->authenticate()) {
                return false;
            }
        }

        $fromAddress = (string) ($this->from['address'] ?? '');
        $fromName    = (string) ($this->from['name'] ?? '');

        if (!$this->command('MAIL FROM:<' . $fromAddress . '>', 250, 'MAIL FROM')) {
            return false;
        }

        if (!$this->command('RCPT TO:<' . $message->to() . '>', [250, 251], 'RCPT TO')) {
            return false;
        }

        if (!$this->command('DATA', 354, 'DATA')) {
            return false;
        }

        $this->write($this->buildData($message, $fromAddress, $fromName));

        if (!$this->expect(250, 'DATA completion')) {
            return false;
        }

        $this->command('QUIT', 221, 'QUIT');

        $this->close();

        return true;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    // --- Internals -------------------------------------------------------

    /**
     * Open the socket (with implicit TLS when configured) and record
     * the failure on any error - never throws.
     */
    private function connect(): bool
    {
        $host = (string) ($this->config['host'] ?? 'localhost');
        $port = (int) ($this->config['port'] ?? 587);
        $timeout = (int) ($this->config['timeout'] ?? 10);
        $encryption = strtolower((string) ($this->config['encryption'] ?? 'starttls'));
        $verify = (bool) ($this->config['verify_peer'] ?? true);

        // Certificate verification on by default (Phase 9.6): a
        // network MitM must not be able to sniff the SMTP auth
        // handshake or the message bodies. The peer name is set so
        // the chain verifies against the configured host (and SNI is
        // sent), and operators can turn it off only through config
        // for self-signed test servers.
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'      => $verify,
                'verify_peer_name' => $verify,
                'peer_name'        => $host,
            ],
        ]);

        $socket = @stream_socket_client(
            'tcp://' . $host . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($socket === false) {
            $this->fail('Unable to connect to ' . $host . ':' . $port . ' (' . ($errstr ?: 'unknown error') . ').');

            return false;
        }

        stream_set_timeout($socket, $timeout);

        if ($encryption === 'tls' && !stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            $this->fail('Implicit TLS handshake failed.');
            fclose($socket);

            return false;
        }

        $this->socket = $socket;

        return true;
    }

    /**
     * AUTH LOGIN: the server challenges with 334 twice (username,
     * then password) and answers 235 on success.
     */
    private function authenticate(): bool
    {
        $username = (string) ($this->config['username'] ?? '');
        $password = (string) ($this->config['password'] ?? '');

        if (!$this->command('AUTH LOGIN', 334, 'AUTH LOGIN')) {
            return false;
        }

        $this->write(base64_encode($username) . "\r\n");

        if (!$this->expect(334, 'username challenge')) {
            return false;
        }

        $this->write(base64_encode($password) . "\r\n");

        return $this->expect(235, 'authentication');
    }

    /**
     * Send one command line and check the reply code(s).
     *
     * @param array<int>|int $expected
     */
    private function command(string $command, array|int $expected, string $label): bool
    {
        $this->write($command . "\r\n");

        return $this->expect($expected, $label);
    }

    /**
     * Read one reply (multi-line replies are folded) and compare its
     * code with the expected one(s).
     *
     * @param array<int>|int $expected
     */
    private function expect(array|int $expected, string $label): bool
    {
        $expected = (array) $expected;

        $line = $this->readLine();

        if ($line === null) {
            return false;
        }

        $code = (int) substr($line, 0, 3);
        $text = trim(substr($line, 3));

        // Multi-line replies: "250-first line" followed by "250 last".
        while (strlen($line) > 3 && $line[3] === '-') {
            $next = $this->readLine();

            if ($next === null) {
                return false;
            }

            $line = $next;
        }

        if (!in_array($code, $expected, true)) {
            $this->fail("SMTP {$label} rejected: {$code} {$text}.");

            return false;
        }

        return true;
    }

    /**
     * Read one CRLF-terminated line, honoring the timeout. Returns
     * null (and records the failure) when the server never answers.
     */
    private function readLine(): ?string
    {
        $line = fgets($this->socket, 1024);

        if ($line === false) {
            $this->fail('SMTP connection closed or timed out while waiting for a reply.');

            return null;
        }

        return $line;
    }

    private function write(string $data): void
    {
        fwrite($this->socket, $data);
    }

    /**
     * Build the raw DATA payload: the message headers (RFC 5322 line
     * endings) plus the HTML body, dot-stuffed so no body line can
     * terminate the DATA section early.
     */
    private function buildData(EmailMessage $message, string $fromAddress, string $fromName): string
    {
        $headers = [
            'From: ' . ($fromName !== '' ? '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromAddress . '>' : '<' . $fromAddress . '>'),
            'To: ' . $message->toLine(),
            'Subject: =?UTF-8?B?' . base64_encode($message->subject()) . '?=',
            'Date: ' . gmdate('r'),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@booksphere>',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=utf-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        $body = str_replace(["\r\n", "\r", "\n"], "\n", $message->html());
        $body = str_replace("\n", "\r\n", $body);
        $body = preg_replace('/^\./m', '..', $body) ?? $body;

        return implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n";
    }

    /**
     * Record the failure, close the socket (when open) and return
     * false - the single exit point of every error path.
     */
    private function fail(string $error): false
    {
        $this->lastError = $error;
        $this->close();

        return false;
    }

    private function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->socket = null;
    }
}