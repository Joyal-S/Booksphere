<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\Models\Author;
use BookSphere\App\Models\Book;
use BookSphere\App\Models\Category;
use BookSphere\App\Requests\BookRequest;

/**
 * BookService
 *
 * The business logic of the book management module. Controllers
 * stay thin: they translate the request into data, hand it to this
 * service, and render the result. Everything that requires a
 * DECISION lives here:
 *
 *     - form validation (field rules + ISBN uniqueness + cover file)
 *     - cover uploads (delegated to MediaService for storage)
 *     - catalogue browsing: combineFilters() sanitizes the raw
 *       query string, then search() / filter() / sort() /
 *       paginate() drive the shared repository query
 *     - the create / update / remove-cover / soft delete workflows
 *
 * The model layer (Book, Author, Category) forwards to their
 * repositories, which execute the SQL; the MediaService handles
 * every file on disk; this service decides which of them should
 * run, and with which values.
 */
final class BookService
{
    /**
     * The allowed publication states of a book. The array keys are
     * the values stored in the database; the values are the labels
     * shown in forms and badges.
     */
    public const STATUSES = [
        'draft'     => 'Draft',
        'published' => 'Published',
        'archived'  => 'Archived',
    ];

    /**
     * The sort presets of the browse page. Each key is the value
     * accepted from the query string ("/books?sort=newest"); the
     * mapped spec tells the repository which COLUMN to order by,
     * in which DIRECTION, and whether NULL values should be pushed
     * to the end (used by the publication-year sorts).
     *
     * This IS the whitelist that keeps user input out of the ORDER
     * BY clause: any sort parameter that is not one of these keys
     * is silently replaced with the default.
     */
    public const SORTS = [
        'newest'       => ['label' => 'Newest',             'column' => 'created_at',     'order' => 'DESC', 'nullsLast' => false],
        'oldest'       => ['label' => 'Oldest',             'column' => 'created_at',     'order' => 'ASC',  'nullsLast' => false],
        'title_asc'    => ['label' => 'Title (A–Z)',       'column' => 'title',          'order' => 'ASC',  'nullsLast' => false],
        'title_desc'   => ['label' => 'Title (Z–A)',       'column' => 'title',          'order' => 'DESC', 'nullsLast' => false],
        'rating_desc'  => ['label' => 'Highest rated',      'column' => 'average_rating', 'order' => 'DESC', 'nullsLast' => false],
        'rating_asc'   => ['label' => 'Lowest rated',       'column' => 'average_rating', 'order' => 'ASC',  'nullsLast' => false],
        'year_desc'    => ['label' => 'Publication year',   'column' => 'published_year', 'order' => 'DESC', 'nullsLast' => true],
        'updated_desc' => ['label' => 'Recently updated',   'column' => 'updated_at',     'order' => 'DESC', 'nullsLast' => false],
    ];

    /** The sort applied when the user has not chosen one. */
    public const DEFAULT_SORT = 'newest';

    /**
     * The allowed page sizes (dropdown on the browse page). The
     * whitelist doubles as a guard: a "per_page" value outside
     * this list falls back to the default instead of becoming a
     * huge LIMIT.
     */
    public const PAGE_SIZES = [10, 20, 50, 100];

    /** The page size used when nothing is chosen. */
    public const DEFAULT_PAGE_SIZE = 10;

    /**
     * The minimum-rating filter options. Keys are the query-string
     * values, values are the dropdown labels. "Any rating" (the
     * empty string) means the filter is inactive.
     */
    public const RATING_FILTERS = [
        ''    => 'Any rating',
        '3'   => '3 stars & up',
        '4'   => '4 stars & up',
        '4.5' => '4.5 stars & up',
    ];

    /**
     * The allowed language codes of a book (stored in the "language"
     * column, which defaults to 'en').
     */
    public const LANGUAGES = [
        'en' => 'English',
        'hi' => 'Hindi',
        'es' => 'Spanish',
        'fr' => 'French',
        'de' => 'German',
    ];

    /**
     * The storage layer used for book covers. Injected here so a
     * future media type (author photos, review images) reuses the
     * exact same pipeline through another MediaService instance.
     */
    private readonly MediaService $media;

    /**
     * Upload rules live in the media configuration. The covers
     * entry (config/media.php) drives the MediaService.
     */
    public function __construct(
        private readonly Book $books,
        private readonly Author $authors,
        private readonly Category $categories,
    ) {
        $this->media = new MediaService((array) config('media.covers', []));
    }

    /**
     * The full author list for the form checkboxes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function authors(): array
    {
        return $this->authors->all();
    }

    /**
     * The full category list for the form checkboxes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function categories(): array
    {
        return $this->categories->all();
    }

    /**
     * Find one book (with its relations) for the show/edit pages.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->books->findWithRelations($id);
    }

    /**
     * Sanitize and normalize raw query-string values into a safe
     * filter set the repository can consume.
     *
     * EVERY value passes through a whitelist or a type check:
     *
     *     - free text (q, publisher) is trimmed + length-limited
     *     - status / language / sort / per_page go through constants
     *     - ids must be positive integers
     *     - years are clamped to a sane range
     *     - the minimum rating is clamped to 0-5
     *     - the page number is at least 1
     *
     * Because every browse method starts with this, raw request
     * input can never reach the SQL layer unfiltered.
     *
     * @param array<string, mixed> $raw Values straight from the query string
     * @return array<string, mixed> The normalized filter set
     */
    public function combineFilters(array $raw): array
    {
        $q = mb_substr(trim((string) ($raw['q'] ?? '')), 0, 100);

        $status = (string) ($raw['status'] ?? '');
        $status = isset(self::STATUSES[$status]) ? $status : '';

        $language = (string) ($raw['language'] ?? '');
        $language = isset(self::LANGUAGES[$language]) ? $language : '';

        $sort = (string) ($raw['sort'] ?? '');
        $sort = isset(self::SORTS[$sort]) ? $sort : self::DEFAULT_SORT;

        $perPage = (int) ($raw['per_page'] ?? self::DEFAULT_PAGE_SIZE);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : self::DEFAULT_PAGE_SIZE;

        $page = max(1, (int) ($raw['page'] ?? 1));

        return [
            'q'           => $q,
            'status'      => $status,
            'category_id' => $this->positiveId($raw['category_id'] ?? null),
            'author_id'   => $this->positiveId($raw['author_id'] ?? null),
            'publisher'   => mb_substr(trim((string) ($raw['publisher'] ?? '')), 0, 120),
            'language'    => $language,
            'year_from'   => $this->year($raw['year_from'] ?? null),
            'year_to'     => $this->year($raw['year_to'] ?? null),
            'min_rating'  => $this->rating($raw['min_rating'] ?? null),
            'sort'        => $sort,
            'perPage'     => $perPage,
            'page'        => $page,
        ];
    }

    /**
     * The main browse query: filters + sort + pagination in one call.
     *
     * Flow:
     *     1. combineFilters() sanitizes the raw input (whitelists)
     *     2. the sort key is resolved to a column/direction spec
     *     3. the repository runs ONE query: COUNT (total) + a
     *        LIMIT/OFFSET slice (only the current page is loaded)
     *     4. the page number is clamped so it never exceeds the
     *        last page
     *
     * @param array<string, mixed> $filters Raw or normalized filters
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, perPage: int, pages: int}
     */
    public function paginate(array $filters, ?int $page = null, ?int $perPage = null): array
    {
        $filters = $this->combineFilters($filters);

        $page    = $page ?? $filters['page'];
        $perPage = $perPage ?? $filters['perPage'];

        // Explicit overrides are re-checked against the whitelist
        // too: a caller-supplied page size must still be one of
        // 10/20/50/100, or it falls back to the default.
        if (!in_array($perPage, self::PAGE_SIZES, true)) {
            $perPage = self::DEFAULT_PAGE_SIZE;
        }

        $result = $this->books->browse([
            'q'           => $filters['q'],
            'status'      => $filters['status'] !== '' ? $filters['status'] : null,
            'category_id' => $filters['category_id'],
            'author_id'   => $filters['author_id'],
            'publisher'   => $filters['publisher'],
            'language'    => $filters['language'] !== '' ? $filters['language'] : null,
            'year_from'   => $filters['year_from'],
            'year_to'     => $filters['year_to'],
            'min_rating'  => $filters['min_rating'],
            'sort'        => $this->sortSpec($filters['sort']),
            'perPage'     => $perPage,
            'offset'      => ($page - 1) * $perPage,
        ]);

        // A catalogue of 0 books still has 1 page (an empty one),
        // so the pagination bar always renders consistently.
        $pages = max(1, (int) ceil($result['total'] / $perPage));
        $page  = min(max(1, $page), $pages);

        return [
            'items'   => $result['items'],
            'total'   => $result['total'],
            'page'    => $page,
            'perPage' => $perPage,
            'pages'   => $pages,
        ];
    }

    /**
     * Search the catalogue by free text.
     *
     * The term is combined with any other active filters and flows
     * through the same pipeline as paginate(), so a search result
     * page is a fully filtered, sorted and paginated catalogue.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, perPage: int, pages: int}
     */
    public function search(string $term, array $filters = [], ?int $page = null, ?int $perPage = null): array
    {
        return $this->paginate(['q' => $term] + $filters, $page, $perPage);
    }

    /**
     * Filter the catalogue without a search term.
     *
     * @param array<string, mixed> $filters The active filters
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, perPage: int, pages: int}
     */
    public function filter(array $filters = [], ?int $page = null, ?int $perPage = null): array
    {
        return $this->paginate($filters, $page, $perPage);
    }

    /**
     * Sort the catalogue by one of the whitelisted presets.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, perPage: int, pages: int}
     */
    public function sort(string $sort, array $filters = [], ?int $page = null, ?int $perPage = null): array
    {
        return $this->paginate(['sort' => $sort] + $filters, $page, $perPage);
    }

    /**
     * The data the filter bar needs: every dropdown source.
     *
     * @return array{categories: array<int, array<string, mixed>>, authors: array<int, array<string, mixed>>, publishers: array<int, mixed>, languages: array<string, string>, statuses: array<string, string>, ratings: array<string, string>}
     */
    public function filterOptions(): array
    {
        return [
            'categories' => $this->categories->all(),
            'authors'    => $this->authors->all(),
            'publishers' => $this->books->distinct('publisher'),
            'languages'  => self::LANGUAGES,
            'statuses'   => self::STATUSES,
            'ratings'    => self::RATING_FILTERS,
        ];
    }

    /**
     * Build the URL of the browse page for a set of (normalized)
     * filters. The single place that knows how filters map to the
     * query string, so the filter chips, the pagination bar and
     * the search form can never disagree again.
     *
     *     BookService::queryString($filters, ['q'])
     *         -> "/books?status=published"          (drop the search)
     *
     *     BookService::queryString($filters, [], ['page' => 2])
     *         -> "/books?q=harry&page=2"            (next page)
     *
     * Empty values are dropped, so the URL only ever carries
     * active filters.
     *
     * @param array<string, mixed> $filters   Normalized filters
     * @param array<int, string>   $remove    Filter keys to drop
     * @param array<string, mixed> $overrides Filter keys to replace
     * @return string An absolute app path, e.g. "/books?q=harry"
     */
    public static function queryString(array $filters, array $remove = [], array $overrides = []): string
    {
        $parts = [
            'q'           => (string) ($filters['q'] ?? ''),
            'status'      => (string) ($filters['status'] ?? ''),
            'category_id' => $filters['category_id'] !== null ? (string) $filters['category_id'] : '',
            'author_id'   => $filters['author_id'] !== null ? (string) $filters['author_id'] : '',
            'publisher'   => (string) ($filters['publisher'] ?? ''),
            'language'    => (string) ($filters['language'] ?? ''),
            'year_from'   => $filters['year_from'] !== null ? (string) $filters['year_from'] : '',
            'year_to'     => $filters['year_to'] !== null ? (string) $filters['year_to'] : '',
            'min_rating'  => $filters['min_rating'] !== null ? (string) $filters['min_rating'] : '',
            'sort'        => (string) ($filters['sort'] ?? ''),
            'per_page'    => (string) ($filters['perPage'] ?? self::DEFAULT_PAGE_SIZE),
        ];

        foreach ($remove as $key) {
            unset($parts[$key]);
        }

        foreach ($overrides as $key => $value) {
            $parts[$key] = (string) $value;
        }

        $parts = array_filter($parts, fn (string $value): bool => $value !== '');

        return '/books' . ($parts === [] ? '' : '?' . http_build_query($parts));
    }

    /**
     * Resolve a whitelisted sort key into a repository sort spec.
     *
     * @return array{column: string, order: string, nullsLast: bool}
     */
    private function sortSpec(string $sort): array
    {
        $spec = self::SORTS[$sort] ?? self::SORTS[self::DEFAULT_SORT];

        return [
            'column'    => (string) $spec['column'],
            'order'     => (string) $spec['order'],
            'nullsLast' => (bool) ($spec['nullsLast'] ?? false),
        ];
    }

    /**
     * A positive integer id, or null when the input is junk.
     */
    private function positiveId(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    /**
     * A publication year clamped to a sane range, or null.
     */
    private function year(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $year = (int) $value;

        return $year >= 1000 && $year <= 2100 ? $year : null;
    }

    /**
     * A minimum rating clamped to 0-5, or null. Values like "4.5"
     * arrive as strings and must round-trip exactly, so the value
     * is parsed as a float, not an int; non-numeric junk is dropped.
     */
    private function rating(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $rating = (float) $value;

        return $rating >= 0 && $rating <= 5 ? $rating : null;
    }

    /**
     * Validate a submitted book form (including the cover file).
     *
     * The pure field rules live in BookRequest; this method adds
     * the two checks that need the database and the uploaded file
     * (ISBN uniqueness and the cover image).
     *
     * @param array<string, mixed> $data   The form values
     * @param array<string, mixed>|null $cover An uploaded file entry
     *                                        (from Request::file), or null
     * @param int|null             $bookId When editing: the book id, so
     *                                     its own ISBN is not flagged
     * @return array<string, array<int, string>> Field -> error messages
     */
    public function errorsFor(array $data, ?array $cover, ?int $bookId = null): array
    {
        $errors = BookRequest::validate($data, self::STATUSES, self::LANGUAGES)->errors();

        // ISBN is optional, but when given it must be unique.
        $isbn = $this->normalizeIsbn($data['isbn'] ?? '');
        if ($isbn !== null && $this->books->isbnExists($isbn, $bookId)) {
            $errors['isbn'][] = 'A book with this ISBN already exists.';
        }

        if ($cover !== null) {
            $coverError = $this->media->validate($cover);
            if ($coverError !== null) {
                $errors['cover'][] = $coverError;
            }
        }

        return $errors;
    }

    /**
     * Create a book. The controller only calls this after
     * errorsFor() returned no errors, so no validation happens here.
     *
     * @param array<string, mixed> $data   The validated form values
     * @param array<string, mixed>|null $cover The uploaded cover, or null
     * @return int The id of the new book
     */
    public function store(array $data, ?array $cover): int
    {
        $coverPath = $cover !== null ? $this->media->store($cover) : null;

        $id = $this->books->create($this->bookColumns($data, $coverPath, $this->normalizeIsbn($data['isbn'] ?? '')));

        $this->books->replaceAuthors($id, $this->idsFrom($data['author_ids'] ?? []));
        $this->books->replaceCategories($id, $this->idsFrom($data['category_ids'] ?? []));

        return $id;
    }

    /**
     * Update a book and manage its cover image:
     *
     *     - a new upload replaces the old image (old file removed)
     *     - remove_cover flag clears the image (file removed)
     *     - neither -> the existing image is kept untouched
     *
     * @return bool Whether a row was updated
     */
    public function update(int $id, array $data, ?array $cover): bool
    {
        $existing = $this->books->findById($id);

        if ($existing === null) {
            return false;
        }

        $coverPath = $existing['cover_image'];

        if ($cover !== null) {
            // New cover: store it, then drop the previous file.
            $coverPath = $this->media->store($cover);
            $this->media->delete($existing['cover_image']);
        } elseif (!empty($data['remove_cover'])) {
            // Remove flag: delete the stored file and clear the column.
            $this->media->delete($existing['cover_image']);
            $coverPath = null;
        }

        $updated = $this->books->update($id, $this->bookColumns($data, $coverPath, $this->normalizeIsbn($data['isbn'] ?? '')));

        $this->books->replaceAuthors($id, $this->idsFrom($data['author_ids'] ?? []));
        $this->books->replaceCategories($id, $this->idsFrom($data['category_ids'] ?? []));

        return $updated;
    }

    /**
     * Soft delete a book (stamps deleted_at). The cover file is
     * removed from disk so soft-deleted books never leave orphan
     * files behind; the row (title, meta, links) is kept so an
     * administrator can restore it later - it will simply show the
     * default placeholder cover again.
     */
    public function softDelete(int $id): bool
    {
        $existing = $this->books->findById($id);

        if ($existing === null) {
            return false;
        }

        $deleted = $this->books->softDelete($id);

        if ($deleted) {
            $this->media->delete($existing['cover_image']);
        }

        return $deleted;
    }

    /**
     * Normalize the column values of a submitted form.
     *
     * Empty strings become NULL so the database stores "no value"
     * instead of a blank string. The ISBN is normalized separately
     * (see normalizeIsbn).
     *
     * @param array<string, mixed> $data The validated form values
     * @param string|null $coverPath     The stored cover URL, or null
     * @param string|null $isbn          The normalized ISBN, or null
     * @return array<string, mixed>
     */
    private function bookColumns(array $data, ?string $coverPath, ?string $isbn): array
    {
        return [
            'title'          => $data['title'],
            'subtitle'       => $this->nullIfEmpty($data['subtitle'] ?? null),
            'description'    => $this->nullIfEmpty($data['description'] ?? null),
            'publisher'      => $this->nullIfEmpty($data['publisher'] ?? null),
            'published_year' => $this->nullIfEmpty($data['published_year'] ?? null),
            'language'       => (string) ($data['language'] ?? 'en'),
            'page_count'     => $this->nullIfEmpty($data['page_count'] ?? null),
            'status'         => (string) ($data['status'] ?? 'draft'),
            'cover_image'    => $coverPath,
            'isbn'           => $isbn,
        ];
    }

    /**
     * Strip spaces and dashes from an ISBN and turn empty input
     * into NULL (the books table allows NULL, not "").
     */
    private function normalizeIsbn(string $isbn): ?string
    {
        $isbn = str_replace([' ', '-'], '', trim($isbn));

        return $isbn === '' ? null : $isbn;
    }

    /**
     * Convert a submitted checkbox group into a list of unique,
     * positive integer ids (junk values are dropped silently).
     *
     * @return array<int, int>
     */
    private function idsFrom(mixed $value): array
    {
        $ids = [];

        foreach ((array) $value as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Turn an empty string into null (used for optional columns).
     */
    private function nullIfEmpty(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === null || $value === '' ? null : (string) $value;
    }
}
