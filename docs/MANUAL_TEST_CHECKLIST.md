# Manual Test Checklist – Book Module (Phase 5.6, extended through Phase 8.6)

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

## 16. Reviews & Ratings (Phase 7.1 + 7.2 + 7.3 + 7.4)

Automated first: `php tests/ReviewTest.php` (254/254, incl. the Phase
7.2 CRUD inventory, the Phase 7.3 component/analytics checks and the
Phase 7.4 professional review lists) plus all five regression suites
(334/334). Total: **588 automated checks**.
Migration 0014 must be applied
(`php database/migrate.php` → "Applied: 0014_extend_reviews_table").
Phase 7.2 is complete: the review form now lives on the book detail
page (`/books/{id}`), on `/books/{id}/reviews` and on
`/reviews/{id}/edit`, with the shared delete confirmation modal.

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

### Phase 7.2 — the complete review CRUD on the book page

- [ ] Book detail (`/books/{id}`) shows the "Reviews & Ratings"
      section with the rating summary; a user without a review sees
      the "Write Review" button that expands the form (rating select,
      title, 20–2000 char body, `_token`).
- [ ] Submit from the book page → "Review submitted successfully."
      flash; the review appears in the section; the book's stats
      strip updates (average + count).
- [ ] A user who already reviewed the book sees "You have already
      reviewed this book." with "View your review" and
      "Edit your review" links — no second form.
- [ ] `/reviews/{id}` (single review page) shows the full review with
      the book link; the owner/admin sees "Edit your review" and the
      delete button.
- [ ] Edit from the book page or `/reviews` → the shared prefilled
      form; saving unchanged content keeps `is_edited` untouched,
      changed content shows the "Edited" badge everywhere.
- [ ] Delete: the shared modal (same one used by books) opens with
      the review title, warns that the rating is removed and the
      stats are recalculated; confirming POSTs `/reviews/{id}/delete`
      with `_token` → "Review deleted successfully." flash, back on
      the book page with recomputed average/count.
- [ ] Responsive + dark mode: the section, forms and modal behave on
      mobile and in dark mode like the rest of the app (card-base,
      Bootstrap, existing design system — no redesign).

### Phase 7.3 — the interactive star rating & rating analytics

- [ ] Book detail (`/books/{id}`): the header stars and the sidebar
      "Ratings" stat show the REAL approved-review count (a seeded
      book that has 1 review says "Based on 1 review" — the sample
      3,100-value columns are never displayed).
- [ ] The "Rating breakdown" panel shows five bars (★★★★★ down to
      ★☆☆☆☆) with percentages + counts; the bars animate to their
      widths when scrolled into view (try the "reduced motion" OS
      setting — no animation).
- [ ] The distribution matches the summary: with one 5-star review,
      the 5-star bar is at 100%, the average shows 5.0.
- [ ] Review form: the rating `<select>` is gone — five clickable
      stars with hover highlighting and a live "You selected ★★★☆☆ 3
      Stars" preview; clicking star 3 fills stars 1–3 and submits
      rating 3.
- [ ] Keyboard: focus the star group (one Tab stop) → ArrowRight /
      ArrowLeft / Home / End change the selection; Space/Enter
      confirm; the preview updates; the form still validates.
- [ ] No-JS fallback: disable JavaScript, reload the write form — the
      hidden rating input still submits the selected value.
- [ ] After submitting a review, the book page's stars, count and
      bars all move together (e.g. a second 5-star review shifts the
      average to 4.0 and both bars to 50%).
- [ ] `/books/{id}/reviews` shows the same summary + distribution
      panel (identical numbers to the book page).
- [ ] Dashboard (`/`): "Top Rated Books" lists REAL books ranked by
      approved-review averages, each card with stars + count; a fresh
      high rating moves the book up the shelf after a reload.
- [ ] Profile (`/profile`): "My rating activity" shows reviews
      written, average given, the most recent rating (stars) and the
      highest-rated book; a user with no reviews sees the empty
      prompt.
- [ ] Admin (`/admin`): "Rating Analytics" shows the catalogue
      average + stars, the distribution bars, Highest rated / Lowest
      rated lists, "Without ratings" and "Average by category" — every
      number consistent with the book pages.
- [ ] Recommendation cards (`/recommendations`): the rating row now
      renders the star component + average; the explainability score
      chip is still visible.
- [ ] Book cards everywhere (browse grid/table) still render stars;
      the old `rating-stars.php` adapter keeps every pre-existing
      caller working.
- [ ] Assets: `rating.css` and `rating.js` load on every page (view
      source / network tab); dark mode keeps the stars and bars
      legible.

### Phase 7.4 — professional review lists (search, sort, filters, pagination, statistics)

- [ ] Book detail (`/books/{id}`): the review section now shows the
      summary header, the distribution, the "Write Review" block and
      the TOOLBAR (search box, Sort select, Per page select, star
      chips + "Edited only") above the professional review cards.
- [ ] Review cards: avatar with initials (same reviewer always the
      same gradient tone) + name linking to `/reviews/user/{id}`,
      stars, date, "Edited" badge on edited reviews, title, body with
      Read more / Read less, book link, disabled Helpful and Report
      buttons; the owner (or an admin) sees Edit + Delete.
- [ ] Read more: a review longer than ~250 characters shows "Read
      more"; clicking expands smoothly (GSAP) and the button becomes
      "Read less"; with "reduced motion" on, the text swaps without
      animation; the FULL text is always in the DOM (view source).
- [ ] Sorting: change the Sort select → the list reloads (skeleton
      cards flash briefly, no blank page) and the order changes
      (Newest / Oldest / Highest rated / Lowest rated / Most
      relevant); the selected sort survives a page change and the
      search term.
- [ ] Per page: change 10 → 20 → 50 → the results line ("Showing
      1–10 of N reviews") and the pager window update; the chosen
      size is remembered across pages.
- [ ] Pagination: with more results than one page, the pager (prev /
      numbers / next) works; the current page is highlighted; sorting
      / filters / search term are preserved in the pager URLs.
- [ ] Filters: click "4★" → only 4-star reviews remain (stats tiles
      and results line agree); add "Edited only"; a combo with no
      matches shows "No reviews match your filters" + "Reset filters"
      (resets to the full list).
- [ ] Search (book page or `/reviews/search`): a keyword matches the
      review TITLE, the review BODY and the REVIEWER'S NAME; the
      stats tiles + results line reflect only the matches; "No
      reviews match your search" + "Clear search" for a miss.
- [ ] `/reviews/search` (community timeline): with an empty box it
      lists every approved review newest-first; the "My reviews only"
      chip narrows it to the signed-in user's own reviews; with a
      term it becomes the search results page (sort + per page +
      chips all work).
- [ ] `/reviews/statistics`: the five platform tiles (total,
      average, highest, lowest, latest — truthful from the reviews
      table), the rating distribution bars, "My Review Activity"
      (signed-in), "Latest Reviews" and "Highest Rated Reviews"
      compact cards.
- [ ] `/reviews/user/{id}` (from any reviewer name / avatar): the
      reviewer's stats + their reviews, paginated and sortable; the
      owner sees their Edit / Delete actions; an unknown id → 404.
- [ ] `/reviews` ("My Reviews"): the signed-in user's own reviews
      with their statistics on top, full toolbar + pagination, and
      Edit / Delete on every row.
- [ ] Dashboard (`/`): "Recent Reviews", "Highest Rated Reviews" and
      "My Latest Review" are REAL rows from the reviews table (no
      placeholders), rendered as the compact cards.
- [ ] Profile (`/profile`): "Recent Reviews" block lists the user's
      latest reviews with the compact cards.
- [ ] Empty states: a book with no reviews shows "No reviews yet"
      (with the write prompt); a search miss shows the search copy;
      a filtered-out list shows the filter copy — each with the right
      action link.
- [ ] Loading skeletons: on any toolbar submit / page change the
      skeleton cards appear in place of the list (no layout shift);
      with JavaScript disabled every form still works (plain GET
      navigation).
- [ ] Guests: `/reviews/search`, `/reviews/statistics`,
      `/reviews/user/{id}` redirect to login like every review route.
- [ ] Assets: `reviews.css` and `reviews.js` load on every page with
      review lists; dark mode keeps the cards, chips, skeletons and
      pager legible.

## 17. Community engagement — helpful votes & reports (Phase 7.5)

- [ ] A review card (book page / `/reviews` / `/reviews/search`) shows
      a truthful "N helpful" count; clicking the Helpful button on
      your OWN review is disabled with the explanatory tooltip.
- [ ] Clicking Helpful on another user's review marks it pressed
      (`aria-pressed=true`) and the count +1; clicking again removes
      the vote and the count returns.
- [ ] The Report button opens the modal with the six reasons; a second
      click on the same button does not open a second report; after
      submitting you see "Thank you. Your report has been submitted."
- [ ] Admin (`/admin/reports`): the queue shows the Pending tab with
      the reported review, reason, reporter and reviewer context;
      Resolve / Dismiss move the report into its tab; Hide removes the
      review from every public surface and lists it under Hidden.
- [ ] Profile: "Community reputation" shows the Helpful Score (votes
      received across approved reviews).

## 18. Reviews & ratings across the platform (Phase 7.6)

- [ ] Sidebar / navbar: "Authors" and "Categories" are real links now.
- [ ] `/authors`: every author with a rating-badge (stars + value) and
      a review counter; unreviewed authors say "Not rated yet".
- [ ] `/authors/{id}` (e.g. George Orwell): three stat tiles (Total
      reviews, Books reviewed, Average rating), the review summary
      with distribution bars, Top reviewers, Highest rated book, Most
      reviewed book and Recent community reviews; an unknown id → 404.
- [ ] `/categories`: every category with its average rating and
      review count.
- [ ] `/categories/{id}` (e.g. Technology): total reviews / books
      reviewed / average, Top rated, Most reviewed, Community
      favourite (spotlight card) and Recently reviewed; an unknown id
      → 404.
- [ ] Dashboard (`/`): "Community Favourite Books" lists the most
      reviewed books (stars + count); "My Highest Rated Book" shows
      the book you rated highest (or the invite to write your first
      review).
- [ ] Profile (`/profile`): "Most reviewed category" tile, "Favourite
      genres" chips (with counts, linking to the category pages) and
      the "Review activity" timeline (bars per month — the tallest is
      full width, others scale; animates like the rating bars).
- [ ] Admin (`/admin`): the Rating Analytics section now opens with
      four headline tiles (Total reviews, Active reviewers, Books
      without reviews, Average platform rating) and the new blocks:
      Most active reviewers (ordered by review count), Most reviewed
      categories and Average by author — every number consistent with
      the book pages.
- [ ] Recommendations (`/recommendations`): the personal shelf still
      explains the score; the "Review score" factor (10%) now appears
      in the note line; a book the community rated 5.0 ranks higher
      than an equal-match book nobody has reviewed.
- [ ] Moderation + deletion discipline: hide a review as admin → the
      platform totals, shelves, author / category pages and admin
      analytics all drop the hidden review; soft-delete a book (admin)
      → it vanishes from every shelf and statistics until restored.

## 19. Production readiness (Phase 7.7)

- [ ] Rate-limited writes: fire more than 20 review writes within an
      hour (submit a review, edit it, repeat) → the next attempt
      answers `429 Too many requests - please try again in a minute.`
      and saves NOTHING; the same for Helpful toggles (60/minute) and
      Report submits (10/hour); the message disappears after the
      window.
- [ ] Duplicate reports: report the same review twice as the same
      user → the second submit is rejected (409); the admin queue
      shows exactly ONE report for that pair.
- [ ] Report your own review: the Report button is disabled on your
      own review (tooltip "You cannot report your own review") — no
      server error appears even when forced.
- [ ] Edit a review → the update works and the "Edited" badge
      appears (regression for the request-validation fix: no 500,
      field errors still render for invalid input).
- [ ] Avatar consistency: the same reviewer shows the same initials
      circle + gradient tone on the book page, the review lists, the
      single-review page and their reviewer page; a reviewer's avatar
      always links to `/reviews/user/{id}`.
- [ ] Dates: every review date ("M j, Y" e.g. "Dec 31, 2026") matches
      between the cards, the single-review page, the admin reports
      table and the profile; no empty/1970 dates appear.
- [ ] Distribution bars: the book page, the author/category summary
      cards and the admin "Distribution" card render the identical
      bars (same classes, same animation); each panel shows the same
      percentages for the same data.
- [ ] Skeleton: on any review-list reload the skeleton cards shimmer
      identically (one shared animation — no double-styled blocks).
- [ ] Typography: `.review-header-average` (book page) and
      `.review-card-title` (list cards) render in the site serif font
      (`--font-serif`), not the browser default.
- [ ] `php tests/ReviewTest.php` prints 369 checks, 0 failed; the
      other six suites stay green (812 total).

## 20. Personal Library (Phase 8.1 + 8.2)

### Phase 8.2 — the My Library page & CRUD

- [ ] Sidebar "My Library" opens the real `/library` page (six status
      sections + Favourites), not the old wishlist placeholder.
- [ ] Add a book: open a book detail page → "Add to Library" panel →
      pick a status → Save → flashed success; the book appears on the
      correct shelf with progress 0 (Want to Read), progress as chosen
      (Currently Reading auto-stamps the start date), or 100% +
      finished date (Finished).
- [ ] Duplicate prevention: adding the same book again is rejected
      (409/redirect with a friendly message) — one record per book.
- [ ] Remove: open a card's Remove button → the confirmation modal
      shows the book's title → Remove deletes ONLY the library record
      (the book stays in the catalogue) and the shelf counters refresh
      without a reload.
- [ ] Change status: pick a new shelf from a card's status select →
      the badge repaints in place; moving to Currently Reading never
      overwrites an existing start date; moving to Finished forces 100%.
- [ ] Favourite: clicking a heart toggles filled/empty with a GSAP
      pop and NO page reload; a finished book can also be a favourite;
      the Favourites section reflects the change immediately.
- [ ] Progress: drag a card slider / type a value → Save (auto-submits
      on release); moving to 100 first asks "Mark this book as
      Finished?" and only then auto-finishes the record.
- [ ] Search: type in the box → debounced live search shows skeleton
      cards then results (title / author / category); clearing the box
      returns to the whole library; the no-JS GET form works too.
- [ ] Counters: after any add / status change / remove / favourite,
      the tab counts and the intro line update from the same
      `/library/statistics` aggregate the statistics page uses.
- [ ] Library stats page: `/library/statistics` shows Total Books, the
      five shelves, Favourites, Average Progress and Books Added This
      Month on the shared stat cards.
- [ ] Empty states: a user with no books sees "No books in your
      library." + Browse Books; an empty section shows its own empty
      state.
- [ ] Dashboard: the "Continue Reading" section lists Currently Reading
      books newest-activity-first with the cover, progress bar and a
      working Resume button that opens the book page; empty when nothing
      is being read.
- [ ] No-JS: disable JavaScript — add, remove (modal via inline
      confirm), status change and progress save all still work (native
      POST + flash redirect).
- [ ] Security: all write forms carry `_token`; a tampered `user_id`
      is ignored (the session user is the owner); an admin cannot edit
      another user's library entry.
- [ ] Responsive: the library page, cards, tabs and the continue cards
      render cleanly on mobile, tablet and desktop; `prefers-reduced-
      motion` disables the pop/hover/lift animations.
- [ ] `php tests/LibraryTest.php` prints 178 checks, 0 failed; all eight
      suites stay green (990 total).

### Phase 8.3 — the premium My Library dashboard

- [ ] `/library` opens the dashboard: the hero greeting with your name,
      the streak / total-books / progress chips, the quick statistics
      row, the quick actions and the Continue Reading section.
- [ ] Hero chips: the streak chip shows a real consecutive-day count of
      library activity; the totals chip shows your shelf total; the
      progress chip shows the average of the started books.
- [ ] Filter bar: search (title / publisher / language / author /
      category), status, category, author and rating selects, the
      favourite / added-this-month / updated-last-30-days toggles and
      the reset link — each change updates the grid live with
      skeleton cards, no reload.
- [ ] Sort: the sort select offers the 8 orders (newest/oldest added,
      recently updated, title asc/desc, highest/lowest rated,
      progress); picking one re-sorts the grid and the choice sticks
      after a reload (`user_preferences`).
- [ ] View toggle: the grid/list icon switch changes the layout; the
      choice persists across visits; the no-JS links still work.
- [ ] Pagination: with >per-page books, the pager shows a ±2-page
      window with prev/next and a current-page highlight; a filtered
      or sorted page keeps its query in the URL so the browser
      back/reload reproduces it.
- [ ] Continue Reading: the section lists currently-reading books with
      the progress bar and a working Resume link; it refreshes without
      a reload after you finish or delete a book.
- [ ] Statistics row: after any add / status / progress / favourite /
      delete, the six stat cards, the header chips and the shelf-tab
      counters refresh from `/library/statistics` — skeletons show
      while loading.
- [ ] Reading Summary: favourite genre, favourite author, average
      rating given and average progress render truthfully from your
      own library (and your approved reviews).
- [ ] Recommended badges: when the recommendation engine is wired,
      "Recommended" badges appear on the cards it suggests (and never
      break the page when absent).
- [ ] Shelf tabs: clicking Want to Read / Currently Reading / Finished /
      Favourites filters the grid to that focus (Favourites pins the
      favourite filter) and the counters stay truthful.
- [ ] Empty states: no books in your library → the Browse Books empty
      state; a filter with no matches → its own "No books match these
      filters" state with a Clear filters link.
- [ ] No-JS: disable JavaScript — search / filter / sort (full GET),
      the view toggle (links) and every write (native POST + flash
      redirect) all still work.
- [ ] Security: the view-mode POST carries `_token` and is
      rate-limited (`library_write`); a tampered `sort=evil` or
      `view=evil` never errors nor clobbers your stored preference.
- [ ] Responsive: hero, chips, filter bar, list rows, the pager and
      the reading summary render cleanly on mobile / tablet / desktop;
      `prefers-reduced-motion` disables the animations.
- [ ] `php tests/LibraryTest.php` prints 227 checks, 0 failed; all eight
      suites stay green (1039 total).

### Phase 8.4 — Smart Library Organization

- [ ] Smart Collections rail: `/library` shows the seven tiles (All
      Books, the five shelves, Favourites), each with the book count,
      the average rating of its books and the last-updated stamp;
      empty shelves say "no books yet".
- [ ] Rail navigation: clicking a shelf tile filters the grid to that
      shelf and highlights the tile; Favourites opens
      `/library/favorites`; the URL stays shareable / reloadable.
- [ ] Rail refresh: after a write (status change, favourite, delete)
      the rail counts / ratings / stamps repaint in place.
- [ ] Description search: type a word that appears only in a book's
      description (e.g. "totalitarian" for 1984) — the grid finds it;
      the same word is never found in another user's library.
- [ ] New sorts: Most Reviewed ranks the platform's own most-approved
      books first; Most Recommended puts the engine's suggestions
      first (and still sorts sensibly when the engine is absent);
      both persist like the other sorts.
- [ ] Review counts: grid cards and list rows show the real approved
      review count of each book.
- [ ] Bulk selection: the checkbox on each card / row (and Select all)
      updates the sticky bulk bar counter; the bar hides again when
      the selection empties.
- [ ] Bulk move: select several books → Move To a shelf → Apply —
      the grid, the rail and the counters refresh; the statuses are
      really changed (no lifecycle timestamps invented).
- [ ] Bulk favourite / un-favourite: one click stars / un-stars every
      selected book; Favourites shelf and counters stay truthful.
- [ ] Bulk remove: Remove opens the confirmation modal listing the
      selected books; confirming deletes exactly those.
- [ ] Bulk no-JS: with JavaScript off the bulk form still submits
      (native POST) and lands on the filtered page with a flash.
- [ ] Quick action menu: the ⋯ button on a card and on a row offers
      View Details (book page), Move To (five shelves, updates in
      place), Favourite / Un-favourite, Share (disabled placeholder —
      "coming soon") and Remove (single delete modal).
- [ ] Keyboard: the bulk controls, the rail links and the quick menu
      are reachable by keyboard with visible focus states; the modal
      traps focus and closes with Escape.
- [ ] Dashboard integration: the dashboard shows Recently Added and
      My Favourite Books shelves with your own books, a Library
      Overview with your real totals, and the collections quick
      access strip.
- [ ] Profile integration: your profile page shows the My Library
      block — summary tiles (books / reading now / finished /
      favourites), your favourite books and categories, and the
      recently added / recently finished lists.
- [ ] Responsive: the rail degrades 7 → 4 → 2 columns, the bulk bar
      and quick menus stay usable on mobile / tablet / desktop.
- [ ] Security: `POST /library/bulk` requires `_token`, a session and
      an owner — a foreign record id in a bulk payload is silently
      skipped; junk actions answer 422; junk statuses answer 409.
- [ ] `php tests/LibraryTest.php` prints 274 checks, 0 failed; all
      eight suites stay green (1053 total).

## 21. Library Recommendations (Phase 8.5)

- [ ] Dashboard: sign in as Riya — the dashboard shows the
      Recommended for You shelf, the Because You Read shelf and the
      Trending Books shelf, each with real book cards carrying an
      explainable reason ("Recommended because you like Science
      Fiction.", "Similar to books you finished.", ...) and a
      confidence chip; the empty states render for a user without
      signals.
- [ ] Book page: open a book with community saves (e.g. 1984) — the
      page shows Readers also enjoyed / Same author / Same category /
      Similar rating / Similar popularity / Recommended for you
      shelves; the anchor book never repeats on its own page; NO
      cover appears twice across the shelves of one page; every card
      shows its reason.
- [ ] Book page as a guest: sign out and open a book — the community
      shelves still render; Recommended for you is simply absent.
- [ ] Library page: `/library` shows the "Recommended for your
      library" block before the filter bar — Because this is in your
      library, People who saved this also liked, Favourite category
      (named after your real top genre), Favourite author (named
      after your real top author) and Recently discovered; your own
      library books never appear in it.
- [ ] Library page empty state: a user with no library sees the
      usual empty states — the block never fabricates a shelf.
- [ ] Profile: `/profile` shows Reading Preferences & Recommendation
      Insights — the favourite categories / authors chips, the
      Recommendation Accuracy tile (a percentage once books have been
      recommended), and the books influencing your recommendations
      (favourites + finished, with covers).
- [ ] Accuracy: take one of the books your profile lists as
      recommended and save it to your library, then reload the
      profile — the acted count and the percentage rise.
- [ ] Cache: revisit the dashboard / library shortly after loading —
      the sections render instantly and `database/cache/recommendations/`
      contains `section_{user}_{section}.json` files (one per shelf);
      after changing your library (add / remove / favourite) the next
      visit rebuilds them.
- [ ] Logs: the `recommendation_logs` table grows one row per served
      book (reason + score + signal) and never grows past the
      configured per-user retention.
- [ ] Admin flush: `POST /admin/recommendations/cache/flush` clears
      the section files together with the hybrid shelves; the next
      page load rebuilds everything.
- [ ] `php tests/RecommendationLibraryIntegrationTest.php` prints 147
      checks, 0 failed; all nine suites stay green (1233 total).

## 22. Library audit & QA (Phase 8.6)

- [ ] Dashboard log hygiene: sign in as Riya, open `/dashboard`, then
      inspect `recommendation_logs` — refreshing the page adds NO
      `dashboard_recommended` rows (the shelf logs once per
      generation); flush the cache from `/admin/recommendations`, then
      one refresh re-logs it exactly once.
- [ ] Progress confirm: drag a book's slider to 100 → the "Mark this
      book as Finished?" dialog appears; Cancel snaps the slider back
      to its committed value; Save auto-finishes.
- [ ] No-JS remove: disable JavaScript, hover a card → Remove posts
      directly to `/library/{id}/delete` and flashes; the book panel's
      Remove and the row Remove do the same (no modal needed).
- [ ] No-JS bulk: with JS off, the bulk bar is visible immediately;
      checkboxes appear on hover; Move To / Favourite / Remove all
      submit natively (Remove sends `action=delete`) with flash
      feedback; the view toggle still links to the grid.
- [ ] Bulk modal: with JS on, select ≥1 book → Remove opens the
      confirmation modal; its list counts only the checked ids.
- [ ] Book panel single confirm: open a book page with JS on, Remove →
      exactly ONE confirm dialog appears (the inline one is gone).
- [ ] Skeleton recovery: search while the network is offline → the
      grid restores, no skeleton stays; after a failed statistics
      refresh the stat cells recover their numbers from the page.
- [ ] Streak chip: save progress on a new day → the streak chip in the
      library header updates in place (no reload).
- [ ] Quick menu sync: Move To "Finished" via the card's menu → the
      card's own status select follows the repaint.
- [ ] Chip split: narrow the window — the header stat chips keep their
      layout while the active-filter chips stay pill-shaped.
- [ ] A11y: tab through the library — the view toggle announces
      `aria-current` on the active link; the checkbox title reads
      "Select {title}" for every card; the profile favourite icon has
      no redundant aria-label.
- [ ] Preference audit: change sort / view twice →
      `recommendation_logs` holds two `library.preference_changed`
      entries (user id + new values); junk actions log nothing.
- [ ] Dead session: with a logged-in session whose user row is gone,
      `/profile` answers 404 "Profile not found." instead of crashing.
- [ ] `php tests/LibraryTest.php` prints 278 checks, 0 failed;
      `php tests/ReviewTest.php` prints 371; `php
      tests/RecommendationOptimizationTest.php` prints 57; all nine
      suites stay green (**1243 total**).
