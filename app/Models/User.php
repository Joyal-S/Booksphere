<?php

declare(strict_types=1);

namespace BookSphere\App\Models;

/**
 * User
 *
 * Data access for the users table: finding, creating and updating
 * accounts. It returns plain associative arrays (never objects) and
 * uses prepared statements through the shared Database connection,
 * so no SQL value ever reaches the database unsanitized.
 *
 * The password column stores a bcrypt hash (password_hash()), never
 * plain text. Email addresses are normalized to lowercase before
 * they are stored, because SQLite's UNIQUE constraint is
 * case-sensitive.
 */
final class User
{
    /**
     * Find a user by primary key.
     *
     * @return array<string, mixed>|null The user row, or null
     */
    public function findById(int $id): ?array
    {
        $rows = db()->query(
            'SELECT id, full_name, email, role, created_at, updated_at
             FROM users
             WHERE id = ?',
            [$id],
        );

        return $rows[0] ?? null;
    }

    /**
     * Find a user by (normalized) email address.
     *
     * Includes the password hash, which is needed by login to
     * verify the submitted password against it.
     *
     * @return array<string, mixed>|null The user row, or null
     */
    public function findByEmail(string $email): ?array
    {
        $rows = db()->query(
            'SELECT *
             FROM users
             WHERE email = ?',
            [strtolower(trim($email))],
        );

        return $rows[0] ?? null;
    }

    /**
     * Create a new user account.
     *
     * @param string $passwordHash The password_hash() result
     */
    public function create(string $fullName, string $email, string $passwordHash): int
    {
        db()->execute(
            'INSERT INTO users (full_name, email, password)
             VALUES (?, ?, ?)',
            [$fullName, strtolower(trim($email)), $passwordHash],
        );

        return (int) db()->lastInsertId();
    }

    /**
     * Update the profile fields of a user (name and email).
     */
    public function updateProfile(int $id, string $fullName, string $email): bool
    {
        return db()->execute(
            'UPDATE users
             SET full_name = ?, email = ?, updated_at = ?
             WHERE id = ?',
            [$fullName, strtolower(trim($email)), $this->now(), $id],
        ) > 0;
    }

    /**
     * Replace the password hash of a user.
     *
     * @param string $passwordHash The password_hash() result
     */
    public function updatePassword(int $id, string $passwordHash): bool
    {
        return db()->execute(
            'UPDATE users
             SET password = ?, updated_at = ?
             WHERE id = ?',
            [$passwordHash, $this->now(), $id],
        ) > 0;
    }

    /**
     * Return the stored password hash of a user.
     *
     * Exists so password changes can verify the current password
     * without exposing the hash through findById().
     *
     * @return string|null The bcrypt hash, or null when not found
     */
    public function findPasswordHash(int $id): ?string
    {
        $rows = db()->query(
            'SELECT password
             FROM users
             WHERE id = ?',
            [$id],
        );

        return $rows[0]['password'] ?? null;
    }

    /**
     * Whether an email address is already taken.
     *
     * @param int|null $exceptId When editing a profile, the user's
     *                           own id is excluded so their own
     *                           (unchanged) address does not count
     *                           as taken.
     */
    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        $rows = db()->query(
            'SELECT id
             FROM users
             WHERE email = ?
               AND id != COALESCE(?, -1)',
            [strtolower(trim($email)), $exceptId],
        );

        return $rows !== [];
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
