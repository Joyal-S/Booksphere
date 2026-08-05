# Phase 7.1 — Reviews & Ratings: Architecture, Database Foundation & Core Backend

## 1. Project Analysis (before implementation)

The project was analysed first, per the brief:

- **The `reviews` table already existed** — migration 0007 (created in Phase 6 for
  the recommendation engine's rating signal) already provided
  `id, book_id, user_id, rating (CHECK 1–5), review, created_at`,
  `UNIQUE (user_id, book_id)` (the one-review-per-book rule, order-independent
  in SQLite), both foreign keys with `ON DELETE CASCADE` and the
  `idx_reviews_book` lookup index. **No table was recreated** — migration 0014
  only adds what the brief needs that was missing.
- **`books.average_rating` / `books.ratings_count` are already denormalized
  columns** (migration 0002, seeded with sample values) that every browse,
  search and recommendation query reads. The brief's "automatic average rating
  calculation / review count update" therefore maps onto ONE maintenance
  statement that recomputes both columns after every review write — the book
  module needed zero redesign.
- **Architecture conventions** were mirrored everywhere: thin controllers
  (Controller → Service → Model facade → Repository → PDO), declarative request
  rules (`BookRequest` → `StoreReviewRequest`), DTOs as `final readonly`
  value objects, `RecommendationPolicy` → `ReviewPolicy`, domain exception
  (`RecommendationException` → `ReviewException`), `final class` models as
  facades, views as PHP templates with `e()` escaping, and the
  fetch/JSON + redirect dual-path pattern of `toggleWishlist()`.

## 2. Files Created

| File | Purpose |
|---|---|
| `database/migrations/0014_extend_reviews_table.php` | incremental ALTER: `title`, `status`, `is_edited`, `updated_at` (+ backfill), indexes `idx_reviews_user` / `idx_reviews_rating` / `idx_reviews_created` |
| `app/DTO/ReviewDTO.php` | immutable `bookId/userId/rating/title/review` transport, `fromArray()` sanitizer |
| `app/Exceptions/ReviewException.php` | `bookNotFound` / `reviewNotFound` / `duplicateReview` / `permissionDenied` |
| `app/Requests/StoreReviewRequest.php` | declarative rules: rating required+int+1–5, title required+max 120, review required+min 20+max 2000, friendly labels |
| `app/Requests/UpdateReviewRequest.php` | delegates to StoreReviewRequest (same rules, no duplication) |
| `app/Repositories/ReviewRepository.php` | ALL review SQL: CRUD, findByBook/ByUser, exists, averageRating/ratingCount (approved only), latestReviews + the five scope reads, `updateBookRatingStats()` (one UPDATE, both books columns) |
| `app/Models/Review.php` | thin facade + relationship methods (`book()`, `user()`) + scopes (`latest/oldest/highestRated/lowestRated/approved`) |
| `app/Policies/ReviewPolicy.php` | `canReview()` (authenticated only), `canEdit()`/`canDelete()` (owner **or** admin) |
| `app/Services/ReviewService.php` | validation entry, book-exists + duplicate rules, is_edited flag, stats recompute, cache invalidation hook, write logging, future moderation/votes/reports/notifications hooks |
| `app/Controllers/ReviewController.php` | `index / store / edit / update / destroy / bookReviews`, ≤40 lines each, no SQL, no business logic, dual fetch/JSON path |
| `app/Views/reviews/index.php` | "My Reviews" (minimal list + Edit/Delete) |
| `app/Views/reviews/_form.php` | shared rating/title/review form (reuses `form-input` component) |
| `app/Views/reviews/edit.php` | edit page wrapper |
| `app/Views/reviews/book.php` | book reviews + rating summary page |
| `tests/ReviewTest.php` | Phase 7.1 suite, **133 checks** |

## 3. Files Modified

| File | Change |
|---|---|
| `routes/web.php` | `ReviewController` wiring + 6 new routes; `/reviews` now serves the real module instead of the coming-soon placeholder |
| `app/Controllers/PageController.php` | removed the now-unclaimed `reviews()` placeholder method (kept honest), docblock updated |
| `docs/ARCHITECTURE.md`, `docs/MANUAL_TEST_CHECKLIST.md`, `README.md` | Phase 7.1 documentation (sections 11–14 below) |

## 4. Database Changes

Migration `0014_extend_reviews_table` (applied to the live DB; forward-only,
never recreates tables):

- `ALTER TABLE reviews ADD COLUMN title TEXT NOT NULL DEFAULT ''` — max 120 chars
  (validated in the request layer; SQLite cannot ALTER-ADD a CHECK to an
  existing column, so length rules live where every other length rule lives:
  the Validator).
- `ALTER TABLE reviews ADD COLUMN status TEXT NOT NULL DEFAULT 'approved'` —
  moderation enum `approved | pending | hidden`, default approved, aggregates
  already count **approved only**, so moderation cannot leak into averages.
- `ALTER TABLE reviews ADD COLUMN is_edited INTEGER NOT NULL DEFAULT 0`.
- `ALTER TABLE reviews ADD COLUMN updated_at TEXT NOT NULL DEFAULT ''`, backfilled
  from `created_at` in the same migration.
- `CREATE INDEX idx_reviews_user (user_id)`, `idx_reviews_rating (rating)`,
  `idx_reviews_created (created_at)`.
- Already present (not duplicated): `UNIQUE (user_id, book_id)` (0007),
  `CHECK (rating BETWEEN 1 AND 5)` (0007), FK cascades (0007),
  `idx_reviews_book` (0007), `idx_reviews_book_created` (0013).

## 5. Routes Added

| Route | Method | Middleware | Action |
|---|---|---|---|
| `/reviews` | GET | Auth | `index()` — my reviews |
| `/books/{id}/reviews` | GET | Auth | `bookReviews()` — a book's approved reviews + rating summary |
| `/books/{id}/reviews` | POST | Auth + CSRF | `store()` — write a review |
| `/reviews/{id}/edit` | GET | Auth | `edit()` — the edit form (owner/admin via policy) |
| `/reviews/{id}/edit` | POST | Auth + CSRF | `update()` |
| `/reviews/{id}/delete` | POST | Auth + CSRF | `destroy()` |

Guests are blocked by AuthMiddleware on every route; the fine owner-or-admin
gate runs inside the controller through `ReviewPolicy`.

## 6. Model Relationships

- `Book hasMany Review` (reviews.book_id FK) — already declared in Book's docblock.
- `Review belongsTo Book` — `Review::book($row)` resolves via Book model.
- `Review belongsTo User` — `Review::user($row)` resolves via User model.
- `User hasMany Review` — documented in the Review model (no lazy-loading magic;
  the relationship methods resolve on demand).

## 7. Repository Methods

`create / update / delete / find / findByBook / findByUser / exists /
averageRating / ratingCount / latestReviews / latest / oldest / highestRated /
lowestRated / approved / updateBookRatingStats / ratingStats`
— all PDO prepared statements; no SQL anywhere else in the module.

## 8. Service Methods

`errorsFor / find / book / reviewsForBook / reviewsByUser / latestReviews /
statsForBook / store / update / delete`
— store() enforces book-exists + duplicate prevention; update() sets
`is_edited = 1` only when content actually changed; every write recomputes the
book's average_rating + ratings_count, invalidates the author's personalized
recommendation cache (the hook Phase 6.3 reserved) and logs the event.

## 9. Validation Rules

| Field | Rules | Message style |
|---|---|---|
| rating | required · integer · 1–5 | "The rating must be a whole number between 1 and 5." |
| title | required · max 120 | "The title must not exceed 120 characters." |
| review | required · min 20 · max 2000 | "The review must be at least 20 characters." |

## 10. Policy Rules

`canReview()`: authenticated only (guests → 403 before the controller).\
`canEdit()` / `canDelete()`: the review's owner **or** an admin; everyone else → 403.

## 11. Security Measures

Prepared statements everywhere (repository), CSRF (`_token`) on every POST
(CsrfMiddleware), output escaping (`e()`) in every view, session-derived
identity (the author id always comes from `auth()->id()`, never the form; an
update cannot re-point a review at another book because book/user are carried
from the stored row), fine authorization (ReviewPolicy) behind the route
middleware, friendly error responses (404/403/409/422) that never leak SQL,
and a write audit trail in `storage/logs/application.log`
(`review.created/updated/deleted` with user id, book id and the Logger's
timestamp).

## 12. Testing Checklist — all green

Automated (`php tests/ReviewTest.php`): **133/133** — schema columns + indexes,
unique-constraint enforcement, all validation rules, every repository query,
service rules (duplicate / missing book / is_edited), average + count sync on
create/update/delete, browse regression, policy matrix (guest/owner/other/admin),
model facade + relationships, controller wiring (fetch/JSON store, 422 errors,
rendered pages), logging audit trail. Regression: all five existing suites pass
(334 checks) + the 21/21 HTTP smoke suite; live HTTP probing exercised the real
flow: create → 302 + stats update, duplicate → 409, invalid → 302 + flash,
update → "Edited" badge, delete → stats recomputed. No PHP errors.

## 13. Documentation Updated

`README.md` (phase banner, folder structure, test instructions, docs list),
`docs/ARCHITECTURE.md` (security table, performance notes, extension points,
developer notes), `docs/MANUAL_TEST_CHECKLIST.md` (section 16, Phase 7.1 manual
checks), and this report.

**How Reviews integrate with the rest of the system:**

- **Books** — the show page already renders `average_rating` / `ratings_count`;
  the service now keeps those columns truthful on every write. The Phase 7.2
  book page will embed the existing `/books/{id}/reviews` section and its
  form (store() already answers JSON for the in-place submit).
- **Recommendations** — reviews are the primary quality signal; every write
  invalidates the author's personalized shelf, so the hybrid scores re-read the
  fresh rating history. The engine's count queries are already served by the
  `idx_reviews_book_created` composite index.
- **Analytics** — `averageRating()` / `ratingCount()` / the scopes are the
  ready-made aggregates for the future analytics module.
- **Future helpful votes** — a `helpful_votes` table + `vote()` method will slot
  beside `store()` in the service; the row shape already carries the review id
  a vote would reference.

## 14. Preparation Notes for Phase 7.2

- **Book-page UI**: embed the review section + form on `/books/{id}` using the
  existing `reviews/book.php` markup and the store() fetch/JSON path; show the
  "Your review" panel (with Edit link) when the user already reviewed the book
  (`exists()` is one call away), and the "Edited" badge from `is_edited`.
- **Per-field errors on the book page**: the 422 JSON payload already carries
  the `errors` map.
- **Rate limiting**: if the write endpoints need it, reuse the Phase 6.5
  `RateLimiter` (config-driven limits), exactly like the wishlist toggle.
- **Moderation (7.4+)**: flip `status` to `pending`/`hidden` via new service
  methods; aggregates and public reads already exclude non-approved reviews.
- Stop condition: **wait for Phase 7.2 – Reviews & Ratings UI.**
