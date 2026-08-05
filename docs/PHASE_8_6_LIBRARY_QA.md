# Phase 8.6 — The Personal Library audit & QA pass

> **Phase:** 8.6 · **Module:** Personal Library (end-to-end audit)
> **Builds on:** Phases 8.1–8.5 (library, dashboard, engine integration) · **Complete**

## 1. Project analysis (what Phase 8.6 started from)

Phases 8.1–8.5 delivered the Personal Library: the CRUD backend, the
premium dashboard, the Smart Collections + bulk actions, and the
library-to-recommendation-engine integration. Phase 8.6 is the
**audit & QA pass**: every layer of the module was re-audited —
backend, frontend, performance, security, logging, accessibility and
the no-JavaScript paths — and every real problem found was fixed
WITHOUT new features, redesigns or changes to the completed business
logic. The mandate of the phase:

- **never rewrite working code** — only improve it;
- **no new features, no redesigns** — the module stays exactly as the
  brief specified;
- **verify every finding against the actual source** (line numbers,
  not hearsay);
- **lock every fix with a regression test**.

### The audit at a glance

| Layer | What was audited | Real problems found | Fixed |
|---|---|---|---|
| Recommendation integration | cache-hit limit handling, N+1 reads, logging on every render | cache-limit bleed, ~6× own-library reads, per-author loop, double dashboard logging | ✅ |
| Review integration | duplicate aggregation on the book page | two GROUP BYs per page | ✅ |
| Library service | preference writes, dashboard payload | un-audited preference changes, undefined `$libraryStats` warning | ✅ |
| User controller | session/user-row consistency | 404 crash when the user row is gone | ✅ |
| Frontend JS | progress confirm, skeletons, counter refresh, quick-menu sync | inert 100% confirm, skeleton stuck on failed fetch, stale streak chip, stale status select | ✅ |
| No-JS paths | delete + bulk delete | delete posted to the CREATE endpoint, bulk bar unreachable | ✅ |
| Accessibility | ARIA on links/buttons/icons/labels | `aria-pressed` on `<a>`, fake `aria-disabled`, decorative-icon aria-label, wrong checkbox title | ✅ |
| CSS | shared class names | `.library-chip` collision (header vs filter chips) | ✅ |
| Dead code | unused attributes/components | `data-view-endpoint`, `skeleton-stat.php` | ✅ |

---

## 2. The findings and their fixes

### 2.1 Backend correctness

**F1 — The personalized-shelf cache ignored the caller's limit.**
`getPersonalizedRecommendations()` served the cached shelf exactly as
stored: a shelf first generated with limit 10 stayed 10 items for a
later caller who asked for 5 (and vice-versa). The cache-hit restore
now re-applies **this caller's** limit and recounts the total:

```php
$items = $this->limitRecommendations((array) ($cached['items'] ?? []), $limit);
$cached['items'] = $items;
$cached['total'] = count($items);
```

**F2 — The dashboard logged the same shelf twice on every render.**
`DashboardController` logged `dashboard_recommended` AND
`because_you_read` every time the page rendered — so the
`recommendation_logs` audit trail inflated with every dashboard visit.
The fix: log only on **fresh generation**, gated by the new
`RecommendationService::personalizedShelfIsCached($userId)` helper
(the cache hit means the rows were already logged when generated);
the duplicate `because_you_read` re-log was removed.

**F3 — N+1: the own-library exclusion read ran up to ~6 times per
library page.** `libraryPageRecommendations()` called
`libraryBookIds()` once per section. The exclusion set is now loaded
**once per page** and threaded through `excludeOwnLibrary()` and
`libraryRecommendations()` via an optional `$ownLibrary` parameter
(the bounded `LIBRARY_EXCLUSION_LIMIT = 200` caps the set).

**F4 — N+1: the same-author section looped per author.**
`bookRecommendations()` called `booksByAuthor()` once per author
(up to `similarity` authors). It now batches them into one
`booksInAuthors()` IN-query; `booksByAuthor()` remains for the
`SameAuthorStrategy` and its tests.

**F5 — Two rating aggregations per book page.**
`BookController::show()` and `ReviewController::show()` each ran
`ratingDistribution()` (a GROUP BY) and then `ratingBreakdown()`
(another GROUP BY) with the same data. `ratingBreakdown()` now
accepts the distribution the summary already computed:

```php
public function ratingBreakdown(int $bookId, ?array $distribution = null): array
```

Both controllers pass `$summary['distribution']` — **one aggregation
per book page**.

**F6 — A preference change was not audited.** `viewPreference()`
persisted sort/view changes silently. It now logs
`library.preference_changed` (user id + the new pair) — the same
audit trail as every other library write — and only when a change is
actually saved.

**F7 — A dead session crashed the profile.** `UserController::show()`
indexed a missing user row. It now answers
`Response::error(404, 'Profile not found.')` (subprocess-tested,
because `Response::error()` exits).

**F8 — Magic numbers.** `200` → `RecommendationService::LIBRARY_EXCLUSION_LIMIT`;
`100` → `RecommendationRepository::MAX_LOG_BATCH`.

**F9 — A PHP warning on the dashboard.** Section 10 (Library Overview)
read `$libraryStats` when the payload could omit it. The variable is
now defaulted to `[]` and the whole section is guarded by
`if ($libraryStats !== []):`.

### 2.2 Frontend behaviour (library.js)

**F10 — The "Mark as Finished?" confirm was silently inert.**
`confirmAtHundred()` compared the input against its own
`dataset.previous` — but the callers overwrote that with the NEW value
before calling it, so `previous === 100` short-circuited and the
confirm never appeared; reaching 100 auto-finished without asking.
The helper now takes the previous value from the caller, and both call
sites (the Save-button submit and the slider-release change) capture
the committed value **before** storing the new one:

```js
const previous = Number(input.dataset.previous ?? input.defaultValue);
if (!confirmAtHundred(input, previous, input.value)) return;
input.dataset.previous = String(input.value);
```

**F11 — A failed filter fetch left the skeleton forever.** `run()`
swapped the results region for the loading skeleton and never restored
it when the request failed. A `lastGoodHtml` variable (the
server-rendered grid, then every successful response) is restored on
error or non-OK responses.

**F12 — A failed statistics fetch left the stat cells skeleton-filled.**
`refreshCounters()` now rebuilds every cell from its server-rendered
`data-stat-*` attributes (`restoreCell`) on failure.

**F13 — The streak chip went stale after a write.** `refreshCounters()`
refreshed the total and progress chips but not the reading-streak
chip, even though a write counts as an activity day. The statistics
fetch payload now carries the streak
(`LibraryController::statistics()`) and the JS updates the chip.

**F14 — The quick menu "Move to" left the card's status select stale.**
`repaintStatus()` repainted the badges but not the card's own status
`<select>`. It now syncs every `[data-library-status-select]` in the
container.

### 2.3 No-JavaScript paths (progressive enhancement)

**F15 — Remove-with-JS-off posted to the CREATE endpoint.** The
delete modal's form defaulted to `action="/library"` (create) and was
only rewritten by JS; with scripts disabled it could not even open
(the modal is Bootstrap-driven). The card and row Remove controls are
now **real CSRF-protected forms** (`data-library-delete-form`) posting
to `/library/{id}/delete` — the native POST + flash redirect is the
no-JS path. With JavaScript, the same forms open the shared
confirmation modal (the submit button carries `data-delete-url` /
`data-delete-title` as `relatedTarget`), and the no-modal fallback
fetch-deletes in place.

**F16 — The book panel double-confirmed.** The panel remove form had
an inline `onsubmit="return confirm(...)"` AND `handlePanelRemove()`
confirmed again — two dialogs for one delete. The inline one is gone;
the JS path asks once, the no-JS path is the plain POST + flash used
by every other control.

**F17 — The bulk bar was unreachable without JavaScript.** The bar
was server-rendered with `is-empty` (CSS `display: none`) and nothing
could ever remove it without JS, and the bulk Remove was a
Bootstrap-modal trigger. Now: the bar renders visible (JS collapses it
on load until a book is selected), and Remove is a real
`action=delete` submit that JS routes through the bulk confirmation
modal (the modal's own form keeps its fetch pipeline). A script-less
browser posts `action=delete` straight through `/library/bulk` with
the flash feedback.

### 2.4 Accessibility & CSS

- `_library-card.php` — the selection checkbox's `title` no longer
  lies about the favourite state ("Select {title}" always).
- `_collections.php` — `aria-disabled="true" tabindex="0"` removed
  from live links (a zero-count collection still filters the grid to
  its honest empty state).
- `_filters.php` — the view toggle uses `aria-current="true"` on the
  active `<a>` instead of the button-only `aria-pressed`; library.js
  mirrors the same attribute; the unused `data-view-endpoint`
  attribute is gone.
- `profile/show.php` — the decorative favourite icon is
  `aria-hidden="true"` instead of claiming a duplicate aria-label.
- `library.css` / `library/index.php` — the header chips were
  renamed `.library-chip` → `.library-stat-chip`: the class was
  **colliding** with the grid's active-filter chips (two incompatible
  definitions under one name, the later one overriding the header
  chips' look and shrinking the filter-chip count font).

### 2.5 Dead code removed

- `app/Views/library/components/skeleton-stat.php` — never included
  anywhere; its markup was duplicated inline in library.js (whose
  comments now say so).
- `data-view-endpoint` form attribute (read by nothing).

---

## 3. Files modified (Phase 8.6)

| File | Change |
|---|---|
| `app/Services/RecommendationService.php` | Cache-hit limit re-apply + recount (F1); `personalizedShelfIsCached()` (F2); `LIBRARY_EXCLUSION_LIMIT`, optional `$ownLibrary` threading (F3, F8); batched `booksInAuthors()` for same-author (F4) |
| `app/Repositories/RecommendationRepository.php` | `MAX_LOG_BATCH` constant (F8) |
| `app/Controllers/DashboardController.php` | Log `dashboard_recommended` only on fresh generation; duplicate `because_you_read` log removed (F2) |
| `app/Controllers/LibraryController.php` | `statistics()` fetch payload now carries the streak (F13) |
| `app/Services/LibraryService.php` | `viewPreference()` logs `library.preference_changed` on real changes (F6) |
| `app/Services/ReviewService.php` | `ratingBreakdown(int $bookId, ?array $distribution = null)` (F5) |
| `app/Controllers/BookController.php` · `ReviewController.php` | pass `$summary['distribution']` into `ratingBreakdown()` (F5) |
| `app/Controllers/UserController.php` | null-user 404 guard (F7) |
| `app/Views/dashboard/index.php` | `$libraryStats` defaulted + guarded section (F9) |
| `public/assets/js/library.js` | F10–F14, F15/F17 JS side, `aria-current` view toggle, `updateBulkBar()` on load |
| `app/Views/library/partials/_library-card.php` · `_library-row.php` | inline CSRF remove forms (F15); checkbox title fix |
| `app/Views/library/partials/_delete-modal.php` | docblock corrected (library.js wires it; the true no-JS path is the inline form) |
| `app/Views/library/partials/_book-panel.php` | duplicate inline confirm removed (F16) |
| `app/Views/library/partials/_bulk-bar.php` | visible without JS; Remove is an `action=delete` submit (F17) |
| `app/Views/library/partials/_collections.php` | live-link ARIA fix |
| `app/Views/library/partials/_filters.php` | `aria-current` view toggle, `data-view-endpoint` removed |
| `app/Views/profile/show.php` | decorative-icon `aria-hidden` |
| `app/Views/library/index.php` · `public/assets/css/library.css` | `.library-chip` → `.library-stat-chip` split (header chips) |
| `app/Views/library/components/skeleton-stat.php` | **deleted** (dead component) |
| `tests/RecommendationOptimizationTest.php` | +4 checks: cache-hit limit re-apply, `personalizedShelfIsCached` hit/miss/guest |
| `tests/LibraryTest.php` | +4 checks: streak payload, preference audit log (×2), 404 subprocess probe |
| `tests/ReviewTest.php` | +2 checks: `ratingBreakdown()` honours the precomputed distribution |

---

## 4. Testing checklist

- `php tests/LibraryTest.php` — **278 checks, 0 failed** (was 274):
  the statistics payload now ships the streak; a preference change
  leaves exactly one `library.preference_changed` entry per real
  change (junk pairs log nothing); the 404 probe (subprocess, because
  `Response::error()` exits) proves a dead session answers
  "Profile not found." instead of crashing.
- `php tests/ReviewTest.php` — **371 checks, 0 failed** (was 369):
  `ratingBreakdown()` with the precomputed distribution returns
  exactly the natural breakdown, and a synthetic distribution drives
  the rows (no re-query).
- `php tests/RecommendationOptimizationTest.php` — **57 checks, 0
  failed** (was 53): the cache-hit limit regression (cached 5, asked 3
  → exactly 3, total recounted) and the `personalizedShelfIsCached()`
  gate (hit / miss after invalidate / guest).
- All nine suites green — **1243 checks total, 0 failed**
  (LibraryTest 278 + ReviewTest 371 + ReviewIntegrationTest 109 +
  RecommendationLibraryIntegrationTest 147 + RecommendationOptimizationTest 57
  + the 3 phase-6 suites + BrowseTest 69).

**Manual checklist** (full plan in `docs/MANUAL_TEST_CHECKLIST.md`):

1. Sign in as Riya → dashboard → Recommended for You renders once per
   generation: open the log and confirm the `dashboard_recommended`
   rows do NOT grow on refresh (only on cache invalidation).
2. Drag a book's progress slider to 100 → the "Mark this book as
   Finished?" dialog appears; Cancel snaps the slider back.
3. Disable JavaScript → the Remove buttons on cards/rows post directly
   and flash; the bulk bar is visible, checkboxes appear on hover, and
   Move / Favourite / Remove all post natively; the view toggle still
   links.
4. Kill the network mid-search → the grid returns, no skeleton stuck;
   the stat cells restore their numbers after a failed refresh.
5. Save progress on a new day → the streak chip updates in place.
6. Quick menu "Move to Finished" → the card's status select follows.
7. Narrow the window → the header chips keep their layout while the
   filter chips stay pill-shaped (the `.library-chip` split).
8. Tab through the page: the view toggle announces the active view via
   `aria-current`; every checkbox/button has a truthful label.

---

## 5. What was deliberately NOT changed

- **The GET preference endpoints** (`/library/filter`, `/library/sort`)
  keep persisting preferences — existing behaviour, not a bug in scope.
- **`booksByAuthor()`** stays (SameAuthorStrategy + its tests still
  use it); only the book-page loop was batched.
- **No new routes, no new tables, no new config keys.** The streak
  rides the existing statistics endpoint; the 404 is the existing
  error response.
- **The module's feature set is untouched** — the audit only made it
  correct, faster and accessible.

---

## 6. Documentation updated

- `docs/PHASE_8_6_LIBRARY_QA.md` (this report).
- `README.md` — current phase, the Phase 8.6 paragraph, the "Done so
  far" list (8.5 + 8.6) and the test totals (1243).
- `docs/MANUAL_TEST_CHECKLIST.md` — the Phase 8.6 manual plan.
- `docs/ARCHITECTURE.md` — the Phase 8.6 notes on the hardened spots.

---

## 7. Preparation notes for the next phase

- `personalizedShelfIsCached()` is a reusable gate: the same
  "log once per generation" pattern can cover the book-page and
  library-page sections if their logging is ever audited for the same
  inflation.
- `ratingBreakdown()`'s optional distribution is the seam for any
  future aggregation reuse (e.g. a shelf that needs the same bars).
- The inline remove forms make every destructive control a plain CSRF
  POST — the pattern the books/reviews delete modals can adopt if they
  are ever brought to the same no-JS standard.
