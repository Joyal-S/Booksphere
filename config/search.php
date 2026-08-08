<?php

declare(strict_types=1);

/**
 * config/search.php
 *
 * Advanced Search configuration (Phase 11.1 - architecture).
 *
 * THE ONLY SEARCH SETTINGS THE APPLICATION READS. Every Phase 11.2+
 * component (query builder, repository, suggestions, history,
 * analytics, the controller) reads everything it needs from this ONE
 * file through the dot-notation config() helper:
 *
 *     config('search.query.max_length')
 *     config('search.pagination.per_page')
 *
 * The file is loaded automatically by Config::loadFromDirectory()
 * (the "search" group) - nothing else needs to be wired.
 *
 * Every value can be overridden through environment variables in the
 * ".env" file, so the same code runs on any machine without edits.
 *
 * Centralized limits live here so a future operator can tune the
 * query budget, the pagination sizes, the suggestion/history caps and
 * the rate-limit windows without touching a single class.
 *
 * Design notes:
 *     - "enabled" is the MASTER SWITCH: with SEARCH_ENABLED=false the
 *       module answers "search is disabled" everywhere instead of
 *       executing - the pattern of config/google_books.php.
 *     - "provider" names the SearchProvider implementation ('sqlite'
 *       now; 'meilisearch' / 'elasticsearch' / 'typesense' / 'algolia'
 *       are future drops behind the SAME interface - Phase 11.1 Task
 *       11). The ProviderFactory resolves it; the application logic
 *       never changes when the provider changes.
 *     - "entities" is the EXTENSION CATALOGUE: one entry per
 *       searchable entity (books, authors, categories, publishers,
 *       reviews; users and collections are marked future-ready). Each
 *       entry lists the FIELDS the search layer may read and the
 *       weight used by future relevance scoring (Phase 11.6+). Adding
 *       an entity = adding one config block, never a rewrite.
 *     - the LIKE-based free-text strategy of the browse module stays
 *       the Phase 11.2 baseline; SQLite FTS5 tables are the documented
 *       scale-up path and are ADDED by a later sub-phase, not here
 *       (this phase only establishes the architecture).
 */

return [
    // Master switch: false = the whole search module is a no-op that
    // answers a friendly "disabled" state; true = search executes.
    'enabled' => (bool) env('SEARCH_ENABLED', true),

    // The active provider implementation name ('sqlite' | future
    // 'meilisearch' | 'elasticsearch' | 'typesense' | 'algolia').
    // Resolved by SearchProviderFactory; the app logic only ever
    // talks to the SearchProvider interface.
    'provider' => (string) env('SEARCH_PROVIDER', 'sqlite'),

    // --- Query rules (Task 4 + Task 7) -------------------------------
    'query' => [
        // The shortest term worth a search (below this = empty query).
        'min_length' => (int) env('SEARCH_QUERY_MIN_LENGTH', 1),
        // The hard cap on a term's length (very long queries are
        // trimmed/rejected here, before any SQL is built).
        'max_length' => (int) env('SEARCH_QUERY_MAX_LENGTH', 200),
        // The cap on how many words one term may contain (a multi-word
        // term beyond this is treated as unsupported and answered with
        // the matching error message).
        'max_words'  => (int) env('SEARCH_QUERY_MAX_WORDS', 10),
        // The ceiling on how many rows a search may return at most
        // (pagination never exceeds it; protects the response size).
        'max_results' => (int) env('SEARCH_MAX_RESULTS', 500),
    ],

    // --- Pagination (Task 9) ------------------------------------------
    'pagination' => [
        // The default page size when the caller does not ask.
        'per_page' => (int) env('SEARCH_PER_PAGE', 24),
        // The page sizes a caller may request; anything else is
        // silently clamped to per_page (a whitelist, like the browse
        // module's SORTS - user input never reaches LIMIT raw).
        'allowed'  => [12, 24, 48, 96],
    ],

    // --- Suggestions (Phase 11.4) -------------------------------------
    'suggestions' => [
        'enabled' => (bool) env('SEARCH_SUGGESTIONS_ENABLED', true),
        // How many suggestion rows the endpoint may return at most.
        'limit'   => (int) env('SEARCH_SUGGESTION_LIMIT', 8),
        // The shortest prefix worth a suggestion: shorter terms answer
        // 422 (a live-type-ahead box has no use for one character).
        'min_length' => (int) env('SEARCH_SUGGESTION_MIN_LENGTH', 2),
    ],

    // --- History (Phase 11.4) -----------------------------------------
    'history' => [
        'enabled' => (bool) env('SEARCH_HISTORY_ENABLED', true),
        // How many of a user's past queries are kept at most.
        'limit'   => (int) env('SEARCH_HISTORY_LIMIT', 12),
        // How long a stored query is kept before cleanup.
        'ttl_days' => (int) env('SEARCH_HISTORY_TTL_DAYS', 90),
    ],

    // --- Analytics (Phase 11.5) ---------------------------------------
    'analytics' => [
        'enabled' => (bool) env('SEARCH_ANALYTICS_ENABLED', true),
        // How many days of raw events are kept before a maintenance
        // sweep removes them.
        'retention_days' => (int) env('SEARCH_ANALYTICS_RETENTION_DAYS', 365),
    ],

    // --- Performance (Task 9) -----------------------------------------
    'performance' => [
        // The wall-clock budget of ONE search (a database call that
        // cannot finish in time answers the timeout error, never a
        // hanging page). SQLite calls are near-instant; the cap
        // protects the future provider-backed searches too.
        'timeout_seconds' => (float) env('SEARCH_TIMEOUT_SECONDS', 5.0),
    ],

    // --- Rate limiting (Task 8) ---------------------------------------
    // Session-backed sliding windows, the SAME RateLimiter the write
    // endpoints use. 'search' guards the full-page search,
    // 'suggestions' the live suggestion endpoint.
    'rate_limit' => [
        'search'      => ['limit' => 60, 'window_seconds' => 60],
        'suggestions' => ['limit' => 120, 'window_seconds' => 60],
    ],

    // --- Advanced filters (Phase 11.3) --------------------------------
    // The books-scope filter toolbar. Each filter is a toggle; its
    // 'values' map is the whitelist the request gate accepts (an
    // unknown value is silently dropped, never a query - the browse
    // filter pattern). Relation filters (category/author/publisher)
    // read their dropdown options from the database through
    // SearchRepository::filterOptions(); 'enabled' only matters here.
    'filters' => [
        'status' => [
            'enabled' => true,
            // 'status' storage values -> display labels (the SAME
            // vocabulary the book edit form stores, so a filter can
            // never request a status the form cannot set).
            'values'  => [
                'draft'     => 'Draft',
                'published' => 'Published',
                'archived'  => 'Archived',
            ],
        ],
        'category' => [
            'enabled' => true,
        ],
        'author' => [
            'enabled' => true,
        ],
        'publisher' => [
            'enabled' => true,
            // A free-text LIKE against books.publisher (the live
            // dropdown is fed by the distinct publisher values).
            'max_length' => 120,
        ],
        'language' => [
            'enabled' => true,
            // 'language' code -> display label (the same codes the
            // book form and the browse toolbar use).
            'values'  => [
                'en' => 'English',
                'hi' => 'Hindi',
                'es' => 'Spanish',
                'fr' => 'French',
                'de' => 'German',
            ],
        ],
        'year' => [
            'enabled' => true,
            // The accepted publication-year range of the from/to
            // inputs (the browse toolbar's same bounds).
            'min' => 1000,
            'max' => 2100,
        ],
        'rating' => [
            'enabled' => true,
            // Minimum average_rating thresholds -> dropdown labels.
            'values'  => [
                '3'   => '3 stars & up',
                '4'   => '4 stars & up',
                '4.5' => '4.5 stars & up',
            ],
        ],
    ],

    // --- Searchable entities (Task 2) ---------------------------------
    // The extension catalogue. 'fields' are the columns the search
    // layer may read; 'weight' is the future relevance score of the
    // field (Phase 11.6 ranking) and is ignored until then. A new
    // searchable entity = one new block here (+ its repository read).
    'entities' => [
        'books' => [
            'enabled' => true,
            'fields'  => [
                'title'          => ['weight' => 10, 'exact' => true],
                'subtitle'       => ['weight' => 6,  'exact' => true],
                'description'    => ['weight' => 2,  'exact' => false],
                'publisher'      => ['weight' => 4,  'exact' => false],
                'language'       => ['weight' => 1,  'exact' => true],
                'isbn'           => ['weight' => 10, 'exact' => true],
                'published_year' => ['weight' => 1,  'exact' => true],
            ],
            'relations' => ['authors', 'categories'],
        ],
        'authors' => [
            'enabled' => true,
            'fields'  => [
                'name' => ['weight' => 8, 'exact' => true],
            ],
        ],
        'categories' => [
            'enabled' => true,
            'fields'  => [
                'name' => ['weight' => 8, 'exact' => true],
                'slug' => ['weight' => 1, 'exact' => true],
            ],
        ],
        'publishers' => [
            'enabled' => true,
            'fields'  => [
                'name' => ['weight' => 4, 'exact' => false],
            ],
            // The distinct publisher values of the books table (the
            // same source the browse module's filter dropdown uses).
            'source' => 'books.publisher',
        ],
        'reviews' => [
            'enabled' => true,
            'fields'  => [
                'body' => ['weight' => 1, 'exact' => false],
            ],
        ],
        // Future-ready: a users table search is described by the
        // architecture but NOT enabled until a later phase ships it.
        'users' => [
            'enabled' => false,
            'fields'  => [
                'name'  => ['weight' => 6, 'exact' => true],
                'email' => ['weight' => 3, 'exact' => true],
            ],
        ],
        // Future-ready: collections do not exist in the schema yet;
        // this block is the place the future collection entity hooks
        // in without touching the architecture.
        'collections' => [
            'enabled' => false,
            'fields'  => [
                'name' => ['weight' => 6, 'exact' => true],
            ],
        ],
    ],
];
