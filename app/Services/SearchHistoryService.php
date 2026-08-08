<?php

declare(strict_types=1);

namespace BookSphere\App\Services;

use BookSphere\App\Repositories\SearchRepository;
use BookSphere\App\Requests\SearchQueryRequest;

/**
 * SearchHistoryService
 *
 * The Phase 11.5 orchestrator of a user's private search history -
 * the middleware between the repository's search_history table and
 * the search page. Deliberately thin: the data-access lives in
 * SearchRepository (the module's one SQL layer), the storage policy
 * (what gets saved, how long, how many) lives HERE in the service,
 * exactly like the suggestions policy lives in SearchSuggestionService.
 *
 * Responsibilities:
 *     - enabled()   the history gate: module master switch AND the
 *                   history sub-switch from config/search.php must
 *                   both be on, mirroring SearchSuggestionService
 *     - list()      the user's recent rows, newest use first, each
 *                   decorated with a RESTORE URL (the exact search
 *                   page URL of that past search - query + scope +
 *                   filters - so the "re-run a past search" link is a
 *                   plain <a href>, shareable and no-JS friendly)
 *     - record()    the WRITE path the SearchController::index calls
 *                   on a real, full-page search; it prunes the
 *                   user's expired rows, UPSERTS the search key and
 *                   caps the stored count at config limit
 *     - remove()    delete ONE row, owner-scoped (a row belonging to
 *                   another user is untouched)
 *     - clear()     delete EVERY row of one user ("Clear search
 *                   history")
 *
 * Storage policy ("the exact search the user ran" - Term 3):
 *     - the UPSERT KEY is (user_id, query, scope, filters): running
 *       the same search twice - even back-to-back - updates the
 *       existing row's last_used_at and bumps its count instead of
 *       inserting a duplicate, so "prevent duplicate consecutive
 *       entries" is enforced by the table's unique index, not by
 *       code that can race.
 *     - filters are stored as the JSON of the WHITELISTED filter map
 *       the request gate already normalized (status/language/min_
 *       rating/year_from/year_to/category_id/author_id/publisher) -
 *       never raw request input.
 *     - the cap (config history.limit) and the TTL (history.ttl_days)
 *       are both applied inside record(), so every write leaves the
 *       user's storage bounded and current.
 *
 * A search that was never valid, or that failed, is NOT recorded:
 * the controller only calls record() on an ok result, and this class
 * double-checks the same gates (defense in depth - a caller bug must
 * not persist junk).
 */
final class SearchHistoryService
{
    public function __construct(
        private readonly SearchRepository $repository,
        private readonly array $config,
    ) {}

    /** Whether search-history is switched on (module + sub-switch). */
    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true)
            && (bool) ($this->config['history']['enabled'] ?? true);
    }

    /** The stored-per-user cap (config.history.limit). */
    public function limit(): int
    {
        return max(1, (int) ($this->config['history']['limit'] ?? 12));
    }

    /** How many days a stored search may live before pruning. */
    public function ttlDays(): int
    {
        return max(0, (int) ($this->config['history']['ttl_days'] ?? 90));
    }

    /**
     * Record one completed search of a user (UPSERT + prune + cap).
     *
     * Only a VALID request with a term is ever stored; the filters are
     * the request's own normalized whitelisted map (already JSON-
     * safe), so a persisted row can be restored exactly: the service
     * list() rebuilds its URL from the same values.
     *
     * @param int $userId the authenticated user id (>= 1)
     */
    public function record(SearchQueryRequest $request, int $userId): void
    {
        if (!$this->enabled() || $userId < 1) {
            return;
        }

        if (!$request->valid() || !$request->hasQuery()) {
            return;
        }

        $now      = gmdate('Y-m-d\TH:i:s\Z');
        $limit    = $this->limit();
        $ttlDays  = $this->ttlDays();

        // Prune the user's expired rows first (the TTL sweep).
        if ($ttlDays > 0) {
            $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - ($ttlDays * 86400));
            $this->repository->pruneHistory($userId, $cutoff);
        }

        // The UPSERT: a brand-new key inserts (count 1), a repeat
        // updates last_used_at + bumps count (consecutive duplicates
        // become one row with a rising frequency).
        $this->repository->upsertHistory(
            $userId,
            $request->term(),
            $request->scope(),
            (string) json_encode($request->filters()),
            $now,
        );

        // Bound the total: drop the overflow beyond the cap.
        $this->repository->capHistory($userId, $limit);
    }

    /**
     * The user's recent searches, newest first, decorated for the UI:
     * the ready rem URL, the relative "last used" label and the
     * stored filter map (so the view can name the filters it shows).
     *
     * @param int|null $limit overrides the configured cap (tests)
     * @return array<int, array{id: int, query: string, scope: string, filters: array<string, mixed>, count: int, lastUsed: string, lastUsedLabel: string, createdAtLabel: string, url: string}>
     */
    public function list(int $userId, ?int $limit = null): array
    {
        if ($userId < 1 || !$this->enabled()) {
            return [];
        }

        $rows = $this->repository->historyRows($userId, $limit ?? $this->limit());

        return array_map(
            fn (array $row): array => $this->decorate($row),
            $rows,
        );
    }

    /**
     * Delete ONE stored search - owner-scoped: a row id that does not
     * belong to the given user removes nothing.
     */
    public function remove(int $id, int $userId): bool
    {
        return $id > 0 && $userId > 0 && $this->repository->deleteHistoryEntry($id, $userId);
    }

    /**
     * Delete every stored search of one user (the "Clear search
     * history" action, owner-scoped to the session user).
     */
    public function clear(int $userId): int
    {
        return $userId > 0 ? $this->repository->clearHistory($userId) : 0;
    }

    /**
     * The display row of one stored search: the restore URL is built
     * by SearchService::queryString from the SAME q/scope/filters the
     * module would accept - so a "run this again" link performs the
     * exact search that was originally stored, and never a
     * hand-assembled query string.
     *
     * The relative time labels reuse the notification cards' global
     * helper (format_notification_time).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decorate(array $row): array
    {
        $query   = (string) ($row['query'] ?? '');
        $scope   = (string) ($row['scope'] ?? 'books');
        $filters = $this->decodeFilters((string) ($row['filters'] ?? '{}'));

        return [
            'id'             => (int) $row['id'],
            'query'          => $query,
            'scope'          => $scope,
            'filters'        => $filters,
            'count'          => max(1, (int) ($row['count'] ?? 1)),
            'lastUsedAt'     => (string) ($row['last_used_at'] ?? ''),
            'lastUsedLabel'  => format_notification_time((string) ($row['last_used_at'] ?? '')),
            'createdAtLabel' => format_notification_time((string) ($row['created_at'] ?? '')),
            'url'            => SearchService::queryString(['q' => $query, 'scope' => $scope] + $filters),
        ];
    }

    /**
     * Merge a stored filters JSON safely: always an array, unknown
     * keys dropped - a tampered row can never inject keys the search
     * module does not know.
     *
     * @return array<string, mixed>
     */
    private function decodeFilters(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}