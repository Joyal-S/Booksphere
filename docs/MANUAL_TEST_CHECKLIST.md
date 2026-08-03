# Manual Test Checklist – Book Module (Phase 5.6, extended through Phase 6.5)

Run the automated suite first (`php tests/BrowseTest.php`), then walk
through this checklist in the browser. Two accounts exist in the seed
data: an **admin** (`admin@booksphere.test`) and a regular **user**.

Setup: `php -S localhost:8000 -t public`, open <http://localhost:8000>.

---

## 1. Authorization

- [ ] Guest: `/books`, `/books/search`, `/books/7`, `/books/create` all
      redirect to the login page.
- [ ] Regular user: can open `/books` and the search box works; NO
      "Add Book" button, NO delete icons in the table, NO Edit/Delete
      buttons on a book's detail page.
- [ ] Regular user: typing `/books/create` or `/books/7/edit` manually
      redirects to login.
- [ ] Admin: sees "Add Book", row actions, status column, Edit/Delete
      on the detail page, and the delete confirmation modal.

## 2. Book create

- [ ] Admin opens `/books/create`; the form shows four sections
      (Basic / Publishing / Details / Media).
- [ ] Submit empty → validation errors under the title, status and
      language fields; nothing is saved.
- [ ] Valid submit (title required; year between 1000 and now;
      page count ≥ 1) → success flash, redirect to the detail page.
- [ ] Authors and categories are selectable as checkboxes; both are
      stored and shown on the detail page.

## 3. Book update

- [ ] Edit page is prefilled from the database (including the
      checkbox selections).
- [ ] Change the title, save → detail page shows the new title and a
      success flash.
- [ ] Submit an invalid year (e.g. 500) → error, previous input kept.
- [ ] A duplicate ISBN (of another book) → "A book with this ISBN
      already exists."; editing the book itself with its own ISBN
      passes.

## 4. Book delete (soft delete)

- [ ] Delete from the table row or the detail page → confirmation
      modal shows the correct title and cover.
- [ ] Confirm → success flash, book disappears from browse/search.
- [ ] The row still exists in the database (`deleted_at` set).

## 5. Cover upload

- [ ] A valid JPG/PNG/WebP (< 5 MB) shows a live preview before save
      and the stored image on the detail page.
- [ ] Oversized file (> 5 MB) → instant client message + server error.
- [ ] Renamed `.exe` → "The file type is not allowed."
- [ ] "Remove cover" on the edit page → after save, the placeholder
      cover shows and the old file is gone from `public/uploads/`.
- [ ] Replacing a cover leaves no old file behind.
- [ ] Drag & drop works; keyboard (Enter/Space on the dropzone) opens
      the file picker.

## 6. Search

- [ ] Live search: typing debounces and updates results without a
      page reload; the URL stays shareable.
- [ ] Search matches title, author name, ISBN, publisher, category
      and description (e.g. `harry`, `rowling`, `9780439064873`,
      `harper`, `fantasy`).
- [ ] Search with no JavaScript (or after disabling JS) still works
      via the Search button (full page).
- [ ] No-match search shows the "No results" empty state.
- [ ] `Ctrl+K` focuses the search box.

## 7. Filters

- [ ] Category, author, language, status and rating selects filter
      correctly and auto-submit.
- [ ] Publisher box + datalist suggestions filter as you type.
- [ ] Year range (From/To) narrows the results.
- [ ] Active filters appear as chips; clicking a chip removes exactly
      that filter; "Clear all" resets everything.
- [ ] Combined search + filters + sort + page size all persist in the
      URL (refresh keeps the state).

## 8. Sort & pagination

- [ ] Every sort preset works: newest, oldest, A–Z, Z–A, highest/lowest
      rated, publication year (NULL years last), recently updated.
- [ ] Page size 10/20/50/100 re-renders and persists.
- [ ] Pagination bar: First/Prev/Next/Last, windowed numbers, ellipses
      for large page counts, aria-labels, current page marked.
- [ ] Deep pages load fast (e.g. per_page 10, page 50 with the 2,500
      performance rows in `browse_test.db`).

## 9. Grid / table view

- [ ] Toggle switches between grid (cover cards) and table.
- [ ] The choice is remembered across reloads (localStorage).
- [ ] No-JS fallback: the table renders by default.

## 10. Responsive & dark mode

- [ ] Sidebar collapses on desktop and slides over on mobile; the
      toolbar rows wrap instead of overflowing at ~375 px width.
- [ ] Grid cards reflow to 1 column on small screens; the table
      scrolls horizontally.
- [ ] Dark mode: browse page, forms, chips, pagination, modals and
      cards all readable with the correct palette.

## 11. Image loading & errors

- [ ] Covers lazy-load (`loading="lazy"`); a broken remote cover shows
      the placeholder tile instead of a broken image icon.
- [ ] The delete modal preview shows the correct cover or fallback.

## 12. Routes & error handling

- [ ] `/books/999999` → plain 404 "Book not found."
- [ ] `/books/create` as guest/user → redirected.
- [ ] Direct `POST /books/7/delete` without a CSRF token → rejected.
- [ ] The JSON endpoint `/books/search` returns valid JSON with
      `html`, `total`, `page`, `pages`, `perPage`, `query`.

---

## 13. Recommendations module (Phase 6.2)

Run the automated suites first: `php tests/RecommendationArchitectureTest.php`
(86/86) and `php tests/BrowseTest.php` (69/69). The HTTP smoke test is
`php tools/smoke_recommendations.php` (start the server with
`php -S 127.0.0.1:8123 -t public` first).

- [ ] Log in as a regular user and open `/recommendations`: six
      strategy cards (Popular, Top Rated, Recently Added, By Category,
      More Like This, Trending) with icons and "Ready" chips.
- [ ] `/recommendations/popular` renders a "Popular right now" shelf:
      real book cards, each with a reason badge ("High average rating
      with strong review counts", ...), a "Running now" chip on the
      Popular card and run metadata (strategy, generated time, count).
- [ ] `/recommendations/top-rated` shows only books with 5+ reviews,
      ordered by average rating (Sapiens, ...); books with fewer
      reviews are absent.
- [ ] `/recommendations/trending` lists books with review/wishlist
      activity in the last 30 days, highest momentum first.
- [ ] `/recommendations/recent` lists the newest books first.
- [ ] `/recommendations/category/{id}` shows the category shelf
      (title A–Z); a bogus id (e.g. `/category/999`) is a 404.
- [ ] `/recommendations/book/{id}` shows "More Like This": other books
      by the anchor's authors, with the anchor itself excluded.
- [ ] The home `/recommendations` shelf merges Popular, Top Rated and
      Recently Added without duplicates.
- [ ] A deleted or draft book never appears on any shelf.
- [ ] Logged out: all seven routes redirect to login;
      `/recommendations/personalized` is a 404.

## 14. Personalization (Phase 6.3)

Automated first: `php tests/PersonalizationTest.php` (62/62), then the
Phase 6.2 regression suites above. The DB migration 0012 must be
applied before any of this works.

- [ ] Log in as `admin@booksphere.test` / `Admin@123` and open
      `/recommendations`: a new "Recommended for you" shelf appears
      above the strategy cards.
- [ ] Every personal card shows a reason badge (e.g. "You enjoy
      Science Fiction and History books.", "Similar to books in your
      wishlist.") and a score chip (`XX/100 · High/Medium/Low`).
- [ ] Books in the logged-in user's wishlist never appear on the
      personal shelf.
- [ ] Open any book (`/books/{id}`), go back to `/recommendations`:
      the opened book is absent and similar books now carry a
      "recently viewed" style reason.
- [ ] Log in as a second user (or create one with different
      wishlist/ratings): the personal shelf is visibly different.
- [ ] A brand-new user (no wishlist, no ratings) still gets a shelf —
      community picks with honest reasons, low confidence chips.
- [ ] The six strategy cards and their shelves are unchanged and still
      render alongside the personal shelf.
- [ ] Cache: revisit `/recommendations` immediately — the shelf is
      served from the per-user file under
      `database/cache/recommendations/` (check the file exists and is
      recreated after its 30-minute TTL expires).

## 15. Production readiness (Phase 6.5)

Automated first: `php tests/RecommendationOptimizationTest.php` (53/53)
plus all four regression suites (334/334 total), then the smoke test
(`php -S 127.0.0.1:8123 -t public` + `php tools/smoke_recommendations.php`,
21/21). Migration 0013 must be applied (`php database/migrate.php`).

- [ ] Freshness: open `/recommendations` — the hero shows "Updated
      just now" (or "Updated X minutes ago" on a cached visit) next to
      the quality ring, and the "Recommended for you" section header
      shows the same phrase in a small chip. Hovering reveals the exact
      generation timestamp.
- [ ] Cross-section duplicates: a book that belongs to both the
      personal shelf and "Because you liked" / "Follow" / "Trending"
      appears exactly ONCE (the main shelf wins); the section totals
      stay accurate.
- [ ] Score scale: every score chip on the dashboard reads between 0
      and 100 on the same scale (personal, popularity, trending, recent,
      "more like this").
- [ ] Admin monitoring: log in as `admin@booksphere.test`, open
      `/admin/recommendations` — the four blocks render: **Cache**
      (files, bytes, stale, cached users, writability), **Config**
      (weights, pool, confidence, limits), **Data** (book/review/
      wishlist/view counts, top categories & authors), **Scores**
      (average popularity/trending, raw + normalized).
- [ ] Regular user: `/admin/recommendations` → 403; guest → redirect to
      login.
- [ ] Cache flush tool: click "Flush recommendation cache" on the
      admin page → success flash, the page's Cache block shows 0 files,
      and the next visit to `/recommendations` rebuilds the shelf
      ("Updated just now").
- [ ] Direct `POST /admin/recommendations/cache/flush` without a CSRF
      token → rejected.
- [ ] Throttle (refresh): click "Refresh my recommendations" 30+ times
      within a minute → the 31st attempt answers HTTP 429 instead of
      refreshing. Wait a minute, then the button works again.
- [ ] Throttle (wishlist): toggle a book in the wishlist 60+ times in
      one minute → 429; the toggle itself still works normally
      otherwise.
- [ ] Indexes: `php database/migrate.php` reports 0013 applied, and
      `EXPLAIN QUERY PLAN` on a recommendations query shows the
      composite indexes (`idx_reviews_book_created`,
      `idx_wishlist_book_created`, `idx_book_views_user_viewed`,
      `idx_books_status_deleted`) being used.
- [ ] Degradation: temporarily make `database/cache/recommendations/`
      unwritable (or drop a corrupt JSON payload into a shelf file) →
      `/recommendations` still renders, rebuilding the shelf, and a
      warning is logged instead of a 500.

## 16. Reviews & Ratings (Phase 7.1)

Automated first: `php tests/ReviewTest.php` (91/91) plus all five
regression suites (425/425 total). Migration 0014 must be applied
(`php database/migrate.php` → "Applied: 0014_extend_reviews_table").
The review form currently lives on `/books/{id}/reviews` (POST target)
and `/reviews/{id}/edit`; the in-page form on the book detail page
arrives in Phase 7.2.

- [ ] Log in as `riya@booksphere.test` and open `/reviews` — "My
      Reviews" lists her seeded reviews with the book title, star
      rating, title, body, date and Edit/Delete actions.
- [ ] Open `/books/{id}/reviews` for a book with no reviews — the
      "No reviews yet" empty state shows with the correct book
      headline.
- [ ] Write a review (POST `/books/{id}/reviews`): submit rating 5 +
      title + a 20+ character body → redirected back to the book
      with a success flash; the book reviews page now shows the new
      review, the reviewer name and "1 review".
- [ ] The book's stats strip on `/books/{id}` and the browse rating
      reflect the change (average = the submitted rating, count = 1).
- [ ] Duplicate: POST the same book again (as the same user) → 409
      "already reviewed" — the UNIQUE index also blocks a direct
      INSERT.
- [ ] Validation: submit rating `0` or `6`, a 121-char title, or a
      19-char body → flash error / 422 with friendly messages; a
      rating of `3.5` is rejected (whole numbers only).
- [ ] Edit: open `/reviews/{id}/edit` (the owner) → the form is
      prefilled; change the rating/title/body and save → redirect to
      the book, and both review lists show the "Edited" badge.
- [ ] Authorization: `arjun@booksphere.test` opening riya's review
      edit URL → 403 "not allowed"; riya's own review → form loads.
- [ ] Delete: owner clicks Delete → confirmation, review disappears,
      the book's count drops back and the average is recomputed.
- [ ] Admin override: log in as `admin@booksphere.test` → the admin
      can edit and delete ANY user's review (same Edit/Delete flow).
- [ ] Guests: `/reviews`, `/books/{id}/reviews` and all review POSTs
      redirect to login.
- [ ] CSRF: POST `/books/{id}/reviews` without a valid `_token` →
      419; the review forms all render a hidden `_token` field.
- [ ] Audit trail: `storage/logs/application.log` gains
      `review.created` / `review.updated` / `review.deleted` JSON
      lines with `user_id`, `book_id` and the timestamp.
- [ ] Recommendation hook: as a user with a cached personal shelf,
      write a review, revisit `/recommendations` — the shelf is
      rebuilt ("Updated just now") because the rating signals
      changed.
