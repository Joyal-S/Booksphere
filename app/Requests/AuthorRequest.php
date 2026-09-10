<?php

declare(strict_types=1);

namespace BookSphere\App\Requests;

use BookSphere\App\Core\Validator;

/**
 * AuthorRequest
 *
 * Form validation rules for author creation and updates in Admin.
 */
final class AuthorRequest
{
    /**
     * Validate submitted author data.
     *
     * @param array<string, mixed> $data
     */
    public static function validate(array $data): Validator
    {
        return (new Validator($data))
            ->required('name', 'author name')
            ->max('name', 255, 'author name')
            ->max('biography', 5000, 'biography');
    }

    /**
     * Whether the submitted form passes validation.
     *
     * @param array<string, mixed> $data
     */
    public static function passes(array $data): bool
    {
        return self::validate($data)->passes();
    }
}
