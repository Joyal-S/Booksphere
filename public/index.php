<?php

declare(strict_types=1);

/**
 * public/index.php
 *
 * The FRONT CONTROLLER: the only PHP file the web server directly
 * executes. Every request for the application arrives here, whether
 * the URL is "/", "/books" or "/admin".
 *
 * It stays intentionally tiny - it only:
 *     1. loads the bootstrap, which wires up the whole application
 *     2. runs it, which handles the request and sends the response
 *
 * All the interesting work happens in bootstrap/app.php and the
 * Core classes.
 *
 * Clean URLs: the web server is configured (public/.htaccess or
 * "php -S ... -t public") so that every request that is not a real
 * file is rewritten to this file. That is why routes like "/books"
 * work without ".php" or "index.php" in the URL.
 */

// Bootstrap returns the fully configured Application instance.
$application = require __DIR__ . '/../bootstrap/app.php';

// Run the request lifecycle (session, error handling, routing).
$application->run();
