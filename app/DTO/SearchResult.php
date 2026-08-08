<?php

declare(strict_types=1);

namespace BookSphere\App\DTO;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * SearchResult
 *
 * The FULL formatted result of one global search, exactly what the
 * controller and the views consume. Formed by SearchResultFormatter,
 * this object bundles the searched page of SearchHits plus everything
 * the pagination bar and the empty/error states need:
 *
 *     - hits        the SearchHit[] page (already provider-neutral)
 *     - total       the total match count (before pagination)
 *     - page / perPage / pages   the pagination numbers
 *     - query       the normalized term that produced the result
 *     - error       a friendly, safe message when the search failed
 *                   ("" = success) - the view renders a raw alert.
 */
final readonly class SearchResult
{
    /**
     * @param array<int, SearchHit> $hits
     */
    public function __construct(
        public array $hits = [],
        public int $total = 0,
        public int $page = 1,
        public int $perPage = 24,
        public int $pages = 1,
        public string $query = '',
        public string $error = '',
    ) {}

    /** Whether the search errored (the view shows the error state). */
    public function ok(): bool
    {
        return $this->error === '';
    }

    /** The first result number on this page (1-based; 0 when empty). */
    public function firstOnPage(): int
    {
        return $this->total === 0 ? 0 : (($this->page - 1) * $this->perPage) + 1;
    }

    /** The last result number on this page (1-based; 0 when empty). */
    public function lastOnPage(): int
    {
        return min($this->total, $this->page * $this->perPage);
    }

    /** A search with no term is never an error - it's the empty page. */
    public function hasQuery(): bool
    {
        return $this->query !== '';
    }
}