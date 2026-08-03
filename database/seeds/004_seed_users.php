<?php

declare(strict_types=1);

/**
 * Seed: users
 *
 * Sample accounts for development and testing.
 *
 * Demo credentials (do NOT use in production):
 *     admin@booksphere.test / Admin@123   (administrator)
 *     riya@booksphere.test  / User@123    (regular user)
 *     arjun@booksphere.test / User@123    (regular user)
 *     meera@booksphere.test / User@123    (regular user)
 *
 * Security note: passwords are stored as HASHES using PHP's
 * password_hash() (bcrypt). The plain password is never stored,
 * and password_verify() will be used to check logins in the
 * authentication phase.
 */

return function (\BookSphere\App\Core\Database $database): void {
    $users = [
        ['Admin User', 'admin@booksphere.test', 'Admin@123', 'admin'],
        ['Riya Sharma', 'riya@booksphere.test', 'User@123', 'user'],
        ['Arjun Patel', 'arjun@booksphere.test', 'User@123', 'user'],
        ['Meera Nair', 'meera@booksphere.test', 'User@123', 'user'],
    ];

    foreach ($users as [$fullName, $email, $password, $role]) {
        $database->execute(
            'INSERT OR IGNORE INTO users (full_name, email, password, role) VALUES (?, ?, ?, ?)',
            [$fullName, $email, password_hash($password, PASSWORD_DEFAULT), $role],
        );
    }
};
