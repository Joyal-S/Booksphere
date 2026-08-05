<?php

declare(strict_types=1);

namespace BookSphere\App\Requests;

use BookSphere\App\Core\Validator;

/**
 * FollowRequest
 *
 * The FORM RULES of the follow / unfollow actions, described
 * declaratively and separated from the service - the same pattern as
 * StoreLibraryRequest and StoreReviewRequest.
 *
 * It answers one question:
 *
 *     "What makes a submitted follow action valid?"
 *
 * Rules:
 *
 *     author_id - required, a whole number between 1 and the id
 *                 ceiling (a route that always carries {id} passes
 *                 it anyway - the rule catches tampered or missing
 *                 ids before any row write)
 *
 * What is NOT here:
 *     - The "author exists" check - that is a service decision
 *       (FollowException::authorNotFound).
 *     - The duplicate check ("one follow per user per author") -
 *       that is a service + UNIQUE-index decision
 *       (FollowException::duplicateFollow).
 *     - The self-follow rule - a service decision
 *       (FollowException::cannotFollowSelf).
 *     - The user id - it comes from the SESSION (the controller
 *       passes it), never from the form.
 */
final class FollowRequest
{
    /** The id ceiling (generous - SQLite ids never reach this). */
    public const MAX_ID = 2147483647;

    /** The rules of the follow form (labels make the messages friendly). */
    public static function validate(array $data): Validator
    {
        return (new Validator($data))
            ->required('author_id', 'author')
            ->between('author_id', 1, self::MAX_ID, 'author');
    }

    /**
     * Whether the submitted follow action passes the field rules.
     */
    public static function passes(array $data): bool
    {
        return self::validate($data)->passes();
    }
}
