<?php

declare(strict_types=1);

/**
 * Migration: remember token column on users
 *
 * Purpose: the backend of the "Remember me" checkbox. When a user
 * signs in with the checkbox ticked, the login issues a random
 * token, stores its SHA-256 hash in this column and puts the raw
 * token in a 30-day HttpOnly cookie. Every request that arrives
 * without a session user but with that cookie is silently
 * re-authenticated, and the token is ROTATED on every use (a
 * replayed cookie stops working after the first restore).
 *
 * Design notes:
 *     - Only the hash is stored: a leaked database cannot be turned
 *       back into working cookies.
 *     - Logout clears the column, which revokes every device that
 *       holds a cookie for this account.
 *     - The column is nullable: accounts that never ticked the
 *       checkbox carry NULL.
 */

return [
    'up' => "ALTER TABLE users ADD COLUMN remember_token TEXT NULL",
    'down' => "ALTER TABLE users DROP COLUMN remember_token",
];
