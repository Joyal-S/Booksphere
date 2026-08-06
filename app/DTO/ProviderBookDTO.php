<?php

declare(strict_types=1);

namespace BookSphere\App\DTO;

/**
 * ProviderBookDTO
 *
 * The provider-neutral record of ONE book as returned by a book-data
 * provider (Google Books today; Open Library, ISBNdb, ... tomorrow).
 *
 * Why it exists:
 *     - The provider layer (BookProvider implementations) speaks in
 *       these records; the rest of the application (search UI, the
 *       Phase 10.3 importer, the Phase 10.5 sync) never sees a raw
 *       API payload again.
 *     - Every field is optional (nullable) EXCEPT the identity pair
 *       (externalId + title): providers are inconsistent, and the
 *       "graceful degradation" rule of Phase 10.1 says a record may
 *       lack authors, categories, an ISBN, a cover... and still be
 *       useful. Only a missing title makes a record unusable.
 *
 * Construction:
 *     The DTO itself is dumb data - the field EXTRACTION lives in the
 *     provider mappers (GoogleBooksProvider::mapVolume()), which is
 *     where the provider-specific payload shapes belong.
 *
 * Why immutable (readonly): the record travels from the provider
 * through the cache and the view without ever being mutated
 * mid-flight - the same decision as ReviewDTO / LibraryItemDTO.
 */
final readonly class ProviderBookDTO
{
    /**
     * @param string                  $externalId     The provider's own
     *                                                id for this volume
     * @param string                  $title          Display title
     * @param string|null             $subtitle
     * @param array<int, string>      $authors        Display names
     * @param array<int, string>      $categories     Genre names
     * @param string|null             $description    Plain text (HTML stripped)
     * @param string|null             $publisher
     * @param string|null             $publishedDate  Full ISO date ("2005-07-16")
     * @param int|null                $publishedYear  Year extracted from the date
     * @param string|null             $language       BCP-47 code ("en")
     * @param int|null                $pageCount
     * @param string|null             $isbn10
     * @param string|null             $isbn13
     * @param string|null             $thumbnail      Cover image URL
     * @param string|null             $previewLink    Link to read/preview the book
     * @param string|null             $infoLink       Link to the provider's page
     * @param float|null              $averageRating  The PROVIDER's rating (0-5)
     * @param int|null                $ratingsCount   The provider's rating count
     * @param string                  $provider       Provider name ('google_books')
     */
    public function __construct(
        public readonly string $externalId,
        public readonly string $title,
        public readonly ?string $subtitle = null,
        public readonly array $authors = [],
        public readonly array $categories = [],
        public readonly ?string $description = null,
        public readonly ?string $publisher = null,
        public readonly ?string $publishedDate = null,
        public readonly ?int $publishedYear = null,
        public readonly ?string $language = null,
        public readonly ?int $pageCount = null,
        public readonly ?string $isbn10 = null,
        public readonly ?string $isbn13 = null,
        public readonly ?string $thumbnail = null,
        public readonly ?string $previewLink = null,
        public readonly ?string $infoLink = null,
        public readonly ?float $averageRating = null,
        public readonly ?int $ratingsCount = null,
        public readonly string $provider = 'google_books',
    ) {}

    /**
     * The preferred ISBN of the record (ISBN-13 first, then ISBN-10),
     * or null when the provider delivered neither.
     */
    public function isbn(): ?string
    {
        return $this->isbn13 ?? $this->isbn10;
    }

    /**
     * The compact display authors string ("J.K. Rowling, Mary GrandPré").
     */
    public function authorsList(): string
    {
        return implode(', ', $this->authors);
    }

    /**
     * The compact display categories string ("Fiction, Fantasy").
     */
    public function categoriesList(): string
    {
        return implode(', ', $this->categories);
    }

    /**
     * Whether the record is usable at all: the title is the one field
     * without which a book is not a book.
     */
    public function hasTitle(): bool
    {
        return trim($this->title) !== '';
    }
}