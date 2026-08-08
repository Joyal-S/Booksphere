# Phase 10.5 - Bulk Import

> **Status:** Implemented and fully tested. An admin can tick several
> Google Books results and import them all in ONE operation with a
> live progress stream, a per-book failure policy, and a full report
> dialog - the same single-book importer scales to a batch with no
> import logic duplicated.
> **Scope discipline:** the operator-facing bulk import only. Sync,
> scheduled jobs and export stay out (Phase 10.6). No new framework or
> dependency; the batch is an ORCHESTRATOR over every Phase 10.3/10.4
> decision already proven.

---

## What was built

| Layer | Class / file | Responsibility |
|---|---|---|
| Service | `BulkImportService` | orchestrates ONE batch through the single-book pipeline: pre-check existing ids (one query), fetch via the same cache/breaker path, dedupe+insert per book, covers after commit; reports per-record + aggregate. Plain sync loop - no background worker. |
| DTO | `ImportReport` | the audit of one run: total / imported / duplicates / failed / skipped / elapsed + one slim row per book + `toArray()` export shape + human `summary()` line. |
| Request | `BulkImportRequest` | the POSTed `google_book_id[]` gate: trim, allowlist, de-dup, batch ceiling, the 422 error map. |
| Controller | `GoogleBooksController::importBulk()` | dual answer: Server-Sent Events for fetch callers (`progress` per book + final `summary`), flash + redirect for the no-JavaScript form. |
| Config | `config/google_books.php` (`bulk` section) | `max_batch` and `batch_size` |
| Routes | `routes/web.php` | `POST /admin/google-books/bulk-import` behind Admin + CSRF middleware; ONE shared `BookImportService` feeds single + bulk. |
| Views | `google-books.php` + `._card` / `_summary-modal` partials | bulk toolbar, per-card checkboxes, live progress panel, final report modal |
| Assets | `google-books.js` / `google-books.css` | selection tracking across re-renders, SSE reader, modal + progress painting |
| Tests | `tests/GoogleBooksBulkImportTest.php` | 38 checks, fully offline (stubbed transport + subprocess probes) |

## Why there is no <batch> transaction

The Phase 10.5 spec is explicit: one failing book must never take down
imports it is not a party to. `BookImportService` already wraps each
book in its own all-or-nothing transaction, so the bulk layer keeps
that granularity - "batch" is the REPORTING cadence only (`batch_size`,
the checkpoint at which the progress flush falls and a log marker
lands). There is deliberately **no batch-wide rollback.**

## The request gate (`BulkImportRequest`)

The POSTed list is `google_book_id[]`:

- payload must be an array; each value trimmed, present, ASCII-safe
  (`[letters/digits + ._-]`, ≤ 128 chars - the real Google volume id
  charset) and de-duplicated (a double-submitted id imports once)
- the ceiling comes from the CONFIGURED batch max (`bulk.max_batch`); a
  valid reply with 0 ids is invalid
- answers the same 422 JSON contract as the single-import guard, so the
  fetch client and the no-JS form both get a bounded message
  ("No books were selected for import." / "Select at most N books...")
- `BulkImportService` still re-normalizes defensively (service never
  trusts its caller)

## The pipeline per book

```
existing map (ONE query: books -> importedIds(ids))
  foreach id:
    1. in the map?        -> duplicate, NO provider fetch
    2. volumes->volume()  -> same cache -> breaker -> live -> stale path;
                             a fetch failure fails THIS book only
    3. no usable title?   -> failed (invalid_record)
    4. importer->import() -> its own dedupe (google_id -> ISBNs ->
                             title+author) + transaction
                             failure -> failed (database)
    5. importer attaches  -> Phase 10.4 cover pipeline (opt-in)
    progress snapshot     -> advance callback (cancel here)
```

- **Memory:** only the id array + slim report rows are kept; the
  catalogue is never loaded as a whole.
- **Cancellation:** the `$advance` callback returns whether to continue;
  the controller stops when the client closed the SSE stream, and the
  remainder is reported as "not attempted" (skipped).
- **Checkpoint log:** a `google_books.bulk.checkpoint` marker every
  `batch_size` books, plus started/cancelled/finished markers - the
  logging hooks Phase 10.6's background sync will drive.

## The report (`ImportReport`)

```
total  = ids asked for
imported   rows actually created (catalogue writes)
duplicated records skipped as already-existing (google id / ISBN /
          title+author - the single-book importer's own dedupe; never an
          error)
failed   records that could NOT be imported (fetch / record / DB) -
          each item carries the reason ('already_imported', 'not_found'
          'unexpected', ...)
skipped  duplicates + not-attempted (a cancelled run stops early)
elapsed  wall-clock seconds
results  one slim entry per book: id, status, bookId?, message, reason
```

`status` values reuse the single-book vocabulary (`imported` /
`duplicate`; `failed` is the bulk layer's own addition), so the report
and the card never disagree on a word. `toArray()` is the stable,
future-exportable shape (Phase 10.6's report exporter can serialize a
run without re-reading it). `summary()` is the one-line human text used
by the no-JS flash.

## The controller's two answers (SSE / redirect)

**Fetch caller** (`X-Requested-With: fetch`) - `streamBulk()`:
a `text/event-stream` connection that emits `progress` events (per
processed book: processed/total/imported/duplicated/failed/remaining +
the book just handled) and one final `summary` event carrying
`ImportReport::toArray()`. The JS listens for this, paints the progress
panel, and opens the summary modal at the end. The `advance()` returns
`connection_aborted() === 0`, so a cancelled stream stops the run
early.

Important details baked in:

- the session write-lock is released before a long batch, so other
  admin tabs are never blocked
- the execution ceiling is lifted for the run (`set_time_limit(0)`)
- an initial `:` padding line flushes past buffering proxies so the
  browser sees the connection open immediately
- `JSON_UNESCAPED_SLASHES` keeps URLs readable in the stream

**No-JS form** - a plain POST; the batch runs synchronously, the
`summary()` is flashed (warning when any book failed) and the browser
redirects to the search page, exactly like the single-import flow.

## Views

`google-books.php` grows three regions (all true progressive
enhancement):

1. **Bulk toolbar** - a single `form` whose checkboxes live ON the cards
   via `form="google-books-bulk-form"` (the notification center's
   pattern), so no JavaScript = the form natively collects the checked
   ids. Select-all toggles every ENABLED card on the page; already-
   imported cards show a disabled checkbox mirroring their "In library"
   button.
2. **Progress panel** - `[data-gb-progress]`: bar + running counts +
   the current book + Cancel. Hidden until a run starts.
3. **Summary modal** - `_summary-modal.php`: the report numbers, the
   elapsed time, and a scrolled list of failed records.

The results partial is unchanged: `_card.php` merely gains the
selection checkbox, and the `data-gb-*` hooks keep the markup shared
verbatim between the page and the live JSON endpoint.

The JS (Phase 10.5 section in `google-books.js`):

- the click-selection is a `Set` that survives live-search re-renders
  and page turns (`boundCheckboxes()` re-mirrors it onto fresh cards)
- submitting reads the SSE body as text, stitches `event:`/`data:`
  blocks (CRLF-tolerant), paints `progress`, and on `summary`
  re-renders the grid (imported cards show "In library") and opens the
  modal; a cancelled/aborted stream stops cleanly
- after a successful single import the checkbox is disabled in place,
  so the same book is never double-selected

## Config (`bulk` section of `config/google_books.php`)

| key | env | default |
|---|---|---|
| `max_batch` | `GOOGLE_BOOKS_BULK_MAX_BATCH` | 200 |
| `batch_size` | `GOOGLE_BOOKS_BULK_BATCH_SIZE` | 40 (reporting cadence only) |

## Running the suite

```bash
php tests/GoogleBooksBulkImportTest.php   # 38 Phase 10.5 checks
php tests/GoogleBooksImportTest.php       # 61 Phase 10.3 regression
php tests/GoogleBooksSearchTest.php       # 57 Phase 10.2 regression
php tests/GoogleBooksCoverTest.php        # 58 Phase 10.4 regression
```

Fully offline: the `GoogleBulkStub` answers the volume lookups from
canned ids, and the controller probes run in dedicated subprocesses (a
`redirect()`/`header()` exits the process, so they are proved as a
black box). Each probe gets its own throwaway database
(`database/gb_bulk_probe.db`), deleted at boot with the main suite's
`gb_bulk_test.db`.

## Verified behaviors (38 checks)

- Request gate: empty / garbage + duplicates / over the ceiling / the
  422 error contract.
- Batch: 10 unique books import with exactly 10 provider fetches, the
  report and DB rows agree.
- Idempotency: the same ids again = 10 duplicates and ZERO new fetches.
- Failure isolation: a 404 book fails (reason `not_found`), the batch
  continues and the others commit.
- Cancellation: a mid-run stop reports the remainder as skipped with no
  wasted provider calls, the progress snapshot carries the running
  counts.
- Report: `toArray()` export shape, status success/failed, the human
  summary line.
- Controller (probed): SSE `progress` + `summary` events with the right
  report numbers; 422 for empty and over-limit selections; no-JS form
  flashes `Bulk import finished: 2 imported.` and writes the rows.

## Full regression

All 20 CLI suites pass with the batch responder wired in - 0 failures
(Auth, Browse, Email, Follow, GoogleBooks ×4, Landing, Library,
Notification ×4, Personalization, Recommendation ×3, Review ×2).

## Out of scope (Phase 10.6+)

- Background / scheduled sync of the bulk importer (a big selection run
  is still a single request).
- Exporting / re-running a stored report (`ImportReport::toArray()` is
  already the serialization boundary for it).
- A bulk cover re-download or an admin cache dashboard (the cover
  service's `isFresh/invalidate/stats()` API is public and ready).

## Files added this phase

```
app/Services/BulkImportService.php
app/DTO/ImportReport.php
app/Requests/BulkImportRequest.php
app/Views/admin/google-books/partials/_summary-modal.php
tests/GoogleBooksBulkImportTest.php
docs/PHASE_10_5_BULK_IMPORT.md   (this file)
```

**Modified** (all additive): `config/google_books.php` (`bulk` section),
`GoogleBooksController` (importBulk + streamBulk), `routes/web.php`
(shared importer + `/bulk-import` route), `google-books.php` (toolbar /
progress panel), `_card.php` (selection checkbox), `google-books.js`
(Phase 10.5 section), `google-books.css` (toolbar / progress / modal
styles); the Google Books suites now construct the controller with the
third `BulkImportService` argument.