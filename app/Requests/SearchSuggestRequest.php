<?php

declare(strict_types=1);

namespace BookSphere\App\Requests;

use BookSphere\App\Core\Validator;

/**
 * SearchSuggestRequest
 *
 * The INBOUND GATE of the Phase 11.4 suggestion endpoint
 * (GET /search/suggest) - the same Validator contract as the search
 * page's gate, tuned for a live type-ahead box:
 *
 *     - 'q' is REQUIRED: an empty prefix cannot produce a suggestion
 *       (unlike the search page, there is no "empty state" to render).
 *     - 'q' must be at least config search.suggestions.min_length
 *       characters (2 by default) - the client drops shorter terms
 *       too, so the server never runs four LIKE scans for one char.
 *     - 'q' is capped at the SAME budgets as the search page
 *       (search.query.max_length characters, search.query.max_words
 *       words) - one query budget for the whole module.
 *     - control characters are stripped before measuring, exactly
 *       like SearchQueryRequest, so a pasted blob of newlines cannot
 *       sneak through the caps.
 *
 * Like SearchQueryRequest, the controller answers 422 with the field
 * errors when this gate fails; the service never double-checks.
 */
final class SearchSuggestRequest
{
    private readonly array $input;

    private readonly Validator $validator;

    public function __construct(
        array $input,
        private readonly array $config,
    ) {
        $this->input = $input;

        $term = $this->term();

        $maxLength = max(1, (int) ($this->config['query']['max_length'] ?? 200));
        $maxWords  = max(1, (int) ($this->config['query']['max_words'] ?? 10));
        $minLength = max(1, (int) ($this->config['suggestions']['min_length'] ?? 2));

        $rules = (new Validator($input))
            ->max('q', $maxLength, 'Search term');

        if ($term === '' || mb_strlen($term) < $minLength) {
            $rules->error('q', "Type at least $minLength characters to see suggestions.");
        }

        if ($term !== '' && $this->wordCount($term) > $maxWords) {
            $rules->error('q', "The search term must not exceed $maxWords words.");
        }

        $this->validator = $rules;
    }

    /** Whether every rule passed. */
    public function valid(): bool
    {
        return $this->validator->passes();
    }

    /** The field -> message error map. */
    public function errors(): array
    {
        return $this->validator->errors();
    }

    /** The normalized term: trimmed, control characters stripped. */
    public function term(): string
    {
        $raw = (string) ($this->input['q'] ?? '');

        return preg_replace('/[\x00-\x1F\x7F]/u', '', trim($raw)) ?? '';
    }

    /** The raw request input (the controller echoes it back for the UI). */
    public function input(): array
    {
        return $this->input;
    }

    /**
     * Count the words of a term (whitespace-separated).
     */
    private function wordCount(string $term): int
    {
        $words = preg_split('/\s+/u', trim($term)) ?: [];

        return count(array_filter($words, fn (string $w): bool => $w !== ''));
    }
}
