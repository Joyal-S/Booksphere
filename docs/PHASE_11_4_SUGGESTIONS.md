# PHASE 11.4 — Search Suggestions & Autocomplete

**Status: complete** — 94/94 SearchTest checks, all regression suites green.

## What was built

Live type-ahead suggestions for the global search: typing in the
search page box **or** the header box opens an accessible, responsive
combobox dropdown of catalogue entities that match the prefix.

### Architecture (the same Phase 11.1 pipeline as the search itself)

```
SearchSuggestRequest (validated gate)
    -> SearchQueryBuilder::buildSuggest()  (neutral SearchQuerySpec)
    -> SearchProvider::suggest()           (SqliteSearchProvider)
       SearchRepository::suggestBooks() / suggestAuthors() /
       suggestCategories() / suggestPublishers()   (LIMIT-capped)
    -> SearchSuggestionService           (rank -> dedupe -> clip -> JSON)
    -> SearchController::suggest()       (GET /search/suggest)
    -> search.js initAutocomplete()      (combobox + keyboard + fetch)
```

### Files created
| File | Role |
|---|---|
| `app/Requests/SearchSuggestRequest.php` | Inbound gate: `q` ≥ 2 chars (`suggestions.min_length`), capped by `query.max_length` / `query.max_words`, controls stripped. |
| `app/DTO/SearchSuggestion.php` | Immutable row shape: `type`, `label`, `subtitle`, `url`. |
| `app/Services/SearchSuggestionService.php` | Orchestrator: per-term pool memo, ranking, dedupe, limit, JSON serialization; never throws. |

### Files modified
| File | Role |
|---|---|
| `config/search.php` | `suggestions.min_length` (SEARCH_SUGGESTION_MIN_LENGTH, 2) added to the existing block. |
| `app/Builders/SearchQueryBuilder.php` | `buildSuggest($term, $perSource)` — the same `wordsOf()` tokenizer as the search. |
| `app/Repositories/SearchRepository.php` | Four lean reads (title/subtitle, `a.name`, category name/slug, DISTINCT publisher) — all bound, `LIMIT`-capped, no `COUNT`; new `anyWordWhere()` helper shares the word-AND matching rule. |
| `app/Services/SqliteSearchProvider.php` | `suggest()` now routes to the four reads and tags every row with `type` + deep link (/books/{id}, `/books?author_id=`, `/books?category_id=`, `/books?publisher=`). |
| `app/Controllers/SearchController.php` | New `suggest()` action (503 disabled / 429 suggestions bucket / 422 gate / 200 list). |
| `routes/web.php` | `SearchSuggestionService` wired; `GET /search/suggest` route (literal). |
| `public/assets/js/search.js` | `initAutocomplete()` — 250 ms debounce, AbortController (only the latest prefix wins), in-memory term cache, stale-response drop, DOM-built rows (`textContent`/`<mark>`, no `innerHTML` with data), Arrow/Enter/Escape/Tab, spinner / no-suggestions / footer (`Search for "…"`, `Clear`). |
| `public/assets/css/search.css` | Dropdown via design tokens (auto light/dark), reduced-motion guard, touch-friendly options. |
| `app/Views/partials/header.php` | Header box enabled with `data-autocomplete data-autocomplete-endpoint="/search/suggest"`. |
| `app/Views/search/index.php` | Page box enabled with the same attributes. |
| `tests/SearchTest.php` | Sections 8 (service) + 9 (controller endpoint); 24 new checks. |

## Ranking (local relevance)
Exact (`label == term`) → prefix (starts with) → partial (contains) →
word-level, then source priority (book > author > category > publisher),
then display label. Multi-word terms match **AND** across every source.

## Performance & safety
- Every read is `LIMIT`-capped and fully parameter-bound; no COUNTs for
  suggestions; the response is capped by `suggestions.limit`.
- Per-request memo cache (`SearchSuggestionService::$poolCache`) and a
  client-side term cache avoid duplicate work; abortable fetches avoid
  pile-ups.
- `SearchSuggestionService` catches provider/timeout failures and answers
  an empty list (suggestions are enhancement) — the 503/429/422 "hard"
  paths belong to the controller.

## Manual verification checklist (keyboard / mouse / responsive / dark / a11y)
- [ ] Type `har` in the page box and the header box → dropdown with an
      icon per type and the matched part highlighted.
- [ ] ↓/↑ move the highlight (`aria-activedescendant` updates), Enter opens
      the highlighted suggestion, Escape closes, Tab closes.
- [ ] Mouse/touch selects a row; clicking the footer search opens
      `/search?q=…`; Clear empties the box.
- [ ] Rapid typing shows only the final prefix's results (spinner during
      fetch, no flicker of stale rows).
- [ ] Dark mode + a ≤575 px viewport look right; reducing motion removes
      the entrance animation.

## 11.5 readiness
The `suggest()` seam is provider-neutral; History (11.5) and Analytics
(11.5) can be added as new controllers/middleware consumers of the same
pipeline without touching suggestions.