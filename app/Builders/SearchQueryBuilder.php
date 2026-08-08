<?php

declare(strict_types=1);

namespace BookSphere\App\Builders;

use BookSphere\App\DTO\SearchQuerySpec;
use BookSphere\App\Requests\SearchQueryRequest;

/**
 * SearchQueryBuilder
 *
 * The Phase 11.2 translator between the VALIDATED request and the
 * provider-neutral spec (the "SearchQueryBuilder" of the Phase 11.1
 * architecture). It holds no validation - SearchQueryRequest has
 * already answered "valid?" - it only NORMALIZES:
 *
 *     - entity      request.scope() -> spec (one of the enabled scopes)
 *     - term        request.term()  -> spec (trimmed, controls stripped)
 *     - words       the term split on whitespace
 *     - exact       whether the single token is an exact-match
 *                   candidate (a bare ISBN / year / language code;
 *                   "exact" today only biases the provider, which
 *                   still matches LIKE - see the provider)
 *     - fields      the config entity's searchable field catalog
 *     - page        already clamped
 *     - perPage     requested size IF whitelisted by config, else
 *                   the config default (a caller can never push a
 *                   raw page size past the LIMIT)
 *     - maxResults  the config ceiling
 *
 * The SAME builder produces both the full-page spec and the live
 * ajax spec, so the two endpoints can never disagree about how a
 * query is normalized. The result feeds SearchProvider.search():
 * the application never builds SQL beyond here.
 */
final class SearchQueryBuilder
{
    public function __construct(private readonly array $config) {}

    public function build(SearchQueryRequest $request): SearchQuerySpec
    {
        $entity  = $request->scope();
        $term    = $request->term();
        $fields  = $this->entityFields($entity);
        $allowed = (array) ($this->config['pagination']['allowed'] ?? [12, 24, 48, 96]);
        $default = max(1, (int) ($this->config['pagination']['per_page'] ?? 24));
        $perPage = in_array($request->perPage(), $allowed, true)
            ? $request->perPage()
            : $default;

        return new SearchQuerySpec(
            entity:     $entity,
            term:       $term,
            words:      $this->wordsOf($term),
            exact:      $this->isExactCandidate($term),
            fields:     $fields,
            sort:       'title',
            filters:    $request->filters(),
            page:       $request->page(),
            perPage:    $perPage,
            maxResults: max(1, (int) ($this->config['query']['max_results'] ?? 500)),
        );
    }

    /**
     * Build the suggestion spec (Phase 11.4): the SAME normalized
     * term tokens drive the type-ahead pool, so a suggestion and a
     * full search can never disagree about how "harry potter" is
     * tokenized. The entity is irrelevant (suggestions span all four
     * sources) - only term/words and the per-source pool size matter.
     *
     * @param int $perSource How many candidates each source may
     *                       contribute (a pool the service later
     *                       ranks, dedupes and slices).
     */
    public function buildSuggest(string $term, int $perSource): SearchQuerySpec
    {
        return new SearchQuerySpec(
            entity:     SearchQuerySpec::SCOPE_BOOKS,
            term:       $term,
            words:      $this->wordsOf($term),
            perPage:    max(1, $perSource),
            maxResults: max(1, (int) ($this->config['query']['max_results'] ?? 500)),
        );
    }

    /**
     * The enabled entity's field catalog from config (['title' =>
     * ['weight'=>10, 'exact'=>true], ...]); [] for unknown/disabled.
     *
     * @return array<string, array<string, mixed>>
     */
    private function entityFields(string $entity): array
    {
        $catalog = (array) ($this->config['entities'] ?? []);

        if (!isset($catalog[$entity]) || !is_array($catalog[$entity])) {
            return [];
        }

        $fields = (array) ($catalog[$entity]['fields'] ?? []);

        return $fields;
    }

    /**
     * Split a term into its search words (whitespace-separated; an
     * ISBN "978-0-593-35342-7" stays ONE token because it has no
     * spaces, and keeps its dashes so the LIKE can match it).
     *
     * @return array<int, string>
     */
    private function wordsOf(string $term): array
    {
        $words = preg_split('/\s+/u', trim($term)) ?: [];

        return array_values(array_filter($words, fn (string $w): bool => $w !== ''));
    }

    /**
     * Whether the bare term is an exact-match candidate (a single
     * token that is a full ISBN, a 4-digit year, or a short
     * language code). The provider still runs a LIKE on it, so this
     * is only a hint that - for a future backend - the term is safe
     * to compare exactly.
     */
    private function isExactCandidate(string $term): bool
    {
        $words = $this->wordsOf($term);

        if (count($words) !== 1) {
            return false;
        }

        $token = (string) $words[0];

        return (bool) preg_match('/^\d[\d-]*\d$|^\d{4}$|^[a-z]{2,3}$/i', $token);
    }
}