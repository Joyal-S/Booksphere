<?php

declare(strict_types=1);

namespace BookSphere\App\Requests;

use BookSphere\App\Core\Validator;

/**
 * StoreLibraryRequest
 *
 * The FORM RULES of the "add a book to my library" form, described
 * declaratively and separated from the service - the same pattern as
 * StoreReviewRequest and BookRequest.
 *
 * It answers one question:
 *
 *     "What makes a submitted library entry valid?"
 *
 * Rules (mirroring the brief):
 *
 *     book_id  - required, a whole number
 *     status   - required, one of the five shelves:
 *                want_to_read | currently_reading | finished |
 *                on_hold | dropped
 *     progress - optional, a whole number between 0 and 100
 *     favorite - optional, a boolean ("0" / "1", "false" / "true")
 *
 * What is NOT here:
 *     - The duplicate check ("one record per user per book") - that
 *       is a database-level rule enforced by the service via the
 *       repository (and the UNIQUE index as the last line of
 *       defence).
 *     - The "book exists" check - that is a service decision
 *       (LibraryException::bookNotFound).
 *     - The lifecycle timestamps - those are service decisions
 *       (status -> started_reading_at / finished_reading_at).
 */
final class StoreLibraryRequest
{
    /** The five allowed library statuses (single home of the list). */
    public const STATUSES = [
        'want_to_read',
        'currently_reading',
        'finished',
        'on_hold',
        'dropped',
    ];

    /** The accepted boolean spellings of the favourite flag. */
    public const FAVORITE_VALUES = ['0', '1', 'false', 'true'];

    /** The rules of the library form (labels make the messages friendly). */
    public static function validate(array $data): Validator
    {
        return (new Validator($data))
            ->required('book_id', 'book')
            ->integer('book_id', 'book')
            ->required('status', 'status')
            ->in('status', self::STATUSES, 'status')
            ->integer('progress', 'progress')
            ->between('progress', 0, 100, 'progress')
            ->in('favorite', self::FAVORITE_VALUES, 'favorite flag');
    }

    /**
     * Whether the submitted library entry passes the field rules.
     */
    public static function passes(array $data): bool
    {
        return self::validate($data)->passes();
    }
}