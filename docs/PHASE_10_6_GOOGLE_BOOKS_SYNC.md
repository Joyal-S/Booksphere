# Phase 10.6 - Google Books Metadata Sync

> **Status:** Implemented and fully tested. Imported Google Books records
> can be refreshed against the provider - ONE book at a time, per-card,
> per-selection or for the whole catalogue - with change detection that
> writes ONLY the fields that actually differ, and a per-book failure
> policy that never lets one bad record take down a run. The app's own
> data (ratings from its reviews, status, ISBN, google id, cover) is
> never touched.
> **Scope discipline:** the synchronization is operator-driven (single,
> bulk, sync-all - all within a request, no background worker). Scheduled
> / cron sync is deliberately out of scope (Phase 10.7+). No new
> framework or dependency: the run is an ORCHESTRATOR over the Phase
> 10.2/10.3/10.4 decisions already proven (the same transport, the same
> mapping, the same cover pipeline, the same SSE report plumbing).

---

## What was built

| Layer | Class / file | Responsibility |
|---|---|---|
| Service | `GoogleBooksSyncService` | orchestrates the per-book pipeline: local lookup (one `metadataFor()` query), fetch via the same cache/breaker path, field-by-field diff, narrow whitelisted write, cover refresh, stamp. Reports per-record + aggregate. |
| DTO | `SyncReport` | the audit of one run: updated / unchanged / failed / skipped / covers_updated / elapsed + one slim row per book + `toArray()` + human `summary()` line. |
| Controller | `GoogleBooksController::sync/ syncBulk/ syncAll` | dual answer: SSE (fetch callers) + flash + redirect (no-JS forms), same streaming plumbing as bulk import. |
| Repo | `Book` (`syncOf`, `metadataFor`, `updateMetadata`, `updateSynced`, `replaceAuthors`, `replaceCategories`, `authorsFor`, `categoriesFor`, `importedBooks`) | the slim data surface the run needs - one whitelist, no new tables. |
| Config | `config/google_books.php` (`sync` section) | `enabled` flag + per-field toggles (`sync.fields.*`), `max_batch`, `batch_size`. |
| Routes | `routes/web.php` | `POST /admin/google-books/sync`, `/sync-bulk`, `/sync-all` behind Admin + CSRF middleware. |
| Views | `google-books.php`, `_card.php`, `_summary-modal.php` | sync-status chips on imported cards, "Sync now" per card, "Sync providers" bulk submit, "Sync all" form, sync-aware report modal. |
| Assets | `google-books.js` / `google-books.css` | sync form delegation, bulk routing fix (formaction), summary-mode toggle, sync chips. |
| Migration | `0032_add_sync_columns_to_books.php` | `synced_at`, `sync_status`, `sync_message` on `books`. |
| Tests | `tests/GoogleBooksSyncTest.php` | 87 checks, fully offline (stubbed transport + subprocess probes). |

## The core principle: sync never disagrees with import

`BookImportService::providerMetadata()` is the ONE field map both paths
share: the importer writes a `google_book_id` row from it, and the sync
runs the SAME map against the same row. A sync can therefore never
decide a field "changed" in a way the importer would have written
differently. The diff runs field by field:

```
title subtitle description publisher published_year language
page_count preview_link provider_rating provider_ratings_count
authors (name list) categories (name list) cover_image
```

and only fields whose local value differs (after `'' == null` and
numeric normalization) land in `$changes` - the narrow whitelist
(`updateMetadata`) is fed exactly the changed columns, never a full-row
write. Relations are compared by NAME list (resolved from the join
tables, not the row) and replaced only when the names actually moved.

Every sync could disable any metadata field globally via the config
rules (`sync.fields.*`) - the "configurable synchronization rules"
surface of Task 3.

## NEVER written

`books.average_rating` + `ratings_count` (derived from the app's OWN
reviews), `status`, `isbn`, `google_book_id`, `cover_image` (the cover
pipeline owns it) and every user-generated table.

## The pipeline per book

```
local map (ONE metadataFor() query for the whole id set)
  foreach id:
    1. no local imported row?  -> skipped, NEVER fetched
    2. volumes.volume(id)      -> same cache/breaker path as import.
                                  NEW (Phase 10.7): the sync path
                                  bypasses the fresh-cache window
                                  (refresh:true) so it detects
                                  provider-side changes; the breaker
                                  still guards live calls
    3. record unusable?        -> failed (invalid_record), stamped
                                  'failed' on the row
    4. diff(book, remote)      -> the changed set
    5. cover (syncCover)       -> attach() only when the provider URL
                                  moved, the state is '' or 'none'
                                  (zero network otherwise)
    6. writes                  -> updateMetadata (changed cols) +
                                  replaceAuthors / replaceCategories
                                  when names moved (relation changes
                                  COUNT toward the stamp thanks to a
                                  Phase 10.7 fix)
    7. stamp                   -> updateSynced(updated | in_sync |
                                  failed, message)
  progress -> $advance callback (cancellation: report remainder
              as skipped)
```

Every step that can fail only fails THAT book - one bad record is
logged, counted, stamped 'failed', and the run moves on. There is NO
batch-wide transaction; every write is its own statement.

## The controller's two answers (SSE / redirect)

**Fetch caller** - `streamBulk()`-style events(`progress` per book:
processed/total/updated/unchanged/failed/skipped + the book just
handled; `summary` final with `SyncReport::toArray()`).

**No-JS form** - a plain POST runs the sync and flashes `Sync finished:
N checked, U updated.` (+ a warning when anything failed) then redirects.

## Config (`sync` section)

| key | env | default |
|---|---|---|
| `enabled` | `GOOGLE_BOOKS_SYNC_ENABLED` | true |
| `max_batch` | `GOOGLE_BOOKS_SYNC_MAX_BATCH` | 200 |
| `batch_size` | `GOOGLE_BOOKS_SYNC_BATCH_SIZE` | 25 (report cadence) |
| `fields.*` | (none) | all true |

## Running the suite

```bash
php tests/GoogleBooksSyncTest.php  # 87 checks
```

Fully offline: a stubbed provider answers the volume lookups, and the
controller probes run in dedicated subprocesses with a throwaway
(`database/gb_sync_test.db`).

## Verified behaviors (87 checks)

- Disabled module: sync answers 503, never writes.
- Single: unchanged (in_sync, "Up to date."), changed (whitelist write
  the small-moleer fields + correct reason), failed fetch (the row is
  stamp `failed` + the run continues), title-less (`invalid_record`),
  cover-only (downloads happen only when URL/state changed; the cache
  keeps it at zero).
- **Relation-only change → the row is stamped `updated`, and the report
  counts it** (Phase 10.7 fix - previously only column changes counted).
- Bulk / sync-all: streaming progress + summary; cancellation (skipped
  remainder); empty selection 422.
- Every per-field toggle in config.sync. fields turns that field from
  synced to untouched; the channel isn't read twice.
- stamp failed when a fetch/record fails (best-effort, logged).

## Phase 10.7 audit changes folded in

- Fixed the H1: relation-only writes changed to the "in sync" stamp.
- Fixed the H2: sync's provider fetch bypasses the volume cache
  (`volume($id, refresh: true)`) so a sync actually detects provider
  edits instead of re-consuming the same cached copy the import wrote.
- Escaped the provider title in the card's visually-hidden label.
- Fixed the JS routing bug where the "Sync providers" bulk button posted
  to the IMPORT endpoint (`streamRun(bulkForm.action,...)`) instead of
  `/sync-bulk` (now uses `submitter.formAction`).
- Wired `applySummaryMode(runMode)` so the report dialog shows the
  sync (updated/unchanged) group after sync runs.

## Out of scope

- Scheduled / background sync (no cron, no worker - Task 4's "later").
- A sync scheduler UI.
- Exporting a stored report.

## Files added this phase

```
app/Services/GoogleBooksSyncService.php
app/DTO/SyncReport.php
database/migrations/000003_add_sync_columns_to_books.php
tests/GoogleBooksSyncTest.php
docs/PHASE_10_6_GOOGLE_BOOKS_SYNC.md        (this file)
```

**Modified** (additive): `config/google_books.php` (`sync` section),
`GoogleBooksController` (sync / syncBulk / syncAll + streamBulk sharing),
`routes/web.php` (three POST routes + shared volume), `Book` (sync
queries/writes), `BookImportService` (exposed `providerMetadata` +
`authorIds`/`categoryIds`), `google-books.php` + `_card.php` +
`_summary-modal.php` (sync chips / button / modal), `google-books.js` /
`google-books.css` (sync features + Phase 10.7 fixes).