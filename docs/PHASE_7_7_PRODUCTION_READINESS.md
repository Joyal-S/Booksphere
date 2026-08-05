# Phase 7.7 — Reviews & Ratings: Production-Readiness Pass

Phase 7.7 is the **quality gate** of the Reviews & Ratings module
(Phases 7.1–7.6). It adds **no new features**: its job is to audit
everything the module ships, fix what the audit finds, harden the
security and performance edges, extend the automated coverage for the
fixes, and document the results — so the module is defensible in a
viva and safe to hand over.

The one explicitly deferred feature is the **Wishlist** (Phase 8.1);
this phase only makes the toggle endpoint's rate-limit config
reusable for the review writes (see §6).

---

## 1. Architecture audit report

The audit walked the full stack — services, repositories, requests,
DTOs, exceptions, policies, models, controller, routes, middleware,
config, views, CSS and JS — and verified the module still honours the
project's invariants:

| Invariant | Verified |
|---|---|
| MVC layering: controllers thin, services own rules, SQL only in repositories | ✓ no new violations found |
| Only `status = 'approved'` reviews aggregate into averages / shelves / stats | ✓ every aggregation re-checked |
| Soft-deleted books excluded (`b.deleted_at IS NULL`) on every join | ✓ |
| Prepared statements everywhere; dynamic fragments only from allowlists | ✓ |
| Models are facades (no logic), views receive plain arrays | ✓ |
| All user output escaped with `e()` | ✓ |

Audit outcomes that needed **no code change** (documented so future
readers don't re-investigate):

- **Logger** uses level methods (`error` / `warning` / `info`) and
  writes one JSON line per entry (`time`, `level`, `message`,
  `context`) — a per-call severity parameter is not needed.
- **Review indexes** are already complete: `idx_reviews_user`,
  `idx_reviews_rating`, `idx_reviews_created` (0014), `idx_reviews_book`
  + `UNIQUE (user_id, book_id)` (0007), `UNIQUE (review_id, user_id)`
  on votes (0015). Only `review_reports` lacked a uniqueness guard
  (closed in this phase, §4).
- **Compact star row** on the dashboard card is the single remaining
  hand-rolled star markup; it is a deliberately different visual
  (five tiny stars, no numeric value) that the shared star-rating
  component cannot render without a new mode — kept, and tracked as
  accepted technical debt (§12).

---

## 2. Files modified

| File | What changed |
|---|---|
| `app/Requests/UpdateReviewRequest.php` | **Bug fix**: added the missing `use BookSphere\App\Core\Validator;` — `validate()` used to throw a PHP `TypeError` against the `Validator` return type |
| `app/Exceptions/ReviewException.php` | New `selfReport(int $reviewId)` factory ("You cannot report your own review N.") |
| `app/Services/ReviewService.php` | `reportReview()` throws `selfReport()` (was wrongly `selfVote()`) and logs the `book_id`; `percentageMap()` private helper; `ratingSummary()` fetches the distribution ONCE (removed a double GROUP BY); `ratingBreakdown()` / `distributionBreakdown()` share `percentageMap()`; `attachVoteState()` batches the vote reads (N+1 fix); length constants delegate to `StoreReviewRequest` |
| `app/Repositories/ReviewRepository.php` | Status constants `STATUS_APPROVED / STATUS_HIDDEN / STATUS_PENDING` replace every SQL literal; `approved()` delegates to `latestReviews()` (was duplicated SQL); `normalizeDistribution()` shared by `ratingDistribution()` / `ratingStats()`; `communityStats()` spotlight queries → one `bookSpotlight()` with an allowlisted ORDER BY; `authorStatistics()` top-book queries → one `authorTopBook()` with an allowlisted ORDER BY; new `userHelpfulVotes(userId, reviewIds)` batched read; **two corrupted double-quoted SQL fragments fixed** (`paginate()` and `reviewActivityTimeline()` — the status-constant refactor had leaked `\'` backslashes into the SQL) |
| `app/Requests/StoreReviewRequest.php` | Public constants `MAX_TITLE_LENGTH = 120`, `MIN_REVIEW_LENGTH = 20`, `MAX_REVIEW_LENGTH = 2000` — the single source of truth for the rules |
| `app/Models/Review.php` | New `userHelpfulVotes()` forward |
| `app/Controllers/ReviewController.php` | Optional `?RateLimiter $limiter = null` constructor param; `throttle('review_write')` in `store()` / `update()`, `throttle('review_vote')` in `helpful()` / `removeHelpful()`, `throttle('review_report')` in `report()`; private `throttle()` reading `config('recommendations.security.rate_limit')` (HTTP 429 when exhausted, no-op when the limiter is not wired) |
| `routes/web.php` | `ReviewController` is constructed with `new RateLimiter(session())` |
| `config/recommendations.php` | `security.rate_limit` gained `review_write` 20/3600, `review_vote` 60/60, `review_report` 10/3600 |
| `app/Helpers/helpers.php` | New `format_review_date()` — the one date formatter for every review surface (empty / invalid input → `''`, never the 1970 date) |
| `app/Views/reviews/partials/_avatar.php` | **New partial**: the initials + deterministic tone avatar (was duplicated three times) |
| `app/Views/reviews/partials/_rating-distribution.php` | Parametrised: optional `$title` (default "Rating breakdown", `''` suppresses the heading for pages with their own section title) and `$empty` message |
| `app/Views/components/review-card.php`, `app/Views/reviews/show.php` | Use the shared `_avatar.php` partial and `format_review_date()` |
| `app/Views/components/review-summary-card.php`, `app/Views/admin/index.php` | Distribution bars now include the shared `_rating-distribution.php` partial (was duplicated markup) |
| `app/Views/books/show.php`, `app/Views/admin/reports.php`, `app/Views/components/community-review-card.php`, `app/Views/components/recent-review-card.php`, `app/Views/components/review-stats.php`, `app/Views/profile/show.php`, `app/Views/reviews/statistics.php` | Use `format_review_date()` |
| `app/Views/components/loading-skeleton.php`, `public/assets/css/reviews.css` | Skeleton avatar + lines reuse the shared `.skeleton` shimmer (duplicated gradient block removed) |
| `public/assets/css/reviews.css` | Fixed `--bs-font-serif` → `--font-serif` in `.review-header-average` and `.review-card-title` (the variable is defined in `app.css`; the typo silently fell back to the browser default) |
| `tests/ReviewTest.php` | New section 14 "PHASE 7.7: hardening" — 24 new checks (see §10) |

---

## 3. Files created

| File | Why |
|---|---|
| `database/migrations/0016_add_review_report_unique_index.php` | The one-report-per-user-per-review guarantee at the database level (see §4) |
| `app/Views/reviews/partials/_avatar.php` | The single avatar implementation (initials, deterministic tone, link / decorative span) |
| `docs/PHASE_7_7_PRODUCTION_READINESS.md` | This report |

---

## 4. Database changes

Migration **0016** (`0016_add_review_report_unique_index.php`):

1. **Deduplicates** existing `review_reports` rows — for every
   `(reported_by, review_id)` pair only the oldest report survives
   (`MIN(id)`), so the new constraint cannot fail on data written by
   the pre-0016 code.
2. **Creates** `CREATE UNIQUE INDEX idx_review_reports_unique ON
   review_reports (reported_by, review_id)` — from now on the
   database itself rejects a second report of the same review by the
   same user (the service-level duplicate check remains, for the
   friendly 409 message).
3. `down` drops the index.

Already verified present (no work needed): `idx_reviews_user`,
`idx_reviews_rating`, `idx_reviews_created` (0014), `idx_reviews_book`
and `UNIQUE (user_id, book_id)` (0007), `UNIQUE (review_id, user_id)`
on `review_helpful_votes` (0015). The module's read paths are all
index-covered.

---

## 5. Performance improvements

| Before | After |
|---|---|
| `attachVoteState()` ran one `userHasHelpfulVote()` query per review (N+1 on every list) | One `userHelpfulVotes(userId, reviewIds)` batched `IN` query returns the whole voted-id map |
| `ratingSummary()` ran the distribution GROUP BY twice | The distribution is fetched once; percentages are derived with the shared `percentageMap()` |
| `communityStats()` ran three near-identical spotlight queries with hand-written ORDER BYs | One `bookSpotlight($bookId, $order)` with an allowlisted ORDER BY map |
| `authorStatistics()` ran two twin top-book queries | One `authorTopBook($authorId, $order)` with an allowlisted ORDER BY map |
| `ratingDistribution()` / `ratingStats()` each hand-rolled the 5→1 fill + sort | One shared `normalizeDistribution()` |
| `approved()` was a second copy of the `latestReviews()` SQL | Delegates to `latestReviews()` |

No behavior changed — every deduplicated query produces the same SQL
against the same indexes.

---

## 6. Security improvements

- **Write throttles for every review mutation.** `ReviewController`
  now carries the same session-backed `RateLimiter` pattern as
  `RecommendationController`:
  - `review_write` — **20 / hour** (create + update)
  - `review_vote` — **60 / minute** (mark / unmark helpful)
  - `review_report` — **10 / hour**
  The limits live in `config/recommendations.php →
  security.rate_limit`; an exhausted bucket answers
  `HTTP 429 "Too many requests - please try again in a minute."`
  before any database write. When no limiter is wired (tests) or no
  limit is configured the gate is a no-op, so the suite is unaffected.
- **Duplicate-report integrity moved into the schema** (migration
  0016, §4) — a race between two requests can no longer create two
  reports of the same review by the same user.
- **Correctness fix with a security flavour**: the missing
  `Validator` import in `UpdateReviewRequest` meant any edit-triggered
  validation crashed with a `TypeError` instead of returning field
  errors (a crash is an availability bug and a bad error surface).
- Re-verified: `review_write`/`review_vote`/`review_report` routes are
  CSRF-protected and login-gated before the throttle runs; sort
  allowlists and prepared statements unchanged (audit pass).

---

## 7. Code-quality improvements

- **The one real production bug fixed**: `UpdateReviewRequest`
  `TypeError` (see §6).
- **`selfReport()` vs `selfVote()`**: reporting your own review used
  to raise the *vote* exception with a confusing message; it now has
  its own factory and message, and the write log for a report carries
  the `book_id`.
- **Constants as the single source of truth**: `StoreReviewRequest`
  owns `MAX_TITLE_LENGTH / MIN_REVIEW_LENGTH / MAX_REVIEW_LENGTH`;
  `ReviewService` delegates to them; `ReviewRepository` owns
  `STATUS_APPROVED / STATUS_HIDDEN / STATUS_PENDING` and every SQL
  literal is gone.
- **The status-constant refactor was self-caught**: the mass replace
  corrupted two double-quoted SQL strings (`paginate()` and
  `reviewActivityTimeline()`); the corrupted literals were found by
  the failing suite and fixed, then re-verified. Lesson recorded: SQL
  fragments live in single-quoted PHP strings.
- **View duplication removed**:
  - avatar initials + tone block: 3 copies → 1 partial
    (`reviews/partials/_avatar.php`)
  - distribution-bar markup: 3 copies → 1 parametrised partial
  - `date('M j, Y', strtotime(...))`: 11 copies → 1 helper
    (`format_review_date()`)
  - skeleton shimmer CSS: 2 copies → 1 shared `.skeleton`

---

## 8. UI / CSS improvements

- **Skeleton reuse**: the loading skeleton's avatar and lines now
  carry the shared `skeleton` class (one shimmer implementation); the
  duplicated gradient block in `reviews.css` is gone.
- **Font bug**: `.review-header-average` and `.review-card-title`
  referenced the undefined `--bs-font-serif` (they silently rendered
  with the browser default serif); fixed to `--font-serif` (defined in
  `app.css`, load order verified: `app.css` → `rating.css` →
  `reviews.css`).
- **Consistency win**: the admin analytics "Distribution" card and the
  author/category summary cards now render the *same* partial as the
  book page — bars, labels and accessibility attributes can never
  drift apart again.
- **No layout changes**: every refactor kept the rendered markup
  byte-equivalent (verified by the existing view checks).

---

## 9. Accessibility

The audit re-verified the module's accessibility surface (no changes
needed):

- rating bars are real `role="progressbar"` elements with visible
  percent + count text;
- the star input is a WAI-ARIA radiogroup with roving tabindex and an
  `aria-live` preview;
- avatars used as links carry a title + `aria-label`; decorative
  avatars are `aria-hidden`;
- review dates render as `<time datetime="...">` on the full cards;
- the read-more toggle is keyboard accessible and honours
  `prefers-reduced-motion`;
- the refactored partials inherit all of the above by construction.

---

## 10. Testing report

`tests/ReviewTest.php` gained **section 14 "PHASE 7.7: hardening"** —
24 new checks:

1. Migration 0016: `idx_review_reports_unique` exists.
2. The database blocks a second report of the same review by the same
   user (raw double INSERT).
3. `userHelpfulVotes()` maps voted reviews, omits unvoted ones and
   tolerates missing ids (the N+1 fix).
4. `UpdateReviewRequest::validate()` returns a `Validator` (no
   `TypeError`) and still reports field errors.
5. `ReviewException::selfReport()` wording; `reportReview()` on the
   author throws the self-report exception.
6. Config contract: all three buckets exist with the exact
   20/3600, 60/60, 10/3600 numbers.
7. **The live 429 gate** — a subprocess probe (the suite writes a
   throwaway probe script to the temp dir, because
   `Response::error()` exits the process): a `store()` call after the
   `review_write` bucket is exhausted answers exactly
   `Too many requests - please try again in a minute.`, and a call
   under the limit passes the gate.
8. Helpers: `format_review_date()` shape, empty and invalid input;
   the avatar partial (initials, deterministic tone, `?` fallback);
   the distribution partial (`$title = ''` suppresses the heading,
   the default heading remains, custom empty state).

Full regression after all fixes:

| Suite | Checks | Result |
|---|---|---|
| `tests/ReviewTest.php` | 369 | 0 failed |
| `tests/ReviewIntegrationTest.php` | 109 | 0 failed |
| `tests/PersonalizationTest.php` | 62 | 0 failed |
| `tests/BrowseTest.php` | 69 | 0 failed |
| `tests/RecommendationArchitectureTest.php` | 86 | 0 failed |
| `tests/RecommendationOptimizationTest.php` | 53 | 0 failed |
| `tests/RecommendationDashboardTest.php` | 64 | 0 failed |
| **Total** | **812** | **0 failed** |

Every touched PHP file passes `php -l`.

---

## 11. Documentation updates

- `README.md` — phase banner updated to 7.7, "Done so far" extended,
  test counts refreshed (ReviewTest 369, total 812), docs list entry
  added.
- `docs/ARCHITECTURE.md` — Reviews module notes extended with the
  Phase 7.7 hardening items.
- `docs/MANUAL_TEST_CHECKLIST.md` — new section 19 (Phase 7.7 manual
  checks: rate-limited writes, unique reports, avatar/date/partial
  consistency).
- This report.

---

## 12. Remaining technical debt (accepted, documented)

| Item | Why it stays |
|---|---|
| Compact `.star-row` markup in `review-card.php` | Deliberately different visual (five tiny stars, no value) that the shared star-rating component can't render without a new mode; a new mode would touch CSS + JS + tests for marginal gain. One contained hand-roll, covered by a test. |
| `format_review_date()` shows UTC dates | The whole app displays UTC dates; timezone conversion is deliberately out of scope. |
| Rate limiter is session-backed | Documented scale-up path to shared storage in `RateLimiter`; fine for this deployment. |
| Review list pages fetch distribution + list as separate queries | Both are index-covered; merging is premature. |

---

## 13. Phase 8 preparation notes

- **Wishlist (Phase 8.1)**: the `wishlist_toggle` rate-limit bucket
  already exists in `security.rate_limit`; the review-write throttle
  pattern in `ReviewController::throttle()` is the template for the
  wishlist toggle controller.
- **Notifications**: `ReviewService` still documents the
  `notify()` hook point for report-resolution emails.
- **Distributed deployments**: move `RateLimiter` to shared storage;
  the review buckets are configuration, not code.
- **Moderation UI**: the admin queue is feature-complete; bulk
  actions and report-history pages are natural Phase 8 candidates.
