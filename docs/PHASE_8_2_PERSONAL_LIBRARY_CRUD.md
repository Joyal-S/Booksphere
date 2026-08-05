# Phase 8.2 — Personal Library CRUD, Reading Status & Progress Tracking

> **Phase:** 8.2 · **Module:** Wishlist & Personal Reading Library (UI)
> **Builds on:** Phase 8.1 (the library backend) · **Next:** Phase 8.3

## 1. Project analysis (what Phase 8.2 started from)

Phase 8.1 delivered the complete Personal Library **backend**: the
`user_library` table (migration 0017), the layered stack
(DTO → service → repository), the five-shelf status lifecycle with its
automatic timestamps, favourites independent of status, progress
validation, the policy matrix and the request rules — all verified by
149 automated checks.

Phase 8.2 turned that backend into the **user-facing library UI**:

| Brief requirement | Where it lives |
|---|---|
| Add Book To Library | Book detail page panel (`_book-panel.php`) + `LibraryService::addBook()` |
| Remove Book From Library | My Library page + shared confirmation modal + `removeBook()` |
| Change Reading Status | Card / panel status selects + `updateStatus()` |
| Toggle Favourite | Fetch heart buttons (no reload) + `toggleFavorite()` |
| Update Reading Progress | Card / panel sliders + `updateProgress()` |
| View Personal Library | `GET /library` — six sections, tabs, counters |
| Status Counters | `statusCounts()` + shelf tab badges |
| Library Statistics | `GET /library/statistics` — the shared stat cards |
| Continue Reading (dashboard) | `DashboardController` + `_continue-card.php` |
| Search your library | `searchLibrary()` (title / author / category) + live search |
| Empty / loading states | Empty-state cards + `skeleton-card.php` |

The phase added **no backend rules** — the status lifecycle, duplicate
prevention, progress bounds and ownership rules already lived in the
service from 8.1; the UI only *uses* them.

---

## 2. Architecture (what the phase added on top of 8.1)

```
Browser (library.js — fetch, progressive enhancement)
   │  X-Requested-With: fetch / plain form
   ▼
LibraryController (thin: policy → validate → service → JSON or view)
   │
   ▼
LibraryService (rules — unchanged from 8.1)
   │
   ▼
LibraryRepository (SQL — unchanged from 8.1)
   │
   ▼
SQLite (user_library + books + junctions)
```

### What changed in this phase

1. **The My Library page** (`app/Views/library/index.php`) — one page
   for every shelf route (`/library`, `/library/wishlist`,
   `/library/currently-reading`, `/library/finished`,
   `/library/favorites`, `?status=...` focus):
   - shelf **tabs with live counters** (`statusCounts()`),
   - a **search box** that is a plain GET form without JS and a
     debounced live search with JS (`/library/search` answers the same
     rendered fragment both ways),
   - one **shelf section per status** (six sections), each with its
     own empty state,
   - the shared **remove confirmation modal**,
   - **loading skeletons** (`library/components/skeleton-card.php`)
     shown while a live search is in flight — no layout shift.
2. **The library card** (`library/partials/_library-card.php`) — the
   reusable unit that shows cover, title, authors, category, the
   progress bar + slider, the status badge, the average rating, the
   favourite heart, and Details / Remove actions. Every control is a
   real CSRF-protected form; `library.js` upgrades them to fetch calls
   and repaints in place.
3. **The book detail panel** (`library/partials/_book-panel.php`) —
   "Add to Library" (status select + button) when the book is new, the
   full "Update Library entry" panel (status badge, favourite heart,
   progress bar + slider, status select, remove) when it is already
   saved. The controller picks the state with
   `LibraryService::bookDetailsState()`.
4. **Library statistics page** (`library/statistics.php`) — Total
   Books, the five shelf counters, Favourites, Average Progress and
   Books Added This Month, reusing the shared `stat-card` component.
5. **Dashboard Continue Reading** — `DashboardController` now receives
   the **same shared `LibraryService` instance** as the library module
   (wired in `routes/web.php`) and hands `currentlyReading()` (books
   currently reading, **newest activity first**) to the view; the
   dashboard renders the `_continue-card.php` cards (cover, title,
   authors, progress bar, Resume button → book detail page).
6. **Asset wiring** — `app/Views/partials/head.php` now loads
   `css/library.css` and `scripts.php` loads `js/library.js` globally,
   so the library interactions work on every page (library, book
   detail, dashboard).

---

## 3. Files created (Phase 8.2)

| File | Purpose |
|---|---|
| `app/Views/library/index.php` | The "My Library" page (tabs, search, six sections, modal) |
| `app/Views/library/statistics.php` | The library statistics page (stat cards) |
| `app/Views/library/partials/_library-card.php` | The reusable library card |
| `app/Views/library/partials/_section.php` | One shelf section (grid + empty state) — *removed in Phase 8.3, superseded by the dashboard grid* |
| `app/Views/library/partials/_search-results.php` | The shared search-results fragment — *removed in Phase 8.3, superseded by `_grid.php`* |
| `app/Views/library/partials/_book-panel.php` | The book detail Add / Update panel |
| `app/Views/library/partials/_delete-modal.php` | The shared remove confirmation modal |
| `app/Views/library/partials/_continue-card.php` | The dashboard Continue Reading card |
| `app/Views/library/components/skeleton-card.php` | The loading skeleton |
| `public/assets/css/library.css` | All library styles (cards, panels, tabs, skeletons, continue shelf, motion) |
| `public/assets/js/library.js` | Favourite / status / progress fetch interactions, live search, counter refresh, card animations |

## 4. Files modified (Phase 8.2)

| File | Change |
|---|---|
| `app/Views/partials/head.php` | Load `css/library.css` |
| `app/Views/partials/scripts.php` | Load `js/library.js` |
| `app/Controllers/DashboardController.php` | Accept the shared `LibraryService`; pass the Continue Reading shelf to the view |
| `app/Views/dashboard/index.php` | New "Continue Reading" section (with empty state) |
| `app/Views/partials/sidebar.php` | (8.1) The Wishlist link points at the real `/library` |
| `tests/LibraryTest.php` | +29 Phase 8.2 checks (search, counters, endpoints, dashboard shelf) |
| `docs/ARCHITECTURE.md`, `README.md` | Documentation |

> Everything else (service, repository, controller, DTO, policy,
> requests, migration 0017, routes) was delivered in Phase 8.1 and is
> **reused unchanged** — the phase added no new SQL and no new rules.

---

## 5. Routes

All library routes were registered in Phase 8.1; Phase 8.2 only uses
them:

| Method | Path | Action |
|---|---|---|
| GET | `/library` | My Library page (sections + tabs + search) |
| POST | `/library` | Add a book (status select) |
| GET | `/library/search` | Live search (JSON fragment) |
| GET | `/library/wishlist` · `/currently-reading` · `/finished` · `/favorites` | Shelf pages |
| GET | `/library/statistics` | Statistics page / JSON counters |
| POST | `/library/{id}` | Change status / progress / favourite |
| POST | `/library/{id}/favorite` | Fetch favourite toggle |
| POST | `/library/{id}/progress` | Fetch progress update |
| POST | `/library/{id}/delete` | Remove (confirmation modal) |

Every route sits behind `AuthMiddleware` + CSRF on the writes; the
owner-only gate runs inside the controller via `LibraryPolicy`.

---

## 6. How each workflow flows

### Add Book

Book detail page → "Add to Library" panel → status select → POST
`/library` → `LibraryController::store()` (policy → `StoreLibraryRequest`
→ `LibraryService::addBook()` → repository `create()`) → flash /
JSON → book lands on the shelf.

### Remove Book

Library card Remove button → Bootstrap confirmation modal (the clicked
button feeds the form target via `data-delete-*`) → POST
`/library/{id}/delete` → `destroy()` → the card fades out in place and
the shelf counters refresh from `/library/statistics`.

### Change Status

The status select posts `/library/{id}`; `updateStatus()` applies the
lifecycle (starting to read stamps `started_reading_at`; finishing
forces progress 100 and stamps `finished_reading_at`; leaving finished
keeps the date as history). The badge and the progress bar repaint in
place.

### Favourite

The heart button posts `/library/{id}/favorite` via fetch — no page
reload; `toggleFavorite()` flips the flag **independently of status**
and returns the new state for the repaint.

### Progress

The range slider + Save button posts `/library/{id}/progress`
(0–100). Reaching **100 asks "Mark this book as Finished?" first**;
on confirmation the server auto-finishes the record (progress and
status can never disagree).

### Library search

`LibraryService::searchLibrary()` → `LibraryRepository::search()`
(LIKE over title, EXISTS over author name and category name, never
leaving the user's own records). The no-JS form renders the fragment
server-side; the JS path fetches `/library/search` debounced and swaps
the same fragment in with skeletons while loading.

---

## 7. Repository methods (reused from Phase 8.1)

`create()` · `update()` · `delete()` · `find()` · `findByUser()` ·
`findByBook()` · `exists()` · `findByStatus()` · `favorites()` ·
`wishlist()` · `currentlyReading()` · `finished()` · `search()` ·
`statistics()` · `preferredGenres()` — every query is a prepared
statement; no SQL exists outside this class.

## 8. Service methods (reused from Phase 8.1)

`addBook()` · `removeBook()` · `updateStatus()` · `toggleFavorite()` ·
`updateProgress()` · `libraryStatistics()` · `statusCounts()` ·
`userLibrary()` · `searchLibrary()` · `shelf()` · `bookDetailsState()` ·
`wishlist()` / `currentlyReading()` / `finished()` / `favoriteBooks()` ·
plus the Phase 8.5 hooks `readingHistory()` / `completedBooks()` /
`preferredGenres()`.

## 9. Controller methods (Phase 8.1, unchanged)

`index()` · `store()` · `update()` · `destroy()` · `toggleFavourite()` ·
`updateProgress()` · `search()` · `statistics()` · the four shelf
routes — all thin (policy → validate → service → answer), no SQL.

## 10. Validation rules (reused from Phase 8.1)

| Field | Rule |
|---|---|
| `book_id` | required, integer, exists |
| `library_status` | in `want_to_read / currently_reading / finished / on_hold / dropped` |
| `progress` | integer, 0–100 |
| `is_favorite` | boolean (`0/1/false/true`) |

## 11. Business rules

- One book → one library record → per user (duplicate additions blocked).
- Removing a book deletes **only the library record**, never the book.
- Status changes update the lifecycle timestamps automatically.
- Favourites are independent of reading status.
- Only the owner may modify a library entry (even admins only view).
- Progress 0–100; 100 suggests Finished — the user confirms first.

## 12. Security measures

- Prepared statements everywhere (no SQL injection).
- CSRF token on every write form and fetch (the token travels with the
  form data).
- `AuthMiddleware` on every route + `LibraryPolicy` owner checks
  (no IDOR — the session user id is always the actor id).
- Output escaping (`e()`) in every template (no XSS).
- Write-endpoint rate limiting (`library_write` bucket → 429).
- Session validation; friendly messages via `LibraryException`; generic
  500s never leak internals.

---

## 13. Testing checklist

- `php tests/LibraryTest.php` — **178 checks, 0 failed** (149 Phase 8.1
  + 29 new Phase 8.2 checks: `searchLibrary` title / author / category,
  `statusCounts`, the generic `shelf()` buckets, `bookDetailsState`,
  the `search` / `toggleFavourite` / `updateProgress` controller
  endpoints, and the dashboard Continue Reading shelf — sorted by last
  updated, rendered with resume cards, empty state included).
- `php tests/ReviewTest.php` (369) — dashboard regression (it renders
  the same page) — **0 failed**.
- `php tests/RecommendationDashboardTest.php` (64),
  `ReviewIntegrationTest.php` (109), `BrowseTest.php` (69),
  `RecommendationArchitectureTest.php` (86), `PersonalizationTest.php`
  (62), `RecommendationOptimizationTest.php` (53) — **all 0 failed**.

**Manual checklist** (see `docs/MANUAL_TEST_CHECKLIST.md` for the full
plan): add a book from the detail page → it lands on the right shelf;
duplicate add is blocked; change status (started/finished stamps);
toggle favourite without reload; move the progress slider to 100 and
confirm "Finished?"; remove via the modal (counters refresh); search
title / author / category; the dashboard Continue Reading shelf lists
reading books newest-first with a working Resume button; the page works
with JavaScript disabled; empty states and skeletons render; responsive
on desktop / tablet / mobile; no PHP errors in the log.

---

## 14. Documentation updated

- `docs/PHASE_8_2_PERSONAL_LIBRARY_CRUD.md` (this report).
- `docs/ARCHITECTURE.md` — the Personal Library extension-point entry
  and the per-module asset-wiring developer note.
- `README.md` — current phase, test totals and the docs index.

---

## 15. Preparation notes for Phase 8.3

> Phase 8.3 is now **implemented** — see
> `docs/PHASE_8_3_LIBRARY_DASHBOARD.md`. The preparation notes below
> are kept as the historical record of what 8.2 primed for it.

Phase 8.3 (per the brief) — the library dashboard redesign, reading
analytics, recommendation integration, reading goals, collections and
reading clubs. The backend is already primed:

- the **recommendation hooks** (`favoriteBooks`, `readingHistory`,
  `completedBooks`, `preferredGenres`) are public service methods
  waiting for the engine integration;
- the **status lifecycle timestamps** (`started_reading_at`,
  `finished_reading_at`) are already stored for reading-history
  analytics;
- `libraryStatistics()` already exposes the aggregates analytics pages
  will need.
