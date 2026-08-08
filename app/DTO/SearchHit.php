<?php

declare(strict_types=1);

namespace BookSphere\App\DTO;

/**
 * SearchHit
 *
 * ONE result row of the global search, rendered by the search
 * views. It is provider-neutral and immutable: whatever the
 * SearchProvider returned (raw SQL rows today, an FTS/ES record
 * later) is normalized here into a single display shape the view
 * can never get wrong.
 *
 * The $data payload carries the entity's own keys (a book row, an
 * author row, ...) so the formatter/views keep full access; the
 * flat convenience fields are what every hit type shares
 * (title, subtitle, url, entity label).
 */
final readonly class SearchHit
{
    /**
     * @param string                          $entity   'books' | 'authors' | ...
     * @param string                          $title    the primary display name
     * @param array<string, mixed>            $data     the entity row data
     * @param string|null                     $subtitle secondary line (author, publisher...)
     * @param string|null                     $url      the "open" link of the hit
     */
    public function __construct(
        public string $entity,
        public string $title,
        public array $data = [],
        public ?string $subtitle = null,
        public ?string $url = null,
    ) {}
}