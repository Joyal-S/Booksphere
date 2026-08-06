<?php

declare(strict_types=1);

namespace BookSphere\App\Requests;

use BookSphere\App\Core\Validator;
use BookSphere\App\Services\GoogleBooksProvider;

/**
 * SearchBooksRequest
 *
 * The QUERY RULES of the Google Books search (Phase 10.2) - the
 * inbound half of the search flow. It answers one question:
 *
 *     "What makes a submitted search valid?"
 *
 * and turns the answer into the provider query string:
 *
 *     type      ->  prefix          Example
 *     ------    ----------------  ---------------------------
 *     any       (raw term)        harry potter
 *     title     intitle:          intitle:"harry potter"
 *     author    inauthor:           inauthor:tolkien
 *     isbn      isbn:              isbn:9780439064873
 *     publisher inpublisher:       inpublisher:penguin
 *     subject   subject:           subject:scifi
 *
 * Rules:
 *     - 'type' must be one of the whitelist above.
 *     - 'q' may be empty: empty terms are NOT an error (the page
 *       renders its empty state), they just leave nothing to search.
 *     - When 'type' is 'isbn' the term must pass the ISBN-10/13
 *       checksum (via GoogleBooksProvider) BEFORE any request - a
 *       malformed ISBN is a field error, never an API call.
 *     - 'page' / 'per_page' are raw-ish here; the service clamps them
 *       against its display limit, so they never need validation rules.
 *     - 'max_length' cap for 'q' matches config search.query_max_length.
 *
 * Both the full page and the live AJAX endpoint build the SAME
 * request object, so server-side and client-side search can never
 * disagree about what is valid or how it is quoted.
 */
final class SearchBooksRequest
{
    public const ANY      = 'any';
    public const TITLE    = 'title';
    public const AUTHOR   = 'author';
    public const ISBN     = 'isbn';
    public const PUBLISHER = 'publisher';
    public const SUBJECT  = 'subject';

    /** @var array<string, string> */
    public const TYPES = [
        self::ANY,
        self::TITLE,
        self::AUTHOR,
        self::ISBN,
        self::PUBLISHER,
        self::SUBJECT,
    ];

    /** @var array<string, string> type => google field prefix */
    private const PREFIXES = [
        self::ANY      => '',
        self::TITLE    => 'intitle:',
        self::AUTHOR   => 'inauthor:',
        self::ISBN     => 'isbn:',
        self::PUBLISHER => 'inpublisher:',
        self::SUBJECT  => 'subject:',
    ];

    private readonly array $input;

    private readonly string $type;

    private readonly string $rawQuery;

    private readonly Validator $validator;

    public function __construct(
        array $input,
        private readonly GoogleBooksProvider $provider,
    ) {
        $this->input    = $input;
        $this->type     = (string) ($input['type'] ?? self::ANY);
        $this->rawQuery = (string) ($input['q'] ?? '');

        $maxLength = max(1, (int) (($input['_max_length'] ?? 100) ?: 100));

        $rules = (new Validator($input))
            ->in('type', self::TYPES, 'search type')
            ->max('q', $maxLength, 'Search term');

        if ($this->type === self::ISBN && $this->rawQuery !== '') {
            $code = preg_replace('/[^0-9Xx]/', '', $this->rawQuery) ?? '';

            if ($code === '' || (!$this->provider->validIsbn10($code) && !$this->provider->validIsbn13($code))) {
                $rules->error('isbn', 'Enter a valid ISBN-10 or ISBN-13 - the checksum is checked before searching.');
            }
        }

        $this->validator = $rules;
    }

    /**
     * Whether every rule passed.
     */
    public function valid(): bool
    {
        return $this->validator->passes();
    }

    /**
     * The field -> message error map.
     */
    public function errors(): array
    {
        return $this->validator->errors();
    }

    /**
     * Whether there is a term to search at all (empty = the empty page).
     */
    public function hasQuery(): bool
    {
        return trim($this->rawQuery) !== '';
    }

    /**
     * The provider query string: the prefixed scope term, so a phrase
     * search stays a phrase (quotes protect terms with spaces).
     */
    public function googleQuery(): string
    {
        $term   = trim($this->rawQuery);
        $prefix = self::PREFIXES[$this->type] ?? '';

        if ($term === '') {
            return '';
        }

        $clean = str_replace('"', '', $term);

        return $prefix . '"' . $clean . '"';
    }

    /**
     * The unprefixed display term for the "results:" line.
     */
    public function query(): string
    {
        return trim($this->rawQuery);
    }

    /**
     * The chosen scope type (always one of self::TYPES).
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * The 1-based page to request.
     */
    public function page(): int
    {
        return max(1, (int) ($this->input['page'] ?? 1));
    }

    /**
     * The requested page size (0 = let the service pick the default).
     */
    public function perPage(): int
    {
        return max(0, (int) ($this->input['per_page'] ?? 0));
    }

    /**
     * The actionable filter map for GoogleBooksService::search().
     *
     * @return array{googleQuery: string, query: string, type: string}
     */
    public function filters(): array
    {
        return [
            'googleQuery' => $this->googleQuery(),
            'query'       => $this->query(),
            'type'        => $this->type,
        ];
    }
}