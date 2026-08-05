<?php

declare(strict_types=1);

namespace BookSphere\App\Requests;

use BookSphere\App\Core\Validator;

/**
 * UpdateLibraryRequest
 *
 * The FORM RULES of the "change my library entry" form.
 *
 * Every field is OPTIONAL here (an update may move the status, set
 * the progress, flip the favourite flag, or any combination of the
 * three) - but whatever IS submitted must pass the same rules as the
 * store form, so this class delegates the rule table to
 * StoreLibraryRequest - the single home of the library field rules.
 *
 * The behavioural difference between create and update lives in the
 * SERVICE (LibraryService::updateStatus / updateProgress /
 * toggleFavorite apply the lifecycle timestamps), not in the field
 * rules.
 */
final class UpdateLibraryRequest
{
    /**
     * The rules of the edit form - the store rules minus the
     * required book_id / status, plus the same bounds.
     */
    public static function validate(array $data): Validator
    {
        return (new Validator($data))
            ->in('status', StoreLibraryRequest::STATUSES, 'status')
            ->integer('progress', 'progress')
            ->between('progress', 0, 100, 'progress')
            ->in('favorite', StoreLibraryRequest::FAVORITE_VALUES, 'favorite flag');
    }

    /**
     * Whether the submitted library update passes the field rules.
     */
    public static function passes(array $data): bool
    {
        return self::validate($data)->passes();
    }
}