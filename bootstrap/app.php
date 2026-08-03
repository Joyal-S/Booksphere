<?php

declare(strict_types=1);

/**
 * bootstrap/app.php
 *
 * The bootstrap process: runs once at the very start of every
 * request and prepares everything the application needs.
 *
 * Steps:
 *     1. Define the application constants (bootstrap/constants.php)
 *     2. Load Composer's autoloader (this also loads the helper
 *        functions from app/Helpers/helpers.php)
 *     3. Load the .env file into environment variables
 *     4. Load every file in config/ into one Config object
 *     5. Create and return the configured Application instance
 */

use BookSphere\App\Core\Application;
use BookSphere\App\Core\Config;
use BookSphere\App\Core\Environment;

// 1. Constants like BOOKSPHERE_ROOT (needed by the helpers and config).
require __DIR__ . '/constants.php';

// 2. Composer autoloader: makes every class and the helper
//    functions (root_path, env, e, asset) available on demand.
require __DIR__ . '/../vendor/autoload.php';

// 3. Load environment variables from ".env" (if it exists).
(new Environment(root_path('.env')))->load();

// 4. Load config/app.php and config/database.php into one object.
$config = Config::loadFromDirectory(root_path('config'));

// 5. Hand the configuration to the Application and return it.
return new Application($config);
