/**
 * library.js
 *
 * The frontend behaviour of the PERSONAL LIBRARY module (Phase 8.2,
 * extended by Phase 8.3): the fetch-driven interactions of the "My
 * Library" dashboard - the filter / sort / view grid, the statistics
 * refresh, the Continue Reading shelf - the book detail page's Add /
 * Update library panel and the dashboard's Continue Reading shelf.
 *
 * Philosophy (the same as app.js / reviews.js):
 *    - PROGRESSIVE ENHANCEMENT. Every control is a real, CSRF-
 *      protected <form> that works with JavaScript disabled (native
 *      POST + flash redirect). This file upgrades those forms to
 *      fetch calls (X-Requested-With: fetch) and repaints the page
 *      in place, so the no-JS and JS experiences never drift.
 *    - EVENT DELEGATION. Favourite / status / progress forms can be
 *      re-injected by the live search, so the handlers live on
 *      document and match by the closest [data-*] form.
 *    - ACCESSIBILITY. aria-pressed / aria-label / aria-busy are kept
 *      truthful, and every animation respects prefers-reduced-motion.
 *
 * Sections:
 *   1. helpers        - fetchPost, announce, heart pop (GSAP)
 *   2. favourite      - POST /library/{id}/favorite (heart repaint)
 *   3. status         - POST /library/{id}        (badge repaint)
 *   4. progress       - POST /library/{id}/progress + confirm at 100
 *   5. delete modal   - the Remove confirmation + in-place removal
 *   6. book panel     - add / remove on the book detail page
 *   7. filter form    - the Phase 8.3 dashboard control row: every
 *                      search / filter / sort / view change fetches
 *                      /library/filter (/library/sort for the sort)
 *                      and swaps the grid fragment in place
 *   8. stats refresh  - after a write the statistics cards are
 *                      skeleton-filled and rebuilt (Phase 8.3
 *                      skeleton-stat), the tab counters / header
 *                      chips / intro line follow, and the Continue
 *                      Reading shelf refreshes its fragment. Phase
 *                      8.4: the Smart Collections rail rebuilds its
 *                      occupancy numbers from the same payload
 *   9. bulk actions   - Phase 8.4: the selection bar (select all /
 *                      count / clear), the Move / Favourite /
 *                      Un-favourite submits (upgraded to fetches) and
 *                      the bulk-delete confirmation modal; every
 *                      write repaints the grid, the counters and the
 *                      collections
 *  10. quick menu     - Phase 8.4: the per-card action menu (View
 *                      Details, Move To, Mark Favourite, Remove, the
 *                      Share placeholder) - one fetch per action
 *  11. animations     - card hover lift (GSAP)
 */

(() => {
    'use strict';

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const root = document.getElementById('app') || document.body;

    /* ---------- 1. Helpers ------------------------------------------ */

    // A CSRF-protected fetch POST built from a form (the _token input
    // is part of the form, so it travels with the request).
    const fetchForm = (form) => fetch(form.action, {
        method: 'POST',
        headers: { 'X-Requested-With': 'fetch' },
        body: new URLSearchParams(new FormData(form)),
        credentials: 'same-origin',
    });

    // Friendly error surfacing: the library page owns an aria-live
    // region ([data-library-status]); anywhere else we fall back to
    // the native dialog so the message is never lost.
    const announce = (message, isError = false) => {
        const live = document.querySelector('[data-library-status]');
        if (live) {
            live.textContent = message;
            return;
        }
        if (window.alert) window.alert(message);
    };

    // Parse a fetch response into its JSON payload, raising the
    // friendliest server message on any non-2xx answer.
    const toPayload = async (response) => {
        let payload = {};
        try {
            payload = await response.json();
        } catch (_) {
            /* non-JSON (e.g. a redirect body) */
        }
        if (!response.ok) {
            const errors = payload.errors;
            const first = errors ? Object.values(errors).flat()[0] : null;
            throw new Error(first || payload.error || 'Something went wrong - please try again.');
        }
        return payload;
    };

    // The heart pop: a short GSAP spring on the icon. Guarded by
    // prefers-reduced-motion and the presence of GSAP (the CSS
    // transition still gives feedback without it).
    const heartPop = (button) => {
        if (reduceMotion || !window.gsap || !button) return;
        const icon = button.querySelector('i');
        if (!icon) return;
        window.gsap.fromTo(icon, { scale: 0.4 }, {
            scale: 1,
            duration: 0.45,
            ease: 'elastic.out(1.1, 0.5)',
            clearProps: 'transform',
        });
    };

    /* ---------- 2. Favourite toggle -------------------------------- */

    // Heart buttons live in the library cards ([data-library-favorite-])
    // and in the book detail panel ([data-library-panel-favorite]).
    const handleFavorite = async (form) => {
        const button = form.querySelector('button[type="submit"]');
        const card = form.closest('[data-library-card]') || form.closest('[data-library-panel]');
        const title = (() => {
            const heading = card?.querySelector('.library-card-title a, .book-title');
            return heading ? heading.textContent.trim() : '';
        })() || 'this book';

        form.closest('.library-card, .library-panel')?.classList.add('is-saving');

        try {
            const response = await fetchForm(form);
            const payload = await toPayload(response);

            const favorite = payload.favorite;
            if (typeof favorite !== 'boolean') return;

            button?.classList.toggle('is-favorite', favorite);
            button?.setAttribute('aria-pressed', favorite ? 'true' : 'false');
            button?.setAttribute('title', favorite ? 'In your favourites' : 'Add to favourites');
            button?.setAttribute(
                'aria-label',
                (favorite ? 'Remove ' : 'Add ') + title + (favorite ? ' from your favourites' : ' to your favourites'),
            );
            heartPop(button);
            refreshCounters();
        } catch (error) {
            announce(error.message, true);
        } finally {
            form.closest('.library-card, .library-panel')?.classList.remove('is-saving');
        }
    };

    /* ---------- 3. Status change ----------------------------------- */

    const STATUS_LABELS = {
        want_to_read: 'Want to Read',
        currently_reading: 'Currently Reading',
        finished: 'Finished',
        on_hold: 'On Hold',
        dropped: 'Dropped',
    };

    // The visible "read the shelf" label of a status value. Prefer the
    // select option's own text (it already knows the label); fall back
    // to the map above.
    const statusLabel = (option, status) => {
        if (option && option.parentElement) {
            const match = option.parentElement.querySelector(`option[value="${status}"]`);
            if (match) return match.textContent.trim();
        }
        return STATUS_LABELS[status] || status;
    };

    // Repaint every status badge that describes the card / panel that
    // just changed (there is one per card, one in the panel head).
    const repaintStatus = (container, status) => {
        container.querySelectorAll('.status-badge').forEach((badge) => {
            [...badge.classList].forEach((cl) => {
                if (cl.startsWith('status-')) badge.classList.remove(cl);
            });
            badge.classList.add('status-' + status);
            badge.textContent = statusLabel(null, status);
        });
    };

    // A status change also implies a progress reset on the two ends of
    // the lifecycle (the server enforces this too; we mirror it so the
    // card never shows a stale bar before the next fetch).
    const mirrorProgressFromStatus = (container, status) => {
        if (status === 'finished') setProgress(container, 100);
        if (status === 'want_to_read') setProgress(container, 0);
    };

    const handleStatus = async (form) => {
        const select = form.querySelector('select[data-library-status-select], select[data-library-panel-status-select]');
        const container = form.closest('[data-library-card], [data-library-panel]');
        if (!select || !container) return;

        const status = select.value;

        container.classList.add('is-saving');

        try {
            const payload = await toPayload(await fetchForm(form));
            repaintStatus(container, status);
            mirrorProgressFromStatus(container, status);
            refreshCounters();
        } catch (error) {
            select.value = select.dataset.previous || select.value;
            announce(error.message, true);
        } finally {
            container.classList.remove('is-saving');
        }
    };

    /* ---------- 4. Reading progress -------------------------------- */

    // Fill the progress bar + % label of one card / panel container.
    const setProgress = (container, value) => {
        const progress = Math.max(0, Math.min(100, Number(value) || 0));
        container.querySelectorAll('[data-library-progress-bar], [data-library-panel-progress-bar]').forEach((bar) => {
            if (reduceMotion || !window.gsap) {
                bar.style.width = progress + '%';
            } else {
                window.gsap.to(bar, { width: progress + '%', duration: 0.45, ease: 'power2.out' });
            }
            bar.setAttribute('aria-valuenow', String(progress));
        });
        container.querySelectorAll('[data-library-progress-value], [data-library-panel-progress-value]').forEach((valueEl) => {
            valueEl.textContent = progress + '%';
        });
    };

    const handleProgress = async (form, container) => {
        const input = form.querySelector('[data-library-progress-input], [data-library-panel-progress-input]');
        const value = Number((input && input.value) || 0);

        container.classList.add('is-saving');

        try {
            const payload = await toPayload(await fetchForm(form));

            // The server may auto-finish the record at 100 and answer
            // with the fresh status - mirror that truthfully.
            setProgress(container, payload.progress ?? value);
            if (payload.status) repaintStatus(container, payload.status);

            const save = form.querySelector('.library-progress-save');
            if (save) {
                save.classList.add('is-saved');
                window.setTimeout(() => save.classList.remove('is-saved'), 900);
            }
            refreshCounters();
        } catch (error) {
            setProgress(container, Number(input?.dataset.previous || (input && input.value) || 0));
            announce(error.message, true);
        } finally {
            container.classList.remove('is-saving');
        }
    };

    // Reaching 100 must be an explicit, confirmed decision: the brief
    // says ask "Mark this book as Finished?" - we never flip the
    // status silently. The server only proceeds once the user confirms.
    const confirmAtHundred = (input, form) => {
        const value = Number(input.value);
        const previous = Number(input.dataset.previous || value);

        if (value !== 100) return true;
        if (previous === 100) return true; // already finished-looking

        const confirmed = window.confirm('Mark this book as Finished?');
        if (!confirmed) {
            input.value = String(previous);
            return false;
        }
        return true;
    };

    /* ---------- 5. Delete modal + in-place removal ------------------ */

    // The shared Remove modal of the library page posts to
    // /library/{id}/delete. Its buttons carry data-delete-url and
    // data-delete-title (the same contract the book/review modals
    // use); we bind it here because the modal is library-specific.
    const bindDeleteModal = () => {
        const modalEl = document.getElementById('libraryDeleteModal');
        if (!modalEl) return;

        const form = document.getElementById('libraryDeleteForm');

        modalEl.addEventListener('show.bs.modal', (event) => {
            const button = event.relatedTarget;
            const title = modalEl.querySelector('#libraryDeleteName');
            if (button && form && title) {
                form.action = button.getAttribute('data-delete-url') || '';
                title.textContent = button.getAttribute('data-delete-title') || '';
            }
        });

        // Upgrade the submit to fetch: remove the card in place with a
        // fade-out, then refresh the counters. If anything fails, the
        // native POST still works (its action was set by the modal).
        form?.addEventListener('submit', async (event) => {
            event.preventDefault();

            const recordId = (form.action.match(/\/library\/(\d+)\/delete/) || [])[1];
            const card = root.querySelector(`[data-library-card][data-record-id="${recordId}"]`);

            try {
                await toPayload(await fetchForm(form));

                if (window.bootstrap) {
                    const modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
                    modal.hide();
                }

                if (card) {
                    if (!reduceMotion && window.gsap) {
                        window.gsap.to(card, { opacity: 0, scale: 0.96, y: -6, duration: 0.3, onComplete: () => card.remove() });
                    } else {
                        card.remove();
                    }
                }

                announce('Book removed from your library.');
                refreshCounters();
            } catch (error) {
                announce(error.message, true);
            }
        });
    };

    /* ---------- 6. Book detail panel (add / remove) ---------------- */

    // Adding a book or removing it from the panel changes the panel's
    // whole state (Add <-> Update), which is watch text, so the page
    // reloads afterwards - the flash confirms the change just like the
    // no-JS path. The favourite / status / progress inside the panel
    // repaint in place (sections 2-4).
    const handlePanelAdd = async (form) => {
        form.closest('.library-panel')?.classList.add('is-saving');
        try {
            await toPayload(await fetchForm(form));
            window.location.reload();
        } catch (error) {
            form.closest('.library-panel')?.classList.remove('is-saving');
            announce(error.message, true);
        }
    };

    const handlePanelRemove = (form) => {
        if (!window.confirm('Remove this book from your library?')) return;

        form.closest('.library-panel')?.classList.add('is-saving');

        (async () => {
            try {
                await toPayload(await fetchForm(form));
                window.location.reload();
            } catch (error) {
                form.closest('.library-panel')?.classList.remove('is-saving');
                announce(error.message, true);
            }
        })();
    };

    /* ---------- 7. Filter form (search + filters + sort + view) ------ */

    // The active query from the dashboard's filter controls (the names
    // match the no-JS GET form, so both paths send the same
    // parameters). Shared by the filter form AND the bulk actions,
    // which re-fetch the same grid after a write.
    const collectFilterParams = (form) => {
        const params = {};
        form.querySelectorAll('[data-library-filter]').forEach((el) => {
            const name = el.getAttribute('name');
            if (!name) return;
            if (el.type === 'checkbox') {
                if (el.checked) params[name] = '1';
            } else if (el.value && String(el.value).trim() !== '') {
                params[name] = el.value;
            }
        });
        const sort = form.querySelector('[data-library-sort]');
        if (sort?.value) params.sort = sort.value;
        params.view = form.getAttribute('data-view-mode') || 'grid';
        return params;
    };

    // The dashboard's control row is ONE GET form; every control in it
    // (search box, selects, toggles, sort, view) triggers a fetch that
    // swaps the [data-library-results] region with the freshly rendered
    // grid fragment - the exact partial the no-JS page renders, so the
    // two paths can never drift. The URL is kept in step (replaceState)
    // so refresh / back still land on the same grid.
    const bindFilterForm = () => {
        const form = document.querySelector('[data-library-filter-form]');
        if (!form) return;

        const filterEndpoint = form.getAttribute('data-filter-endpoint') || '/library/filter';
        const sortEndpoint   = form.getAttribute('data-sort-endpoint') || '/library/sort';
        const region         = document.querySelector('[data-library-results]');
        const tabs           = document.querySelector('[data-library-tabs]');
        const liveStatus     = document.querySelector('[data-library-status]');

        // The loading skeleton mirrors one row of library cards so the
        // region never collapses while the request is in flight.
        const skeletonHtml = () => `
            <div class="skeleton" style="margin:24px 0;padding:0 0 24px;">
                <div class="row g-3 g-xl-4 row-cols-1 row-cols-sm-2 row-cols-xl-3 row-cols-xxl-4" aria-hidden="true">
                    ${[0, 1, 2, 3].map(() => `
                    <div class="col">
                        <div class="library-skeleton-card skeleton">
                            <div class="library-skeleton-cover skeleton"></div>
                            <div class="library-skeleton-body">
                                <div class="library-skeleton-line library-skeleton-line--title skeleton"></div>
                                <div class="library-skeleton-line library-skeleton-line--meta skeleton"></div>
                                <div class="library-skeleton-line library-skeleton-line--bar skeleton"></div>
                                <div class="library-skeleton-line skeleton"></div>
                            </div>
                        </div>
                    </div>`).join('')}
                </div>
            </div>`;

        const setPending = (busy) => {
            form.classList.toggle('is-searching', busy);
            if (region) {
                region.setAttribute('aria-busy', busy ? 'true' : 'false');
                if (busy) region.innerHTML = skeletonHtml();
            }
        };

        const highlightTab = (params) => {
            if (!tabs) return;
            const active = params.favorite ? 'favorites' : (params.status || 'all');
            tabs.querySelectorAll('[data-library-tab]').forEach((tab) => {
                tab.classList.toggle('is-active', tab.getAttribute('data-library-tab') === active);
            });
        };

        let inFlight = null;
        let debounceTimer = null;

        const run = async (endpoint) => {
            const params = collectFilterParams(form);

            inFlight?.abort();
            inFlight = new AbortController();

            setPending(true);

            try {
                const query = new URLSearchParams(params).toString();
                const response = await fetch(`${endpoint}?${query}`, {
                    signal: inFlight.signal,
                    headers: { 'X-Requested-With': 'fetch' },
                });

                if (!response.ok) return;

                const data = await response.json();
                if (region) region.innerHTML = data.html;
                if (liveStatus) {
                    liveStatus.textContent = `${data.total} book${data.total === 1 ? '' : 's'}`;
                }
                window.history.replaceState(null, '', `/library?${query}`);
                highlightTab(params);
                refreshCounters();
            } catch (error) {
                if (error?.name === 'AbortError') return;
            } finally {
                setPending(false);
            }
        };

        const searchInput = form.querySelector('[data-library-search-input]');

        searchInput?.addEventListener('input', () => {
            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(() => run(filterEndpoint), 300);
        });

        // Selects and toggles apply on change.
        form.querySelectorAll('select[data-library-filter], input[data-library-filter]').forEach((control) => {
            control.addEventListener('change', () => run(filterEndpoint));
        });

        // The sort applies on change AND persists (the sort endpoint).
        const sortControl = form.querySelector('[data-library-sort]');
        sortControl?.addEventListener('change', () => run(sortEndpoint));

        // The view switch: the active view travels with the query, and
        // the filter endpoint persists it (the /library/view-mode write
        // is the explicit no-JS form of the same preference).
        form.querySelectorAll('[data-library-view]').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                const view = button.getAttribute('data-library-view');
                form.setAttribute('data-view-mode', view);
                form.querySelectorAll('[data-library-view]').forEach((btn) => {
                    const active = btn.getAttribute('data-library-view') === view;
                    btn.classList.toggle('is-active', active);
                    btn.setAttribute('aria-pressed', active ? 'true' : 'false');
                });
                run(filterEndpoint);
            });
        });
    };

    /* ---------- 8. Stats + counter refresh --------------------------- */

    // After any write the whole overview refreshes from
    // /library/statistics - the same aggregate the statistics page
    // shows, so the two can never disagree. While the numbers are in
    // flight the stat cells are skeleton-filled (the Phase 8.3
    // skeleton-stat component), then rebuilt from their data
    // attributes with the fresh values; the header chips, the tab
    // counters and the intro line follow.
    const refreshCounters = () => {
        const statsSection = document.querySelector('[data-library-stats]');
        const endpoint = statsSection?.getAttribute('data-library-stats-endpoint') || '/library/statistics';
        const cells = statsSection ? [...statsSection.querySelectorAll('[data-stat-cell]')] : [];

        const skeletonStatHtml = () => `
            <div class="library-skeleton-stat skeleton">
                <span class="library-skeleton-tile skeleton"></span>
                <span class="library-skeleton-line skeleton"></span>
                <span class="library-skeleton-line library-skeleton-line--sm skeleton"></span>
            </div>`;

        const rebuildStat = (cell, value) => {
            const icon = cell.getAttribute('data-stat-icon') || 'fa-circle-info';
            const label = cell.getAttribute('data-stat-label') || '';
            const tone = cell.getAttribute('data-stat-tone') || 'primary';
            cell.innerHTML = `
                <div class="stat-card">
                    <div class="stat-card-top">
                        <span class="stat-icon tone-${tone}" aria-hidden="true"><i class="fa-solid ${icon}"></i></span>
                    </div>
                    <div class="stat-value">${value}</div>
                    <div class="stat-label">${label}</div>
                </div>`;
        };

        // Skeleton-fill the cells while the numbers are in flight.
        cells.forEach((cell) => { cell.innerHTML = skeletonStatHtml(); });

        fetch(endpoint, { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
            .then((response) => (response.ok ? response.json() : null))
            .then((payload) => {
                const stats = payload?.statistics;
                if (!stats) {
                    // No payload (e.g. the statistics page): leave the
                    // skeletons out of sight - nothing to rebuild.
                    cells.forEach((cell) => { cell.innerHTML = ''; });
                    return;
                }

                const statuses = stats.statuses || {};
                const values = {
                    total: stats.total,
                    currently_reading: statuses.currently_reading || 0,
                    finished: statuses.finished || 0,
                    favorites: stats.favorites || 0,
                    average_progress: Math.round(Number(stats.average_progress || 0) * 10) / 10,
                    added_this_month: stats.added_this_month || 0,
                };

                cells.forEach((cell) => {
                    const value = values[cell.getAttribute('data-stat-cell')];
                    rebuildStat(cell, value ?? 0);
                });

                // The tab counters.
                const map = {
                    all: stats.total,
                    want_to_read: statuses.want_to_read || 0,
                    currently_reading: statuses.currently_reading || 0,
                    finished: statuses.finished || 0,
                    on_hold: statuses.on_hold || 0,
                    dropped: statuses.dropped || 0,
                    favorites: stats.favorites || 0,
                };

                Object.entries(map).forEach(([key, value]) => {
                    const tab = root.querySelector(`[data-library-tab="${key}"]`);
                    const count = tab?.querySelector('.library-tab-count');
                    if (count) count.textContent = value;
                });

                // The header chips and the intro line.
                const chipTotal = root.querySelector('[data-chip-total-value]');
                if (chipTotal) chipTotal.textContent = stats.total;

                const chipProgress = root.querySelector('[data-chip-progress-value]');
                if (chipProgress) chipProgress.textContent = Math.round(Number(stats.average_progress || 0)) + '%';

                const totalEl = root.querySelector('[data-library-total]');
                if (totalEl) {
                    const bookWord = stats.total === 1 ? 'book' : 'books';
                    let text = `You keep ${stats.total} ${bookWord} in your library.`;
                    if (map.currently_reading > 0) text += ` You are currently reading ${map.currently_reading}.`;
                    totalEl.textContent = text;
                }

                // The continue shelf may have changed with the write.
                refreshContinue();

                // The Phase 8.4 collections rail: rebuild every item's
                // occupancy numbers (count / average rating / last
                // updated) from the same statistics payload.
                refreshCollections(payload.collections);
            })
            .catch(() => { /* cosmetics; ignore */ });
    };

    // Rebuild the Smart Collections rail from the collectionStatistics()
    // payload of /library/statistics. The item markup keeps its data
    // contract ([data-library-tab] for the highlight), so only the
    // numbers inside change.
    const refreshCollections = (collections) => {
        if (!collections) return;
        root.querySelectorAll('[data-library-tab]').forEach((item) => {
            const data = collections[item.getAttribute('data-library-tab')];
            if (!data) return;

            const count = Number(data.count || 0);
            const box = item.querySelector('.library-collection-count');
            if (box) {
                box.innerHTML = `<span data-collection-count>${count}</span> ${count === 1 ? 'book' : 'books'}`;
            }

            const meta = item.querySelector('.library-collection-meta');
            if (!meta) return;

            const rating = Number(data.average_rating || 0).toFixed(1);
            const updated = (data.last_updated || '').slice(0, 10);
            const updatedOn = updated ? new Date(updated + 'T00:00:00Z').toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) : '';

            meta.innerHTML = count > 0
                ? `<span><i class="fa-solid fa-star" aria-hidden="true"></i>${rating}</span>`
                    + (updatedOn ? `<span class="library-collection-dot" aria-hidden="true">·</span><span><i class="fa-regular fa-clock" aria-hidden="true"></i>${updatedOn}</span>` : '')
                : '<span>no books yet</span>';
        });
    };

    // Refresh the Continue Reading shelf fragment after a write that
    // may have moved a book off the shelf (a status flip, an
    // auto-finish at 100%). A small skeleton shows while the fresh
    // fragment is in flight; the empty state is part of the fragment.
    const refreshContinue = () => {
        const region = document.querySelector('[data-library-continue]');
        if (!region) return;

        fetch('/library/continue-reading', { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
            .then((response) => (response.ok ? response.json() : null))
            .then((payload) => {
                if (payload?.html) region.innerHTML = payload.html;
            })
            .catch(() => { /* cosmetics; ignore */ });
    };

    /* ---------- 10. Bulk actions (Phase 8.4) ------------------------- */

    // The bulk bar's form id (the grid checkboxes point at it with the
    // HTML5 form attribute, so they live OUTSIDE the form's markup but
    // still travel with its submission - the card forms stay valid).
    const BULK_FORM_ID = 'library-bulk-form';

    const bulkChecked = () => [...root.querySelectorAll('[data-library-select-input]:checked')];
    const bulkInputs  = () => [...root.querySelectorAll('[data-library-select-input]')];

    // Keep the bar honest: hidden while nothing is selected, the count
    // in sync, the select-all state mirroring the page checkboxes.
    const updateBulkBar = () => {
        const bar = document.querySelector('[data-library-bulk-form]');
        if (!bar) return;

        const checked = bulkChecked().length;
        const inputs  = bulkInputs();

        bar.classList.toggle('is-empty', checked === 0);

        // The page-wide "bulk mode": with at least one book selected
        // every card reveals its selection checkbox (see the CSS).
        root.classList.toggle('is-bulk-mode', checked > 0);

        const count = bar.querySelector('[data-bulk-count]');
        if (count) count.textContent = checked;

        const selectAll = bar.querySelector('[data-bulk-select-all]');
        if (selectAll) {
            selectAll.checked = inputs.length > 0 && checked === inputs.length;
            selectAll.indeterminate = checked > 0 && checked < inputs.length;
        }

        const modalCount = root.querySelector('[data-bulk-modal-count]');
        if (modalCount) modalCount.textContent = checked;
    };

    const clearBulkSelection = () => {
        bulkInputs().forEach((input) => { input.checked = false; });
        updateBulkBar();
    };

    // Re-fetch the current filter query and swap the results region -
    // the same fragment path the filter form uses, so the grid and the
    // numbers around it always agree after a bulk write.
    const refreshResults = () => {
        const form = document.querySelector('[data-library-filter-form]');
        const region = document.querySelector('[data-library-results]');
        if (!form || !region) return;

        const params = collectFilterParams(form);
        const query = new URLSearchParams(params).toString();

        fetch(`/library/filter?${query}`, { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
            .then((response) => (response.ok ? response.json() : null))
            .then((data) => {
                if (data?.html) {
                    region.innerHTML = data.html;
                    window.history.replaceState(null, '', `/library?${query}`);
                }
                updateBulkBar();
            })
            .catch(() => { /* cosmetics; ignore */ });
    };

    // The bulk form's submit: a native POST for every action (the
    // clicked button carries action=...), upgraded to a fetch. The ids
    // are appended manually - they live outside the form's DOM.
    const bindBulkForm = () => {
        const bar = document.querySelector('[data-library-bulk-form]');
        if (!bar) return;

        const selectAll = bar.querySelector('[data-bulk-select-all]');
        const clearBtn  = bar.querySelector('[data-bulk-clear]');

        document.addEventListener('change', (event) => {
            if (event.target.matches('[data-library-select-input]')) updateBulkBar();
            if (event.target.matches('[data-bulk-select-all]')) {
                bulkInputs().forEach((input) => { input.checked = selectAll.checked; });
                updateBulkBar();
            }
        });

        clearBtn?.addEventListener('click', clearBulkSelection);

        bar.addEventListener('submit', async (event) => {
            event.preventDefault();

            const action = event.submitter?.value || '';
            const ids = bulkChecked().map((input) => input.value);

            if (!action) return;
            if (ids.length === 0) {
                announce('Select at least one book.', true);
                return;
            }

            const body = new URLSearchParams(new FormData(bar));
            body.set('action', action);
            if (action === 'move_status') {
                body.set('status', bar.querySelector('[data-bulk-status]')?.value || 'want_to_read');
            }
            ids.forEach((id) => body.append('ids[]', id));

            bar.classList.add('is-saving');

            try {
                const payload = await toPayload(await fetch(bar.action, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'fetch' },
                    body,
                    credentials: 'same-origin',
                }));
                announce(payload.message || 'Library updated.');
                clearBulkSelection();
                refreshResults();
                refreshCounters();
            } catch (error) {
                announce(error.message, true);
            } finally {
                bar.classList.remove('is-saving');
            }
        });
    };

    // The bulk-delete confirmation modal: copy the selected ids into
    // its form when it opens, upgrade its submit to a fetch.
    const bindBulkDeleteModal = () => {
        const modalEl = root.querySelector('#libraryBulkModal');
        if (!modalEl) return;

        const form = root.querySelector('[data-bulk-delete-form]');
        const countEl = modalEl.querySelector('[data-bulk-modal-count]');

        modalEl.addEventListener('show.bs.modal', () => {
            const ids = bulkChecked().map((input) => input.value);
            if (countEl) countEl.textContent = ids.length;
            if (!form) return;

            form.querySelectorAll('input[name="ids[]"]').forEach((el) => el.remove());
            ids.forEach((id) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'ids[]';
                input.value = id;
                form.appendChild(input);
            });
        });

        form?.addEventListener('submit', async (event) => {
            event.preventDefault();

            const body = new URLSearchParams(new FormData(form));
            const ids = body.getAll('ids[]');

            if (ids.length === 0) {
                announce('Select at least one book.', true);
                return;
            }

            try {
                const payload = await toPayload(await fetch(form.action, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'fetch' },
                    body,
                    credentials: 'same-origin',
                }));

                if (window.bootstrap) {
                    window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                }

                announce(payload.message || 'Books removed.');
                clearBulkSelection();
                refreshResults();
                refreshCounters();
            } catch (error) {
                announce(error.message, true);
            }
        });
    };

    /* ---------- 11. Quick action menu (Phase 8.4) -------------------- */

    const csrfTokenValue = () => document.querySelector('input[name="_token"]')?.value || '';

    // The "Move to <shelf>" items: one POST per record, the same
    // lifecycle the card's status select drives.
    const quickStatus = async (card, status) => {
        const recordId = card.getAttribute('data-record-id');
        const body = new URLSearchParams({ _token: csrfTokenValue(), status });

        card.classList.add('is-saving');
        try {
            await toPayload(await fetch(`/library/${recordId}`, {
                method: 'POST',
                headers: { 'X-Requested-With': 'fetch' },
                body,
                credentials: 'same-origin',
            }));
            repaintStatus(card, status);
            mirrorProgressFromStatus(card, status);
            refreshCounters();
        } catch (error) {
            announce(error.message, true);
        } finally {
            card.classList.remove('is-saving');
        }
    };

    // The "Mark / Un-mark as Favourite" item: one POST per record, the
    // same toggle the heart button drives - the heart repaints too.
    const quickFavorite = async (card, button) => {
        const recordId = card.getAttribute('data-record-id');
        const body = new URLSearchParams({ _token: csrfTokenValue() });

        card.classList.add('is-saving');
        try {
            const payload = await toPayload(await fetch(`/library/${recordId}/favorite`, {
                method: 'POST',
                headers: { 'X-Requested-With': 'fetch' },
                body,
                credentials: 'same-origin',
            }));

            if (typeof payload.favorite === 'boolean') {
                const heart = card.querySelector('[data-library-favorite-form] .library-fav-btn');
                if (heart) {
                    heart.classList.toggle('is-favorite', payload.favorite);
                    heart.setAttribute('aria-pressed', payload.favorite ? 'true' : 'false');
                    heartPop(heart);
                }
                button.setAttribute('data-quick-favorite', payload.favorite ? '0' : '1');
                const label = button.querySelector('[data-quick-fav-label]');
                if (label) label.textContent = payload.favorite ? 'Un-favourite' : 'Mark as Favourite';
            }
            refreshCounters();
        } catch (error) {
            announce(error.message, true);
        } finally {
            card.classList.remove('is-saving');
        }
    };

    // The quick menu items: delegated, so cards re-injected by the
    // live search behave exactly like the server-rendered ones. The
    // Share item is the brief's explicit PLACEHOLDER - it announces
    // the future feature and does nothing else.
    document.addEventListener('click', (event) => {
        const card = event.target.closest('[data-library-card]');
        if (!card) return;

        const statusButton = event.target.closest('[data-quick-status]');
        if (statusButton) {
            event.preventDefault();
            quickStatus(card, statusButton.getAttribute('data-quick-status'));
            return;
        }

        const favoriteButton = event.target.closest('[data-quick-favorite]');
        if (favoriteButton) {
            event.preventDefault();
            quickFavorite(card, favoriteButton);
            return;
        }

        if (event.target.closest('[data-quick-share]')) {
            event.preventDefault();
            announce('Sharing is coming soon.');
            return;
        }

        const removeButton = event.target.closest('[data-quick-remove]');
        if (removeButton && window.bootstrap) {
            event.preventDefault();
            const modalEl = root.querySelector('#libraryDeleteModal');
            if (modalEl) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show(removeButton);
            }
        }
    });

    /* ---------- 9. Card hover lift (GSAP) --------------------------- */

    const bindCardTilt = () => {
        if (reduceMotion || !window.gsap) return;
        document.querySelectorAll('.library-card, .continue-card, .library-row').forEach((card) => {
            card.addEventListener('mouseenter', () => {
                window.gsap.to(card, { y: -5, duration: 0.25, ease: 'power2.out' });
            });
            card.addEventListener('mouseleave', () => {
                window.gsap.to(card, { y: 0, duration: 0.3, ease: 'power2.out' });
            });
        });
    };

    /* ---------- Wiring ---------------------------------------------- */

    bindDeleteModal();
    bindFilterForm();
    bindCardTilt();
    bindBulkForm();
    bindBulkDeleteModal();

    // Favourite / status / progress are delegated so cards re-injected
    // by the live search behave exactly like the server-rendered ones.

    document.addEventListener('submit', (event) => {
        const favoriteForm = event.target.closest('[data-library-favorite-form], [data-library-panel-favorite]');
        if (favoriteForm) {
            event.preventDefault();
            handleFavorite(favoriteForm);
            return;
        }

        const progressForm = event.target.closest('[data-library-progress-form], [data-library-panel-progress-form]');
        if (progressForm) {
            event.preventDefault();
            const input = progressForm.querySelector('[data-library-progress-input], [data-library-panel-progress-input]');
            const container = progressForm.closest('[data-library-card], [data-library-panel]');
            if (input) input.dataset.previous = String(input.value);
            if (!confirmAtHundred(input, progressForm)) return;
            handleProgress(progressForm, container);
            return;
        }

        const statusForm = event.target.closest('[data-library-status-form], [data-library-panel-status]');
        if (statusForm) {
            event.preventDefault();
            handleStatus(statusForm);
            return;
        }

        const panelAdd = event.target.closest('[data-library-panel-add]');
        if (panelAdd) {
            event.preventDefault();
            handlePanelAdd(panelAdd);
            return;
        }

        const panelRemove = event.target.closest('[data-library-panel-remove]');
        if (panelRemove) {
            event.preventDefault();
            handlePanelRemove(panelRemove);
        }
    });

    document.addEventListener('change', (event) => {
        const select = event.target.closest('[data-library-status-select], [data-library-panel-status-select]');
        if (select) {
            select.dataset.previous = select.value;
            select.form?.requestSubmit();
            return;
        }

        // Dragging a page slider releases as a change -> submit it (the
        // Save button remains as the keyboard / no-JS path).
        const range = event.target.closest('[data-library-progress-input], [data-library-panel-progress-input]');
        if (range) {
            range.dataset.previous = String(range.value);
            if (confirmAtHundred(range, range.form)) {
                range.form?.requestSubmit();
            }
        }
    });
})();