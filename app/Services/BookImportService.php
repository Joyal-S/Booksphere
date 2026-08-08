<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\DTO\ImportResult;
use BookSphere\App\DTO\ProviderBookDTO;
use BookSphere\App\Models\Author;
use BookSphere\App\Models\Book;
use BookSphere\App\Models\Category;
use Throwable;

/**
 * BookImportService
 *
 * The Phase 10.3 importer: ONE provider record -> one catalogue row.
 * The controller does the transport (GoogleBooksService::volume());
 * this service owns the DECISIONS of the import:
 *
 *     1. dedupe   - four checks in the exact order of the phase spec:
 *        google_book_id -> isbn13 -> isbn10 -> title+author fallback.
 *        The first hit answers "duplicate" WITHOUT writing anything.
 *     2. staging  - authors and categories are find-or-created (the
 *        UNIQUE name columns make INSERT OR IGNORE + read-back the
 *        race-safe pattern), then linked through the two junction
 *        tables like the manual book form does (replaceAuthors/
 *        replaceCategories).
 *     3. atomicity- the book row AND every relation land in ONE SQLite
 *        transaction, so a failure halfway leaves nothing behind -
 *        an import is all-or-nothing.
 *
 * Field mapping (the phase spec):
 *     - plain columns: title, subtitle, description, publisher,
 *       published_year (from the provider's date), language (BCP-47),
 *       page_count, isbn (ISBN-13 preferred), google_book_id
 *     - cover_image holds the PROVIDER's thumbnail URL right after the
 *       insert; when the Phase 10.4 cover pipeline is wired in and
 *       import.fetch_covers is on, CoverDownloadService replaces it
 *       with the LOCAL cached path (or the placeholder on failure) -
 *       the app always serves a local cover, never Google, from then on
 *     - preview_link: the best Google Books link (read/preview)
 *     - provider_rating / provider_ratings_count: the PROVIDER's
 *       figures, kept separate from books.average_rating/ratings_count
 *       which ReviewService derives from the app's OWN reviews
 *     - status: 'published' by default (config), so an imported book
 *       is instantly visible in browse/discover/recommendations
 *
 * Database errors are deliberately NOT caught here (the shared
 * ErrorHandler path handles them like every other module) - the
 * transaction is rolled back and the exception rethrown. Only the
 * provider layer throws the typed GoogleBooksException, and it is
 * caught upstairs in the controller. The cover pipeline is opt-in and
 * never throws (CoverDownloadService::attach() degrades internally),
 * so importing is atomic AND always succeeds even when its cover
 * fails.
 */
final class BookImportService
{
    public function __construct(
        private readonly Book $books,
        private readonly Author $authors,
        private readonly Category $categories,
        private readonly array $config = [],
        private readonly ?CoverDownloadService $covers = null,
    ) {}

    /**
     * Import ONE provider record (or answer "duplicate").
     */
    public function import(ProviderBookDTO $book): ImportResult
    {
        $existing = $this->books->findByGoogleBookId($book->externalId);

        if ($existing !== null) {
            return $this->duplicate('This book is already in the catalogue.', (int) $existing['id']);
        }

        $candidates = $this->isbnCandidates($book);

        if ($candidates !== [] && ($existing = $this->books->findByIsbns($candidates)) !== null) {
            return $this->duplicate('A book with this ISBN is already in the catalogue.', (int) $existing['id']);
        }

        if (($existing = $this->books->findByTitleAndAuthors($book->title, $book->authors)) !== null) {
            return $this->duplicate('A book with this title and author is already in the catalogue.', (int) $existing['id']);
        }

        // Single transaction: a mid-import failure rolls the book and
        // its relations back together - no orphan rows, no half-book.
        $pdo = db()->pdo();
        $pdo->beginTransaction();

        try {
            $bookId = $this->books->createImported($this->columnsFor($book));

            $this->books->replaceAuthors($bookId, $this->authorIds($book->authors));
            $this->books->replaceCategories($bookId, $this->categoryIds($book->categories));
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }

        $pdo->commit();

        // Phase 10.4: download + cache the cover AFTER the database
        // transaction (a network call never holds the SQLite write
        // lock). The pipeline is a pure opt-in: without an injected
        // service, with fetch_covers off, or with the module disabled
        // the provider URL stays in cover_image exactly as in 10.3.
        if ($this->covers !== null && $this->covers->isEnabled() && (bool) ($this->config['import']['fetch_covers'] ?? true)) {
            $this->covers->attach((string) $bookId, $book->thumbnail);
        }

        return new ImportResult(
            ImportResult::STATUS_IMPORTED,
            $bookId,
            '"' . $book->title . '" was imported into the catalogue.',
        );
    }

    /**
     * The [google_book_id => local book id] map for a page of provider
     * records. The search cards use it to show "In library" instead of
     * "Import" - ONE query for the whole page, never a lookup per card.
     *
     * @param array<int, ProviderBookDTO> $records
     * @return array<string, int>
     */
    public function importedMap(array $records): array
    {
        $ids = [];

        foreach ($records as $record) {
            if ($record instanceof ProviderBookDTO) {
                $ids[] = $record->externalId;
            }
        }

        return $this->books->importedIds($ids);
    }

    private function duplicate(string $message, int $bookId): ImportResult
    {
        return new ImportResult(ImportResult::STATUS_DUPLICATE, $bookId, $message);
    }

    /**
     * The ISBN strings to check an incoming record against.
     *
     * The books table stores ONE canonical isbn per row (the record's
     * ISBN-13 when it has one). Two records of the same physical book
     * can carry DIFFERENT forms of that ISBN (one ships ISBN-13, the
     * other ISBN-10), so the dedupe check must compare every form and
     * its converted mirror - otherwise a record whose ISBN is the
     * other form of an existing book's ISBN slips through.
     *
     * @return array<int, string>
     */
    private function isbnCandidates(ProviderBookDTO $book): array
    {
        $candidates = [];

        foreach ([$book->isbn13, $book->isbn10] as $isbn) {
            if (!is_string($isbn) || $isbn === '') {
                continue;
            }

            $candidates[] = $isbn;

            $mirror = $this->isbn13From10($isbn);
            if ($mirror !== null) {
                $candidates[] = $mirror;
            }

            $mirror = $this->isbn10From13($isbn);
            if ($mirror !== null) {
                $candidates[] = $mirror;
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * ISBN-13 from an ISBN-10 ("978" prefix + first nine digits + the
     * recomputed modulo-10 check digit), or null when not convertible.
     */
    private function isbn13From10(string $isbn10): ?string
    {
        $digits = preg_replace('/[^0-9Xx]/', '', $isbn10);

        if (!is_string($digits) || strlen($digits) !== 10) {
            return null;
        }

        $body = '978' . substr($digits, 0, 9);
        $sum  = 0;

        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $body[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return $body . ((10 - ($sum % 10)) % 10);
    }

    /**
     * ISBN-10 from an ISBN-13 - only for the "978" registrant (the
     * vast majority of real books): drop the prefix, take the next
     * nine digits and recompute the modulo-11 check digit.
     */
    private function isbn10From13(string $isbn13): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', $isbn13);

        if (!is_string($digits) || strlen($digits) !== 13 || substr($digits, 0, 3) !== '978') {
            return null;
        }

        $body = substr($digits, 3, 9);
        $sum  = 0;

        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $body[$i] * (10 - $i);
        }

        $check = (10 - ($sum % 11)) % 11;

        if ($check === 10) {
            return null;
        }

        return $body . $check;
    }

    /**
     * Map the provider-neutral record onto the import columns of the
     * books table (see the class docblock for the decisions).
     *
     * @return array<string, mixed>
     */
    private function columnsFor(ProviderBookDTO $book): array
    {
        $meta = $this->providerMetadata($book);

        return [
            'title'                  => $meta['title'],
            'subtitle'               => $meta['subtitle'],
            'description'            => $meta['description'],
            'publisher'              => $meta['publisher'],
            'published_year'         => $meta['published_year'],
            'language'               => $meta['language'],
            'page_count'             => $meta['page_count'],
            'cover_image'            => $meta['cover_image'],
            'status'                 => (string) ($this->config['import']['default_status'] ?? 'published'),
            'isbn'                   => $book->isbn(),
            'google_book_id'         => $book->externalId,
            'preview_link'           => $meta['preview_link'],
            'provider_rating'        => $meta['provider_rating'],
            'provider_ratings_count' => $meta['provider_ratings_count'],
        ];
    }

    /**
     * The provider-OWNED metadata of one record, in ONE canonical
     * shape. Both the importer (columnsFor) and the Phase 10.6
     * synchronizer read from this single map, so an import and a
     * refresh can never disagree about what a field should be - the
     * sync only ever writes the columns this map feeds.
     *
     * @return array<string, mixed>
     */
    public function providerMetadata(ProviderBookDTO $book): array
    {
        return [
            'title'                  => $book->title,
            'subtitle'               => $book->subtitle,
            'description'            => $book->description,
            'publisher'              => $book->publisher,
            'published_year'         => $book->publishedYear,
            'language'               => $book->language ?? 'en',
            'page_count'             => $book->pageCount,
            'cover_image'            => $book->thumbnail,
            'preview_link'           => $book->previewLink ?? $book->infoLink,
            'provider_rating'        => $book->averageRating,
            'provider_ratings_count' => $book->ratingsCount,
            'authors'                => $this->normalizedNames($book->authors),
            'categories'             => $this->normalizedNames($book->categories),
        ];
    }

    /**
     * The normalized display-name list of a relation field: trimmed,
     * de-duplicated, empty entries dropped, order kept. The ONE list
     * rule for both the author/category naming and the sync change
     * detection (a provider list with a stray space must not ping as
     * "changed").
     *
     * @param array<int, string> $names
     * @return array<int, string>
     */
    public function normalizedNames(array $names): array
    {
        $clean = [];

        foreach (array_values($names) as $name) {
            if (!is_string($name) || trim($name) === '') {
                continue;
            }

            $name = trim($name);

            if (in_array($name, $clean, true)) {
                continue;
            }

            $clean[] = $name;
        }

        return $clean;
    }

    /**
     * Find-or-create every author name into its id, in order.
     *
     * @return array<int, int>
     */
    public function authorIds(array $names): array
    {
        $ids = [];

        foreach ($this->normalizedNames($names) as $name) {
            $ids[] = $this->authors->findOrCreate($name);
        }

        return $ids;
    }

    /**
     * Find-or-create every category name into its id, in order.
     *
     * @return array<int, int>
     */
    public function categoryIds(array $names): array
    {
        $ids = [];

        foreach ($this->normalizedNames($names) as $name) {
            $ids[] = $this->categories->findOrCreate($name);
        }

        return $ids;
    }
}