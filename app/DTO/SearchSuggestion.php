<?php

declare(strict_types=1);

namespace BookSphere\App\DTO;

/**
 * SearchSuggestion
 *
 * The Phase 11.4 one-row shape of the suggestion endpoint - an
 * immutable value the controller can serialize straight into the
 * JSON array (type, label, subtitle, url) and the autocomplete JS
 * renders directly:
 *
 *     - type      'book' | 'author' | 'category' | 'publisher'
 *                 (drives the icon; future sources extend the set)
 *     - label     the display name (book title, author name, ...)
 *     - subtitle  the secondary line ('Author', a book's subtitle)
 *     - url       where selecting the suggestion navigates - the
 *                 SAME deep links the search cards already use
 *                 (/books/{id}, /books?author_id=, ...)
 */
final readonly class SearchSuggestion
{
    public function __construct(
        public string $type,
        public string $label,
        public ?string $subtitle,
        public string $url,
    ) {}
}