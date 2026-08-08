/* =========================================================
   google-books.js
   The Google Books search page (Phase 10.2 + 10.3 + 10.5 +
   10.6, admin only).

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

   Phase 10.6 single sync: the same delegated pattern drives the
   "Sync now" button on imported cards (its own form, its own
   inline feedback).

   Phase 10.5/10.6 bulk bar: the page's bulk form
   (data-gb-bulk-form) collects the card checkboxes natively via
   the form="google-books-bulk-form" attribute; this script
   upgrades it (FormData is read only once, on submit):
     - the selection is remembered in a Set across live-search
       re-renders and pagination, and mirrored back onto the fresh
       checkboxes (boundCheckboxes)
     - the selection is OPEN to imported AND non-imported cards
       (Phase 10.6): "Import selected" posts the non-library ids,
       "Sync selected" (formaction=/sync-bulk) posts the library
       ones; without JavaScript both buttons still submit - import
       de-duplicates, sync skips what is not imported
     - "Select all" toggles every card on the page
     - submitting streams the run back over Server-Sent Events:
       `progress` events paint the shared panel (mode-aware
       labels - imported/duplicates for a sync, etc.), `summary`
       opens the report dialog toggled for import vs sync; Cancel
       aborts the stream and the server stops the run mid-flight
     - Phase 10.6 "Sync all imported books" runs through the SAME
       stream plumbing behind a confirmation dialog
     - without JavaScript every one of these is a plain POST and
       the server flashes the summary + redirects

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

    /* ---------- Import + single sync (Phase 10.3 + 10.6) -------------- */

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

            // The record is now in the library: nothing left to import.
            const check = card?.querySelector('[data-gb-check]');
            if (check) {
                check.dataset.gbInLibrary = 'true';
                syncBulkBar();
            }
        } catch (error) {
            setImportState(button, 'Import', false);
            showImportFeedback(card, 'danger', 'Could not reach the server - please try again.');
        }
    });

    // The single-book "Sync now" card button (Phase 10.6): same
    // delegated pattern, same inline feedback, a much shorter request.
    resultsRegion?.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-gb-sync]');
        if (!button || button.disabled) return;

        const syncForm = button.closest('[data-gb-sync-form]');
        if (!syncForm) return;

        event.preventDefault();

        const card = syncForm.closest('.gb-card');

        button.disabled = true;
        showImportFeedback(card, 'info', 'Synchronizing this book…');

        try {
            const response = await fetch(syncForm.action, {
                method: 'POST',
                headers: { 'X-Requested-With': 'fetch' },
                body: new URLSearchParams(new FormData(syncForm)),
                credentials: 'same-origin',
            });

            let payload = {};
            try {
                payload = await response.json();
            } catch (_) { /* non-JSON body */ }

            if (!response.ok) {
                const message = payload?.error
                    || (payload?.errors?.google_book_id || [])[0]
                    || 'The synchronization failed - please try again.';
                button.disabled = false;
                showImportFeedback(card, 'danger', message);
                return;
            }

            const tone = payload.status === 'updated'
                ? 'success'
                : (payload.status === 'failed' ? 'danger' : 'info');
            button.disabled = false;
            showImportFeedback(card, tone, payload.message || 'Done.');
        } catch (error) {
            button.disabled = false;
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

            // Re-mirror the remembered selection onto the fresh cards.
            boundCheckboxes();

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

    /* ---------- Bulk selection + streaming runs (Phase 10.5 + 10.6) --- */

    const bulkForm = document.querySelector('[data-gb-bulk-form]');
    const bulkButton = document.querySelector('[data-gb-bulk-button]');
    const bulkSyncButton = document.querySelector('[data-gb-bulk-sync]');
    const bulkCount = document.querySelector('[data-gb-bulk-count]');
    const selectAll = document.querySelector('[data-gb-select-all]');
    const progressPanel = document.querySelector('[data-gb-progress]');
    const progressTrack = document.querySelector('[data-gb-progress-track]');
    const progressBar = document.querySelector('[data-gb-progress-bar]');
    const progressCount = document.querySelector('[data-gb-progress-count]');
    const progressCurrent = document.querySelector('[data-gb-progress-current]');
    const progressTitle = document.querySelector('[data-gb-progress-title]');
    const progressImported = document.querySelector('[data-gb-progress-imported]');
    const progressDuplicates = document.querySelector('[data-gb-progress-duplicates]');
    const progressFailed = document.querySelector('[data-gb-progress-failed]');
    const progressRemaining = document.querySelector('[data-gb-progress-remaining]');
    const importedLabel = document.querySelector('[data-gb-stat-imported-label]');
    const duplicatesLabel = document.querySelector('[data-gb-stat-duplicates-label]');
    const cancelButton = document.querySelector('[data-gb-progress-cancel]');
    const summaryModal = document.getElementById('gbSummaryModal');
    const summaryTitle = document.querySelector('[data-gb-summary-title]');
    const summaryImportGroup = document.querySelectorAll('[data-gb-summary-group="import"]');
    const summarySyncGroup = document.querySelectorAll('[data-gb-summary-group="sync"]');
    const summaryFailuresTitle = document.querySelector('[data-gb-summary-failures-title]');
    const syncAllForm = document.querySelector('[data-gb-sync-all-form]');
    const syncAllModal = document.getElementById('gbSyncAllModal');

    // The selection is remembered by volume id, so a live search or
    // pagination that re-renders the grid never loses the tick.
    const selection = new Set();
    let runAbort = null;
    let runMode = 'import'; // 'import' | 'sync' | 'sync-all'

    // Every checkbox is selectable now (Phase 10.6 opened the set to
    // imported cards, which carry data-gb-in-library="true").
    const allChecks = () => {
        if (!resultsRegion) return [];
        return [...resultsRegion.querySelectorAll('[data-gb-check')];
    };

    const allIds = () => [...selection];

    const libraryIds = () => allChecks()
        .filter((check) => check.checked && check.dataset.gbInLibrary === 'true')
        .map((check) => check.dataset.gbCheckId)
        .filter((id) => selection.has(id));

    const importIds = () => allIds().filter((id) => !libraryIds().includes(id));

    const syncBulkBar = () => {
        const size = selection.size;
        const lib = libraryIds().length;

        if (bulkCount) bulkCount.textContent = size === 0 ? '0 selected' : `${size} selected`;
        if (bulkButton) bulkButton.disabled = size - lib === 0;
        if (bulkSyncButton) bulkSyncButton.disabled = lib === 0;

        if (selectAll) {
            const checks = allChecks();
            const allSelected = checks.length > 0 && checks.every((check) => check.checked);
            selectAll.checked = allSelected;
            selectAll.indeterminate = !allSelected && checks.some((check) => check.checked);
        }
    };

    // Mirror the remembered selection onto a freshly rendered grid and
    // refresh the toolbar (count, disabled state, select-all tri-state).
    const boundCheckboxes = () => {
        allChecks().forEach((check) => {
            check.checked = selection.has(check.dataset.gbCheckId);
        });
        syncBulkBar();
    };

    // One delegated toggle for every card checkbox (cards re-render).
    resultsRegion?.addEventListener('change', (event) => {
        const check = event.target.closest('[data-gb-check]');
        if (!check) return;

        const id = check.dataset.gbCheckId;
        if (check.checked) selection.add(id);
        else selection.delete(id);
        syncBulkBar();
    });

    // "Select all" flips every card on the current page.
    selectAll?.addEventListener('change', () => {
        allChecks().forEach((check) => {
            check.checked = selectAll.checked;
            const id = check.dataset.gbCheckId;
            if (selectAll.checked) selection.add(id);
            else selection.delete(id);
        });
        syncBulkBar();
    });

    const setStat = (selector, value) => {
        const el = document.querySelector(selector);
        if (el) el.textContent = String(value ?? 0);
    };

    // The panel is mode-aware: imported/duplicates for an import run,
    // updated/unchanged for a sync run.
    const applyMode = (mode) => {
        runMode = mode;

        if (progressTitle) progressTitle.textContent = mode === 'import' ? 'Importing books…' : 'Synchronizing books…';
        if (importedLabel) importedLabel.textContent = mode === 'import' ? 'imported' : 'updated';
        if (duplicatesLabel) duplicatesLabel.textContent = mode === 'import' ? 'duplicates' : 'unchanged';
    };

    const paintProgress = (event) => {
        const processed = Number(event.processed) || 0;
        const total = Number(event.total) || 1;
        if (total > 0 && progressBar) progressBar.style.width = `${Math.round((processed / total) * 100)}%`;
        if (progressTrack) progressTrack.setAttribute('aria-valuenow', String(Math.round((processed / total) * 100)));

        if (progressCount) progressCount.textContent = `${processed} of ${total} books`;
        if (progressCurrent && event.book?.title) progressCurrent.textContent = event.book.title;

        if (runMode === 'import') {
            setStat('[data-gb-progress-imported]', event.imported);
            setStat('[data-gb-progress-duplicates]', event.duplicates);
        } else {
            setStat('[data-gb-progress-imported]', event.updated);
            setStat('[data-gb-progress-duplicates]', event.unchanged);
        }
        setStat('[data-gb-progress-failed]', event.failed);
        setStat('[data-gb-progress-remaining]', event.remaining);
    };

    const resetProgress = () => {
        if (progressBar) progressBar.style.width = '0%';
        if (progressTrack) progressTrack.setAttribute('aria-valuenow', '0');
        if (progressCount) progressCount.textContent = '0 of 0 books';
        if (progressCurrent) progressCurrent.textContent = '';
        setStat('[data-gb-progress-imported]', 0);
        setStat('[data-gb-progress-duplicates]', 0);
        setStat('[data-gb-progress-failed]', 0);
        setStat('[data-gb-progress-remaining]', 0);
    };

    const showProgress = (visible) => {
        if (progressPanel) progressPanel.hidden = !visible;
        if (cancelButton) cancelButton.disabled = !visible;
        if (bulkButton) bulkButton.disabled = visible;
        if (bulkSyncButton) bulkSyncButton.disabled = visible;
        if (selectAll) selectAll.disabled = visible;
    };

    // Toggle the report dialog's import vs sync stat groups + titles.
    const applySummaryMode = (mode) => {
        const showSync = mode !== 'import';

        summaryImportGroup.forEach((el) => { el.hidden = showSync; });
        summarySyncGroup.forEach((el) => { el.hidden = !showSync; });

        if (summaryTitle) summaryTitle.textContent = showSync ? 'Sync summary' : 'Import summary';
        if (summaryFailuresTitle) summaryFailuresTitle.textContent = showSync ? 'Not synchronized' : 'Not imported';
    };

    const showSummary = (report) => {
        // Toggle the report's import vs sync stat groups to match the
        // MODE the run actually ran in (import/sync/sync-all), not the
        // modal's default import view.
        applySummaryMode(runMode);

        setStat('[data-gb-summary-updated]', report.updated);
        setStat('[data-gb-summary-unchanged]', report.unchanged);
        setStat('[data-gb-summary-imported]', report.imported);
        setStat('[data-gb-summary-duplicates]', report.duplicates);
        setStat('[data-gb-summary-failed]', report.failed);
        setStat('[data-gb-summary-skipped]', report.skipped);
        setStat('[data-gb-summary-total]', report.total);

        const elapsed = Number(report.elapsed_seconds) || Number(report.elapsedSeconds) || 0;
        const elapsedNote = document.querySelector('[data-gb-summary-elapsed]');
        if (elapsedNote) elapsedNote.textContent = elapsed > 0 ? `Finished in ${elapsed.toFixed(1)}s.` : '';

        const messageNote = document.querySelector('[data-gb-summary-message]');
        if (messageNote) {
            if (report.summary) {
                messageNote.textContent = report.summary;
            } else if (runMode === 'import') {
                messageNote.textContent = `${report.imported || 0} imported${report.duplicates ? `, ${report.duplicates} already in the library` : ''}.`;
            } else {
                messageNote.textContent = `${report.updated || 0} updated, ${report.unchanged || 0} unchanged.`;
            }
        }

        const failures = (report.results || []).filter((item) => item.status === 'failed');
        const failuresBox = document.querySelector('[data-gb-summary-failures]');
        const failureList = document.querySelector('[data-gb-summary-failure-list]');

        if (failures.length && failureList && failuresBox) {
            failureList.innerHTML = failures
                .map((item) => '<li>' + escapeHtml(item.message || item.id || 'Unknown book') + '</li>')
                .join('');
            failuresBox.hidden = false;
        } else if (failuresBox) {
            failuresBox.hidden = true;
        }

        // The finished records are handled: prune the selection, refresh
        // the grid (cards re-render with fresh "In library"/sync state)
        // and open the report dialog.
        (report.results || []).forEach((item) => {
            if (item.status !== 'failed') selection.delete(item.id);
        });
        syncBulkBar();

        fetchResults();

        if (window.bootstrap && summaryModal) {
            bootstrap.Modal.getOrCreateInstance(summaryModal).show();
        }
    };

    // Parse one SSE block (event: / data: lines) into {type, data}.
    const parseSseBlock = (block) => {
        let type = 'message';
        let data = '';
        block.split('\n').forEach((line) => {
            if (line.startsWith('event: ')) type = line.slice(7).trim();
            else if (line.startsWith('data: ')) data += line.slice(6);
        });
        return { type, data };
    };

    // The ONE streaming runner behind import-bulk, sync-bulk and
    // sync-all: opens the fetch stream, paints `progress` events and
    // hands the final `summary` event to showSummary().
    const streamRun = async (url, formData) => {
        showProgress(true);
        resetProgress();
        runAbort = new AbortController();

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'X-Requested-With': 'fetch' },
                body: formData instanceof FormData ? new URLSearchParams(formData) : formData,
                signal: runAbort.signal,
                credentials: 'same-origin',
            });

            if (!response.ok) {
                const body = await response.json().catch(() => null);
                const message = (body?.errors?.google_book_id || [])[0]
                    || (body?.error) || 'The request could not start.';

                showProgress(false);
                if (liveStatus) liveStatus.textContent = message;
                if (hint) {
                    hint.textContent = message;
                    hint.hidden = false;
                }
                return;
            }

            // The server streams back progress + summary events; stitch
            // the SSE blocks together from the raw body.
            const reader = response.body?.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let report = null;

            while (reader) {
                const { done, value } = await reader.read();
                if (done) break;
                buffer += (decoder.decode(value, { stream: true })).replace(/\r\n/g, '\n');
                const blocks = buffer.split('\n\n');
                buffer = blocks.pop() || '';

                blocks.forEach((block) => {
                    const { type, data } = parseSseBlock(block);
                    if (!data) return;
                    let payload;
                    try { payload = JSON.parse(data); } catch (_) { return; }
                    if (type === 'progress') paintProgress(payload);
                    else if (type === 'summary') report = payload;
                });
            }

            if (report) {
                showProgress(false);
                showSummary(report);
                return;
            }

            showProgress(false);
            if (liveStatus) {
                liveStatus.textContent = runAbort.signal.aborted
                    ? 'Run cancelled.'
                    : 'The connection was interrupted - check the results and try again.';
            }
        } catch (error) {
            if (error?.name === 'AbortError') {
                showProgress(false);
                if (liveStatus) liveStatus.textContent = 'Run cancelled.';
            } else {
                showProgress(false);
                if (liveStatus) liveStatus.textContent = 'Could not reach the server - try again.';
            }
        } finally {
            runAbort = null;
            syncBulkBar();
        }
    };

    // The bulk bar submit: dispatch by WHICH submit button was pressed.
    // Import -> only the non-library selection; sync -> only the
    // library selection (the plain POST keeps working without JS).
    bulkForm?.addEventListener('submit', (event) => {
        const submitter = event.submitter || {};

        // Already-imported cards have no import value; never import them.
        // The form itself still submits both buttons natively without JS.
        event.preventDefault();

        const isSync = submitter.hasAttribute && submitter.hasAttribute('data-gb-bulk-sync');
        const mode = isSync ? 'sync' : 'import';

        const ids = new Set(isSync ? libraryIds() : importIds());
        if (ids.size === 0) return;

        const formData = new FormData(bulkForm);
        const requestData = new FormData();
        formData.forEach((value, key) => {
            if (key === '_token') requestData.append(key, value);
        });
        ids.forEach((id) => requestData.append('google_book_id', id));

        applyMode(mode);
        // Which endpoint does the run hit? The SUBMITTED button
        // decides: "Import selected" uses the form's own action, while
        // "Sync providers" carries formaction=/admin/google-books/sync-bulk.
        // Posting the sync submission to the import endpoint would make
        // the "sync" button import instead - honor the formaction.
        const url = (submitter.formAction) ? submitter.formAction : bulkForm.action;
        streamRun(url, requestData).then(() => {});
    });

    // Cancel: abort the stream; the server notices connection_aborted
    // on its next progress write and stops processing new books.
    cancelButton?.addEventListener('click', () => {
        runAbort?.abort();
    });

    // The "Sync all imported books" action (Phase 10.6): confirm once
    // (JS only), then stream through the same plumbing.
    syncAllForm?.addEventListener('submit', (event) => {
        const button = event.submitter;
        if (!button || !button.hasAttribute('data-gb-sync-all')) return;
        event.preventDefault();

        if (window.bootstrap && syncAllModal) {
            bootstrap.Modal.getOrCreateInstance(syncAllModal).show();
            return;
        }

        startSyncAll();
    });

    const startSyncAll = () => {
        applyMode('all');
        streamRun(syncAllForm.action, new FormData(syncAllForm));
    };

    const syncAllConfirm = document.querySelector('[data-gb-sync-all-confirm]');
    syncAllConfirm?.addEventListener('click', () => {
        if (window.bootstrap && syncAllModal) {
            bootstrap.Modal.getOrCreateInstance(syncAllModal).hide();
        }
        startSyncAll();
    });

    syncHint();
    applyMode('import');
})();