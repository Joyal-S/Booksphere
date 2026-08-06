<?php

declare(strict_types=1);

/**
 * config/email.php
 *
 * Email notification configuration (Phase 9.5). Every setting can be
 * overridden through environment variables in the ".env" file, so the
 * same code runs on any machine without edits.
 *
 * The module is OPTIONAL by design: with EMAIL_ENABLED=false (the
 * default) the notification system and the whole application work
 * exactly as before - no email is attempted, no queue row is written,
 * and a missing SMTP server can never break a request. Enabling email
 * with the "log" transport (EMAIL_TRANSPORT=log, the default) writes
 * every message to storage/logs/emails.log instead of the network, so
 * the full pipeline can be exercised without an SMTP server at all.
 *
 * Note on credentials: the SMTP username / password come ONLY from
 * environment variables (never hardcoded here) - the ".env" file is
 * gitignored, so credentials stay out of the repository.
 *
 * Values are read with dot notation, e.g. config('email.from.address').
 */

return [
    // Master switch: false (default) = the module is a no-op.
    'enabled' => (bool) env('EMAIL_ENABLED', false),

    // Sender identity shown on every message.
    'from' => [
        'address' => (string) env('EMAIL_FROM_ADDRESS', 'no-reply@booksphere.test'),
        'name'    => (string) env('EMAIL_FROM_NAME', 'BookSphere'),
    ],

    // Transport: "log" (write to the email log file, no network) or
    // "smtp" (deliver through the SMTP server below).
    'transport' => (string) env('EMAIL_TRANSPORT', 'log'),

    // SMTP delivery settings (only used when transport = "smtp").
    'smtp' => [
        'host'       => (string) env('SMTP_HOST', 'localhost'),
        'port'       => (int) env('SMTP_PORT', 587),
        // "none" | "tls" (implicit TLS on connect) | "starttls" (upgrade after EHLO)
        'encryption' => (string) env('SMTP_ENCRYPTION', 'starttls'),
        // Whether to AUTH LOGIN with the credentials below.
        'auth'       => (bool) env('SMTP_AUTH', false),
        'username'   => (string) env('SMTP_USERNAME', ''),
        'password'   => (string) env('SMTP_PASSWORD', ''),
        // Whether the TLS handshake verifies the server certificate
        // (host name + chain). ON by default (Phase 9.6 hardening);
        // disable with SMTP_VERIFY_PEER=false only on test servers
        // without a valid certificate.
        'verify_peer'  => (bool) env('SMTP_VERIFY_PEER', true),
        // Per-socket timeout in seconds (connection + reads).
        'timeout'      => (int) env('SMTP_TIMEOUT', 10),
    ],

    // The queue: when enabled, emails are written to the email_queue
    // table first (pending) and a worker / console (EmailNotification
    // Service::processQueue) delivers them later - the generation and
    // delivery are fully separated, so a slow SMTP server never holds
    // up the request that triggered the notification.
    'queue' => [
        'enabled' => (bool) env('EMAIL_QUEUE_ENABLED', false),
        // Rows processed per processQueue() run.
        'batch'   => (int) env('EMAIL_QUEUE_BATCH', 25),
    ],

    // Delivery log (successes, failures, skips). This lives on disk
    // and is never exposed to end-users.
    'log_file' => root_path('storage/logs/email.log'),

    // Absolute app URL used for links in emails (matches app.url).
    'app_url' => rtrim((string) env('APP_URL', ''), '/'),
];