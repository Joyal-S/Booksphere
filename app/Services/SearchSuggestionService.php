<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\Builders\SearchQueryBuilder;
use BookSphere\App\Exceptions\SearchException;
use BookSphere\App\Requests\SearchSuggestRequest;

/**
 * SearchSuggestionService
 *
 * The Phase 11.4 orchestrator of live suggestions - the provider-
 * neutral complement of SearchService. The provider.fetch() a
 * candidate POOL (Limit-capped rows from every source), this class
 * decides what the user sees:
 *
 *     SearchSuggestRequest (validated gate)
 *       -> SearchQueryBuilder::buildSuggest() (neutral spec)
 *       -> SearchProvider::suggest() (raw typed candidates)
 *       -> rank -> dedupe -> limit -> serialize (JSON rows)
 *
 * Ranking (local relevance, Task 7):
 *     exact  (label == term, case-insensitive)        -> 0
 *     prefix (label STARTS WITH term)                 -> 1
 *     partial(label CONTAINS term)                    -> 2
 *     word   (a multi-word token matches a label word)-> 3
 *     other                                            -> 4
 * Ties fall back to source priority (book > author >
 * category > publisher) then the display label, so 'ha' puts a
 * Harry Potter volume above a partial publisher match.
 *
 * Dedupe: one row per (type + lower(label)) - a title appearing in
 * both books and authors cannot steal two slots.
 *
 * The SAME failure philosophy as SearchService: a provider error or
 * a blown time budget answers an EMPTY suggestion list (ok still
 * true) - suggestions are enhancement, never an error page. The
 * "hard" failures (disabled, 422, 429) belong to the controller.
 */
final class SearchSuggestionService
{
    /**
     * Per-request memo of candidate pools (term -> pool). A type-ahead
     * debounce can land two identical terms in one request; the second
     * one reuses the first pool instead of replaying four queries.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $poolCache = [];

    public function __construct(
        private readonly SearchProvider $provider,
        private readonly SearchQueryBuilder $builder,
        private readonly array $config,
    ) {}

    /**
     * Whether suggestions are switched on (both the module master
     * switch AND the suggestions sub-switch must be on).
     */
    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true)
            && (bool) ($this->config['suggestions']['enabled'] ?? true);
    }

    /**
     * Answer the JSON-ready suggestion payload for a validated
     * request. Never throws: provider/timeout failures degrade to an
     * empty list (the controller keeps agreeing JSON either way).
     *
     * @return array{ok: bool, term: string, suggestions: array<int, array{type: string, label: string, subtitle: string|null, url: string}>, total: int}
     */
    public function suggest(SearchSuggestRequest $request): array
    {
        $term = $request->term();

        if (!$request->valid() || $term === '') {
            return ['ok' => false, 'term' => $term, 'suggestions' => [], 'total' => 0];
        }

        $limit = max(1, (int) ($this->config['suggestions']['limit'] ?? 8));

        $pool = $this->pool($term, $limit);

        if ($pool === null) {
            return ['ok' => true, 'term' => $term, 'suggestions' => [], 'total' => 0];
        }

        $rows = array_slice($this->format($this->dedupe($this->rank($pool, $term))), 0, $limit);

        return ['ok' => true, 'term' => $term, 'suggestions' => $rows, 'total' => count($rows)];
    }

    /**
     * Fetch (and memoize) the raw candidate pool for a term.
     *
     * @return array<int, array<string, mixed>>|null null on failure
     */
    private function pool(string $term, int $limit): ?array
    {
        if (isset($this->poolCache[$term])) {
            return $this->poolCache[$term];
        }

        // The pool is deliberately deeper than the final cap: ranking
        // has 2-3 rows per source even when exact/prefix dominate.
        $spec   = $this->builder->buildSuggest($term, $limit * 3);
        $budget = max(0.05, (float) ($this->config['performance']['timeout_seconds'] ?? 5.0));
        $start  = microtime(true);

        try {
            $pool = $this->provider->suggest($spec);
        } catch (SearchException $e) {
            $this->report($e);

            return null;
        }

        if ((microtime(true) - $start) > $budget) {
            return null;
        }

        $this->poolCache[$term] = $pool;

        return $pool;
    }

    /**
     * Score each candidate by its match tier (lower is more relevant);
     * ties fall back to source priority then the lowercase label.
     *
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array<string, mixed>>
     */
    private function rank(array $candidates, string $term): array
    {
        $priority = ['book' => 0, 'author' => 1, 'category' => 2, 'publisher' => 3];
        $needle   = mb_strtolower($term);

        usort($candidates, function (array $a, array $b) use ($needle, $priority): int {
            $delta = $this->tier((string) $a['label'], $needle) <=> $this->tier((string) $b['label'], $needle);

            if ($delta !== 0) {
                return $delta;
            }

            $delta = ($priority[$a['type']] ?? 9) <=> ($priority[$b['type']] ?? 9);

            if ($delta !== 0) {
                return $delta;
            }

            return mb_strtolower((string) $a['label']) <=> mb_strtolower((string) $b['label']);
        });

        return $candidates;
    }

    /**
     * One row per (type, lower(label)): a duplicate keeps the row
     * that ranked first.
     *
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array<string, mixed>>
     */
    private function dedupe(array $candidates): array
    {
        $seen  = [];
        $kept  = [];

        foreach ($candidates as $candidate) {
            $key = $candidate['type'] . '|' . mb_strtolower((string) $candidate['label']);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $kept[]     = $candidate;
        }

        return $kept;
    }

    /**
     * Serialize the ranked rows into the JSON shape the autocomplete
     * renders (subtitle null when empty).
     *
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array{type: string, label: string, subtitle: string|null, url: string}>
     */
    private function format(array $candidates): array
    {
        return array_map(static function (array $candidate): array {
            $subtitle = (string) ($candidate['subtitle'] ?? '');

            return [
                'type'     => (string) $candidate['type'],
                'label'    => (string) $candidate['label'],
                'subtitle' => $subtitle !== '' ? $subtitle : null,
                'url'      => (string) $candidate['url'],
            ];
        }, $candidates);
    }

    /**
     * The match tier of one label against the lowercased term.
     */
    private function tier(string $label, string $needle): int
    {
        $label = mb_strtolower($label);

        if ($label === $needle) {
            return 0;
        }

        if (str_starts_with($label, $needle)) {
            return 1;
        }

        if (str_contains($label, $needle)) {
            return 2;
        }

        // Multi-word fallback: any token of the term matches anywhere
        // in the label ("potter" finds "Harry Potter and the…").
        foreach (preg_split('/\s+/u', $needle) ?: [] as $word) {
            if ($word !== '' && str_contains($label, $word)) {
                return 3;
            }
        }

        return 4;
    }

    /**
     * Log a suggestion failure once.
     */
    private function report(SearchException $e): void
    {
        error_log('[search.suggest] ' . $e->reason() . ': ' . $e->getMessage());
    }
}