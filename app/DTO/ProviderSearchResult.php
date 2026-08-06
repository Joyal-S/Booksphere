<?php

declare(strict_types=1);

namespace BookSphere\App\DTO;

/**
 * ProviderSearchResult
 *
 * The RESULT of one provider search: the page of ProviderBookDTOs
 * plus everything the search UI needs to render it. It is the return
 * type of GoogleBooksService::search() - the controller and the view
 * consume this single object, never the raw provider payload.
 *
 * The search is GRACEFUL by design (Phase 10.1): a failed or disabled
 * provider never throws out of the service. Instead the result carries
 * a safe, user-facing $error string and an empty item list, so the
 * page always has something renderable.
 *
 * Fields:
 *     - items      the ProviderBookDTOs of the current page
 *     - totalItems the provider's total match count (may be capped)
 *     - page       the current page (1-based, already clamped)
 *     - perPage    the page size that was applied
 *     - pages      the total page count (clamped to the provider's
 *                  accessible window - Google Books stops at index 1000)
 *     - stale      served from a cache entry older than the TTL
 *     - cached     served from the cache at all (vs. a fresh request)
 *     - error      a friendly, safe message when the search failed
 */
final readonly class ProviderSearchResult
{
    /**
     * @param array<int, ProviderBookDTO> $items
     */
    public function __construct(
        public array $items = [],
        public int $totalItems = 0,
        public int $page = 1,
        public int $perPage = 10,
        public int $pages = 1,
        public bool $stale = false,
        public bool $cached = false,
        public string $error = '',
    ) {}

    /** Whether the search reached a live provider at all. */
    public function ok(): bool
    {
        return $this->error === '';
    }

    /** The first result on the page (1-based; 0 when empty). */
    public function firstOnPage(): int
    {
        return $this->totalItems === 0 ? 0 : (($this->page - 1) * $this->perPage) + 1;
    }

    /** The last result on the page (1-based; 0 when empty). */
    public function lastOnPage(): int
    {
        return min($this->totalItems, $this->page * $this->perPage);
    }
}