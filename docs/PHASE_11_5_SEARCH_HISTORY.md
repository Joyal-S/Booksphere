# PHASE 11.5 — Search History

**Status: complete** — 47/47 SearchHistoryTest checks, 94/94 SearchTest
regression, all other suites green.

## What was built

A private, per-user **search history**: every real (full-page) search
signed-in users run on the search page is saved to a new `search_history`
table, shown back to them in a card under the search toolbar, and lets
them **re-run a past search** (form repopulated with its term, scope and
filters — no page reload), **delete one entry** or **clear the whole
history** — each destructive action behind the app's shared Bootstrap
confirm dialog and CSRF-protected.

Search Analytics / Recommendation / user-behaviour analysis remain a
**later phase (Phase 12)** — nothing here records events, only searches.

### Architecture (the same Phase 11.1 pipeline as the rest of the module)

```
SearchQueryRequest (validated gate)
    -> SearchController::index  (page-only: never the live fetch)
    -> SearchHistoryService::record($request, $userId)
        SearchRepository::pruneHistory / upsertHistory / capHistory
        (UNIQUE(user_id, query, scope, filters) -> UPSERT, no dups)
    -> SearchHistoryService::list($userId)   (decorated rows + restore URL)
    -> search/partials/_history.php + _history-modal.php
    -> search.js initHistory()              (restore + confirm, no reload)
DELETE /search/history            -> clearHistory   (delete all)
DELETE /search/history/{id}       -> deleteHistory  (delete one)
```

## Files created

| File | Role |
|---|---|
| `database/migrations/0033_create_search_history_table.php` | The table: owner (FK → users, cascade), query, scope, filters JSON, created_at/last_used_at/count + `UNIQUE(user_id, query, scope, filters)` (the dedupe is **structural**) + `(user_id, last_used_at DESC)` index. |
| `app/Services/SearchHistoryService.php` | The orchestrator: `enabled()`, `limit()`, `ttlDays()`, `record()` (prune → upsert → cap), `list()`, `remove()`, `clear()`. |
| `app/Views/search/partials/_history.php` | The history card: restore links (+ data-q/scope/filters), per-row delete + clear-all forms (`_method=DELETE`, `_token`), empty state. |
| `app/Views/search/partials/_history-modal.php` | The ONE shared Bootstrap confirm dialog for delete-one/clear-all (JS polish; the inline forms are the no-JS path). |
| `tests/SearchHistoryTest.php` | 47 checks: gate, dedupe/upsert, partition key, list decoration + restore round-trip, cap + TTL, ownership, controller page/writes, router, probes. |

## Files modified

| File | Role |
|---|---|
| `app/Repositories/SearchRepository.php` | `upsertHistory`, `historyRows`, `pruneHistory`, `capHistory`, `deleteHistoryEntry`, `clearHistory` — all owner-scoped, prepared/bound. |
| `app/Controllers/SearchController.php` | ctor + `$history`; `index()` records on the page path (never fetch, never pagination, only ok results) and passes `$history`; new `deleteHistory`/`clearHistory` (dual answer: JSON for fetch, flash+redirect no-JS). |
| `routes/web.php` | `SearchHistoryService` wiring; `DELETE /search/history` + `DELETE /search/history/{id}` (Auth + CSRF, `_method` override). |
| `app/Views/search/index.php` | Renders `_history.php` (when enabled) + the modal on the search page. |
| `public/assets/js/search.js` | `initHistory()` — delegated `data-history-search` restore (dispatches a `search.restore` event on the search form), confirm-modal bind for delete/clear; `initSearchForm` listens for `search.restore`, repopulates the LIVE form and re-runs `fetchResults()`. |
| `public/assets/css/search.css` | Card + list + item + meta + confirm styling (design tokens, responsive, 44px touch targets). |
| `tests/SearchTest.php` | Harness wires the new `$history` service (94 checks still green). |

## Storage policy (Task 2–3: auto-record, no empty/dup, timestamps, frequency)

- **What is saved:** every VALID request with a term (`hasQuery()`) that
  arrived as a real full-page GET /search (page 1, result ok). The live
  fetch `/search` (typing preview), suggestions, invalid requests, empty
  terms and guests are never saved.
- **Dedup by schema:** `UNIQUE(user_id, query, scope, filters)` + the
  UPSERT make a repeat (even back-to-back, even re-cased) bump one row's
  `count` instead of inserting a duplicate. Scope and the active filter
  map are part of the key, so "harry" and "harry + status=published" are
  distinct entries.
- **Frequency timing:** `record()` prunes expired rows (`last_used_at` <
  now − `ttl_days`), UPSERTs, then caps the stored rows to the NEWEST
  `limit` — so a user's storage is bounded every write.
- **Restore URL:** `list()` decorates each row with the exact search page
  URL (query + scope + stored filters) via `SearchService::queryString`,
  so a clickable re-run reproduces the search and is shareable.

## Rules honoured (validation / security / authorization / a11y)

- The inbound gate is the SAME validated `SearchQueryRequest`; nothing raw
  is stored; filters are the request's own whitelisted map (JSON), never
  user SQL.
- Every delete/clear is **owner-scoped** in SQL (`WHERE user_id = ?` + id);
  CSRF (`_token`) + `AuthMiddleware` on both routes.
- `search.js` builds the confirm dialog's text from the clicked row,
  enables `modal()`, submits the PENDING (native) form on confirm — the
  no-JS path is the inline form itself.
- Keyboard: the confirm modal is a real Bootstrap dialog; restore links
  are real `<a href>` (focusable, Enter works with no JS).

## Performance

- The unique key + `(user_id, last_used_at)` index make list/prune/cap
  single-index read/writes; reads and upserts are bound statements; the
  cap and prune each run in one DELETE.

## Tests run

- SearchHistoryTest: **47/47** (dedicated search-history suite)
- SearchTest: **94/94** (controller wiring + suggestion sections intact)
- Full regression: Auth 73, GoogleBooksSearch 57 PASS, and every other
  suite (Browse, Email, Follow, Landing, Library, Notifications x3,
  Personalization, Recommendation, Review x2) reported **Failed: 0** —
  the sweep was green across the board.

## Readiness for Phase 11.6

- `search_history` is the module's own table with the module's own repo
  methods — Phase 12 analytics can extend the same schema/service
  pattern without touching the search pipeline.
- The `history.*` config block (search.php) is the single source of cap
  and TTL; no operator code changes needed to tune them.
- No placeholders, no re-entry points left: the history services, routes,
  views and tests are wired like every other module.