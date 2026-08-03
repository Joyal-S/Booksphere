<?php

declare(strict_types=1);

namespace BookSphere\App\Requests;

use BookSphere\App\Core\Validator;

/**
 * BookRequest
 *
 * Purpose:
 *     The FORM RULES of the book forms, described declaratively and
 *     separated from the service. It answers one question:
 *
 *         "What makes a submitted book form valid?"
 *
 *     - rules() declares each field, its constraints and its label.
 *     - validate() turns those declarations into a Validator and
 *       runs them, returning the field -> messages error map.
 *
 * Why it exists:
 *     - Rules live next to the book domain (their natural home)
 *       instead of buried inside the service.
 *     - The BookService orchestrates the whole workflow and calls
 *       the coverage-specific checks (ISBN uniqueness, cover file)
 *       itself, because those need the database and the request
 *       file. Field validation is delegated here.
 *
 * What is NOT here:
 *     - ISBN uniqueness (needs the repository).
 *     - Cover upload validation (needs the uploaded file).
 *       BookService composes those AFTER these rules pass.
 *
 * How it fits inside MVC:
 *     Controller -> Service (orchestration + DB checks)
 *                       -> BookRequest (pure form rules)
 *                       -> Validator -> errors map -> view
 */
final class BookRequest
{
    /**
     * The declarative rule table. Every entry is
     *
     *     field => [validator->method, method args..., error label]
     *
     * The rules array describes the shape of a valid book form and
     * the single source of truth for its field constraints.
     *
     * @param array<string, mixed> $statuses   The allowed status keys
     *                                         (flip to values for the form)
     * @param array<string, mixed> $languages  The allowed language keys
     */
    public static function validate(
        array $data,
        array $statuses,
        array $languages,
    ): Validator {
        $year = (int) date('Y');

        return (new Validator($data))
            ->required('title', 'title')
            ->max('title', 255, 'title')
            ->max('subtitle', 255, 'subtitle')
            ->max('isbn', 20, 'ISBN')
            ->max('publisher', 255, 'publisher')
            ->max('description', 5000, 'description')
            ->integer('published_year', 'publication year')
            ->between('published_year', 1000, $year, 'publication year')
            ->integer('page_count', 'page count')
            ->between('page_count', 1, 100000, 'page count')
            ->in('status', array_keys($statuses), 'status')
            ->in('language', array_keys($languages), 'language');
    }

    /**
     * Whether the submitted form passes the pure field rules.
     *
     * Convenience used by the service before it runs the database
     * dependent checks.
     */
    public static function passes(array $data, array $statuses, array $languages): bool
    {
        return self::validate($data, $statuses, $languages)->passes();
    }
}