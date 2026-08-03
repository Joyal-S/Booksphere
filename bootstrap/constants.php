<?php

declare(strict_types=1);

/**
 * bootstrap/constants.php
 *
 * Application constants: values that never change while the
 * application runs. Constants are defined here, in one dedicated
 * file, so the rest of the code can rely on them without asking
 * where they come from.
 *
 * This file is required by bootstrap/app.php BEFORE the Composer
 * autoloader, because the helper functions and the configuration
 * already need these values on the first request.
 */

// Absolute path of the project root (the folder that contains
// public/). dirname(__DIR__) climbs from "bootstrap/" up to the
// project root, so this works no matter where the code is hosted.
define('BOOKSPHERE_ROOT', dirname(__DIR__));
