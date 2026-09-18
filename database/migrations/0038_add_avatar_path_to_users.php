<?php

declare(strict_types=1);

/**
 * Migration: add avatar_path column to users
 *
 * Purpose: Profile Image Upload Feature. Allows authenticated users
 * to store a custom profile picture. The column stores the relative
 * public path (e.g. '/uploads/profiles/user_1_abc123.webp') or NULL
 * when the user has no custom image and uses the initials fallback.
 *
 * Design notes:
 *     - Nullable: accounts start with NULL (initials circle fallback).
 *     - Only relative paths are stored, ensuring machine-agnostic portability.
 */

return [
    'up' => "ALTER TABLE users ADD COLUMN avatar_path TEXT NULL",
    'down' => "ALTER TABLE users DROP COLUMN avatar_path",
];
