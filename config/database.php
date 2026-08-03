<?php

declare(strict_types=1);

/**
 * config/database.php
 *
 * Database configuration, prepared for the SQLite phase.
 *
 * The Database class (app/Core/Database.php) is already ready to
 * use this path, but no code reads this configuration yet. This
 * file exists now so the phase that introduces the database only
 * has to wire things up - nothing else has to change.
 */

return [
    // Which database engine the application uses.
    'driver' => 'sqlite',

    // Absolute path of the SQLite database file.
    // The file is created automatically when the database is
    // first used (the parent directory must be writable).
    'path' => root_path(env('DB_PATH', 'database/booksphere.db')),
];
