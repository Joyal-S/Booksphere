<?php

declare(strict_types=1);

namespace BookSphere\App\DTO;

/**
 * SearchQuerySpec
 *
 * The provider-NEUTRAL query specification of the global search
 * (Phase 11.2). One immutable value object describes a complete
 * search request:
 *
 *     - entity      the searchable entity ('books', 'authors',
 *                   'categories', 'publishers', 'reviews') - the
 *                   "scope" the request gate whitelisted
 *     - term        the normalized single search string (quoted
 *                   phrases preserved, quotes stripped)
 *     - words       the multi-word tokens ('harry potter' becomes
 *                   ['harry', 'potter']); every word must match
 *     - exact       whether the term is an exact-match candidate
 *                   (a bare ISBN / language code / slug)
 *     - fields      the config entity's searchable fields with
 *                   their 'exact' flag (from config.search.entities)
*     - sort        the deterministic order key (provider-specific
 *                   by design; today 'title')
 *     - filters     the normalized, whitelisted filter map of the
 *                   QUESTION (Phase 11.3): the books-scope filters a
 *                   user can request - status, language, min_rating,
 *                   year_from/year_to, category_id, author_id,
 *                   publisher. Always empty for the non-book scopes
 *                   (they have no columns to filter). Every key and
 *                   slot is validated by the request gate BEFORE it
 *                   ever reaches the provider.
 *     - page        the 1-based page number (already clamped)
 *     - perPage     the page size (already whitelisted)
 *     - maxResults  the hard ceiling on returned rows
 *
 * This is the seam that keeps the application provider-agnostic
 * (Phase 1.1 Task 11): the builder emits a spec, the provider
 * translates THAT into SQL (or, later, into an FTS5 / ES query
 * DSL). Application logic never builds SQL.
 */
final readonly class SearchQuerySpec
{
    public const SCOPE_BOOKS      = 'books';
    public const SCOPE_AUTHORS    = 'authors';
    public const SCOPE_CATEGORIES = 'categories';
    public const SCOPE_PUBLISHERS = 'publishers';
    public const SCOPE_REVIEWS    = 'reviews';

    /**
     * @param array<int, string>          $words   the multi-word tokens
     * @param array<string, array<string, mixed>> $fields  entity field catalog
     * @param array<string, mixed>         $filters normalized filter map
     */
    public function __construct(
        public string $entity     = self::SCOPE_BOOKS,
        public string $term       = '',
        public array $words       = [],
        public bool $exact        = false,
        public array $fields      = [],
        public string $sort       = 'title',
        public array $filters     = [],
        public int $page          = 1,
        public int $perPage       = 24,
        public int $maxResults    = 500,
    ) {}

    /** The LIMIT/OFFSET window of the current page. */
    public function limit(): int
    {
        return min($this->perPage, $this->maxResults);
    }

    /** The OFFSET of the current page. */
    public function offset(): int
    {
        return max(0, ($this->page - 1) * $this->limit());
    }

    /** Whether there is any term to match (empty = the empty page). */
    public function hasQuery(): bool
    {
        return $this->term !== '';
    }

    /** Whether any books-scope filter is active. */
    public function hasFilters(): bool
    {
        return $this->filters !== [];
    }
}