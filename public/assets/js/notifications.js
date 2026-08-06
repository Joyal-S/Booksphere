/**
 * notifications.js — the Notification Center UI (Phase 9.4).
 *
 * Progressive enhancement over the server-rendered controls, which all
 * stay real CSRF forms so the no-JS path (native submit + redirect +
 * flash) always works:
 *
 *     1. BADGE    — reads /notifications/unread-count once on load and
 *        re-reads it after every read/delete cycle; the number pulses
 *        when it RISES (a new notification). A light poll keeps it
 *        fresh while the tab sits open, so the bell is always the
 *        server's count.
 *     2. DROPDOWN — the bell panel loads its five most recent items
 *        from GET /notifications?per_page=5 every time it opens
 *        (skeleton, empty and error states), and its Mark all read
 *        repaints the panel + badge in place.
 *     3. CENTER   — the filter chips and pager links are real links to
 *        /notifications/center; this module intercepts the clicks and
 *        swaps [data-notif-results] through the shared
 *        /notifications/fragment (the SAME partial the first paint
 *        used), so the page and the fetch never drift. A skeleton
 *        shows while a request is in flight and the previous content
 *        is restored on error.
 *     4. ACTIONS  — read/unread (PATCH) flip the card in place;
 *        delete one, delete selected and clear all (DELETE/POST)
 *        route through ONE shared Bootstrap confirmation modal before
 *        the CSRF-protected form is submitted by fetch, then the list
 *        and the badge repaint together.
 *     5. BULK     — the external checkboxes (form="notif-bulk-form")
 *        are collected explicitly into ?ids[]= on submit, exactly
 *        like library.js — the win is never a DOM-dependent gamble.
 */
(() => {
    'use strict';

    const REDUCED_MOTION = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* Escape a value for safe innerHTML (the feed's items are raw). */
    const esc = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');

    /* Parse a fetch response into its JSON payload, raising the
       friendliest server message on any non-2xx answer. */
    const toPayload = async (response) => {
        let payload = {};
        try {
            payload = await response.json();
        } catch (_) {
            /* non-JSON (e.g. a redirect body) */
        }
        if (!response.ok) {
            throw new Error(payload.error || 'Something went wrong - please try again.');
        }
        return payload;
    };

    /* The center's aria-live region; pages without one (the bell
       dropdown on any page) get the status through the sr-only badge
       live region instead - a native alert() is never used (Phase
       9.6: a scripted dialog on unrelated pages is worse than no
       announcement). */
    const announce = (message, isError = false) => {
        const live = document.querySelector('[data-notif-status]');
        if (live) {
            live.textContent = (isError ? 'Error: ' : '') + message;
        }
    };

    /* A CSRF-protected fetch submission of a real form (its _token and
       _method inputs travel with it). */
    const fetchForm = (form) => fetch(form.action, {
        method: 'POST',
        headers: { 'X-Requested-With': 'fetch' },
        body: new URLSearchParams(new FormData(form)),
        credentials: 'same-origin',
    });

    const jsonGet = (url) => fetch(url, {
        headers: { 'X-Requested-With': 'fetch' },
        credentials: 'same-origin',
    });

    /* Relative age for the dropdown's compact items (the feed carries
       raw UTC timestamps). */
    const timeAgo = (iso) => {
        const stamp = Date.parse(iso);
        if (Number.isNaN(stamp)) return '';
        const diff = Date.now() - stamp;
        const mins = Math.floor(diff / 60000);
        if (mins < 1) return 'just now';
        if (mins < 60) return mins + 'm ago';
        const hours = Math.floor(mins / 60);
        if (hours < 24) return hours + 'h ago';
        const days = Math.floor(hours / 24);
        if (days < 7) return days + 'd ago';
        return new Date(stamp).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    };

    /* ------------------------------------------------------------------
       1. The badge: the unread count on the bell.
       ------------------------------------------------------------------ */

    const badgeEl = () => document.querySelector('[data-notif-badge]');
    const badgeLive = () => document.querySelector('[data-notif-live]');
    let lastBadge = null;

    const setBadge = (count) => {
        const badge = badgeEl();
        if (!badge) return;

        const next = Math.max(0, count);
        const changed = next !== lastBadge;
        badge.textContent = String(next);
        badge.hidden = next <= 0;

        const trigger = document.querySelector('[data-notif-trigger]');
        if (trigger) {
            trigger.setAttribute('aria-label', next > 0
                ? 'Notifications (' + next + ' unread)'
                : 'Notifications');
        }

        // The sr-only live region announces a CHANGE (never the
        // load-time seed), so screen readers learn of new mail
        // without being paged by a 45 s poll.
        const live = badgeLive();
        if (live && changed && lastBadge !== null) {
            live.textContent = next === 0
                ? 'No unread notifications'
                : next + ' unread notification' + (next === 1 ? '' : 's');
        }

        // The Mark all read buttons (bell dropdown + center intro)
        // make no sense when nothing is unread.
        document.querySelectorAll('[data-notif-mark-all-btn]').forEach((button) => {
            button.disabled = next <= 0;
        });

        // The pulse when the count RISES (a new notification); the
        // first paint never pulses (lastBadge is null until then).
        if (next > lastBadge && lastBadge !== null && !REDUCED_MOTION) {
            badge.classList.remove('is-pulsing');
            void badge.offsetWidth; /* restart the animation */
            badge.classList.add('is-pulsing');
        }

        lastBadge = next;
    };

    let badgeInFlight = false;

    const refreshBadge = async () => {
        // The badge is a cosmetic surface: never request it (or let a
        // slow answer overlap a newer one) while the tab is hidden or
        // another refresh is already in flight.
        if (document.hidden || badgeInFlight) return;
        badgeInFlight = true;
        try {
            const payload = await toPayload(await jsonGet('/notifications/unread-count'));
            setBadge(payload.count);
        } catch (_) {
            /* the badge is cosmetic — never surface poll errors */
        } finally {
            badgeInFlight = false;
        }
    };

    /* ------------------------------------------------------------------
       2. The dropdown — the bell panel.
       ------------------------------------------------------------------ */

    const panelEl = () => document.querySelector('[data-notif-panel]');
    let panelInFlight = false;

    const panelMessage = (icon, text) =>
        '<div class="notif-menu-message"><i class="' + icon + '" aria-hidden="true"></i><p>' + esc(text) + '</p></div>';

    const compactItem = (item) => {
        const unread = Number(item.is_read) === 1 ? '' : ' is-unread';
        return (
            '<div class="notif-item' + unread + '" data-notif-item data-notif-id="' + esc(item.id) + '">'
            + '<span class="notif-icon notif-icon--' + esc(item.color || 'primary') + '" aria-hidden="true">'
            + '<i class="' + esc(item.icon || 'fa-solid fa-bell') + '"></i></span>'
            + '<div class="notif-item-body">'
            + '<div class="notif-item-head">'
            + '<h3 class="notif-item-title">' + esc(item.title || '') + '</h3>'
            + '<span class="notif-item-time">' + esc(timeAgo(item.created_at)) + '</span>'
            + '</div>'
            + '<p class="notif-item-message">' + esc(item.message || '') + '</p>'
            + '</div></div>'
        );
    };

    const loadPanel = async () => {
        const panel = panelEl();
        if (!panel || panelInFlight) return;
        panelInFlight = true;

        try {
            const payload = await toPayload(await jsonGet('/notifications?per_page=5'));
            const items = payload.items || [];
            panel.innerHTML = items.length === 0
                ? panelMessage('fa-regular fa-bell-slash', "You're all caught up.")
                : items.map(compactItem).join('');
        } catch (_) {
            panel.innerHTML = panelMessage('fa-solid fa-triangle-exclamation', 'Could not load notifications.');
        } finally {
            panelInFlight = false;
        }
    };

    /* ------------------------------------------------------------------
       3. The center page: the list region + its state.
       ------------------------------------------------------------------ */

    const resultsRegion = () => document.querySelector('[data-notif-results]');
    const skeletonTpl = () => document.querySelector('[data-notif-skeleton]');
    let listSequence = 0;

    /* Move focus to the list's heading after a fragment swap, so
       keyboard users continue where they were; the heading is
       tabindex="-1" in the partial so it can receive focus without
       entering the tab order. */
    const focusHeading = () => {
        const heading = document.querySelector('[data-notif-results-heading]');
        if (heading) heading.focus();
    };

    // The current list state, seeded from the page's query string and
    // advanced by every chip / pager click.
    const urlParams = new URLSearchParams(window.location.search);
    const state = {
        tab: urlParams.get('tab') || 'all',
        filter: urlParams.get('filter') || '',
        page: parseInt(urlParams.get('page') || '1', 10) || 1,
    };

    const syncChips = () => {
        document.querySelectorAll('[data-notif-chip]').forEach((chip) => {
            const chipUrl = new URL(chip.href, window.location.href);
            chip.classList.toggle('is-active', chipUrl.searchParams.get('tab') === state.tab
                && (chipUrl.searchParams.get('filter') || '') === state.filter);
        });
    };

    /* The intro's lead + the Mark all read button react to the unread
       count a fragment answer carries. */
    const repaintLead = (unread) => {
        const text = document.querySelector('[data-notif-unread-text]');
        if (text) {
            text.textContent = unread > 0
                ? unread + ' unread notification' + (unread === 1 ? '' : 's')
                : 'nothing unread';
        }
        document.querySelectorAll('[data-notif-mark-all-btn]').forEach((button) => {
            button.disabled = unread <= 0;
        });
    };

    const loadList = async ({ silent = false } = {}) => {
        const region = resultsRegion();
        const template = skeletonTpl();
        if (!region) return null;

        // A sequence token keeps fast chip/pager clicks in order: an
        // older response can never overwrite a newer one.
        const seq = ++listSequence;

        const previous = region.innerHTML;
        if (template) region.innerHTML = template.innerHTML;
        region.setAttribute('aria-busy', 'true');

        try {
            const url = '/notifications/fragment'
                + '?tab=' + encodeURIComponent(state.tab)
                + '&filter=' + encodeURIComponent(state.filter)
                + '&page=' + state.page;
            const payload = await toPayload(await jsonGet(url));
            if (seq !== listSequence) return payload; /* superseded */
            region.innerHTML = payload.html;
            region.removeAttribute('aria-busy');
            syncChips();
            repaintLead(payload.unread);
            setBadge(payload.unread);
            // The fragment arrives with real content: move keyboard
            // focus to the list heading so a pager/chip click does not
            // drop the reader at the top of the page (Phase 9.6).
            focusHeading();
            if (!silent) {
                announce('Showing page ' + state.page + ' of your notifications.');
            }
            return payload;
        } catch (error) {
            if (seq !== listSequence) return null;
            region.innerHTML = previous;
            region.removeAttribute('aria-busy');
            announce(error.message, true);
            return null;
        }
    };

    /* ------------------------------------------------------------------
       4. The shared confirmation modal + the action runners.
       ------------------------------------------------------------------ */

    const confirmModal = () => document.getElementById('notifConfirmModal');
    let pendingAction = null;

    const openConfirm = (title, body, goLabel, action) => {
        const element = confirmModal();
        if (!element) {
            action(); /* no modal on this page — run the action */
            return;
        }
        pendingAction = action;
        element.querySelector('#notifConfirmTitle').textContent = title;
        element.querySelector('#notifConfirmBody').textContent = body;
        const go = element.querySelector('#notifConfirmGo');
        go.innerHTML = '<i class="fa-solid fa-trash me-1" aria-hidden="true"></i>' + esc(goLabel);
        bootstrap.Modal.getOrCreateInstance(element).show();
    };

    /* Run one destructive form: submit by fetch, then repaint the
       list + the badge together, and report the removed count. The
       badge is read from the fragment's own unread number - no
       second COUNT request (Phase 9.6). */
    const runForm = async (form) => {
        try {
            const payload = await toPayload(await fetchForm(form));
            const listPayload = await loadList({ silent: true });
            if (listPayload === null) await refreshBadge(); /* no region on this page */
            if (payload.deleted !== undefined) {
                announce(payload.deleted + ' notification' + (payload.deleted === 1 ? '' : 's') + ' deleted.');
            }
        } catch (error) {
            await loadList({ silent: true }); /* restore the region */
            announce(error.message, true);
        }
    };

    /* ------------------------------------------------------------------
       5. Event wiring — all delegated, so every control works whether
       it was server-rendered or arrived inside a fragment swap.
       ------------------------------------------------------------------ */

    // 5.0 The badge: seed on load, then the poll + refocus refresh.
    refreshBadge();
    setInterval(refreshBadge, 45000);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refreshBadge();
    });

    // 5.1 The bell opens: refresh the panel and the badge.
    document.addEventListener('show.bs.dropdown', (event) => {
        if (!event.relatedTarget || !event.relatedTarget.matches('[data-notif-trigger]')) return;
        loadPanel();
        refreshBadge();
    });

    // 5.2 Filter chips: an in-place swap instead of navigation.
    document.addEventListener('click', (event) => {
        const chip = event.target.closest('[data-notif-chip]');
        if (!chip) return;
        event.preventDefault();
        const chipUrl = new URL(chip.href, window.location.href);
        state.tab = chipUrl.searchParams.get('tab') || 'all';
        state.filter = chipUrl.searchParams.get('filter') || '';
        state.page = 1;
        loadList();
    });

    // 5.3 The pager links inside the results region.
    document.addEventListener('click', (event) => {
        const region = resultsRegion();
        if (!region) return;
        const link = event.target.closest('.review-pagination a[href]');
        if (!link || !region.contains(link)) return;
        event.preventDefault();
        const linkUrl = new URL(link.href, window.location.href);
        state.tab = linkUrl.searchParams.get('tab') || state.tab;
        state.filter = linkUrl.searchParams.get('filter') || state.filter;
        state.page = parseInt(linkUrl.searchParams.get('page') || '1', 10) || 1;
        loadList();
    });

    // 5.4 The bulk selection state (count / disabled / select-all).
    const updateBulk = () => {
        const region = resultsRegion();
        if (!region) return;
        const checks = [...region.querySelectorAll('[data-notif-check]')];
        const checked = checks.filter((el) => el.checked);

        const count = document.querySelector('[data-notif-bulk-count]');
        if (count) count.textContent = checked.length === 0 ? '0 selected' : checked.length + ' selected';

        const deleteBtn = document.querySelector('[data-notif-bulk-delete]');
        if (deleteBtn) deleteBtn.disabled = checked.length === 0;

        const selectAll = document.querySelector('[data-notif-select-all]');
        if (selectAll) {
            selectAll.checked = checks.length > 0 && checked.length === checks.length;
            selectAll.indeterminate = checked.length > 0 && checked.length < checks.length;
        }
    };

    document.addEventListener('change', (event) => {
        const selectAll = event.target.closest('[data-notif-select-all]');
        if (selectAll) {
            const region = resultsRegion();
            if (region) {
                region.querySelectorAll('[data-notif-check]').forEach((el) => {
                    el.checked = selectAll.checked;
                });
            }
        }
        updateBulk();
    });

    // 5.5 The submissions: mark-all, toggle, delete one, bulk, clear.
    document.addEventListener('submit', async (event) => {
        const form = event.target.closest(
            'form[data-notif-mark-all], form[data-notif-toggle], form[data-notif-delete-form], form[data-notif-bulk-form], form[data-notif-clear-form]',
        );
        if (!form) return;
        event.preventDefault();

        // Mark all read: repaint the list + badge + dropdown panel.
        if (form.matches('[data-notif-mark-all]')) {
            try {
                const payload = await toPayload(await fetchForm(form));
                const listPayload = await loadList({ silent: true });
                if (listPayload === null) await refreshBadge(); /* no region (dropdown on any page) */
                if (panelEl()) await loadPanel();
                announce(Number(payload.changed || 0) + ' notification' + (payload.changed === 1 ? '' : 's') + ' marked as read.');
            } catch (error) {
                announce(error.message, true);
            }
            return;
        }

        // Read-state toggle: flip the card in place, then the badge.
        if (form.matches('[data-notif-toggle]')) {
            const item = form.closest('[data-notif-item]');
            if (!item) return;
            try {
                const payload = await toPayload(await fetchForm(form));
                const nowRead = form.action.includes('/read');
                item.classList.toggle('is-unread', !nowRead);
                const label = form.querySelector('[data-notif-toggle-label]');
                if (label) {
                    label.textContent = nowRead ? 'Mark as unread' : 'Mark as read';
                    label.setAttribute('aria-pressed', nowRead ? 'true' : 'false');
                }
                form.action = form.action.replace(/\/\d+\/(read|unread)$/, '/' + (nowRead ? 'unread' : 'read'));
                await refreshBadge();
                announce(nowRead ? 'Marked as read.' : 'Marked as unread.');
            } catch (error) {
                announce(error.message, true);
            }
            return;
        }

        // Bulk delete: collect the checked ids into ?ids[]= and route
        // through the confirmation modal.
        if (form.matches('[data-notif-bulk-form]')) {
            const ids = [...(resultsRegion()?.querySelectorAll('[data-notif-check]:checked') || [])]
                .map((el) => el.value);
            if (ids.length === 0) {
                announce('Select at least one notification.', true);
                return;
            }
            openConfirm(
                'Delete notifications?',
                'Delete the ' + ids.length + ' selected notification' + (ids.length === 1 ? '' : 's') + '?',
                'Delete',
                () => {
                    form.querySelectorAll('input[name="ids[]"]').forEach((el) => el.remove());
                    ids.forEach((id) => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'ids[]';
                        input.value = id;
                        form.appendChild(input);
                    });
                    runForm(form);
                },
            );
            return;
        }

        // Single delete: confirm with the notification's title.
        if (form.matches('[data-notif-delete-form]')) {
            const item = form.closest('[data-notif-item]');
            const title = item?.querySelector('.notif-item-title')?.textContent?.trim() || 'this notification';
            openConfirm(
                'Delete notification?',
                'Delete "' + title + '"? This cannot be undone.',
                'Delete',
                () => runForm(form),
            );
            return;
        }

        // Clear all: the heaviest hammer, asked the most explicitly.
        if (form.matches('[data-notif-clear-form]')) {
            openConfirm(
                'Clear all notifications?',
                'Delete your entire notification history? This cannot be undone.',
                'Clear all',
                () => runForm(form),
            );
        }
    });

    // 5.6 The confirmation modal's go button runs the pending action.
    const goButton = document.getElementById('notifConfirmGo');
    if (goButton) {
        goButton.addEventListener('click', () => {
            const element = confirmModal();
            if (element) bootstrap.Modal.getInstance(element)?.hide();
            if (pendingAction) {
                const action = pendingAction;
                pendingAction = null;
                action();
            }
        });
    }
})();
