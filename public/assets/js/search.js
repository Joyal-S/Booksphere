/* =========================================================
   search.js
   The global search page (Phase 11.2) - live search across
   books, authors, categories, publishers and reviews, plus the
   Phase 11.4 live suggestion (autocomplete) dropdown.

   --- LIVE SEARCH (Phase 11.2/11.3) ---
   Flow: the user types in the search box (or clicks a scope
   tab) -> keystrokes are debounced for 300 ms -> the form is
   serialized and fetched from /search with
   X-Requested-With: fetch -> the response carries the freshly
   rendered search/partials/_results.php partial -> it is
   swapped into [data-search-results] and the address bar is
   kept in sync (history.replaceState), so results stay
   shareable. The scope radio change is a deliberate refocus,
   so it fetches immediately instead of debouncing.

   The results region is re-rendered on every response, so its
   pagination links need ONE persistent delegated listener: a
   click rewrites the hidden 'page' form field and re-runs the
   live fetch - no full page reload.

   Errors are surfaced politely:
     - 422 -> the server's field errors are announced
     - AbortError (a newer keystroke replaced us) is ignored
     - network failures announce a friendly offline message

   --- SUGGESTIONS (Phase 11.4) ---
   Every [data-autocomplete] input (the page box AND the header
   box - that is why this block runs on every page, not just the
   search page) opens a combobox dropdown. Keystrokes are
   debounced for 250 ms, the previous request is aborted so only
   the latest prefix wins, duplicate terms reuse the in-memory
   cache and stale responses (a keystroke superseded the term
   while we waited) are dropped. Rows are built with the DOM API
   (textContent, never innerHTML with data), so a hostile label
   can never inject markup. ARIA: combobox -> listbox -> option,
   aria-activedescendant tracks the roving highlight.

   Enter-select is handled by ONE document-level CAPTURE listener
   (below): the header box's own Enter handler in app.js would
   otherwise navigate to /search before this module ever sees the
   keystroke - the capture phase intercepts the choice first.

   --- SEARCH HISTORY (Phase 11.5) ---
   The saved-searches card under the toolbar (server-rendered by
   search/partials/_history.php). Clicking a saved search does NOT
   navigate: search.js repopulates the live form (term, scope and
   every filter select) from the row's data-* attributes and
   re-runs the same fetchResults() pipeline - the results and the
   address bar update in place, no page reload. Delete-one and
   clear-all are intercepted, confirmed through the ONE shared
   historyConfirmModal, then submitted as their native CSRF-
   protected POST (hidden _method=DELETE) so the server flashes +
   redirects - the no-JS forms are the same forms, with the modal
   as pure enhancement.

   Progressive enhancement: with JavaScript disabled the plain
   GET form submits to /search and the page re-renders
   server-side - the live endpoint and the suggestions are pure
   enhancement.
   ========================================================= */

(() => {
    'use strict';

    initSearchForm();
    initAutocomplete();
    initEnterSelect();
    initHistory();

    /* --------------------------------------------------------------
       Live search form (Phase 11.2/11.3)
       -------------------------------------------------------------- */

    function initSearchForm() {
        const form = document.querySelector('[data-search-form]');

        if (!form) return;

        const endpoint = form.dataset.searchEndpoint || '/search';
        const resultsRegion = document.querySelector('[data-search-results]');
        const liveStatus = document.querySelector('[data-search-status]');
        const searchInput = form.querySelector('[data-live-search]');
        const scopeRadios = form.querySelectorAll('[data-scope-radio]');
        // A hidden field the pagination links write to, so fetchResults()
        // always serializes the CURRENT page.
        let pageField = form.querySelector('[data-search-page]');

        if (!pageField) {
            pageField = document.createElement('input');
            pageField.type = 'hidden';
            pageField.name = 'page';
            pageField.value = '1';
            pageField.dataset.searchPage = '';
            form.appendChild(pageField);
        }

        let debounceTimer = null;
        let inFlight = null;

        const updateScopeUI = () => {
            scopeRadios.forEach((radio) => {
                const label = radio.closest('.search-scope');
                if (label) {
                    label.classList.toggle('is-active', radio.checked);
                }
            });
        };

        // Initialize selected scope pill from URL query parameter (default to 'all')
        const urlParams = new URLSearchParams(window.location.search);
        const urlScope = urlParams.get('scope') || 'all';
        scopeRadios.forEach((radio) => {
            radio.checked = (radio.value === urlScope);
        });
        updateScopeUI();

        // Keep the address bar in sync with the current search, so a
        // result set can be shared/bookmarked like a normal page URL.
        const syncUrl = () => {
            const params = new URLSearchParams(new FormData(form));
            [...params.entries()].forEach(([key, value]) => {
                if (value === '') params.delete(key);
            });
            const query = params.toString();
            history.replaceState(null, '', '/search' + (query ? '?' + query : ''));
            updateScopeUI();
        };

        const fetchResults = async () => {
            const params = new URLSearchParams(new FormData(form));

            // Abort the previous request: only the latest keystroke wins.
            inFlight?.abort();
            inFlight = new AbortController();

            form.classList.add('is-searching');
            resultsRegion?.setAttribute('aria-busy', 'true');

            try {
                const response = await fetch(`${endpoint}?${params.toString()}`, {
                    signal: inFlight.signal,
                    headers: { 'X-Requested-With': 'fetch' },
                });

                const body = await response.json().catch(() => null);

                if (!response.ok) {
                    const message = (body?.errors?.q || body?.errors?.scope || [])[0]
                        || 'The search could not be processed. Please try again.';
                    if (liveStatus) liveStatus.textContent = message;
                } else {
                    if (resultsRegion) resultsRegion.innerHTML = body.html || '';
                    if (liveStatus) {
                        liveStatus.textContent = `${body.total} results, page ${body.page} of ${body.pages}`;
                    }
                    syncUrl();
                }
            } catch (error) {
                // AbortError is expected when a newer keystroke replaced us.
                if (error?.name === 'AbortError') return;

                if (liveStatus) liveStatus.textContent = 'Could not reach the server - please try again.';
            } finally {
                form.classList.remove('is-searching');
                resultsRegion?.setAttribute('aria-busy', 'false');
            }
        };

        const schedule = () => {
            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(fetchResults, 300);
        };

        // Debounced live search on the term input.
        searchInput?.addEventListener('input', schedule);

        // Scope radios search immediately (a deliberate refocus, a
        // full new query - no debounce).
        scopeRadios.forEach((radio) => {
            radio.addEventListener('change', () => {
                updateScopeUI();
                pageField.value = '1';
                window.clearTimeout(debounceTimer);
                fetchResults();
            });
        });

        // Filter selects search immediately on change - the Phase 11.3
        // bar, the same [data-auto-submit] idiom the browse toolbar uses.
        // A filter change is a fresh page of results, never a deep scroll.
        form.querySelectorAll('[data-auto-submit]').forEach((control) => {
            control.addEventListener('change', () => {
                pageField.value = '1';
                window.clearTimeout(debounceTimer);
                fetchResults();
            });
        });

        // The results region is re-rendered on every response, so its
        // pagination links need ONE persistent delegated listener.
        resultsRegion?.addEventListener('click', (event) => {
            const link = event.target.closest('a[href*="page="]');
            if (!link) return;

            event.preventDefault();

            const target = new URL(link.href);
            pageField.value = target.searchParams.get('page') || '1';
            window.clearTimeout(debounceTimer);
            fetchResults();
        });

        // Phase 11.5: re-run a SAVED search (the history card's rows).
        // The history module dispatches 'search.restore' with the
        // stored term, scope and filter map; this handler writes them
        // into the SAME live form (a fresh page of results, filters
        // applied from the row - never stale form state left by the
        // current query) and re-runs the existing pipeline. The URL
        // stays in sync, so the restored search is shareable like
        // every other live result.
        form.addEventListener('search.restore', (event) => {
            const detail = event.detail || {};

            if (typeof detail.q === 'string' && detail.q !== '') {
                searchInput.value = detail.q;
            }

            scopeRadios.forEach((radio) => {
                radio.checked = (radio.value === (detail.scope || 'all'));
            });
            updateScopeUI();

            const filters = detail.filters && typeof detail.filters === 'object' ? detail.filters : {};
            const filterControls = form.querySelectorAll('[data-filter-key]');
            filterControls.forEach((control) => {
                const key = control.dataset.filterKey;
                if (!key) return;
                control.value = filters[key] ?? '';
            });
            ['publisher', 'year_from', 'year_to'].forEach((name) => {
                const control = form.elements[name];
                if (control) control.value = filters[name] ?? '';
            });

            pageField.value = '1';
            window.clearTimeout(debounceTimer);
            fetchResults();
        });
    }

    /* -------------------------------------------------------------
       Autocomplete (Phase 11.4)
       ------------------------------------------------------------- */

    const SUGGEST_ICONS = {
        book: 'fa-book',
        author: 'fa-user-pen',
        category: 'fa-tags',
        publisher: 'fa-building',
    };

    function initAutocomplete() {
        const inputs = document.querySelectorAll('[data-autocomplete]');

        if (!inputs.length) return;

        let uid = 0;

        inputs.forEach((input) => {
            uid += 1;

            // A positioned wrapper holds the input + its dropdown, so the
            // dropdown never depends on the caller's layout class.
            const wrap = document.createElement('div');
            wrap.className = 'autocomplete';
            input.parentNode.insertBefore(wrap, input);
            wrap.appendChild(input);

            // A screen-reader-only status region announcing counts.
            const live = document.createElement('span');
            live.className = 'visually-hidden autocomplete-live';
            live.setAttribute('aria-live', 'polite');
            wrap.appendChild(live);

            const menuId = `autocomplete-menu-${uid}`;
            const menu = document.createElement('div');
            menu.id = menuId;
            menu.className = 'autocomplete-menu';
            menu.setAttribute('role', 'listbox');
            menu.setAttribute('aria-label', 'Search suggestions');
            menu.hidden = true;
            wrap.appendChild(menu);

            input.setAttribute('role', 'combobox');
            input.setAttribute('aria-autocomplete', 'list');
            input.setAttribute('aria-controls', menuId);
            input.setAttribute('aria-expanded', 'false');

            const endpoint = input.dataset.autocompleteEndpoint || '/search/suggest';
            const minLength = parseInt(input.dataset.autocompleteMin || '2', 10) || 2;

            let debounceTimer = null;
            let inFlight = null;
            const cache = Object.create(null);
            let activeIndex = -1;

            const announce = (message) => {
                if (live) live.textContent = message;
            };

            const close = () => {
                window.clearTimeout(debounceTimer);
                menu.hidden = true;
                menu.textContent = '';
                input.setAttribute('aria-expanded', 'false');
                input.removeAttribute('aria-activedescendant');
                activeIndex = -1;
            };

            const go = (url) => {
                if (!url) return;
                close();
                window.location.assign(url);
            };

            const buildLabel = (text, needle) => {
                const span = document.createElement('span');
                span.className = 'autocomplete-option-label';
                const lower = text.toLowerCase();
                const idx = lower.indexOf(needle);
                if (idx === -1) {
                    span.textContent = text;
                    return span;
                }
                if (idx > 0) span.append(text.slice(0, idx));
                const mark = document.createElement('mark');
                mark.textContent = text.slice(idx, idx + needle.length);
                span.appendChild(mark);
                span.append(text.slice(idx + needle.length));
                return span;
            };

            const clearDropdown = () => {
                input.value = '';
                close();
                input.focus();
            };

            const render = (term, suggestions) => {
                menu.textContent = '';
                activeIndex = -1;

                // Loading spinner row while a request is in flight.
                if (suggestions === null) {
                    const busy = document.createElement('div');
                    busy.className = 'autocomplete-status autocomplete-status--busy';
                    const spinner = document.createElement('span');
                    spinner.className = 'spinner-border spinner-border-sm';
                    spinner.setAttribute('aria-hidden', 'true');
                    busy.appendChild(spinner);
                    busy.append('Searching…');
                    menu.appendChild(busy);
                    menu.hidden = false;
                    input.setAttribute('aria-expanded', 'true');
                    return;
                }

                if (suggestions.length === 0) {
                    const empty = document.createElement('div');
                    empty.className = 'autocomplete-status';
                    empty.append('No suggestions for "', term, '"');
                    menu.appendChild(empty);
                    announce(`No suggestions for ${term}`);
                } else {
                    announce(`${suggestions.length} suggestions for ${term}`);
                }

                // One option per suggestion: icon + highlighted label +
                // subtitle. Everything is built with textContent (never
                // innerHTML), so a hostile label cannot inject markup.
                suggestions.forEach((suggestion, i) => {
                    const item = document.createElement('div');
                    item.className = 'autocomplete-option';
                    item.id = `${menuId}-${i}`;
                    item.setAttribute('role', 'option');
                    item.dataset.url = suggestion.url || '';

                    const icon = document.createElement('span');
                    icon.className = 'autocomplete-option-icon';
                    icon.setAttribute('aria-hidden', 'true');
                    const iconEl = document.createElement('i');
                    iconEl.className = 'fa-solid ' + (SUGGEST_ICONS[suggestion.type] || 'fa-magnifying-glass');
                    icon.appendChild(iconEl);

                    const body = document.createElement('span');
                    body.className = 'autocomplete-option-body';
                    body.appendChild(buildLabel(suggestion.label || '', term.toLowerCase()));
                    if (suggestion.subtitle) {
                        const sub = document.createElement('small');
                        sub.className = 'autocomplete-option-subtitle';
                        sub.textContent = suggestion.subtitle;
                        body.appendChild(sub);
                    }

                    item.appendChild(icon);
                    item.appendChild(body);

                    // preventDefault stops the blur that would otherwise
                    // fire before click on touchpads/mice.
                    item.addEventListener('mousedown', (event) => event.preventDefault());
                    item.addEventListener('click', () => go(suggestion.url));

                    menu.appendChild(item);
                });

                // Footer: run the full search for the term, or clear the box.
                const footer = document.createElement('div');
                footer.className = 'autocomplete-footer';

                const searchLink = document.createElement('button');
                searchLink.type = 'button';
                searchLink.className = 'autocomplete-footer-link';
                searchLink.textContent = `Search for "${term}"`;
                searchLink.addEventListener('mousedown', (event) => event.preventDefault());
                searchLink.addEventListener('click', () => go(`/search?q=${encodeURIComponent(term)}`));
                footer.appendChild(searchLink);

                const clearBtn = document.createElement('button');
                clearBtn.type = 'button';
                clearBtn.className = 'autocomplete-footer-clear';
                clearBtn.textContent = 'Clear';
                clearBtn.addEventListener('mousedown', (event) => event.preventDefault());
                clearBtn.addEventListener('click', clearDropdown);
                footer.appendChild(clearBtn);

                menu.appendChild(footer);

                menu.hidden = false;
                input.setAttribute('aria-expanded', 'true');
            };

            const fetchSuggestions = async (term) => {
                inFlight?.abort();
                const controller = new AbortController();
                inFlight = controller;

                render(term, null);

                try {
                    const response = await fetch(`${endpoint}?q=${encodeURIComponent(term)}`, {
                        signal: controller.signal,
                        headers: { 'X-Requested-With': 'fetch' },
                    });

                    const body = await response.json().catch(() => null);

                    // Stale: a newer keystroke already replaced the term.
                    if (term !== input.value.trim()) return;

                    if (!response.ok || !body || body.ok !== true) {
                        render(term, []);
                        return;
                    }

                    cache[term] = body.suggestions || [];
                    render(term, cache[term]);
                } catch (error) {
                    if (error?.name === 'AbortError') return;
                    if (term === input.value.trim()) render(term, []);
                }
            };

            const schedule = (term) => {
                window.clearTimeout(debounceTimer);

                if (term.length < minLength) {
                    close();
                    return;
                }

                // Already rendered for this exact term -> nothing to do.
                if (cache[term] && !menu.hidden) return;

                debounceTimer = window.setTimeout(() => fetchSuggestions(term), 250);
            };

            input.addEventListener('input', () => {
                schedule(input.value.trim());
            });

            input.addEventListener('focus', () => {
                const term = input.value.trim();
                if (term.length >= minLength) schedule(term);
            });

            input.addEventListener('keydown', (event) => {
                const key = event.key;

                if (key === 'ArrowDown' || key === 'ArrowUp') {
                    if (menu.hidden) return;
                    event.preventDefault();

                    const options = [...menu.querySelectorAll('.autocomplete-option')];
                    if (!options.length) return;

                    const dir = key === 'ArrowDown' ? 1 : -1;
                    activeIndex = (activeIndex + dir + options.length) % options.length;

                    options.forEach((option, i) => {
                        const isActive = i === activeIndex;
                        option.classList.toggle('is-active', isActive);
                        if (isActive) {
                            option.setAttribute('aria-selected', 'true');
                        } else {
                            option.removeAttribute('aria-selected');
                        }
                    });

                    input.setAttribute('aria-activedescendant', options[activeIndex].id);
                    options[activeIndex].scrollIntoView({ block: 'nearest' });
                    return;
                }

                if (key === 'Escape') {
                    if (!menu.hidden) {
                        event.preventDefault();
                        close();
                    }
                    return;
                }

                if (key === 'Tab') {
                    close();
                }
            });

            // Close when focus leaves the input (the mousedown-prevention
            // on the options keeps a click working through the blur).
            input.addEventListener('blur', () => {
                window.setTimeout(close, 150);
            });

            document.addEventListener('click', (event) => {
                if (!wrap.contains(event.target)) close();
            });
        });
    }

    /* -------------------------------------------------------------
       Enter-select (document capture phase)
       ------------------------------------------------------------- */

// When an autocomplete option is roving-highlighted and the user
    // presses Enter, run it FIRST - before the header box's own Enter
    // handler (app.js) navigates to /search?q=... and swallows the
    // selection. The capture phase reaches the document before the
    // event is ever dispatched on the input.
    function initEnterSelect() {
        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter') return;

            const active = document.querySelector('.autocomplete-option.is-active');
            if (!active) return;

            const url = active.dataset.url;
            if (!url) return;

            event.preventDefault();
            event.stopPropagation();

            const wrap = active.closest('.autocomplete');
            wrap?.querySelector('[data-autocomplete]')?.removeAttribute('aria-expanded');
            if (wrap) wrap.querySelector('.autocomplete-menu').hidden = true;

            window.location.assign(url);
        }, true);
    }

    /* -------------------------------------------------------------
       Search history (Phase 11.5)
       ------------------------------------------------------------- */

    // The saved-searches card. Without it (no history, or disabled)
    // there is nothing to wire - the header box's autocomplete is
    // untouched, and the module stays inert on other pages.
    function initHistory() {
        const historyRegion = document.querySelector('[data-search-history]');
        if (!historyRegion) return;

        const confirmModalEl = document.getElementById('historyConfirmModal');
        const confirmBody = document.getElementById('historyConfirmBody');

        // Re-run a saved search: this is delegated at DOCUMENT level so
        // it works for every row as it re-renders (the history list is
        // server-rendered; pagination/live fetches never redraw it, but
        // delegation also keeps the handler stable across full-page
        // navigation). The row is a plain <a href> - no-JS browsers get
        // the full reload, JS gets the in-place live search.
        document.addEventListener('click', (event) => {
            const link = event.target.closest('[data-history-search]');
            if (!link || !link.dataset.url) return;

            // Let the plain-link behaviour win when scripting is on but
            // somehow the live form is absent (should never happen on
            // the search page).
            const form = document.querySelector('[data-search-form]');
            if (!form) {
                window.location.assign(link.href);
                return;
            }

            event.preventDefault();

            let filters = {};
            try {
                filters = JSON.parse(link.dataset.filters || '{}') || {};
            } catch (ignore) {
                filters = {};
            }

            form.dispatchEvent(new CustomEvent('search.restore', {
                bubbles: true,
                detail: { q: link.dataset.q || '', scope: link.dataset.scope || 'books', filters },
            }));
        });

        // Delete / clear confirmation. The card's inline forms post
        // with hidden _method=DELETE; with JS present a click on the
        // submit runs through the shared modal instead, and its
        // confirm button then submits the PENDING form (the awaiting
        // no-JS form object) - the server flashes + redirects back.
        if (!confirmModalEl || !confirmBody) return;

        const goButton = document.getElementById('historyConfirmGo');
        const titleEl = document.getElementById('historyConfirmTitle');
        const modal = new bootstrap.Modal(confirmModalEl);
        let pendingForm = null;

        historyRegion.addEventListener('click', (event) => {
            const button = event.target.closest('[data-history-delete], [data-history-clear]');
            if (!(button instanceof HTMLElement)) return;

            const form = button.closest('form');
            if (!form) return;

            event.preventDefault();
            pendingForm = form;

            if (button.hasAttribute('data-history-clear')) {
                confirmBody.textContent = 'This removes every saved search from your history.';
                titleEl.textContent = 'Clear search history?';
            } else {
                const row = button.closest('.history-item');
                const saved = row?.querySelector('[data-history-search]');
                const term = saved?.getAttribute('data-q') || 'this saved search';
                confirmBody.textContent = `Remove "\u00AB${term}\u00BB" from your history?`;
                titleEl.textContent = 'Remove saved search?';
            }

            modal.show();
        });

        goButton?.addEventListener('click', () => {
            if (!pendingForm) {
                modal.hide();
                return;
            }

            // Submit the ORIGINAL form (the POST with _method=DELETE
            // + CSRF token): the controller redirects with a flash.
            pendingForm.submit();
            modal.hide();
        });
    }
})();