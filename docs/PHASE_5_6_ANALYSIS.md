# Phase 5.6 – Project Analysis Report

Date: 2026-08-03
Scope: the entire Book module (Phase 5.1 – 5.5), preparing the codebase for the Recommendation Engine (Phase 6).

> This report documents the state **before** the refactoring in this phase.
> Every finding below was verified by reading the code (no guesswork).

---

## 1. Architecture (what is already good)

```
Controller  ->  Service (business rules)  ->  Model (facade)  ->  Repository (SQL)  ->  PDO -> SQLite
```

- MVC layering is clean; controllers are thin and services make the decisions.
- `BookRepository::browse()` is **one** query behind search, filters, sorting and
  pagination (COUNT + LIMIT/OFFSET slice) - no duplicated SQL.
- User input is whitelisted twice before it can reach SQL:
  1. `BookService::combineFilters()` (constants + type checks),
  2. `BookRepository::SORTABLE_COLUMNS` / `DISTINCT_COLUMNS` (defence in depth).
- Every query uses prepared statements; views escape with `e()`.
- `MediaService` is a reusable, well-audited upload pipeline (MIME sniffing,
  structural validation, random names, path-traversal-safe deletes).
- The browse page and the live-search JSON endpoint share one partial
  (`books/partials/_results.php`), so they can never drift apart.
- Database indexes (verified against the dev database) already cover the browse
  filters: `idx_books_status`, `idx_books_language`, `idx_books_publisher`,
  `idx_books_published_year`, `idx_books_average_rating`, `idx_books_deleted_at`,
  `idx_books_created_at`, `idx_books_updated_at`, `idx_books_title`, plus
  composite junction indexes `idx_book_authors_author (author_id, book_id)` and
  `idx_book_categories_category (category_id, book_id)`.

## 2. Duplicate logic (fixes in this phase)

| # | Where | Problem | Fix |
|---|-------|---------|-----|
| D1 | `BookController::rawFilters()`, `books/index.php` (`chipUrl`), `books/partials/_results.php` (`$query`) | The 12-key filter list is written **three times**; every new filter must be added in three places | One `BookService::queryString($filters, $remove, $overrides)` builds every browse URL |
| D2 | `BookController::submitted()`, `defaults()`, `fromBook()` | Three parallel 12-field arrays | One `formValues(Request, ?array $book)` |
| D3 | `BookController::renderPartial()` | Re-implements the View engine | New `View::fragment()` in Core |
| D4 | `helpers.php`, `Database`, `BookService`, `books/_form.php` | `Config::loadFromDirectory()` globs+requires all config files **up to 4× per request** | Cached `config()` helper |
| D5 | `BookController::create/store/edit/update` | The form view payload (authors, categories, statuses, languages, old, errors) is repeated 4× | One `formData()` helper |
| D6 | `BookController::show/edit/update` | "find book or 404" repeated 3× | One `findOrFail()` helper |
| D7 | `books/components/book-card.php` | Re-implements the cover `<img>`/fallback instead of using the `book-cover` component | Reuse `book-cover` |
| D8 | `BookRepository::all()` | Re-writes the `SELECT_COLUMNS` subqueries inline instead of reusing the constant (and is dead) | Removed entirely |
| D9 | `components/book-card.php` vs `books/components/book-card.php` | Two components named `book-card.php` with different jobs | Dashboard card renamed `placeholder-book-card.php` |

## 3. Dead code (removed in this phase)

| # | Code | Why it is dead |
|---|------|----------------|
| E1 | `app/Policies/BookPolicy.php` | Zero callers; authorization is enforced by `AdminMiddleware` at the route table |
| E2 | `app/Core/Model.php`, `app/Core/Service.php` | No model or service extends them (all models/services are standalone final classes) |
| E3 | `Book::all()/search()/filter()/find()/findByCategory()/findByAuthor()` + repository counterparts | Zero callers; the service only uses `browse()/distinct()/create()/update()/findById()/findWithRelations()/softDelete()/replace*()/isbnExists()` |
| E4 | `books/components/loading-state.php` + `.book-loading` CSS block + `[data-loading-scope]` JS block | Leftovers from the pre-browse CRUD list; no view includes the component |
| E5 | `filterOptions()['years']` (a `DISTINCT published_year` query) | Computed on every browse load but never rendered (the year filter uses free-text inputs) |

## 4. Performance observations

- Browse page currently runs **6 queries**: categories + authors + publishers +
  years distinct, then COUNT + page slice. After removing the unused years query:
  **5 queries** (the two lookup lists are small, cached-free tables; acceptable).
- Config files are parsed up to 4× per request (D4) - fixed with one cached load.
- `LIKE '%term%'` free-text search cannot use B-tree indexes; measured at ~6 ms
  over 2,500 rows in the performance test - acceptable for this scale.
- Pagination is server-side and offset-based (correct for small/medium catalogues;
  cursor pagination would be the scale-up path - see technical debt).

## 5. Security review (verification + findings)

| Area | Status |
|------|--------|
| SQL injection | Safe - all values bound; sort/distinct columns double-whitelisted |
| XSS | Safe - `e()` on every value, attribute and option in the book views |
| CSRF | Safe - every state-changing route carries `CsrfMiddleware`; delete is POST |
| File upload | Safe - `is_uploaded_file`, sniffed MIME, pixel dims, PNG/JPEG/WebP structural checks, random names, whitelist extensions |
| Session | Safe - HttpOnly + SameSite=Lax + Secure-on-HTTPS, id regenerated on login/logout, login rate limiting |
| Authorization | **Finding F1**: `books/show.php` renders "Edit book" / "Delete book" buttons to *every* signed-in user (the routes behind them are admin-only, so the gate holds - but the buttons must not appear). Fixed by gating them on `auth_is_admin()`. |
| Error handling | 404s via `Response::error()`; graceful JSON for live search |

## 6. UI observations

- Dark mode, responsive grid and components are consistent (verified by reading
  CSS sections 10-12 and the views).
- `books/_form.php` uses an inline `style="max-width: 860px"` - replaced with a
  CSS class (design-system consistency).
- Dead loading-skeleton styles removed from `app.css`; the browse styles are
  untouched.

## 7. Code quality observations

- PSR-12 violations: `BookService` declares the `private readonly MediaService
  $media` property *after* the constructor (properties belong before methods).
- Naming is otherwise clear and consistent; comments are extensive and suitable
  for an MCA student reading the codebase.

## 8. Test coverage (before this phase)

- `tests/BrowseTest.php`: **66 assertions passing** - search, filters, sorting,
  pagination, injection resistance, performance (2,500+ rows), controller/view
  smoke tests (admin + non-admin).
- No regression suite existed for the book *form* (create/update) and the show
  page; the manual checklist (see `docs/MANUAL_TEST_CHECKLIST.md`) covers them,
  and a show-page authorization assertion was added to the automated suite.
