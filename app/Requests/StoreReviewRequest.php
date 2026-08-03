<?php

declare(strict_types=1);

namespace BookSphere\App\Requests;

use BookSphere\App\Core\Validator;

/**
 * StoreReviewRequest
 *
 * The FORM RULES of the "write a review" form, described
 * declaratively and separated from the service - the same pattern
 * as BookRequest in the Book module.
 *
 * It answers one question:
 *
 *     "What makes a submitted review valid?"
 *
 * Rules (mirroring the brief):
 *
 *     rating  - required, a whole number between 1 and 5
 *     title   - required, max 120 characters
 *     review  - required, between 20 and 2000 characters
 *
 * Every rule carries a friendly label so the Validator produces
 * human-readable messages like "The review must be at least 20
 * characters." instead of raw error codes.
 *
 * What is NOT here:
 *     - The duplicate check ("one review per user per book") -
 *       that is a database-level rule enforced by the service via
 *       the repository (and the UNIQUE index as the last line of
 *       defence).
 *     - The "book exists" check - that is a service decision
 *       (ReviewException::bookNotFound).
 */
final class StoreReviewRequest
{
    /** The rules of the review form (labels make the messages friendly). */
    public static function validate(array $data): Validator
    {
        return (new Validator($data))
            ->required('rating', 'rating')
            ->integer('rating', 'rating')
            ->between('rating', 1, 5, 'rating')
            ->required('title', 'title')
            ->max('title', 120, 'title')
            ->required('review', 'review')
            ->min('review', 20, 'review')
            ->max('review', 2000, 'review');
    }

    /**
     * Whether the submitted review passes the field rules.
     */
    public static function passes(array $data): bool
    {
        return self::validate($data)->passes();
    }
}
