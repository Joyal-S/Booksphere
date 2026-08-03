# Phase 6.1 — Recommendation Engine: Architecture

Scope: **architecture only**. The strategy pattern, dependency injection,
interfaces, DTOs and the full request pipeline are implemented and tested.
The scoring **algorithms** are deliberately NOT implemented — they are the
Phase 6.2 deliverable (see §9). No changes were made to the Book module
(its regression suite still passes 69/69).

---

## 1. Project analysis (what Phase 6.1 started from)

| Area | Finding | Decision |
|---|---|---|
| Signal tables | `reviews` (0007), `wishlist` (0008), `recommendations` (0009) already exist, fully migrated and seeded | **No new migration needed.** The `recommendations` table (user_id, book_id, score 0–100, reason, created_at, UNIQUE(user_id, book_id)) is the storage sink the migration itself describes as "the recommendation engine writes its results here" |
| Layering | Book module: Controller → Service → Model facade → Repository → PDO/SQLite | Recommendations mirror it, with the strategy layer between Service and Repository |
| Placeholder route | `/recommendations` was a `PageController` "coming soon" page | Replaced by `RecommendationController`; the now-dead `PageController::recommendations()` was removed (Phase 5.6 dead-code rule) |
| Autoload | PSR-4 `BookSphere\App\` → `app/` | New folders (`Strategies`, `DTO`, `Policies`, `Exceptions`) need no composer change; `composer dump-autoload` was run |
| Router | Exact routes tried before parameterized | `/recommendations/popular` etc. can never collide with `/recommendations/book/{id}` |
| Infrastructure | `db()` (PDO singleton), `Response::view/error`, `View`, `auth_*` helpers, `AuthMiddleware` | All reused; `RecommendationPolicy` adds the module's fine authorization gate |

## 2. Architecture

```
HTTP request
    │  routes/web.php  (AuthMiddleware + SecureHeadersMiddleware)
    ▼
RecommendationController          thin: authorize → build context → render
    │  RecommendationPolicy        fine authorization gate (auth_check)
    │
    ▼
RecommendationService             ORCHESTRATOR: choose → validate → execute → return DTO
    │  (never SQL, never scoring)
    ▼
RecommendationFactory             registry: strategy key → strategy instance
    │  (unknown key → RecommendationException)
    ▼
RecommendationStrategy            interface contract
    │  supports(context)          can this algorithm run with this input?
    │  recommend(context)         run the algorithm (Phase 6.2)
    ▼
PopularStrategy │ RatingStrategy │ CategoryStrategy │ AuthorStrategy │ RecentStrategy
    │  constructor-injected data channel (one shared instance)
    ▼
RecommendationRepository          ALL SQL, prepared statements only
    │  wishlist (0008) │ reviews (0007) │ recommendations (0009) │ books
    ▼
db() → PDO → SQLite
```

Data flow of one request (e.g. `/recommendations/trending`):

1. `RecommendationController::trending()` → `authorize()` (policy) → builds `RecommendationContext` from route/query values (sanitized in `fromArray()`).
2. `RecommendationService::recommend('rating', $context)` → `RecommendationFactory::make('rating')` → `RatingStrategy`.
3. The service checks `supports($context)` (loud `RecommendationException` → 404 on mismatch).
4. `RatingStrategy::recommend($context)` returns a `RecommendationResult` DTO (Phase 6.1: placeholder with a real catalogue count read through the repository — proof the whole chain works end to end).
5. The controller renders `views/recommendations/index.php` (strategy cards + result section).

## 3. Files created

| File | Role |
|---|---|
| `app/Controllers/RecommendationController.php` | Six actions: `index`, `show` (per-book), `personalized`, `popular`, `trending`, `recent` |
| `app/Services/RecommendationService.php` | Orchestrator: choose → validate → execute → DTO; also the strategy→route map for the overview cards |
| `app/Services/RecommendationFactory.php` | Strategy registry (variadic constructor, keyed by `key()`, loud failure on unknown keys) |
| `app/Strategies/RecommendationStrategy.php` | The interface: key/label/description/icon/supports/recommend |
| `app/Strategies/AbstractRecommendationStrategy.php` | Shared scaffold: repository DI + the Phase 6.1 placeholder `recommend()` |
| `app/Strategies/PopularStrategy.php` | Key `popular` — catalogue-wide popularity signal |
| `app/Strategies/RatingStrategy.php` | Key `rating` — top rated; stands in for *trending* until Phase 6.2 |
| `app/Strategies/CategoryStrategy.php` | Key `category` — user/category/book-anchored; stands in for *personalized* until Phase 6.2 |
| `app/Strategies/AuthorStrategy.php` | Key `author` — "more like this" (anchor book required) |
| `app/Strategies/RecentStrategy.php` | Key `recent` — newest arrivals |
| `app/Repositories/RecommendationRepository.php` | All data reads: activeBookCount, recommendationsForUser, wishlistBookIds, recentBooks, topRatedBooks, booksByCategory, booksByAuthor — prepared statements, `deleted_at IS NULL`, LIMIT bound |
| `app/DTO/RecommendationContext.php` | Immutable input: userId/bookId/categoryId/authorId/limit (sanitized in `fromArray()`, limit clamped 1–50) |
| `app/DTO/RecommendationResult.php` | Immutable output: strategyKey/label, items, total, note, generatedAt |
| `app/Policies/RecommendationPolicy.php` | Fine authorization gate (route middleware is the coarse gate) |
| `app/Exceptions/RecommendationException.php` | Unknown strategy / unsupported context → controller turns it into a 404 |
| `app/Views/recommendations/index.php` | One template for all six routes: strategy cards + result section |
| `tests/RecommendationArchitectureTest.php` | 52-assertion CLI suite (see §7) |

Files modified: `routes/web.php` (wiring + 6 routes), `app/Controllers/PageController.php`
(dead `recommendations()` removed), `public/assets/css/app.css` (`rec-*` strategy-card
styles), `docs/ARCHITECTURE.md` (§6 extension points).

## 4. Folder structure (new module)

```
app/
├─ Controllers/  RecommendationController.php
├─ Services/     RecommendationService.php, RecommendationFactory.php
├─ Strategies/   RecommendationStrategy.php (interface),
│                AbstractRecommendationStrategy.php,
│                PopularStrategy.php, RatingStrategy.php,
│                CategoryStrategy.php, AuthorStrategy.php, RecentStrategy.php
├─ Repositories/ RecommendationRepository.php
├─ DTO/          RecommendationContext.php, RecommendationResult.php
├─ Policies/     RecommendationPolicy.php
├─ Exceptions/   RecommendationException.php
└─ Views/recommendations/  index.php
```

## 5. Routes

All behind `AuthMiddleware` (login required), open to every signed-in user:

| Route | Action | Strategy |
|---|---|---|
| `/recommendations` | index | — (overview of the five strategies) |
| `/recommendations/popular` | popular | `popular` (Popular) |
| `/recommendations/trending` | trending | `rating` (Rating — stand-in until Phase 6.2 momentum) |
| `/recommendations/recent` | recent | `recent` (Recent) |
| `/recommendations/personalized` | personalized | `category` (Category — stand-in until Phase 6.2 profile) |
| `/recommendations/book/{id}` | show | `author` (Author — "more like this") |

## 6. Extension points (for Phase 6.2+)

- **New strategy** = one class + one line in the factory wiring (`routes/web.php`). Nothing else changes (Open/Closed).
- **Hybrid strategies** — the service is the funnel: a `HybridStrategy` receives other strategies (or the factory) and combines their results; no pipeline change.
- **Storage** — `RecommendationRepository::recommendationsForUser()` already reads the `recommendations` sink table; a future "save/regenerate" flow writes through a new repository method, computation and display stay separated (per the migration's design notes).
- **Signals** — `wishlistBookIds()` (wishlist), reviews (via `topRatedBooks()` + a future per-user rating read), stored scores (recommendations). No schema change required.
- **Caching / auditing / persistence** — add around `RecommendationService::recommend()`, which every request already funnels through.
- **Explanation UI** — the DTO carries `note` today; Phase 6.2 adds per-item `reason` ("Because you liked The Martian") without changing the pipeline.
- **Trending momentum** — swap `RatingStrategy` for a dedicated momentum strategy behind `/recommendations/trending`; the route wiring only changes the key.
- **Fine authorization** — new `RecommendationPolicy` methods (e.g. admin-only regeneration) plug in without touching routes.
- **Config** — `config/recommendations.php` (limits, default strategy, enabled strategies) slots into `config/` and the factory wiring when needed.

## 7. Testing checklist (Phase 6.1)

Automated — `php tests/RecommendationArchitectureTest.php` → **52/52**:

1. Context: positive ids pass, junk → null, user fallback, limit clamp (1–50), non-numeric → default
2. Factory: make() for all five keys, unknown key throws, registration order preserved
3. Service: returns the DTO, placeholder metadata, note reaches the database (real catalogue count), unsupported context throws, strategy metadata + overview URLs
4. Strategies: all implement the interface; support contracts (Popular/Rating/Recent = any request; Category = user/category/book; Author = author/book)
5. Repository: activeBookCount matches SQLite, LIMIT honoured, relation lists attached, category/author filters honoured, wishlist + recommendations reads work
6. Policy: denies guests, allows signed-in users
7. Controller smoke: every route renders, active card highlighted, guest gated (302)

Regression — `php tests/BrowseTest.php` → **69/69** (Book module untouched).

Manual — `php -S localhost:8000 -t public`, log in as `riya@booksphere.test / User@123`:

- [ ] `/recommendations` shows five strategy cards with icons and "Architecture ready" chips
- [ ] `/recommendations/popular`, `/trending`, `/recent`, `/personalized` render a result section with a real catalogue count and the active card highlighted
- [ ] `/recommendations/book/1` renders "More Like This"
- [ ] Sidebar "Recommendations" highlights on all six pages
- [ ] Logged out: all six routes redirect to `/login`; `/recommendations/xyz` → 404
- [ ] Admin and regular user both see all pages (no admin-only restriction)

## 8. Design decisions worth defending in a viva

- **Strategy pattern** — algorithms are isolated classes behind one interface; routes swap strategies without touching the algorithm classes (demonstrated today: `/trending` runs the Rating strategy as a documented stand-in).
- **Service never sees SQL** — the orchestration funnel enforces the layering; repositories own prepared statements and the `deleted_at IS NULL` rule.
- **Loud failure** — unknown keys and unsupported contexts throw instead of silently degrading; the controller maps them to 404s.
- **Immutable DTOs** — context and result can't be mutated mid-pipeline; the request stays side-effect free.
- **Zero schema change** — the Phase 5 migration 0009 was already designed as the recommendation sink; Phase 6.1 proves it (and wishlist/reviews) are sufficient.
- **No dead code** — every class is used: the policy is called by every action, the repository is exercised by the pipeline's placeholder note and by the test suite, and the dead `PageController::recommendations()` was removed.

## 9. Phase 6.2 recommendations (next)

1. **Implement the five `recommend()` algorithms** using the existing repository reads; keep them deterministic and explainable:
   - Popular: wishlist-save count (+ review activity) as the popularity signal
   - Rating: `topRatedBooks()` with a confidence threshold (minimum `ratings_count`)
   - Recent: `recentBooks()` (already a plain read)
   - Category: derive favourite categories from the user's wishlist/reviews, fill via `booksByCategory()`
   - Author: resolve the anchor book's authors, fill via `booksByAuthor()`
2. **Explainability** — add per-item `reason` to `RecommendationResult` ("Because you liked The Martian") and render it on the cards.
3. **Cold-start fallback** — users with no wishlist/reviews fall back to Popular/Recent automatically (service-level choice; the strategy pattern makes this a `supports()`/context decision).
4. **Persistence** — write computed results into the `recommendations` table (score + reason) and serve `recommendationsForUser()` when fresh, keeping computation and display separated.
5. **Exclusion rules** — exclude books the user already owns/has read (wishlist-held books, reviewed books); dedupe.
6. **Trending momentum** — a dedicated strategy reading recent review velocity, replacing the Rating stand-in.
7. **Tests** — extend `tests/RecommendationArchitectureTest.php` with algorithm assertions (ordering, limits, exclusion, cold start) on the seeded catalogue.
