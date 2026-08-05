# Phase 7.4 — Professional Review Display, UX & Book Review Interface

## 1. Project Analysis (before implementation)

Phase 7.4 was built on the Phase 7.3 rating system, which was already
verified green (176/176 ReviewTest checks, 510 total across the six
suites, 21/21 HTTP smoke):

- **The review lists were plain**: the book detail page and
  `/books/{id}/reviews` rendered a simple `.review-list` of muted
  text blocks; "My Reviews" was a table; there was no search, no
  sorting, no filtering, no pagination and no statistics anywhere in
  the Reviews module. The dashboard's Recent Reviews / Highest Rated
  / My Latest Review sections still used placeholder data.
- **The backend was ready**: Phase 7.1 had already built the
  repository scope reads (`latest`, `highestRated`, ...) and Phase 7.3
  added `ratingSummary()` / `ratingBreakdown()`. What was missing was
  the *browsing* vocabulary — sort, search, filters, pagination and
  aggregates — plus a shared list presenter so every review surface
  could never drift apart.
- **One-composed-query rule**: the pager must always agree with the
  list, so `paginate()` runs ONE COUNT and ONE SELECT sharing a
  single WHERE builder, and the search runs INSIDE the SQL (a LIKE
  over the joined users table), never PHP-side filtering.
- **Truthfulness by construction**: every displayed number
  (statistics page, stats tiles, per-book summary) aggregates the
  real `reviews` table through the repository; the seeded SAMPLE
  `average_rating` / `ratings_count` columns are never displayed.
- **Phase 7.5 stays out of scope**: helpful votes, moderation,
  reporting and the real relevance algorithm arrive in Phase 7.5, so
  their buttons render disabled with explanatory titles and "Most
  relevant" is the temporary rating-first ordering (documented in the
  repository `SORTS` constant).
- **The router already prioritizes literal routes**, so the new
  `/reviews/search`, `/reviews/statistics` and `/reviews/user/{id}`
  can never fall into the Phase 7.2 `/reviews/{id}` parameterized
  route — they are still registered BEFORE it for clarity.
- **The 2-arg controller constructor had to survive**: older tests
  call `new ReviewController($service, $policy)`, so the presenter is
  an optional third parameter created on demand (a promoted-readonly
  reassignment is a PHP fatal — the property is declared separately).

## 2. Files Created

| File | Purpose |
|---|---|
| `app/Presenters/ReviewListPresenter.php` | the view-model of every review list: `state(Request)` (normalized sort / page size / page / search / filters), `toolbar($state, $base, $extra)` and `pagination($state, $result, $base)` payloads; `preservedParams()` keeps sort + q + filters across page changes |
| `app/Views/components/review-card.php` | the professional review card (see §6), one component with a full timeline variant and the `$compact` dashboard variant |
| `app/Views/components/review-header.php` | the review-section header: eyebrow, title, truthful average, stars and "Based on N reviews" |
| `app/Views/components/review-search.php` | the labelled search box (GET form semantics, works inside the toolbar form) |
| `app/Views/components/review-filters.php` | the star / Edited-only / My-reviews-only filter chips with preserved query strings |
| `app/Views/components/review-pagination.php` | the result line ("Showing 1–10 of 47 reviews"), the per-page select and the pager window (prev / numbers / next) |
| `app/Views/components/review-stats.php` | the five stat-card tiles (total, average, highest, lowest, latest) + the shared distribution bars |
| `app/Views/components/loading-skeleton.php` | shimmering placeholder cards matching the review-card layout (no layout shift) |
| `app/Views/reviews/partials/_toolbar.php` | ONE toolbar form carrying search + sort + per-page + hidden active filters, plus the filter chips |
| `app/Views/reviews/partials/_empty.php` | the three empty states (search / filters / none) through the shared empty-state component |
| `app/Views/reviews/partials/_section.php` | the full book review section (header, distribution, write form, toolbar, list, pagination) shared by the book detail page and `/books/{id}/reviews` |
| `app/Views/reviews/search.php` | the community review search / timeline page |
| `app/Views/reviews/statistics.php` | the platform review statistics page |
| `app/Views/reviews/user.php` | the public reviews page of one reviewer |
| `public/assets/css/reviews.css` | the review-list styles: timeline cards, toolbar, chips, read-more, skeletons (reuses the existing `rec-shimmer` keyframes), pagination, empty states |
| `public/assets/js/reviews.js` | the read-more/read-less enhancement (GSAP height animation, reduced-motion aware, full text stays in the DOM) and the loading-skeleton toggling on toolbar submits |

## 3. Files Modified

| File | Change |
|---|---|
| `app/Repositories/ReviewRepository.php` | Phase 7.4 section: `SORTS` allowlist + `sort()`, `paginate()` (one COUNT + one SELECT sharing `where()`), `search()` (LIKE over title / body / reviewer name through the join), `statistics()` (aggregate + GROUP BY distribution), `userReviews()`, and the private `where()` builder (book / user / rating / edited / q conditions; user-scoped lists skip the approved-only clause, community lists enforce `r.status = 'approved'`) |
| `app/Models/Review.php` | thin facade forwards: `sort`, `paginate`, `search`, `statistics`, `userReviews` |
| `app/Services/ReviewService.php` | `SORT_OPTIONS`, `PER_PAGE_OPTIONS`, `DEFAULT_PAGE_SIZE` constants; `sortReviews()` (allowlist), `normalizeListOptions()` (the single query-string gate: casts, trims, safe defaults), `paginateReviews()`, `searchReviews()`, `reviewStatistics()`, `userReviews()`, `highestRatedReviews()`, `distributionBreakdown()` (display rows 5→1 with percent) |
| `app/Controllers/ReviewController.php` | optional `ReviewListPresenter` (2-arg compatible); `index()` paginated + own stats; new `search()`, `statistics()`, `userReviews()` (404 for unknown users); `bookReviews()` paginated with the toolbar |
| `app/Controllers/BookController.php` | `show()` renders the review section through the shared presenter (`toolbar` + `pagination` passed) |
| `app/Controllers/DashboardController.php` | real review sections: Recent Reviews, Highest Rated Reviews and My Latest Review (placeholders removed) |
| `app/Controllers/UserController.php` | `show()` passes the user's 3 latest reviews |
| `routes/web.php` | `GET /reviews/search`, `GET /reviews/statistics`, `GET /reviews/user/{id}` registered (before `/reviews/{id}`); the shared `$reviewListPresenter` is created after `$reviewService` and injected into `ReviewController` |
| `app/Views/components/review-card.php` | rewritten (§6) |
| `app/Views/reviews/partials/_list.php` | renders the professional cards in the `.review-list` timeline with per-row `$canManage` (owner row or admin) |
| `app/Views/reviews/index.php`, `book.php`, `show.php` | rewritten onto the new components/partials |
| `app/Views/books/show.php` | the review section is now `_section.php` + `_delete-modal.php` |
| `app/Views/dashboard/index.php` | sections 5–7 use real review data; placeholder `$recentReviews` array deleted |
| `app/Views/profile/show.php` | new "Recent Reviews" block under the profile buttons |
| `app/Views/partials/head.php` / `scripts.php` | load `reviews.css` (after `rating.css`) and `reviews.js` (after `rating.js`) |
| `tests/ReviewTest.php` | Phase 7.4 section 12 (see §10) |
| `README.md`, `docs/ARCHITECTURE.md`, `docs/MANUAL_TEST_CHECKLIST.md` | Phase 7.4 documentation (§11) |

## 4. Database Changes

None. Everything is computed live from the existing `reviews` table
(migration 0014) with prepared statements; `books.average_rating` /
`ratings_count` stay as the write-path cache only.

## 5. Routes Added

| Route | Controller | Purpose |
|---|---|---|
| `GET /reviews/search` | `ReviewController::search` | the server-side review search / community timeline (sort, filters, pagination, "My reviews only" chip) |
| `GET /reviews/statistics` | `ReviewController::statistics` | the platform review statistics page |
| `GET /reviews/user/{id}` | `ReviewController::userReviews` | the public reviews page of one reviewer (linked from every card's avatar / name) |

All three sit behind `AuthMiddleware` like every review route and are
registered BEFORE `GET /reviews/{id}` (the router also resolves exact
routes first, so there is no collision).

## 6. The review card component contract

Any view sets `$review` (a row with the `user_name` / `book_id` /
`book_title` display columns) and requires the component:

```php
$review   = [...];  // required: the review row
$compact  = false;  // optional: the small dashboard/profile card
$manage   = false;  // optional: render the owner Edit / Delete actions
$showBook = true;   // optional: the book title link
$verified = false;  // optional: the Phase 7.5 "Verified" badge
require root_path('app/Views/components/review-card.php');
```

- **Full variant** (`review-card--full`, `data-review-card`): avatar
  with deterministic initials + gradient tone (`avatar-N` from
  `crc32(name) % 6`, so a reviewer always gets the same tone), linked
  to `/reviews/user/{id}`; reviewer name link; the "Edited" badge and
  the future-ready "Verified" badge; stars; review title; the body in
  a `data-review-body` element (read-more handled by reviews.js at
  ~250 chars — the full text always stays in the DOM); the book link;
  the disabled Helpful / Report buttons (Phase 7.5) and the
  owner/admin Edit / Delete actions when `$manage` is true.
- **Compact variant**: the classic two-line dashboard card (avatar,
  name link, stars, date, quote, book link) — one component, two
  layouts.

## 7. The list pipeline (presenter → service → repository)

Every review list page shares one pipeline, so the pages can never
drift apart:

1. `ReviewListPresenter::state($request)` — reads `sort`, `per_page`,
   `page`, `q`, `rating`, `edited`, `mine` through `Request::input()`.
2. `ReviewService::normalizeListOptions()` — the single gate: the sort
   key passes the allowlist (unknown → `newest`), the page size must be
   in `[10, 20, 50]` (else 10), the page number is clamped `>= 1`, the
   rating `1–5` (else 0 = no filter), `edited` / `mine` become booleans
   and `q` is trimmed. No value from the query string can ever reach
   the SQL raw.
3. `ReviewRepository::where($options)` builds the shared WHERE:
   - `book_id` / `user_id` / `rating` / `edited` / `q` as bound
     parameters only;
   - the status rule: a **user-scoped** list shows all of that user's
     reviews; every **community** read enforces
     `r.status = 'approved'`, so moderation states can never leak into
     public lists or statistics;
   - `q` becomes `(r.title LIKE ? OR r.review LIKE ? OR u.full_name
     LIKE ?)` — the search runs inside the SQL against the joined
     users table.
4. `paginate()` runs one COUNT and one SELECT over the same WHERE,
   then clamps the page into `[1, pages]`; `statistics()` runs one
   aggregate and one GROUP BY over the same WHERE. The pager line, the
   stats tiles and the list always agree.
5. `ReviewListPresenter::pagination()` builds the payload; the pager
   links preserve `sort` / `q` / `rating` / `edited` / `mine` and only
   replace the page number.

## 8. The truthful statistics surface

| Surface | Source | Shows |
|---|---|---|
| `/reviews/statistics` | `reviewStatistics()` + `highestRatedReviews(5)` + `latestReviews(5)` | platform total / average / highest / lowest / latest, the distribution bars, the signed-in user's activity, the newest and highest-rated community voices |
| Stats tiles on every review list | `reviewStatistics()` over the filtered rows | total, average, highest, lowest, latest for exactly the listed rows |
| Book section + `/books/{id}/reviews` | `ratingSummary()` + `ratingBreakdown()` | real average, real count, distribution bars (unchanged from 7.3) |
| Dashboard Recent / Highest Rated / My Latest | `latestReviews(4)` / `highestRatedReviews(4)` / `reviewsByUser(me, 1)` | real community voices and the user's own latest review |

Every aggregate is a prepared statement over the `reviews` table; the
seeded sample columns are never displayed.

## 9. Security & Accessibility

- **Security**: the sort key is allowlisted (`SORTS` + fallback), so a
  crafted `sort` value cannot inject SQL or crash the query; every
  filter value (rating, page size, page, user, book) is cast to int
  and range-checked; the search term and every other value are bound
  parameters; page sizes are allowlisted and the page number clamped;
  all page output passes through `e()` escaping; the toolbar forms are
  GET (state lives in shareable URLs, no CSRF surface) and the only
  new POST-dependent markup (Delete) reuses the existing CSRF-protected
  Phase 7.2 flow.
- **Accessibility**: the read-more button is a real `<button>` with
  `aria-expanded` and the full text stays in the DOM (screen readers
  and no-JS visitors always see everything); `prefers-reduced-motion`
  skips the GSAP animation; the skeletons are `aria-hidden` and fade in
  with no layout shift; the filter chips are labelled links with
  visible focus; the selects have `<label>`s; the avatar links carry
  `title` + `aria-label`; the star rows keep their `role="img"`
  labels.

## 10. Testing Checklist — all green

Automated: `php tests/ReviewTest.php` **254/254** — the new Phase 7.4
section 12 covers the controlled dataset (six reviewers, one fresh
book): the paginated list (true total, page count, window, page
clamp, large page size), all five sorts including the unknown-key
SQL-injection fallback, the rating / edited / user filters and their
combos, the server-side search over title / body / reviewer name
(case-insensitive, combined with filters, empty result), the
statistics (true average 20/6, highest / lowest, latest date, the
5→1 distribution with zero-filling, honouring user and rating
filters), the service normalization gate (safe defaults, casts,
invalid values), `distributionBreakdown()` percentages, the presenter
payloads (state, toolbar, pagination, preserved params), the
controller renders of My Reviews / search / statistics / per-user /
book pages (toolbar, stats tiles, results line, filter state, the
"My reviews only" empty state, the community timeline) and the shared
components (card full + compact + owner actions, toolbar form + chips,
the three empty states, skeleton count, search box, stats panel +
distribution bars).

Regression: all five existing suites **334/334** (Book module and
Recommendation Engine unaffected) — **588 checks total, 0 failures**;
full-file PHP lint **0 errors (178 files)**; **21/21** HTTP smoke
checks. Live HTTP verification: login → `/reviews?sort=oldest&per_page=20`
(toolbar, stats, pagination, cards) → `/reviews/search?q=the&rating=5`
(filtered results + results line) → `/reviews/statistics` (tiles, My
Review Activity, community shelves, distribution bars) →
`/reviews/user/1` → book detail with a fresh review submitted
end-to-end over HTTP (write form → redirect → "Based on 1 review" →
professional card with read-more body + Helpful/Report placeholders →
found again through `/reviews/search?q=smoke` with the correct pager
line), followed by a clean deletion through the service (book stats
stay in sync).

## 11. Documentation Updated

`README.md` (phase banner now 7.4, review-suite description + 254
checks + 588 total, docs list), `docs/ARCHITECTURE.md` (Reviews
module entry updated with the presenter layer and the list pipeline),
`docs/MANUAL_TEST_CHECKLIST.md` (section 18 "Phase 7.4: professional
review lists" manual checks), and this report.

**The browsing flow:** a reader opens a book → sees the summary
header, the distribution, the toolbar and the paginated professional
cards → types a keyword, picks a sort, clicks a star chip or changes
the page size → the toolbar form submits with loading skeletons, the
pager always agrees with the list → clicks a reviewer name → lands on
that reviewer's public page with their truthful statistics → opens
the statistics page for the platform-wide numbers. Every number is a
live aggregation of the approved reviews; nothing is sampled.

**Future work (Phase 7.5):** the disabled Helpful / Report buttons
become real (votes + moderation), the "Verified" badge is issued, and
the temporary `relevant` sort is replaced by the real relevance
algorithm — the `SORTS` allowlist and the `where()` builder are the
exact seams those features plug into.
