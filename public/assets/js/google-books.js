/* =========================================================
   google-books.js
   The Google Books search page (Phase 10.2 + 10.3, admin only).

   Real-time search with debounce + abort, mirroring the browse
   page's live search (app.js) but isolated behind its own
   data-gb-* hooks, so the generic book-browse binding never
   touches this form.

   Phase 10.3: the Import button on every result card. Cards
   re-render after each live search, so the import handler is a
   SINGLE delegated listener on the results region. A click
   serializes the card's own form (it carries the _token and the
   google_book_id) and POSTs to /admin/google-books/import; the
   server re-fetches the volume, dedupes and inserts it, and the
   card turns into an "In library" button with a small inline
   result message. Without JavaScript the same form submits as a
   normal POST and the server redirects + flashes instead.

   Flow: the user types in the search box (or changes the scope
   select) -> keystrokes are debounced for 300 ms -> the form is
   serialized and fetched from /admin/google-books/search -> the
   response carries the freshly rendered results partial -> it is
   swapped into [data-gb-results] and the address bar is kept in
   sync (history.replaceState), so results stay shareable.

   Progressive enhancement: with JavaScript disabled the plain GET
   form submits to /admin/google-books and the page re-renders
   server-side - the live endpoint is pure enhancement.
   ========================================================= */

(() => {
    'use strict';

    const form = document.querySelector('[data-gb-form]');

    if (!form) return;

    const endpoint = form.dataset.searchEndpoint || '/admin/google-books/search';
    const resultsRegion = document.querySelector('[data-gb-results]');
    const liveStatus = document.querySelector('[data-gb-status]');
    const typeSelect = form.querySelector('[data-gb-type]');
    const searchInput = form.querySelector('[data-gb-search-input]');
    const hint = document.querySelector('[data-gb-hint]');

    let debounceTimer = null;
    let inFlight = null;

    /* ---------- Import (Phase 10.3) ---------------------------------- */

    // Escape a server-provided message before it is injected into the
    // card feedback markup (provider data can contain anything).
    const escapeHtml = (value) => {
        const div = document.createElement('div');
        div.textContent = String(value);
        return div.innerHTML;
    };

    const setImportState = (button, label, disabled) => {
        const text = button.querySelector('[data-gb-import-label]');
        if (text) text.textContent = label;
        button.disabled = disabled;
    };

    const showImportFeedback = (card, tone, message) => {
        const feedback = card?.querySelector('[data-gb-feedback]');
        if (!feedback) return;
        feedback.innerHTML = '<div class="alert alert-' + tone + ' gb-feedback py-2 px-2 mb-0" role="status">'
            + escapeHtml(message) + '</div>';
    };

    // One delegated click handler for every card's Import form. The
    // button is disabled while a request is in flight, so a double
    // click (or a repeated click) can never fire two imports.
    resultsRegion?.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-gb-import]');
        if (!button || button.disabled) return;

        const importForm = button.closest('[data-gb-import-form]');
        if (!importForm) return;

        event.preventDefault();

        const card = importForm.closest('.gb-card');

        setImportState(button, 'Importing…', true);
        showImportFeedback(card, 'info', 'Importing this book into the catalogue…');

        try {
            const response = await fetch(importForm.action, {
                method: 'POST',
                headers: { 'X-Requested-With': 'fetch' },
                body: new URLSearchParams(new FormData(importForm)),
                credentials: 'same-origin',
            });

            let payload = {};
            try {
                payload = await response.json();
            } catch (_) {
                /* non-JSON body (e.g. an admin error page) */
            }

            if (!response.ok) {
                const message = payload?.error
                    || (payload?.errors?.google_book_id || [])[0]
                    || 'The import failed - please try again.';
                setImportState(button, 'Import', false);
                showImportFeedback(card, 'danger', message);
                return;
            }

            const tone = payload.status === 'duplicate' ? 'warning' : 'success';
            setImportState(button, 'In library', true);
            showImportFeedback(card, tone, payload.message || 'Done.');
        } catch (error) {
            setImportState(button, 'Import', false);
            showImportFeedback(card, 'danger', 'Could not reach the server - please try again.');
        }
    });

    /* ---------- Search helpers (Phase 10.2) --------------------------- */

    // Keep the address bar in sync with the current search, so a
    // result set can be shared/bookmarked like a normal page URL.
    const syncUrl = () => {
        const params = new URLSearchParams(new FormData(form));
        [...params.entries()].forEach(([key, value]) => {
            if (value === '') params.delete(key);
        });
        const query = params.toString();
        history.replaceState(null, '', '/admin/google-books' + (query ? '?' + query : ''));
    };

    // The scope selector gets a small contextual hint (e.g. the ISBN
    // format the server validates the checksum against).
    const syncHint = () => {
        if (!hint || !typeSelect) return;

        const hints = {
            isbn: 'Formats accepted: 978-0-439-06487-3 or 0-439-06487-X (checksum-validated).',
            subject: 'e.g. "science fiction", "cooking", "history".',
            publisher: 'e.g. "Penguin", "O\'Reilly".',
        };

        const text = hints[typeSelect.value] || '';

        if (text) {
            hint.textContent = text;
            hint.hidden = false;
        } else {
            hint.hidden = true;
        }
    };

    const fetchResults = async () => {
        const params = new URLSearchParams(new FormData(form));

        // Abort the previous request: only the latest keystroke wins.
        inFlight?.abort();
        inFlight = new AbortController();

        resultsRegion?.setAttribute('aria-busy', 'true');
        form.classList.add('is-searching');

        try {
            const response = await fetch(`${endpoint}?${params.toString()}`, {
                signal: inFlight.signal,
                headers: { 'X-Requested-With': 'fetch' },
            });

            if (!response.ok) {
                // 422 = validation errors: show them in the hint.
                if (response.status === 422) {
                    const body = await response.json().catch(() => null);
                    const message = body?.errors?.q || body?.errors?.type || 'Check the search term and try again.';

                    if (hint) {
                        hint.textContent = message;
                        hint.hidden = false;
                    }
                }

                return;
            }

            const data = await response.json();

            if (resultsRegion) resultsRegion.innerHTML = data.html;

            // Announce the new totals to screen readers.
            if (liveStatus) {
                liveStatus.textContent = `${data.total} results, page ${data.page} of ${data.pages}${data.stale ? ', showing cached results' : ''}`;
            }

            syncUrl();
        } catch (error) {
            // AbortError is expected when a newer keystroke replaced us.
            if (error?.name === 'AbortError') return;
        } finally {
            resultsRegion?.setAttribute('aria-busy', 'false');
            form.classList.remove('is-searching');
        }
    };

    // Debounced live search on the term input: 300 ms keeps the
    // provider quiet while typing.
    searchInput?.addEventListener('input', () => {
        window.clearTimeout(debounceTimer);
        debounceTimer = window.setTimeout(fetchResults, 300);
    });

    // The scope select searches immediately (and updates the hint).
    typeSelect?.addEventListener('change', () => {
        syncHint();
        window.clearTimeout(debounceTimer);
        fetchResults();
    });

    syncHint();
})();
