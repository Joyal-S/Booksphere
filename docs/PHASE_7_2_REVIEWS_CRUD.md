# Phase 7.2 — Reviews & Ratings: Complete Review CRUD

## 1. Project Analysis (before implementation)

Phase 7.2 was built strictly on the Phase 7.1 backend, which was already
verified green (133/133 checks, 334 regression checks, 21/21 HTTP smoke):

- **The layered backend already existed and was NOT redesigned**: thin
  `ReviewController` → `ReviewService` (business rules) → `Review` model
  facade → `ReviewRepository` (all SQL) → PDO, with `ReviewDTO`,
  `ReviewPolicy` and `StoreReviewRequest` / `UpdateReviewRequest` in place.
  Phase 7.2 added NO new SQL, NO new business rules and NO new classes –
  it delivered the CRUD UI and the complete user workflows on top of the
  existing seams.
- **The workflow reads the Phase 7.1 service already provided**: the
  "already reviewed" decision, the rating summary, the owner-or-admin
  gates, the stats recompute and the write logging were all one call
  away. The Phase 7.2 work was therefore almost entirely view work plus
  the thin controller wiring that calls the prepared service.
- **Design system preserved**: every new template reuses the existing
  `card-base` cards, `form-input` component, `rating-stars` component,
  `empty-state` component, `alert` component, flash partial, Bootstrap
  collapse/modal and the app.js generic delete-modal handler – the same
  markup the Book module uses. Nothing was redesigned.
- **One shared modal, one shared list, one shared form**: the delete
  modal, the approved-reviews list and the review form are PARTIALS
  included by both the book detail page and the `/books/{id}/reviews`
  page, so the two pages can never drift apart.

## 2. Files Created

None. Phase 7.2 is the UI completion of the Phase 7.1 backend; the
review views and partials were delivered as part of this phase's
implementation along with the tests that verify them.

## 3. Files Modified

| File | Change |
|---|---|
| `app/Views/reviews/_form.php` | the shared review form: star rating (1–5) select, title (maxlength 120), body textarea (20–2000), `_token`, reusable `form-input` component |
| `app/Views/reviews/partials/_write-section.php` | the book-page write block: "Write Review" button + Bootstrap collapse form, or the "You have already reviewed this book." panel with View/Edit links |
| `app/Views/reviews/partials/_list.php` | the shared approved-reviews list with per-row Edit / Delete (owner-or-admin rows only) and the "Edited" badge |
| `app/Views/reviews/partials/_delete-modal.php` | the shared delete confirmation modal (CSRF POST, review title injected by app.js) |
| `app/Views/reviews/edit.php` | the edit page: prefilled shared form + "Edited" status in the header |
| `app/Views/reviews/show.php` | the single-review page with owner/admin actions |
| `app/Views/reviews/index.php` | "My Reviews" card grid with Edit/Delete + the shared modal |
| `app/Views/reviews/book.php` | the `/books/{id}/reviews` page: rating summary + write block + list |
| `app/Views/books/show.php` | embeds the "Reviews & Ratings" section (write block, summary, list, modal) |
| `routes/web.php` | added `GET /reviews/{id}` (`show()`) – the single-review page |
| `public/assets/js/app.js` | the generic `bindDeleteModal()` now also binds `#reviewDeleteModal` (same handler the book module uses) |
| `tests/ReviewTest.php` | extended with the Phase 7.2 checks (sections 10a–10f, see §12) |
| `README.md`, `docs/ARCHITECTURE.md`, `docs/MANUAL_TEST_CHECKLIST.md` | Phase 7.2 documentation (§13) |

## 4. Database Changes

None. Phase 7.1's migration 0014 already provides `title`, `status`,
`is_edited`, `updated_at` and the lookup indexes; the `reviews` table
and the `books.average_rating` / `books.ratings_count` columns are
untouched. All Phase 7.2 behaviour rides on the existing schema.

## 5. Routes Added

| Route | Method | Middleware | Action |
|---|---|---|---|
| `/reviews/{id}` | GET | Auth | `show()` — the single-review page |

Existing Phase 7.1 routes complete the CRUD surface: `/reviews` (index),
`/books/{id}/reviews` (GET bookReviews, POST store with CSRF),
`/reviews/{id}/edit` (GET/POST, CSRF), `/reviews/{id}/delete` (POST,
CSRF). Every route sits behind AuthMiddleware; owner-or-admin gates run
in the controller through `ReviewPolicy`.

## 6. Repository Methods

Unchanged from Phase 7.1 (all PDO prepared statements, no SQL anywhere
else): `insert / update / delete / find / findByBook / findByUser /
exists / averageRating / ratingCount / latestReviews / latest / oldest /
highestRated / lowestRated / approved / updateBookRatingStats /
ratingStats / findByUserAndBook / ratingDistribution`. The
`updateBookRatingStats` statement recomputes average AND count in one
UPDATE, so the two columns can never drift apart.

## 7. Service Methods

The Phase 7.2 CRUD vocabulary, added to `ReviewService` as thin
delegations over the single Phase 7.1 implementations:

- `createReview(ReviewDTO)` → `store()` – book-exists + duplicate rules,
  insert, stats recompute, cache invalidation, log
- `updateReview(id, ReviewDTO)` → `update()` – is_edited stamping when
  content actually changed, stats recompute, cache invalidation, log
- `deleteReview(id)` → `delete()` – hard delete, stats recompute, cache
  invalidation, log
- `validateReview(data)` → `errorsFor()` – the request rules, no
  duplicate logic
- `canUserReview(userId, bookId)` – "has not reviewed yet"
- `userHasReviewed(userId, bookId)` – the one-review-per-book read
- `recalculateBookRating(bookId)` / `recalculateReviewCount(bookId)` –
  both delegate to the single atomic `updateBookRatingStats` statement
- `userReview(userId, bookId)` – the signed-in user's own review row
  (the book page's "already reviewed" panel)

## 8. Controller Methods

`ReviewController` – thin as before (≤40 lines, no SQL, no business
logic): `index()` (my reviews), `show()` (single review + owner/admin
actions), `store()` (validate → policy → service → JSON or flash +
redirect, 422/409/404 errors), `edit()` (policy-gated, prefilled form),
`update()` (validation errors re-render the form; book/user carried from
the stored row, never from the form), `destroy()` (policy-gated POST,
flash + redirect), `bookReviews()` (rating summary + write block +
list). `BookController::show()` receives the same `ReviewService` and
asks it for the approved reviews, the summary and the user's own review.

## 9. Validation Rules

| Field | Rules | Message example |
|---|---|---|
| rating | required · integer · between 1–5 | "The rating must be a whole number between 1 and 5." |
| title | required · max 120 | "The title must not exceed 120 characters." |
| review | required · min 20 · max 2000 | "The review must be at least 20 characters." |
| book | must exist (`ReviewException::bookNotFound`) | "Book not found: N." |
| user | must be logged in (AuthMiddleware + `ReviewPolicy::canReview`) | 403 / redirect to login |

## 10. Business Rules

- One review per user per book – service check + `UNIQUE (user_id,
  book_id)` as the last line of defence (409 on race).
- Guests can never write a review.
- Only the owner (or an admin) can edit/delete a review – enforced per
  request by `ReviewPolicy` (no IDOR: the review id is never trusted
  without the ownership check).
- Editing stamps `is_edited = 1` ONLY when content actually changed; an
  unchanged re-save keeps the flag.
- Every create/update/delete recomputes the book's average rating and
  review count, invalidates the author's personalized recommendation
  shelf (the prepared hook) and writes a structured log line.
- Only `approved` reviews ever count towards averages, counts and
  public lists (moderation-proof by construction).

## 11. Security Features

CSRF token on every form and modal POST (verified: 419 without
`_token`); prepared statements only; `e()` output escaping on every
user-supplied value (names, titles, bodies, URLs); session-derived
identity (author id always `auth()->id()`, never the form); update and
delete verify ownership against the stored row before touching it
(IDOR-safe); friendly 403/404/409/422 responses that never expose SQL;
structured audit log (`review.created/updated/deleted` with user id,
book id, review id and timestamp).

## 12. Testing Checklist — all green

Automated: `php tests/ReviewTest.php` **133/133**, including the
Phase 7.2 inventory: `createReview`/`updateReview`/`deleteReview`
full pipelines with stats sync, the `userHasReviewed`/`canUserReview`
rule reads, the `recalculateBookRating`/`recalculateReviewCount`
restore methods, `userReview`, `ratingDistribution`, repository
`insert`/`findByUserAndBook`, the exact success message on the fetch
path, the single-review page (owner Edit action), and the book detail
page integration (review section renders, Write Review entry point,
form action + CSRF token, approved list, no delete controls for
non-owners, "already reviewed" panel with links, owner delete modal,
empty state). Regression: all five existing suites **334/334**
(Book module and Recommendation Engine unaffected), **21/21** HTTP
smoke checks, full-file PHP lint **0 errors**. Live HTTP probing
verified the real flow end-to-end: login → `/reviews` → `/books/1`
(review section) → `/books/1/reviews` (all 200 with expected content).

## 13. Documentation Updated

`README.md` (phase banner now 7.2, review-suite description + 133
checks, docs list), `docs/ARCHITECTURE.md` (Phase 7.2 completed on the
7.1 seam), `docs/MANUAL_TEST_CHECKLIST.md` (section 16 retitled
"Phase 7.1 + 7.2" with the book-page CRUD manual checks), and this
report.

**The review lifecycle:**

- **Create** – book detail → "Write Review" (collapse form) → validate
  → `ReviewService::store` (book-exists, duplicate check) →
  `ReviewRepository::create` (prepared INSERT) → `updateBookRatingStats`
  → recommendation-cache invalidation → log → "Review submitted
  successfully." flash → the review appears in the section.
- **Edit** – "Edit your review" → prefilled form → validate →
  `update` (content-diff stamps `is_edited`) → stats recompute →
  log → "Review updated successfully." flash → "Edited" badge on every
  list.
- **Delete** – Delete → shared confirmation modal → POST with `_token`
  → `delete` → stats recompute → log → "Review deleted successfully."
  flash → redirect to the book.

**Average rating calculation:** `books.average_rating` is recomputed by
one atomic `UPDATE` as `AVG(rating)` over the book's APPROVED reviews
(`ratings_count` in the same statement); every display reads the
denormalized columns, so no per-request aggregation is ever paid.

**Future integration:** the recommendation engine already consumes the
columns this phase keeps fresh, and every write invalidates the author's
personalized shelf; analytics can read the same aggregates; a helpful
votes table + `vote()` will slot beside `store()` in the service; the
`status` enum awaits moderation (7.4+), with aggregates already
excluding non-approved rows.

## 14. Preparation Notes for Phase 7.3

- **Interactive star component**: replace the rating `<select>` in
  `reviews/_form.php` with a clickable star input; `ratingDistribution()`
  and the `rating-stars` component already answer the display side.
- **Review cards**: the `_list.php` partial is the natural seam for a
  richer `ReviewCard` component (author avatar, helper text, actions).
- **Helpful votes / sorting / pagination**: `latestReviews()` and the
  scopes already sort; pagination can reuse the Book module's browse
  pattern (limit/offset with a total count).
- **Moderation (7.4+)**: flip `status` via new service methods; public
  reads and aggregates already exclude pending/hidden reviews.
- Stop condition: **wait for Phase 7.3 – Reviews & Ratings UI
  refinements.**
