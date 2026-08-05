# Phase 8.3 — The Premium "My Library" Dashboard

> **Phase:** 8.3 · **Module:** Wishlist & Personal Reading Library (dashboard)
> **Builds on:** Phase 8.1 (backend) + Phase 8.2 (CRUD UI) · **Next:** Phase 8.4

## 1. Project analysis (what Phase 8.3 started from)

Phase 8.1 delivered the Personal Library **backend** (migration 0017,
the layered stack, the five-shelf lifecycle), Phase 8.2 turned it into
the **CRUD UI** (My Library page with shelf sections, live counters,
search, the book-detail panel, the dashboard Continue Reading shelf).

Phase 8.3 replaced the Phase 8.2 page with the **premium library
dashboard** the brief describes — the reading dashboard with a
personal header, statistics, quick actions, a filterable / sortable /
pageable book grid with a grid/list view switch, and a reading
summary:

| Brief requirement | Where it lives |
|---|---|
| The page opens with a personal greeting | `library/index.php` hero header (name + wave) |
| Total books and currently reading chips | Hero chips (streak / total / progress) |
| Quick statistics row | `LibraryService::libraryDashboard()` + stat cards |
| Quick actions (Browse Books, Import Books, Statistics) | `library-quick-actions` grid |
| Continue Reading section | `LibraryService::continueReading()` + `_continue-grid.php` |
| Filter / sort / view bar | `_filters.php` (q, status, category, author, rating, favourites, recency, 8 sort orders, grid/list toggle) |
| Shelf tabs with live counters | Kept from 8.2 (now focus the grid instead of switching sections) |
| Search (title / author / category) | Extended to publisher / language too — `filterClause()` |
| Grid & list views | `_grid.php` + `_library-row.php`, persisted per user |
| Pagination | `paginate()` + `_pagination.php` |
| Loading skeletons | `skeleton-stat.php` + the JS skeleton grid |
| Recommended badges | `recommendedFor()` — optional engine integration |
| Reading Summary | `readingSummary()` (favourite genre / author, average rating given, average progress) |
| Reading Streak | `readingStreak()` (real consecutive-day library-activity count) |
| Remembering user preferences | migration 0018 `user_preferences` (sort + view) |

Unlike Phase 8.2 (which added no rules), Phase 8.3 added the
**preferences persistence layer** (migration 0018), the **combined
grid read** (filter + sort + paginate over one shared WHERE builder)
and the **analytics reads** (summary + streak).

---

## 2. Architecture (what the phase added)

```
Browser (library.js — fetch, progressive enhancement)
   │  X-Requested-With: fetch / plain form
   ▼
LibraryController (thin: policy → service → JSON fragment or view)
   │
   ▼
LibraryService (rules: allowlists, normalization, preference merging)
   │
   ▼
LibraryRepository (SQL: filterClause, SORTS, paginate, summary, streak)
   │
   ▼
SQLite (user_library + books + junctions + user_preferences [0018])
```

### The key design decisions

1. **One shared WHERE builder.** `LibraryRepository::filterClause()`
   builds every filtered library read (the grid `filter()`, the
   `countFiltered()` pagination denominator, and the simple `search()`
   the book-detail live search uses). All three match the same
   columns (title / publisher / language / author / category), so the
   two search surfaces can never drift apart. Every value is a
   prepared parameter; unknown filter keys are silently ignored.
2. **Sorting: SQL fragments in the repository, labels in the
   service.** `LibraryRepository::SORTS` holds the ONLY ORDER BY
   fragments the data layer will ever build (8 sorts, each ending in
   an id tie-break so ordering is deterministic); `LibraryService::SORTS`
   holds the display labels. An unknown sort id falls back to the
   default — a tampered request can only ever produce the default
   view, never an error.
3. **The grid fragment is the single source of truth.** `_grid.php`
   (chips + grid/list + pagination) is rendered server-side by the
   no-JS page AND returned as the JSON `html` fragment by
   `/library/filter` and `/library/sort` — the JS swaps the same
   fragment in, so the two paths cannot drift.
4. **Preferences: one row per user, merge-on-write.**
   `LibraryService::viewPreference()` reads the stored sort/view,
   applies only *valid* incoming values (an unknown value is ignored,
   **not stored** — a tampered request can never reset a user's own
   choice), persists changes via the repository UPSERT and returns
   the merged result as the source of truth for the request.
   `sortParameter()` returns **null when the request carries no sort**
   so a plain grid request (e.g. `/library?q=...`) keeps the user's
   stored sort instead of resetting it.
5. **The statistics row refetches itself.** After any write the JS
   calls `/library/statistics` again, skeleton-fills the stat cells
   and rebuilds them from `data-stat-*` attributes — no page reload,
   no layout shift, and the shelf-tab counters + header chips update
   from the same aggregate.

---

## 3. Files created (Phase 8.3)

| File | Purpose |
|---|---|
| `database/migrations/0018_create_user_preferences_table.php` | The `user_preferences` table (user_id PK, library_sort default `newest_added`, library_view CHECK grid/list, ON DELETE CASCADE) |
| `app/Views/library/partials/_grid.php` | The shared results fragment (filter chips + grid/list + pagination + empty states) |
| `app/Views/library/partials/_filters.php` | The GET filter/sort/view bar (search box, selects, checkboxes, sort, view toggle) |
| `app/Views/library/partials/_library-row.php` | The list-view row (cover thumb, titles, badge, rating, progress, actions) |
| `app/Views/library/partials/_pagination.php` | The ±2 page-window pager (preserves the query string) |
| `app/Views/library/partials/_continue-grid.php` | The shared Continue Reading fragment (cards or empty state) |
| `app/Views/library/components/skeleton-stat.php` | The stat-card loading skeleton |

## 4. Files modified (Phase 8.3)

| File | Change |
|---|---|
| `app/Repositories/LibraryRepository.php` | +`SORTS`, `PREFERENCE_COLUMNS`, `filterClause()`, `filter()`, `countFiltered()`, `paginate()`, `filterOptions()`, `continueReading()` (delegate of `currentlyReading()`), `dashboard()`, `readingSummary()`, `readingStreak()`, `preference()`, `savePreferences()`; `search()` now matches publisher / language too |
| `app/Services/LibraryService.php` | +`STATUSES`/`SORTS`/`VIEWS`/per-page/default constants, `continueReading()`, `libraryDashboard()`, `recommendedFor()` (nullable engine), `readingSummary()`, `readingStreak()`, `filterOptions()`, `filterLibrary()`, `viewPreference()` |
| `app/Models/UserLibrary.php` | Passthroughs for every new repository read |
| `app/Controllers/LibraryController.php` | `index()` + the four shelf routes now render the dashboard; `search()` upgraded to the grid fragment; +`filter()`, `sort()`, `viewMode()`, `continueReading()`; private helpers `dashboardViewData()`, `buildGrid()`, `filteredGrid()`, `jsonGrid()`, `parseFilters()`, `sortParameter()`, `pageParameter()`, `redirectTarget()` |
| `routes/web.php` | +`GET /library/filter`, `GET /library/sort`, `GET /library/continue-reading`, `POST /library/view-mode` (CSRF) |
| `app/Views/library/index.php` | Full dashboard rewrite (hero + chips, stats row, quick actions, Continue Reading, filter bar, tabs, results region, reading summary, delete modal) |
| `app/Views/library/partials/_library-card.php` | +recommended badge, "Updated" stamp, Resume button, wrapped cover link |
| `public/assets/js/library.js` | Sections 7–8 rewritten: `bindFilterForm()` (debounced live filter/sort/view), `refreshCounters()` (stats + chips + tab counters), `refreshContinue()` (continue shelf refetch) |
| `public/assets/css/library.css` | New section 9 (hero, chips, stats, quick actions, filter bar, list rows, pagination, recommended badge) + responsive rules |
| `tests/LibraryTest.php` | +49 Phase 8.3 checks (section 18: schema, grid reads, summaries, streak, preferences, endpoints) — now 227 |

> Removed (superseded by the dashboard): `library/partials/_section.php`
> and `library/partials/_search-results.php` — only the old index
> referenced them.

---

## 5. Routes

| Method | Path | Action |
|---|---|---|
| GET | `/library` | The dashboard (hero, stats, quick actions, continue, filters, grid, summary) |
| GET | `/library/filter` | Live filter endpoint → grid fragment JSON |
| GET | `/library/sort` | Live sort endpoint (persists the sort) → grid fragment JSON |
| GET | `/library/continue-reading` | Continue Reading fragment JSON |
| POST | `/library/view-mode` | Grid/list toggle (persists the view; throttled `library_write`) |
| GET | `/library/search` | Upgraded: full grid fragment for the query |
| GET | `/library/wishlist` · `/currently-reading` · `/finished` · `/favorites` | Shelf pages — now focus the dashboard grid |
| GET | `/library/statistics` | Statistics page / JSON counters |

Every route sits behind `AuthMiddleware` + CSRF on the writes; the
owner-only gate runs inside the controller via `LibraryPolicy`.

---

## 6. How each workflow flows

### The dashboard render (`GET /library`)

`index()` answers the Phase 8.1 JSON for fetch callers, otherwise
renders the page from `dashboardViewData()`: `libraryDashboard()`
(statistics + summary + streak + the recommended badge set),
`continueReading()`, `statusCounts()`, `filterOptions()` (category /
author dropdowns) and the first grid page (`filterLibrary()` with the
user's stored sort/view). The shelf routes pass a `focus` so the tabs
land on the right shelf.

### Live filter / sort / view

- the search box and every select/checkbox fetch
  `/library/filter?q=...&status=...&sort=...` — one call carries every
  active parameter;
- the sort select fetches `/library/sort?sort=...` (and persists it);
- the grid/list toggle posts `/library/view-mode` (and persists it);
- `filteredGrid()` answers the rendered `_grid.php` fragment; the JS
  swaps `[data-library-results]` with skeletons while the fetch is in
  flight, updates the URL via `history.replaceState` (a filtered page
  is shareable / reloadable), then refreshes the stats + counters.

### The preference merge

`viewPreference($userId, $sort, $view)` reads the stored row, applies
only allowlisted changes, persists via the UPSERT and returns the
merged pair — the request renders with exactly what it got back.
Without a preferences row the defaults are `newest_added` / `grid`.

### The stats self-refresh

`refreshCounters()` fetches `/library/statistics`, skeleton-fills the
stat cells, rebuilds each card from its `data-stat-*` attributes,
updates the header chips and the shelf-tab counters, then calls
`refreshContinue()` which swaps the continue shelf fragment.

### The reading streak

`readingStreak()` counts the DISTINCT calendar days in the user's
`updated_at` history (any library write touches `updated_at`). The
current run counts backwards from today — or yesterday, when today is
not yet active — so a streak that started yesterday is still alive;
the longest run walks the full (400-row capped) history. The dashboard
renders the current streak in the hero.

---

## 7. Repository methods added (Phase 8.3)

`filter()` · `countFiltered()` · `paginate()` · `filterOptions()` ·
`continueReading()` (delegate of `currentlyReading()`) · `dashboard()` ·
`readingSummary()` · `readingStreak()` · `preference()` ·
`savePreferences()` — plus the shared private `filterClause()` and the
`SORTS` / `PREFERENCE_COLUMNS` maps. Every query is a prepared
statement; no SQL exists outside this class.

## 8. Service methods added (Phase 8.3)

`continueReading()` · `libraryDashboard()` · `recommendedFor()` ·
`readingSummary()` · `readingStreak()` · `filterOptions()` ·
`filterLibrary()` (normalizes filters + falls back on junk sorts) ·
`viewPreference()` (allowlist merge + persistence) — plus the
constants `STATUSES` (labels), `SORTS` (labels), `VIEWS`,
`PER_PAGE_GRID = 12`, `PER_PAGE_LIST = 20`, `DEFAULT_SORT`,
`DEFAULT_VIEW`.

## 9. Controller methods added (Phase 8.3)

`filter()` · `sort()` · `viewMode()` · `continueReading()` — all thin
(policy → service → answer); `search()` and the page renderers were
upgraded to the shared grid/dashboard builders.

---

## 10. Validation & business rules (Phase 8.3)

- The filter allowlists live in the **service** (statuses, sorts,
  views); the repository never validates values it receives — the
  SQL only ever sees keys the maps allow.
- `library_view` is additionally CHECK-constrained in the database
  (migration 0018) — the last line of defence.
- `library_sort` deliberately has **no** CHECK: sorting is an
  open-ended map owned by the service.
- An unknown filter value is dropped, an unknown sort falls back to
  the default, an unknown view-mode value keeps the stored view —
  tampered requests can never produce an error, only defaults.
- Preferences are merged, never overwritten blindly: a plain grid
  request without a sort parameter keeps the user's stored sort.
- The `recommendedFor()` badge set is best-effort — without a wired
  engine (or on an engine failure) it is simply empty.

## 11. Security measures

- Prepared statements everywhere (no SQL injection).
- CSRF token on every write form and fetch (the view-mode POST).
- `AuthMiddleware` on every route + `LibraryPolicy` owner checks.
- Output escaping (`e()`) in every template (no XSS).
- Write-endpoint rate limiting (`library_write` bucket → 429) on the
  view-mode POST.
- The preferences UPSERT writes only the two allowlisted columns —
  a stray key can never inject a column.

---

## 12. Testing checklist

- `php tests/LibraryTest.php` — **227 checks, 0 failed** (178 from
  Phases 8.1–8.2 + 49 new Phase 8.3 checks: the `user_preferences`
  schema incl. the CHECK/FK/CASCADE defence, `filter` /
  `countFiltered` / `paginate` / `filterOptions` grid reads with the
  SORTS orderings, `readingSummary` aggregates, `readingStreak`,
  `viewPreference` merge-and-persist (incl. junk rejection),
  `libraryDashboard` composition, and the `filter` / `sort` /
  `viewMode` / `continueReading` controller endpoints).
- The other seven suites stay green — **1039 checks total, 0 failed**.

**Manual checklist** (see `docs/MANUAL_TEST_CHECKLIST.md` for the full
plan): open `/library` → greeting + chips; the stats row; Continue
Reading; live search with skeletons; filter by shelf / category /
author / rating / favourites / recency; sort by all eight orders; the
grid/list toggle (reloads in the chosen view); pagination (a filtered
page is reloadable — the URL keeps the query); the reading summary;
the streak chip; the recommended badges (when the engine is wired);
the no-JS fallbacks; responsive on mobile / tablet / desktop.

---

## 13. Documentation updated

- `docs/PHASE_8_3_LIBRARY_DASHBOARD.md` (this report).
- `docs/ARCHITECTURE.md` — the Personal Library entry extended with
  Phase 8.3.
- `README.md` — current phase, test totals and the docs index.
- `docs/MANUAL_TEST_CHECKLIST.md` — the Phase 8.3 manual plan.

---

## 14. Preparation notes for Phase 8.4

The analytics module (per the brief, **do not implement yet**) can
build directly on the Phase 8.3 reads:

- `readingStreak()` already returns both `current` and `longest`;
- `readingSummary()` already aggregates favourite genre / author and
  the average rating given;
- the dashboard's `user_preferences` pattern is the template for any
  future per-user settings (reading goals, collections);
- the grid's `filterClause()` is the single place a new filter column
  would be added.
