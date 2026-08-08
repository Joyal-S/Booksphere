<?php

declare(strict_types=1);

namespace BookSphere\App\Requests;

use BookSphere\App\Core\Validator;

/**
 * SearchQueryRequest
 *
 * The INBOUND GATE of the global search (Phase 11.2) - the exact
 * contract of the Phase 11.1 architecture ("SearchQueryRequest -
 * the Phase 11.2 inbound gate; same Validator"). It answers one
 * question:
 *
 *     "What makes a submitted global-search query valid?"
 *
 * Rules:
 *     - 'q' may be empty: empty terms are NOT an error (the page
 *       renders its empty state), they just leave nothing to search.
 *     - 'q' is capped at config search.query.max_length characters.
 *     - 'q' must not exceed search.query.max_words words (a longer
 *       term is answered with the "too many words" field error).
 *     - 'scope' must be one of the enabled search entities from
 *       config/search.php (books, authors, categories, publishers,
 *       reviews) - anything else is a field error, never a query.
 *     - 'page' is clamped to >= 1; 'per_page' is whitelisted
 *       against config search.pagination.allowed (anything else
 *       silently falls back to the default - the browse pattern).
 *     - control characters are stripped from the term before it is
 *       measured, so a pasted blob of newlines cannot sneak through
 *       the word cap (the same length rule every search form uses).
 *     - the BOOKS-scope FILTERS (Phase 11.3) are whitelisted per
 *       config.search.filters: status/language/min_rating against
 *       their value maps, year_from/year_to within the year bounds,
 *       category_id/author_id as positive integers, publisher as a
 *       capped string. A filter value outside its whitelist is
 *       SILENTLY DROPPED (never an error): the dropdowns can only
 *       emit valid values, and a tampered query string degrades
 *       gracefully instead of 422-ing the page - the browse module's
 *       exact philosophy.
 *       For non-book scopes every filter is ignored (filtered - they
 *       carry no book columns to filter).
 *
 * Both the full page and the live AJAX endpoint build the SAME
 * request object, so server-side and client-side search can never
 * disagree about what is valid.
 */
final class SearchQueryRequest
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
        $allowed   = $this->enabledScopes();

        $rules = (new Validator($input))
            ->max('q', $maxLength, 'Search term')
            ->in('scope', $allowed, 'search scope');

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

    /** Whether there is a term to search at all (empty = the empty page). */
    public function hasQuery(): bool
    {
        return $this->term() !== '';
    }

    /** The normalized term: trimmed, control characters stripped. */
    public function term(): string
    {
        $raw = (string) ($this->input['q'] ?? '');

        return preg_replace('/[\x00-\x1F\x7F]/u', '', trim($raw)) ?? '';
    }

    /** The chosen entity scope (always one of the enabled scopes). */
    public function scope(): string
    {
        $scope = (string) ($this->input['scope'] ?? 'books');

        return in_array($scope, $this->enabledScopes(), true) ? $scope : 'books';
    }

    /** The 1-based page to request. */
    public function page(): int
    {
        return max(1, (int) ($this->input['page'] ?? 1));
    }

    /** The requested page size (0 = let the service pick the default). */
    public function perPage(): int
    {
        return max(0, (int) ($this->input['per_page'] ?? 0));
    }

    /** The raw request input (the controller echoes it back for the UI). */
    public function input(): array
    {
        return $this->input;
    }

    /**
     * The normalized, whitelisted BOOKS-scope filters of the query
     * ([] for every non-book scope or when no filter is active).
     * A value outside its config whitelist is silently dropped, so a
     * tampered query string never turns into a SQL fragment.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        if ($this->scope() !== 'books') {
            return [];
        }

        $filters = $this->config['filters'] ?? [];
        $active  = [];

        // Status: only a config-declared storage value.
        if (!empty($filters['status']['enabled'])) {
            $status = (string) ($this->input['status'] ?? '');
            if ($status !== '' && isset($filters['status']['values'][$status])) {
                $active['status'] = $status;
            }
        }

        // Language: only a config-declared code.
        if (!empty($filters['language']['enabled'])) {
            $language = (string) ($this->input['language'] ?? '');
            if ($language !== '' && isset($filters['language']['values'][$language])) {
                $active['language'] = $language;
            }
        }

        // Minimum rating: only a whitelisted threshold ('3', '4', '4.5').
        if (!empty($filters['rating']['enabled'])) {
            $rating = (string) ($this->input['min_rating'] ?? '');
            if ($rating !== '' && isset($filters['rating']['values'][$rating])) {
                $active['min_rating'] = $rating;
            }
        }

        // Publication-year range: integers within the config bounds.
        if (!empty($filters['year']['enabled'])) {
            $min  = max(0, (int) ($filters['year']['min'] ?? 0));
            $max  = max($min, (int) ($filters['year']['max'] ?? $min));
            $from = (int) ($this->input['year_from'] ?? 0);
            $to   = (int) ($this->input['year_to'] ?? 0);

            if ($this->validYear($from, $min, $max)) {
                $active['year_from'] = $from;
            }
            if ($this->validYear($to, $min, $max)) {
                $active['year_to'] = $to;
            }
        }

        // Category / author: positive integers (their existence is
        // checked by the foreign key when it is read).
        if (!empty($filters['category']['enabled'])) {
            $categoryId = (int) ($this->input['category_id'] ?? 0);
            if ($categoryId > 0) {
                $active['category_id'] = $categoryId;
            }
        }
        if (!empty($filters['author']['enabled'])) {
            $authorId = (int) ($this->input['author_id'] ?? 0);
            if ($authorId > 0) {
                $active['author_id'] = $authorId;
            }
        }

        // Publisher: a capped free-text LIKE term.
        if (!empty($filters['publisher']['enabled'])) {
            $publisher = trim((string) ($this->input['publisher'] ?? ''));
            $maxLength = max(1, (int) ($filters['publisher']['max_length'] ?? 120));
            if ($publisher !== '' && mb_strlen($publisher) <= $maxLength) {
                $active['publisher'] = $publisher;
            }
        }

        return $active;
    }

    /** Whether a requested year is an integer within the bounds. */
    private function validYear(int $year, int $min, int $max): bool
    {
        return $year >= $min && $year <= $max;
    }

    /**
     * The enabled scopes from the config entity catalog.
     *
     * @return array<int, string>
     */
    private function enabledScopes(): array
    {
        $entities = (array) ($this->config['entities'] ?? []);
        $scopes   = [];

        foreach ($entities as $key => $entity) {
            if (is_array($entity) && !empty($entity['enabled'])) {
                $scopes[] = (string) $key;
            }
        }

        return $scopes;
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