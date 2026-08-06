<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\DTO\ProviderBookDTO;
use BookSphere\App\Exceptions\GoogleBooksException;

/**
 * GoogleBooksProvider
 *
 * The MAPPING layer of the Google Books module (Phase 10.2): it owns
 * the domain knowledge of the Google Books payload shape and turns
 * raw API responses into provider-neutral ProviderBookDTO records.
 *
 * Separation of concerns (Phase 10.1 architecture):
 *     - GoogleBooksClient     : HTTP transport, retries, exceptions
 *     - GoogleBooksProvider   : field extraction, ISBN validation,
 *                               thumbnail selection, DTO construction
 *     - GoogleBooksService    : caching, circuit breaker, orchestration
 *     - GoogleBooksController : request handling, views, JSON
 *
 * The provider is PURE by design - no cache, no database, no side
 * effects. Every method is a function of its input. That keeps the
 * mapping logic unit-testable with canned payloads and lets the
 * service re-map cached raw payloads without any cost.
 *
 * Graceful degradation (Phase 10.1): a record may lack authors,
 * categories, an ISBN or a cover and still be returned - only a
 * missing title drops it (GoogleBooksException::invalidResponse is
 * NOT used for that; the record is simply filtered out by the
 * service).
 *
 * ISBN policy: identifiers are validated with their checksums (both
 * ISBN-10 and ISBN-13). Invalid identifiers are dropped from the
 * record - never propagated - and a record whose ONLY identifiers are
 * invalid is still returned (the title is the identity, the ISBN is
 * metadata).
 */
class GoogleBooksProvider
{
    /**
     * Map ONE raw Google Books volume payload to a ProviderBookDTO.
     *
     * @param array<string, mixed> $volume
     */
    public function mapVolume(array $volume): ?ProviderBookDTO
    {
        $info     = is_array($volume['volumeInfo'] ?? null) ? $volume['volumeInfo'] : [];
        $external = is_string($volume['id'] ?? null) ? $volume['id'] : '';

        $title = trim((string) ($info['title'] ?? ''));

        if ($external === '' || $title === '') {
            return null;
        }

        [$isbn10, $isbn13] = $this->validatedIsbns($info['industryIdentifiers'] ?? null);

        $subtitle = isset($info['subtitle']) && is_string($info['subtitle'])
            ? trim($info['subtitle'])
            : null;

        $publishedDate = isset($info['publishedDate']) && is_string($info['publishedDate'])
            ? trim($info['publishedDate'])
            : null;

        return new ProviderBookDTO(
            externalId:     $external,
            title:          $title,
            subtitle:       $subtitle !== '' ? $subtitle : null,
            authors:        $this->stringList($info['authors'] ?? null),
            categories:     $this->stringList($info['categories'] ?? null),
            description:    $this->plainText($info['description'] ?? null),
            publisher:      $this->optionalString($info['publisher'] ?? null),
            publishedDate:  $publishedDate !== '' ? $publishedDate : null,
            publishedYear:  $this->yearFrom($publishedDate),
            language:       $this->optionalString($info['language'] ?? null),
            pageCount:      $this->positiveInt($info['pageCount'] ?? null),
            isbn10:         $isbn10,
            isbn13:         $isbn13,
            thumbnail:      $this->thumbnailFor($info['imageLinks'] ?? null),
            previewLink:    $this->optionalString($info['previewLink'] ?? null),
            infoLink:       $this->optionalString($info['infoLink'] ?? null),
            averageRating:  $this->ratingOf($info['averageRating'] ?? null),
            ratingsCount:   $this->positiveInt($info['ratingsCount'] ?? null),
            provider:       'google_books',
        );
    }

    /**
     * Map a page of raw volumes to DTO records, dropping anything that
     * does not map (missing id/title). A page that maps to nothing
     * stays an empty list - the caller decides how to present it.
     *
     * @param array<int, array<string, mixed>> $volumes
     * @return array<int, ProviderBookDTO>
     */
    public function mapVolumes(array $volumes): array
    {
        $mapped = [];

        foreach ($volumes as $volume) {
            $record = $this->mapVolume($volume);

            if ($record !== null) {
                $mapped[] = $record;
            }
        }

        return $mapped;
    }

    /**
     * The cover URL for this volume, or null. The configured image size
     * is mapped to the Google Books zoom level: "thumbnail" asks for
     * zoom=1 (the default small thumbnail), "medium" for zoom=2, and
     * the raw "small" / "thumbnail" family is used as-is.
     *
     * @param array<string, mixed>|null $imageLinks
     */
    private function thumbnailFor(?array $imageLinks): ?string
    {
        if (!is_array($imageLinks)) {
            return null;
        }

        $size  = (string) ($this->config['images']['size'] ?? 'thumbnail');
        $candidates = [
            'medium'    => $imageLinks['medium'] ?? null,
            'thumbnail' => $imageLinks['thumbnail'] ?? null,
            'small'     => $imageLinks['small'] ?? null,
        ];

        $url = $candidates[$size] ?? ($candidates['thumbnail'] ?? $candidates['medium'] ?? $candidates['small'] ?? null);

        if (!is_string($url) || $url === '') {
            return null;
        }

        return $this->zoom($url, $size === 'medium' ? 2 : 1);
    }

    /**
     * Rewrite a Google Books cover URL to the requested zoom level
     * (zoom=1 is the small thumbnail, zoom=2 the medium size).
     */
    private function zoom(string $url, int $zoom): string
    {
        return preg_replace('/zoom=\d+/', "zoom={$zoom}", $url, 1) ?? $url;
    }

    /**
     * The validated ISBN-10 / ISBN-13 pair of this volume, or nulls.
     * Identifiers that fail their checksum are dropped silently.
     *
     * @param array<string, mixed>|null $identifiers  industryIdentifiers list
     * @return array{0: ?string, 1: ?string} [isbn10, isbn13]
     */
    private function validatedIsbns(?array $identifiers): array
    {
        $isbn10 = null;
        $isbn13 = null;

        if (!is_array($identifiers)) {
            return [null, null];
        }

        foreach ($identifiers as $entry) {
            if (!is_array($entry) || !is_string($entry['identifier'] ?? null)) {
                continue;
            }

            $value = trim($entry['identifier']);
            $type  = is_string($entry['type'] ?? null) ? $entry['type'] : '';

            if ($type === 'ISBN_13' && $isbn13 === null && $this->validIsbn13($value)) {
                $isbn13 = $value;
            } elseif ($type === 'ISBN_10' && $isbn10 === null && $this->validIsbn10($value)) {
                $isbn10 = $value;
            }
        }

        return [$isbn10, $isbn13];
    }

    /**
     * The ISBN-13 checksum test (modulo 10, alternating 1/3 weights).
     */
    public function validIsbn13(string $isbn): bool
    {
        $digits = preg_replace('/[^0-9]/', '', $isbn);

        if (!is_string($digits) || strlen($digits) !== 13) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $digits[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - ($sum % 10)) % 10 === (int) $digits[12];
    }

    /**
     * The ISBN-10 checksum test (modulo 11; 'X' is a valid check digit).
     */
    public function validIsbn10(string $isbn): bool
    {
        $digits = preg_replace('/[^0-9Xx]/', '', $isbn);

        if (!is_string($digits) || strlen($digits) !== 10) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $digits[$i] * (10 - $i);
        }

        $check = strtoupper($digits[9]) === 'X' ? 10 : (int) $digits[9];

        return $sum + $check === 0 || ($sum + $check) % 11 === 0;
    }

    /**
     * A list of strings from a payload field (authors, categories),
     * trimmed and deduplicated, keeping order.
     *
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $list = [];

        foreach ($value as $entry) {
            if (is_string($entry) && ($item = trim($entry)) !== '' && !in_array($item, $list, true)) {
                $list[] = $item;
            }
        }

        return $list;
    }

    /**
     * The trimmed string value, or null when empty / not a string.
     */
    private function optionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * A positive integer field (pageCount, ratingsCount), or null.
     */
    private function positiveInt(mixed $value): ?int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            return null;
        }

        $number = (int) $value;

        return $number > 0 ? $number : null;
    }

    /**
     * A 0-5 rating, or null when out of range.
     */
    private function ratingOf(mixed $value): ?float
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return null;
        }

        $rating = (float) $value;

        return $rating >= 0 && $rating <= 5 ? $rating : null;
    }

    /**
     * The description as plain text: HTML entities decoded, tags
     * stripped, whitespace collapsed, truncated for the card view.
     */
    private function plainText(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $text = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));

        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > 480 ? mb_substr($text, 0, 477) . '...' : $text;
    }

    /**
     * The publication year from an ISO-ish date string ("2005",
     * "2005-07-16", "2005-07"), or null when it cannot be read.
     */
    private function yearFrom(?string $date): ?int
    {
        if ($date === null || $date === '') {
            return null;
        }

        $year = (int) substr($date, 0, 4);

        return $year >= 1000 && $year <= 9999 ? $year : null;
    }

    public function __construct(private readonly array $config = [])
    {
    }
}
