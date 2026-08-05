# Phase 8.4 — Smart Library Organization & Advanced Library Experience

> **Phase:** 8.4 · **Module:** Wishlist & Personal Reading Library (organization)
> **Builds on:** Phases 8.1–8.3 (backend, CRUD UI, dashboard) · **Next:** Phase 8.5

## 1. Project analysis (what Phase 8.4 started from)

Phases 8.1–8.3 delivered the Personal Library **backend** (migration
0017), the **CRUD UI**, and the premium **library dashboard** (hero,
statistics, filter/sort/view grid, reading summary + streak,
persisted preferences). The grid already searched title / publisher /
language / author / category and offered eight sort orders.

Phase 8.4 turns the library into an **intelligent organization
system** — the Smart Collections rail, a description-reaching search,
two new sorts (Most Reviewed / Most Recommended), **bulk actions**
(move / favourite / delete), the quick-action menu on every card,
and the **dashboard + profile integration**, all on top of the
existing stack — no new schema, no redesigned module:

| Brief requirement | Where it lives |
|---|---|
| Smart Collections rail (All / 5 shelves / Favourites) | `_collections.php` + `collectionStatistics()` (count, avg rating, last updated per collection) |
| Advanced search (title / author / category / publisher / language / **description**) | `LibraryRepository::filterClause()` — description added to the shared search |
| Advanced filters (status, favourite, category, author, rating, recency, combineable) | Phase 8.3 `parseFilters()` + `filterClause()` — unchanged surface, reused |
| Sorting incl. **Most Reviewed** / **Most Recommended** | `SORTS['most_reviewed']` (approved-review subquery) + `orderFor()` (recommendation-set CASE) |
| Remember the user's sort | Phase 8.3 `user_preferences` — the sort select persists already |
| Bulk actions (select → move / favourite / un-favourite / delete) | `POST /library/bulk` + `bulkStatus` / `bulkFavorite` / `bulkDelete` (repository, service, controller) |
| Bulk confirmation before destructive actions | `_bulk-delete-modal.php` + the existing single-delete modal |
| Library card: review count + quick actions menu | `_library-card.php` / `_library-row.php` (`library-review-count`, `library-quick-toggle` menu: View Details / Move To / Favourite / Remove / Share placeholder) |
| Share (placeholder only) | Quick menu entry disabled with a "coming soon" note — **not implemented** |
| Library statistics | Phase 8.3 `libraryDashboard()` reused; the dashboard's Library Overview now shows the user's OWN numbers |
| Profile integration (summary, favourite books / categories, recently added / finished) | `UserController::show()` + `profile/show.php` "My Library" block, via the SHARED `LibraryService` |
| Dashboard integration (recently added, favourites, overview, collections quick access) | `DashboardController` + `dashboard/index.php` sections 3–4 + 10 |
| Empty states | `_collections.php` ("no books yet") + the grid's existing empty states (No Books / Search / Filter) |
| Loading states | Phase 8.3 skeleton grid reused for every fetch |
| GSAP sparingly | Card hover lift + quick-menu pop (existing pattern) |

The Phase 8.4 scope deliberately **stops at bulk actions on the
library shelf**: the "move to status" bulk op updates the status and
the `updated_at` stamp only — the lifecycle timestamps
(`started_reading_at` / `finished_reading_at`) are **not** guessed by
a bulk UPDATE, exactly like the per-record rule (the single-record
moves are the only writers of those stamps; a future phase could
decide otherwise — see section 14).

---

## 2. Architecture (what the phase added)

```
Browser (library.js — fetch + the HTML5 form attribute trick)
   │  X-Requested-With: fetch / plain form
   ▼
LibraryController (thin: policy → throttle → service → JSON or view)
   │
   ▼
LibraryService (rules: status allowlist, bulk normalization,
   │             recommendation-set helper, cache invalidation)
   ▼
LibraryRepository (SQL: bulkStatus/Favorite/Delete with the OWNER
   │               guard, collectionStatistics UNION, orderFor CASE)
   ▼
SQLite (user_library + books + reviews + book_categories …)
```

### The key design decisions

1. **Bulk = one owner-gated UPDATE/DELETE.** Every bulk operation
   normalizes the incoming ids (`normalizeIds()`: positive ints only,
   de-duplicated) and then uses `ownedIdsClause()` — a bare
   `user_id = ? AND id IN (…)` with prepared placeholders — so a
   tampered payload can never touch another user's rows and a
   non-numeric value can never reach SQL. The service validates the
   target status against the five-shelf allowlist BEFORE the write.
2. **Collection statistics = one UNION ALL aggregation.**
   `collectionStatistics()` computes count / mean book rating / last
   `updated_at` for `all`, the five shelves and `favorites` in a
   single prepared statement, then fills a **defaulted map of all
   seven collection ids** (an empty shelf reads `count 0`, rating
   `0.0`, no stamp) — the same guaranteed-keys contract as
   `statusCounts()`, so the rail never has to guard a missing key.
3. **Most Reviewed counts the platform's own approved reviews** (a
   subquery over `reviews`), not the external `ratings_count` — a
   book actually reviewed on BookSphere outranks a popular-but-
   unreviewed title. The row SELECT also carries `book_review_count`
   so the cards can show the real count.
4. **Most Recommended = the engine's suggestion set first.**
   `orderFor()` swaps in a parameterized
   `CASE WHEN b.id IN (?) …` when the sort is `most_recommended` and
   a recommendation set is available; without the engine (optional) it
   degrades to `ratings_count DESC` — never an error.
5. **The collections rail repaints in place.** After any write
   (`refreshCollections()`), the rail's counts / ratings / stamps
   update from the same `collectionStatistics()` read the page first
   rendered — no full reload, no drift.
6. **Description search stays inside the user's own library.** The
   description `LIKE` was added to the single shared `filterClause()`,
   so `search()`, `filter()` and the grid all reach it consistently —
   and only over the user's records.
7. **Dashboard and profile read the same service.** Both the
   dashboard's Recently Added / Favourites / Overview and the
   profile's My Library block are fed by the SHARED `LibraryService`
   (the single source of truth), never duplicate queries or
   placeholder numbers.
8. **Bulk selection works without JS surprises.** The checkbox rows
   live OUTSIDE the bulk form's DOM and reference it via the HTML5
   `form="library-bulk-form"` attribute; the JS appends the selected
   ids before submit and keeps the counter / select-all in step.

---

## 3. Files created (Phase 8.4)

| File | Purpose |
|---|---|
| `app/Views/library/partials/_collections.php` | The Smart Collections rail (7 tiles, each with count / avg rating / last-updated, active highlight, `data-library-tabs` contract) |
| `app/Views/library/partials/_bulk-bar.php` | The sticky bulk action bar (n selected, Move To select + Apply, Favourite / Un-favourite, Remove with modal) |
| `app/Views/library/partials/_bulk-delete-modal.php` | The bulk destructive confirmation modal (name list + CSRF form) |
| `docs/PHASE_8_4_SMART_LIBRARY.md` | This report |

## 4. Files modified (Phase 8.4)

| File | Change |
|---|---|
| `app/Repositories/LibraryRepository.php` | +`bulkStatus()` / `bulkFavorite()` / `bulkDelete()` (+ the shared `normalizeIds()` / `ownedIdsClause()`), `collectionStatistics()` (defaulted map), `recentlyAdded()` / `recentlyUpdated()`, the `most_reviewed` SORTS fragment + `orderFor()` recommendation CASE; `filterClause()` searches the **description**; the base SELECT ships `book_review_count`; `filter()` / `paginate()` accept the recommendation set |
| `app/Services/LibraryService.php` | +`collectionStatistics()`, `recentlyAdded()`, `recentlyUpdated()`, `bulkStatus()` / `bulkFavorite()` / `bulkDelete()` (+ `afterBulkWrite()` cache invalidation + audit log), `filterLibrary()` normalizes the new sorts and passes the recommendation set (`recommendedForSort()`); `SORTS` +`most_reviewed` / `most_recommended` |
| `app/Models/UserLibrary.php` | Passthroughs for the new repository methods |
| `app/Controllers/LibraryController.php` | +`bulk()` (POST /library/bulk: closed action allowlist, 422 / 409 answers, owner re-gate), `statistics()` answers `collections` too; `dashboardViewData()` adds `collections` / `activeShelf`; **bug fix** — the four shelf routes (`wishlist` / `currentlyReading` / `finished` / `favorites`) called the nonexistent `libraryViewData()`, now call `dashboardViewData()` |
| `routes/web.php` | +`POST /library/bulk` (CSRF); `UserController` wired with the shared `LibraryService` |
| `app/Views/library/index.php` | The shelf tabs replaced by the `_collections.php` rail; bulk bar + bulk modal included; docblock updated |
| `app/Views/library/partials/_library-card.php` | +selection checkbox (`form="library-bulk-form"`), quick action menu (View Details / Move To 5 shelves / Favourite / Share placeholder / Remove), `library-review-count`; `$statusLabels` scoped via include context |
| `app/Views/library/partials/_library-row.php` | Same quick menu + review count for the list view |
| `app/Views/library/partials/_filters.php` | Search placeholder updated ("title, author, category, publisher, language or description") |
| `app/Views/library/partials/_grid.php` | Exposes `$statusLabels` to the card/row partials; review-count chip label |
| `public/assets/js/library.js` | `collectFilterParams()` extracted; `refreshCollections()` repaint; quick-menu delegation (`data-quick-status` / `data-quick-favorite` / `data-quick-share` / `data-quick-remove`); bulk bar + modal bindings (`data-bulk-select-all`, counter, clear); section header updated |
| `public/assets/css/library.css` | New section 13 (collections rail 7→4→2 responsive grid, per-collection icon colors, quick menu, bulk bar, review count, `profile-book-thumb`) + the `library-collections--quick` dashboard variant |
| `app/Controllers/DashboardController.php` | Passes `recentlyAdded`, `favouriteBooks`, `libraryCounts` (statusCounts), `collections`, `statusLabels` to the dashboard |
| `app/Views/dashboard/index.php` | Real "Recently Added" + "My Favourite Books" shelves (sections 3–4), real "Library Overview" numbers + collections quick access (section 10); docblock updated |
| `app/Controllers/UserController.php` | +`LibraryService` injected (routes wiring); `show()` composes the My Library block (`statusCounts`, `favoriteBooks(4)`, `preferredGenres(5)`, `recentlyAdded(3)`, `finished(3)`) |
| `app/Views/profile/show.php` | +"My Library" dash-section (summary tiles, favourite books, favourite categories, recently added / finished) before Recent Reviews |
| `tests/LibraryTest.php` | +47 Phase 8.4 checks (section 19) — now **274** |

---

## 5. Routes

| Method | Path | Action |
|---|---|---|
| POST | `/library/bulk` | The bulk actions endpoint (CSRF + throttle `library_write`): `move_status` / `favorite` / `unfavorite` / `delete` with `ids[]` → JSON affected count or 422 / 409 |
| GET | `/library` · `/library/filter` · `/library/sort` … | Phase 8.3 routes — the rail links into `/library?status=…` (the five shelves) and `/library/favorites` (the favourites shelf) |

The rail's favourites tile links to the dedicated `/library/favorites`
route (NOT `?status=favorites` — `favorites` is not a status key, and
`parseFilters()` would silently drop it, showing all books).

---

## 6. How each workflow flows

### The collections rail

`dashboardViewData()` calls `collectionStatistics()` once; the rail
renders all seven tiles with the real numbers. After a write the JS
`refreshCollections()` re-fetches `/library/statistics` (which now
answers `collections`) and repaints the counts / ratings / stamps in
place.

### Bulk actions

1. The user ticks checkboxes (each `form="library-bulk-form"`); the
   counter updates.
2. The bar's Apply buttons submit the form; the JS appends the
   checked ids, posts `/library/bulk` with the CSRF token, then calls
   `refreshCollections()` + `refreshResults()` (the grid refetches the
   active filter) and clears the selection.
3. The server re-validates: non-empty `ids[]`, allowlisted action,
   valid status → `bulkStatus()` / `bulkFavorite()` / `bulkDelete()` —
   each re-gated by the user id; JSON answers `affected`.
4. Delete confirms through the bulk modal (names listed) — and the
   server still refuses foreign rows.

### The quick menu

Every card/row's ⋯ button opens the menu: View Details (book page),
Move To (the five shelves — updates in place via the same fetch path
as the status select), Favourite toggle, Share (placeholder, disabled
with a "coming soon" note), Remove (single-record delete modal).

### Dashboard / profile integration

Both controllers read the shared `LibraryService` — `recentlyAdded()`
+ `favoriteBooks()` feed the two dashboard shelves; `statusCounts()`
+ `collections` feed the Overview and the quick access strip; the
profile's My Library block reuses the same calls (capped) plus
`preferredGenres()` and `finished()`.

---

## 7. Repository methods added (Phase 8.4)

`bulkStatus()` · `bulkFavorite()` · `bulkDelete()` · `collectionStatistics()`
· `recentlyAdded()` · `recentlyUpdated()` — plus the shared private
`normalizeIds()` / `ownedIdsClause()`, the `most_reviewed` fragment in
`SORTS`, the recommendation-aware `orderFor()` CASE and the
description search in `filterClause()`. Every query remains a prepared
statement.

## 8. Service methods added (Phase 8.4)

`bulkStatus()` · `bulkFavorite()` · `bulkDelete()` (allowlist + cache
invalidation + audit log via `afterBulkWrite()`) · `collectionStatistics()`
· `recentlyAdded()` · `recentlyUpdated()` · `recommendedForSort()` ·
the two new `SORTS` labels.

## 9. Controller methods added (Phase 8.4)

`bulk()` — thin: `requireAccess()` → `throttle('library_write')` →
service → JSON `affected` / 422 / 409. The private `dashboardViewData()`
gains the `collections` / `activeShelf` payload.

---

## 10. Validation & business rules (Phase 8.4)

- The bulk action set is a **closed allowlist** in the controller; the
  target status must be one of the five shelves (service rule) — both
  answer 422 / 409, never a partial write.
- The ids are normalized (positive ints, de-duplicated) and every
  statement re-gated by `user_id = ?` — IDOR-safe by construction.
- `collectionStatistics()` guarantees all seven keys (empty shelves
  read `count 0` / `rating 0.0`).
- `most_reviewed` counts only **approved** platform reviews;
  `most_recommended` honours the engine set when present and degrades
  gracefully without it.
- Bulk moves never invent lifecycle timestamps (see section 1).

## 11. Security measures

- Prepared statements everywhere — the bulk `IN (…)` clause is built
  from per-id placeholders; `ownedIdsClause()` never interpolates a
  value.
- CSRF token on the bulk form, the bulk modal and every quick action;
  `AuthMiddleware` on the route; owner re-gate in SQL.
- Output escaping (`e()`) in every new partial.
- Write-endpoint rate limiting (`library_write`) on `/library/bulk`.
- No new secrets, no sessions expanded.

---

## 12. Testing checklist

- `php tests/LibraryTest.php` — **274 checks, 0 failed** (227 from
  Phases 8.1–8.3 + 47 new Phase 8.4 checks: the collections payload
  (keys, counts, rounded ratings, empty-library default map),
  recently-added/updated ordering, the description search (a seed word
  that exists ONLY in the description), the Most Reviewed /
  Most Recommended orderings (incl. the engine-set CASE), every bulk
  operation (status move without lifecycle stamps, junk ids ignored,
  the foreign-record IDOR skip, favourite round-trip), the controller
  bulk endpoint (affected count, empty selection, junk action, junk
  status), and the rendered dashboard + profile integration).
- The other seven suites stay green — **1053 checks total, 0 failed**
  (LibraryTest 274 + ReviewTest 369 + ReviewIntegrationTest 109 +
  BrowseTest + 4 recommendation suites).

**Manual checklist** (full plan in `docs/MANUAL_TEST_CHECKLIST.md`):
open `/library` → the collections rail with real numbers; search a
description word; sort by Most Reviewed / Most Recommended; tick
several books → the bulk bar; move / favourite / un-favourite / remove
(with the confirmation modal); the quick menu on a card and a row; the
dashboard's Recently Added / Favourites / Overview / quick access; the
profile's My Library block; responsive (7→4→2 rail columns); keyboard
focus on the bulk controls; the no-JS fallback of the bulk form.

---

## 13. Documentation updated

- `docs/PHASE_8_4_SMART_LIBRARY.md` (this report).
- `docs/ARCHITECTURE.md` — the Personal Library entry extended with
  Phase 8.4.
- `README.md` — current phase, test totals and the docs index.
- `docs/MANUAL_TEST_CHECKLIST.md` — the Phase 8.4 manual plan.

---

## 14. Preparation notes for Phase 8.5

- The bulk "move to status" deliberately leaves the lifecycle
  timestamps untouched; if a future phase wants bulk moves to stamp
  `started_reading_at` / `finished_reading_at`, the change is confined
  to the three bulk SQL statements (and their tests).
- The recommendation hooks (`favoriteBooks()`, `completedBooks()`,
  `readingHistory()`, `preferredGenres()`) are already consumed by
  the profile block — the engine hooks are ready.
- The `_collections.php` rail is the natural mount point for **custom
  collections** later: the rail order is one `$collectionMeta` array
  and the aggregation one UNION arm.
- `collectionStatistics()` already returns everything a statistics
  widget needs (counts, mean rating, last activity per shelf); a
  future analytics module can aggregate it directly.
- Reading goals / achievements / community features are explicitly out
  of scope and wait for their own phases.
