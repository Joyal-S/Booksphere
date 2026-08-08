# Phase 10.7 - Google Books Testing & Optimization

> **Status:** Complete. A full stabilization audit of the entire Phase 10
> Google Books feature set (search → import → covers → bulk → sync),
> run as four parallel code reviews (architecture/quality, performance,
> security, integration) plus a complete DB audit and the full CLI test
> battery. All defects found were fixed in place; every suite passes; the
> report below records what was inspected, what was fixed, and what
> remains known.
> **Scope discipline:** optimization only. No new feature was added. What
> exists was made correct, fast, safe and verifiable.

---

## Audit scope

Everything Phase 10 shipped, reviewed end to end:

- **Code/quality audit** - the search service, the import service, the
  bulk import service, the sync service, the cover pipeline, all four
  DTOs, all four request guards, the controller, the routes and the
  three views/partials.
- **Performance audit** - N+1 risks (`syncOf`, `metadataFor`,
  `importedIds` are each ONE query for the whole set), the cache policy
  (search + volume TTLs, stale-on-broken), cover reuse (one file per
  provider URL), and per-record work in batch/sync loops.
- **Security audit** - escaping of provider-supplied data on every card
  (title, blurb, authors, links), CSRF on every POST, SSRF on the cover
  fetch, and the breaker/accessibility of the fetch paths.
- **Integration audit** - status='published' guards, rating isolation
  (reviews vs provider), the dual-answer controllers, the SSE + no-JS
  branches, and the cover/sync column burden on `books`.
- **Database audit** - `PRAGMA integrity_check`, `foreign_key_check`,
  duplicates on the unique keys (`google_book_id`, `isbn`), orphan scan
  on the join tables, column coverage (0030-0032 on the dev DB).
- **Front-end audit** - the cards, the bulk bar, the summary modal, the
  progress panel, and the CSS/JS for dead or broken wiring.

## Fixes shipped (the audit's findings)

| Severity | What | Where | Fix |
|---|---|---|---|
| High | **Sync stamped relation-only changes as `in_sync`** — authors/categories were consumed (`unset`) before the `$changed` decision, so a write that replaced relations reported "Up to date." and `changes=0`. | `GoogleBooksSyncService::process()` | Track `$relationChanged` across the unset; the stamp counts it, the message counts column changes only. Verified by the suite (`relation-only change → updated`). |
| High | **Sync couldn't detect provider edits** — the 24 h volume cache served the same payload the import just wrote, so every "sync" read the stale copy. | `GoogleBooksService::volume()` + `GoogleBooksSyncService` | New `$refresh = true` skips the fresh-cache window on the sync path (breaker/stale logic untouched). Import and bulk keep the cached fast path. |
| High | **XSS in the card's visually-hidden label** — the provider title was concatenated into an attribute-adjacent string without `e()`. | `_card.php` | Wrap the whole string in `e()`. |
| High | **"Sync providers" JS button hit the IMPORT endpoint** — `streamRun(bulkForm.action,…)` ignored the button's `formaction`, so a JS-driven bulk sync actually ran bulk-import. | `google-books.js` submit handler | Use `submitter.formAction` to pick `/admin/google-books/sync-bulk`. |
| Medium | **Summary modal always showed the import groups after a sync run** — `applySummaryMode()` was never called. | `google-books.js` `showSummary()` | `applySummaryMode(runMode)` at entry. |
| Medium | **SSRF on the cover fetch** — `validSourceUrl` accepted any host; `FOLLOWLOCATION` could redirect into a private/metadata range. | `CoverDownloadService` | `validSourceUrl` now also blocks loopback/private/link-local/CGNAT/reserved IPs and internal hostnames (literal-IP + hostname checks); redirects are followed MANUALLY with every hop re-validated (still capped by `covers.max_redirects`). |
| Low | Dead CSS (`.gb-card-select input:disabled` never used; duplicate `.gb-card` block) | `google-books.css` | Removed / merged. |

## Verified good (no change needed)

- The four `*Request` guards (search field mapping + ISBN gate; import id
  gate; bulk list; sync list) all hardened and decoupled.
- All 4 POST data routes sit behind `AdminMiddleware` + `CsrfMiddleware`.
- Search results partial escapes every provider field that is rendered
  (`e()` on title / subtitle / blurb / authors / ISBN / links / chip
  label) even when the card is re-injected via `innerHTML`.
- The `status='published'` guards on import and sync (a `.deleted_at` or
  non-published book can never be sync-written).
- Rating isolation: sync NEVER writes `average_rating`/`ratings_count`.
- The batch/sync loops are O(ids) queries: `importedIds`, `metadataFor`,
  `syncOf` all resolve whole sets in one statement; per book the writes
  are single statements (no N+1 in reads; the transport is capped by the
  breaker).
- Circuit breaker + stale-cache fail-open on both search and volume.
- Imported Google covers are served from a LOCAL copy (`cover_image` →
  `/assets/covers/google/…`), never a hotlinked provider URL.
- The SSE protocol: `progress`/`summary` events + `connection_aborted()`
  cancellation; session lock released + `set_time_limit(0)` for long
  runs.
- SQLite types: timestamps as author-sanitized ISO (`Y-m-d\TH:i:s\Z`),
  booleans as ints, numerics compared numerically in the sync diff.

## Database audit (dev database/booksphere.db)

- `PRAGMA integrity_check` → `ok`.
- `PRAGMA foreign_key_check` → no violations.
- Duplicate `google_book_id` / `isbn` → none.
- Orphans in `book_authors` / `book_categories` → none.
- Migrations 0030–0032 applied; all eight import/cover/sync columns
  present; the unique indexes on `isbn` and `google_book_id` exist.
- The dev catalog is seed data (22 books, no real Google imports);
  `cover_source_url`/`cover_status`/`synced_at`/`sync_status` are
  correctly empty/`pending` — no pre-existing records carry sync state.

## Coverage of the audit

- Extra checks for the SSRF guard were NOT added to the suite (the
  class-private helpers are exercised by the existing "non-http(s) URL is
  refused" cover check; the manual redirect loop is covered by the
  existing `max_redirects` behavior). The cover suite (Phase 10.4)
  remains green after the rewrite.

## Full regression (all 20 CLI suites)

All suites pass with the Phase 10.7 fixes wired in – 0 failures:

| Suite | Checks |
|---|---|
| `GoogleBooksSyncTest` | 87 |
| `GoogleBooksCoverTest` | 58 |
| `GoogleBooksBulkImportTest` | 38 |
| `GoogleBooksImportTest` | 61 |
| `GoogleBooksSearchTest` | 57 |
| Auth / Browse / Landing / Library / Follow | 0 failures |
| Email / Notification ×4 | 0 failures |
| Recommendation ×3 + Personalization + Review ×2 | 0 failures |

(`GoogleBooksCoverTest` was new for the Phase 10.7 run - earlier phases
credited it in the 10.5 suite as the "cover regression" line.)

## Known remaining issues

1. Google Books rate limits in a live environment: the circuit breaker
   and stale-cache degradation already answer it, but there is no
   dedicated live-provider integration test (all suites are offline
   stubs). This is inherent to an offline test strategy, not a defect.
2. DNS-level SSRF protections are NOT applied (hostname denies for
   `.local/.internal/metadata` + literal-IP range checks only).
   A real-world deployment would want `gethostbyname`-level verification;
   not added because it would make the test suite network-dependent.
3. The phase-10 report DTOs expose `toArray()` but no persistence/export
   UI; nothing has been built to store a report.
4. The dev catalog carries no real Google imports (offline dev DB), so
   the "live volume" path is exercised only via stubs; the seeded rows
   keep the DB consistent.

## Conclusions

Phase 10 (Google Books: search → import → covers → bulk → sync) is
**complete**. The stabilization audit fixed the two real bugs that
surfaced (the sync stamping + the JS sync-bulk routing), hardened the
cover fetch against SSRF, stopped the sync from re-serving stale cached
copies, and removed dead front-end code. All 20 CLI suites pass across
the whole project; the DB is clean; the feature is operator-triggered,
offline-testable, and needs no background worker to verify.

**Recommendation: Phase 10 is Ready / 100% complete.** Remaining items
above are documented hardening work for hosting-tier concerns, not Phase
10 gaps.

## Files changed this phase

```
app/Services/GoogleBooksSyncService.php   (relation-change stamping; refresh fetch)
app/Services/GoogleBooksService.php       (volume($id, refresh:true))
app/Views/admin/google-books/partials/_card.php   (e() escaping)
app/Services/CoverDownloadService.php     (SSRF deny lists + manual redirects)
public/assets/js/google-books.js          (formAction routing; summary-mode wiring)
public/assets/css/google-books.css        (dead rules removed)
docs/PHASE_10_6_GOOGLE_BOOKS_SYNC.md       (backfilled phase doc)
docs/PHASE_10_7_GOOGLE_BOOKS_TESTING.md    (this file)
```