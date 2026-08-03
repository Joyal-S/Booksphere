<?php

declare(strict_types=1);

/**
 * Migration: users
 *
 * Purpose: user accounts.
 * Stores the identity used for sign-in: display name, email,
 * password hash and the role that controls access in later phases.
 *
 * Relationships (defined in the other tables' migrations):
 *     users 1---n reviews        (one user can write many reviews)
 *     users 1---n wishlist       (one user can save many books)
 *     users 1---n recommendations (one user gets many suggestions)
 *
 * Why this table exists:
 *     - Every person who signs in needs an account.
 *     - The role column ('admin' | 'user') drives access control.
 *
 * Design notes:
 *     - email is UNIQUE: two accounts can never share an address.
 *     - password stores the HASH (password_hash()), never plain text.
 *     - created_at / updated_at are UTC ISO-8601 timestamps that
 *       SQLite generates automatically on insert.
 */

return [
    'up' => "
        CREATE TABLE users (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name  TEXT    NOT NULL,
            email      TEXT    NOT NULL UNIQUE,
            password   TEXT    NOT NULL,
            role       TEXT    NOT NULL DEFAULT 'user'
                           CHECK (role IN ('admin', 'user')),
            created_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            updated_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
        )
    ",
    'down' => 'DROP TABLE users',
];
