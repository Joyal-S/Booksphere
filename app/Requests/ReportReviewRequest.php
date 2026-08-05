<?php

declare(strict_types=1);

namespace BookSphere\App\Requests;

use BookSphere\App\Core\Validator;

/**
 * ReportReviewRequest
 *
 * The FORM RULES of the "report a review" modal, described
 * declaratively and separated from the service - the same pattern
 * as StoreReviewRequest in the same module.
 *
 * Rules:
 *
 *     reason      - required, one of the six fixed values (Spam,
 *                   Harassment, Offensive Content, False
 *                   Information, Duplicate, Other). The database
 *                   CHECK constraint is the last line of defence.
 *     description - optional, max 1000 characters, min 10 when
 *                   provided (an empty box is fine; a one-word
 *                   box is not worth a moderator's time).
 *
 * What is NOT here:
 *     - The "you cannot report your own review" rule - a policy
 *       decision (ReviewPolicy::canReport).
 *     - The duplicate-report rule ("one report per user per
 *       review") - a service decision
 *       (ReviewException::alreadyReported).
 *     - The "review exists" check - a service decision
 *       (ReviewException::reviewNotFound).
 */
final class ReportReviewRequest
{
    /** The six accepted report reasons (array values are the stored values). */
    public const REASONS = [
        'Spam'              => 'Spam',
        'Harassment'        => 'Harassment',
        'Offensive Content' => 'Offensive Content',
        'False Information' => 'False Information',
        'Duplicate'         => 'Duplicate',
        'Other'             => 'Other',
    ];

    /** The maximum report description length. */
    public const MAX_DESCRIPTION_LENGTH = 1000;

    /** The rules of the report modal. */
    public static function validate(array $data): Validator
    {
        $validator = (new Validator($data))
            ->required('reason', 'reason')
            ->in('reason', array_keys(self::REASONS), 'reason')
            ->max('description', self::MAX_DESCRIPTION_LENGTH, 'description');

        // The description is OPTIONAL: an empty box is a valid
        // report (the reason alone tells the moderators enough).
        // The 10-character minimum applies only when a description
        // is actually provided - the Validator's min rule fires on
        // every string, so the condition is decided here.
        if (trim((string) ($data['description'] ?? '')) !== '') {
            $validator->min('description', 10, 'description');
        }

        return $validator;
    }

    /**
     * Whether the submitted report passes the field rules.
     */
    public static function passes(array $data): bool
    {
        return self::validate($data)->passes();
    }
}
