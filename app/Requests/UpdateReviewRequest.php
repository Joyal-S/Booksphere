<?php

declare(strict_types=1);

namespace BookSphere\App\Requests;

/**
 * UpdateReviewRequest
 *
 * The FORM RULES of the "edit your review" form.
 *
 * Editing a review can change every field, so the rules are the
 * SAME as the store form (rating 1-5, title <= 120, review
 * 20-2000). Instead of duplicating the rule table, this class
 * delegates to StoreReviewRequest - the single home of the review
 * field rules.
 *
 * The behavioural difference between create and update lives in
 * the SERVICE (ReviewService::update() stamps is_edited = 1 when
 * the content actually changed), not in the field rules.
 */
final class UpdateReviewRequest
{
    /**
     * The rules of the edit form - identical to the store form.
     */
    public static function validate(array $data): Validator
    {
        return StoreReviewRequest::validate($data);
    }

    /**
     * Whether the submitted review passes the field rules.
     */
    public static function passes(array $data): bool
    {
        return StoreReviewRequest::passes($data);
    }
}
