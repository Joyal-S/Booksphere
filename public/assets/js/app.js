/**
 * app.js
 *
 * Global frontend behaviour of the BookSphere shell:
 *
 *   1. dark / light theme toggle (saved in localStorage)
 *   2. sidebar: desktop collapse toggle, mobile slide-in overlay
 *   3. search bar: Ctrl/Cmd + K focuses it (visual only for now)
 *   4. GSAP entrance animations for [data-animate] elements
 *   5. GSAP count-up for statistic values marked with [data-count]
 *   6. book management: delete confirmation modal + cover preview
 *   7. book browse: debounced live search, grid/table view toggle
 *      (preference in localStorage), auto-submitting filter selects
 *
 * Every animation respects prefers-reduced-motion.
 */

(() => {
    'use strict';

    const root = document.documentElement;
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ---------- 1. Theme toggle ---------- */
    const savedTheme = localStorage.getItem('booksphere-theme');

    if (savedTheme) {
        root.dataset.bsTheme = savedTheme;
    }

    const syncThemeIcon = () => {
        const dark = root.dataset.bsTheme === 'dark';
        const icon = document.querySelector('[data-theme-toggle] .theme-icon');

        if (icon) {
            icon.classList.toggle('fa-moon', !dark);
            icon.classList.toggle('fa-sun', dark);
            icon.classList.toggle('fa-regular', dark);
        }
    };

    document.querySelector('[data-theme-toggle]')?.addEventListener('click', () => {
        const next = root.dataset.bsTheme === 'dark' ? 'light' : 'dark';
        root.dataset.bsTheme = next;
        localStorage.setItem('booksphere-theme', next);
        syncThemeIcon();
    });

    syncThemeIcon();

    /* ---------- 2. Sidebar behaviour ---------- */
    const body = document.body;
    const sidebarCollapseBtn = document.querySelector('[data-sidebar-collapse]');
    const sidebarOpenBtn = document.querySelector('[data-sidebar-open]');
    const sidebarBackdrop = document.querySelector('.sidebar-backdrop');

    // Desktop: collapse the sidebar to an icon rail (persisted).
    const savedCollapsed = localStorage.getItem('booksphere-sidebar-collapsed') === '1';
    body.classList.toggle('sidebar-collapsed', savedCollapsed);

    sidebarCollapseBtn?.addEventListener('click', () => {
        const collapsed = body.classList.toggle('sidebar-collapsed');
        localStorage.setItem('booksphere-sidebar-collapsed', collapsed ? '1' : '0');
        sidebarCollapseBtn.querySelector('i')?.classList.toggle('fa-angles-right', collapsed);
        sidebarCollapseBtn.querySelector('i')?.classList.toggle('fa-angles-left', !collapsed);
    });

    // Mobile: slide the sidebar in as an overlay.
    const closeSidebar = () => body.classList.remove('sidebar-open');

    sidebarOpenBtn?.addEventListener('click', () => body.classList.add('sidebar-open'));
    sidebarBackdrop?.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeSidebar();
    });

    // Close the overlay when a navigation link is chosen.
    document.querySelectorAll('.sidebar .nav-item').forEach((item) => {
        item.addEventListener('click', closeSidebar);
    });

    /* ---------- 3. Search shortcut + header search ---------- */
    const searchInput = document.querySelector('[data-search-input]');

    document.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            searchInput?.focus();
        }
    });

    // Phase 11.2: the header search box now leads to the global
    // search page. Pressing Enter (or the Escape key once to clear,
    // twice to blur) navigates to /search?q=... - a real search,
    // not a visual-only box. The shortcut hint in the page's own
    // search field (search.js) keeps the same Ctrl+K affordance.
    searchInput?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            const term = searchInput.value.trim();
            event.preventDefault();
            const query = term ? '?q=' + encodeURIComponent(term) : '';
            window.location.assign('/search' + query);
        }
    });

    /* ---------- 4. Entrance animations (GSAP) ---------- */
    if (window.gsap && !reduceMotion) {
        gsap.from('[data-animate]', {
            opacity: 0,
            y: 18,
            duration: 0.5,
            stagger: 0.12,
            ease: 'power2.out',
            clearProps: 'all',
        });

        /* ---------- 5. Statistic count-up ---------- */
        gsap.utils.toArray('[data-count]').forEach((element) => {
            const target = parseFloat(element.dataset.count || '0');
            const decimals = (String(element.dataset.count).split('.')[1] || '').length;

            gsap.fromTo(
                element,
                { innerText: 0 },
                {
                    innerText: target,
                    duration: 0.9,
                    ease: 'power1.out',
                    snap: { innerText: 10 ** -decimals },
                    onUpdate() {
                        element.textContent = Number(element.innerText).toLocaleString(undefined, {
                            minimumFractionDigits: decimals,
                            maximumFractionDigits: decimals,
                        });
                    },
                },
            );
        });
    }
    /* ---------- 6. Delete confirmation modals ---------- */

    // One shared Bootstrap modal serves every delete row of its
    // kind (books: #deleteModal, reviews: #reviewDeleteModal).
    // When the modal opens, the clicked button (relatedTarget)
    // supplies the form target and the item title, so the modal
    // always posts to the right route and shows the exact item
    // that will be deleted. The books modal additionally previews
    // the cover; bindDeleteModal skips the cover wiring when no
    // cover element ids are given (the review modal).
    const bindDeleteModal = (modalId, titleId, formId, coverImgId, coverFallbackId) => {
        const modal = document.getElementById(modalId);
        if (!modal) return;

        const cover = coverImgId ? modal.querySelector(`#${coverImgId}`) : null;
        const coverFallback = coverFallbackId ? modal.querySelector(`#${coverFallbackId}`) : null;

        const showCover = (src) => {
            if (!cover || !coverFallback) return;

            const hasCover = Boolean(src);
            cover.hidden = !hasCover;
            coverFallback.hidden = hasCover;

            if (hasCover) {
                cover.src = src;
            }
        };

        modal.addEventListener('show.bs.modal', (event) => {
            const button = event.relatedTarget;
            const form = modal.querySelector(`#${formId}`);
            const title = modal.querySelector(`#${titleId}`);

            if (button && form && title) {
                form.action = button.getAttribute('data-delete-url') || '';
                title.textContent = button.getAttribute('data-delete-title') || '';
                showCover(button.getAttribute('data-delete-cover') || '');
            }
        });

        modal.addEventListener('hidden.bs.modal', () => showCover(''));
    };

    // Book delete (Phase 5, with cover preview) and review delete
    // (Phase 7.2, title only) share the exact same wiring.
    bindDeleteModal('deleteModal', 'deleteBookTitle', 'deleteForm', 'deleteBookCover', 'deleteBookCoverFallback');
    bindDeleteModal('reviewDeleteModal', 'reviewDeleteTitle', 'reviewDeleteForm', null, null);

    // Media upload cards: drag & drop zone, live preview and
    // Replace / Remove actions (book covers today; any media type
    // rendered through components/upload-card.php tomorrow).
    //
    // The card keeps THREE states, mirrored by the DOM:
    //     server   - whatever the book currently has (file untouched)
    //     pending  - a new file was chosen, not yet submitted
    //     removing - the "Remove" button flipped the hidden flag
    //
    // The client-side type/size guard mirrors the server rules
    // (MediaService reads the same limits from config/media.php) so
    // invalid files get instant feedback - but it is only UX: the
    // server re-validates every file, since client checks are
    // trivially bypassed.
    document.querySelectorAll('[data-upload-card]').forEach((card) => {
        const input      = card.querySelector('[data-upload-input]');
        const dropzone   = card.querySelector('[data-upload-dropzone]');
        const hint       = card.querySelector('[data-upload-hint]');
        const preview    = card.querySelector('[data-upload-preview]');
        const empty      = card.querySelector('[data-upload-empty]');
        const removeBtn  = card.querySelector('[data-upload-remove-btn]');
        const flag       = card.querySelector('[data-upload-remove-flag]');
        const errorText  = card.querySelector('[data-upload-error]');
        const browseBtn  = card.querySelector('[data-upload-browse]');
        const hasCurrent = card.dataset.hasCurrent === '1';
        const currentSrc = preview ? preview.getAttribute('src') : '';
        const maxBytes   = Number(input?.dataset.maxBytes || 5 * 1024 * 1024);

        let pendingFile = null;
        let objectUrl   = null;

        // Parse the input's accept attribute ("image/jpeg,.jpg,...")
        // into mime types and extensions for the client-side guard.
        const allowedTypes = new Set();
        const allowedExtensions = new Set();

        (input?.getAttribute('accept') || '').split(',').forEach((entry) => {
            const item = entry.trim();
            if (item.startsWith('.')) allowedExtensions.add(item.slice(1).toLowerCase());
            else if (item.includes('/')) allowedTypes.add(item.toLowerCase());
        });

        const meetsClientRules = (file) => {
            if (file.size > maxBytes) {
                errorText.textContent = 'This file is larger than 5 MB.';
                return false;
            }
            const extension = file.name.split('.').pop()?.toLowerCase() || '';
            if (!allowedTypes.has(file.type) && !allowedExtensions.has(extension)) {
                errorText.textContent = 'Only JPG, PNG and WebP images are allowed.';
                return false;
            }
            return true;
        };

        const showError = (message) => {
            errorText.textContent = message;
            errorText.hidden = false;
        };

        const clearError = () => {
            errorText.hidden = true;
            errorText.textContent = '';
        };

        // State 1 - back to what the book has on the server.
        const restoreServerState = () => {
            if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
            pendingFile = null;
            if (input) input.value = '';
            if (flag) flag.value = '0';
            if (preview) preview.hidden = !hasCurrent;
            if (empty) empty.hidden = hasCurrent;
            if (removeBtn) removeBtn.hidden = !hasCurrent;
            if (hint) hint.textContent = hasCurrent ? 'Replace the current cover' : 'Choose a file or drag & drop';
            clearError();
        };

        // State 2 - a valid new file was picked.
        const showPendingFile = (file) => {
            if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
            pendingFile = file;
            if (flag) flag.value = '0';
            if (preview) {
                preview.src = URL.createObjectURL(file);
                objectUrl = preview.src;
                preview.hidden = false;
            }
            if (empty) empty.hidden = true;
            if (removeBtn) removeBtn.hidden = false;
            if (hint) hint.textContent = file.name;
            clearError();
        };

        // State 3 - the user asked to remove the current cover.
        const markRemoving = () => {
            if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
            pendingFile = null;
            if (input) input.value = '';
            if (flag) flag.value = '1';
            if (preview) preview.hidden = true;
            if (empty) empty.hidden = false;
            if (removeBtn) removeBtn.hidden = true;
            if (hint) hint.textContent = 'The current cover will be removed when you save.';
            clearError();
        };

        const handleFile = (file) => {
            if (!file) {
                restoreServerState();
                return;
            }
            if (!meetsClientRules(file)) {
                // Keep the previous state; the error message explains.
                return;
            }
            showPendingFile(file);
        };

        // Browse buttons + dropzone click / keyboard open the picker.
        browseBtn?.addEventListener('click', () => input?.click());
        dropzone?.addEventListener('click', (event) => {
            if (event.target === browseBtn) return;
            input?.click();
        });
        dropzone?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                input?.click();
            }
        });

        input?.addEventListener('change', () => handleFile(input.files[0]));

        // Drag & drop: highlight the zone while dragging, accept the
        // dropped file. The picker may reject assigning dropped files
        // to input.files on old browsers - the preview still shows.
        ['dragenter', 'dragover'].forEach((eventName) => {
            dropzone?.addEventListener(eventName, (event) => {
                event.preventDefault();
                dropzone.classList.add('is-dragging');
            });
        });
        ['dragleave', 'drop'].forEach((eventName) => {
            dropzone?.addEventListener(eventName, (event) => {
                event.preventDefault();
                dropzone.classList.remove('is-dragging');
            });
        });
        dropzone?.addEventListener('drop', (event) => {
            const file = event.dataTransfer?.files[0];
            if (!file) return;
            try {
                const transfer = new DataTransfer();
                transfer.items.add(file);
                if (input) input.files = transfer.files;
            } catch (_) { /* preview-only fallback below */ }
            handleFile(file);
        });

        removeBtn?.addEventListener('click', () => {
            if (pendingFile) {
                restoreServerState();
            } else if (hasCurrent) {
                markRemoving();
            }
        });
    });

    /* ---------- 7. Book browse (Phase 5.5) ---------- */

    // Real-time search + filters with server-side pagination.
    //
    // Flow: the user types in the search box (or any [data-live-search]
    // field) -> the keystrokes are debounced for 300 ms -> the whole
    // form is serialized and fetched from /books/search -> the response
    // contains the freshly rendered results partial + result numbers ->
    // the partial is swapped in and the URL is kept shareable.
    //
    // The server is the only source of truth: the page's own form POST
    // behaviour is untouched, so with JavaScript disabled the Search
    // button still works exactly the same.
    const browseForm = document.querySelector('[data-live-search-form]');

    if (browseForm) {
        const endpoint = browseForm.dataset.searchEndpoint || '/books/search';
        const resultsRegion = document.querySelector('[data-live-results]');
        const liveStatus = document.querySelector('[data-live-status]');
        const viewTarget = document.querySelector('[data-book-view]');

        let debounceTimer = null;
        let inFlight = null;

        // Keep the address bar in sync with the current filters, so
        // results can be shared/bookmarked like a normal page URL.
        const syncUrl = () => {
            const params = new URLSearchParams(new FormData(browseForm));
            [...params.entries()].forEach(([key, value]) => {
                if (value === '') params.delete(key);
            });
            const query = params.toString();
            history.replaceState(null, '', '/books' + (query ? '?' + query : ''));
        };

        const fetchResults = async () => {
            const params = new URLSearchParams(new FormData(browseForm));

            // Abort the previous request: only the latest keystroke wins.
            inFlight?.abort();
            inFlight = new AbortController();

            resultsRegion?.setAttribute('aria-busy', 'true');
            browseForm.classList.add('is-searching');

            try {
                const response = await fetch(`${endpoint}?${params.toString()}`, {
                    signal: inFlight.signal,
                    headers: { 'X-Requested-With': 'fetch' },
                });

                if (!response.ok) return;

                const data = await response.json();

                if (resultsRegion) resultsRegion.innerHTML = data.html;

                // Announce the new totals to screen readers.
                if (liveStatus) {
                    liveStatus.textContent = `${data.total} books, page ${data.page} of ${data.pages}`;
                }

                syncUrl();
                applyView(currentView);
            } catch (error) {
                // AbortError is expected when a newer keystroke replaced us.
                if (error?.name === 'AbortError') return;
            } finally {
                resultsRegion?.setAttribute('aria-busy', 'false');
                browseForm.classList.remove('is-searching');
            }
        };

        // Debounced live search on every free-text field (q, publisher,
        // the year range). 300 ms keeps the server quiet while typing.
        browseForm.querySelectorAll('[data-live-search]').forEach((field) => {
            field.addEventListener('input', () => {
                window.clearTimeout(debounceTimer);
                debounceTimer = window.setTimeout(fetchResults, 300);
            });
        });

        // Selects and number inputs submit the form on change - a
        // native, keyboard-friendly navigation to the new results.
        browseForm.querySelectorAll('[data-auto-submit]').forEach((control) => {
            control.addEventListener('change', () => browseForm.requestSubmit());
        });

        /* ---- Grid / table view toggle (preference remembered) ---- */
        const toggleButtons = [...browseForm.querySelectorAll('[data-view-toggle]')];

        let currentView = localStorage.getItem('booksphere-book-view')
            || viewTarget?.dataset.bookView
            || 'table';

        const applyView = (view) => {
            currentView = view;
            if (viewTarget) viewTarget.dataset.bookView = view;

            toggleButtons.forEach((button) => {
                const active = button.dataset.viewToggle === view;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        };

        toggleButtons.forEach((button) => {
            button.addEventListener('click', () => {
                applyView(button.dataset.viewToggle);
                localStorage.setItem('booksphere-book-view', button.dataset.viewToggle);
            });
        });

        applyView(currentView);
    }
})();

/* =========================================================
   Phase 6.4 - Recommendation Dashboard behaviour
   (progressive enhancement: every feature here has a native
   HTML fallback, so the page works with JavaScript disabled)
   ========================================================= */

(() => {
    'use strict';

    const dashboard = document.querySelector('[data-dashboard]');

    /* ---------- 1. Refresh with loading skeletons ---------- */

    // The refresh form POSTs to /recommendations/refresh. We never
    // hijack the navigation - instead we swap the page into its
    // "running" state (spinning button + skeleton shelves) and let
    // the browser load the fresh page. The skeletons keep the
    // layout stable and communicate the rebuild while it happens.
    document.querySelectorAll('[data-refresh-form]').forEach((form) => {
        form.addEventListener('submit', () => {
            const button = form.querySelector('[data-refresh-button]');
            const skeletons = dashboard?.querySelector('[data-skeletons]');

            button?.classList.add('is-running');
            button?.setAttribute('aria-busy', 'true');
            dashboard?.classList.add('is-refreshing');
            if (skeletons) skeletons.hidden = false;
        });
    });

    /* ---------- 2. "Why this book?" panel toggle ---------- */

    // One open panel per card: toggling a second one closes the
    // first, so the card never scrolls twice its height.
    document.querySelectorAll('[data-reason-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const card = button.closest('.rec-card');
            const panel = card?.querySelector('[data-reason-panel]');
            const nowOpen = Boolean(panel && panel.hidden);

            card?.querySelectorAll('[data-reason-panel]').forEach((p) => { p.hidden = true; });
            card?.querySelectorAll('[data-reason-toggle]').forEach((b) => {
                b.setAttribute('aria-expanded', 'false');
                b.classList.remove('is-open');
            });

            if (nowOpen && panel) {
                panel.hidden = false;
                button.setAttribute('aria-expanded', 'true');
                button.classList.add('is-open');
            }
        });
    });

    /* ---------- 3. Wishlist toggle (fetch, with native fallback) ---------- */

    document.querySelectorAll('[data-wishlist-form]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const button = form.querySelector('[data-wishlist-state]');

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'fetch' },
                    body: new URLSearchParams(new FormData(form)),
                    credentials: 'same-origin',
                });

                if (!response.ok) throw new Error('Wishlist toggle failed');

                const payload = await response.json();

                if (typeof payload.saved === 'boolean') {
                    const bookId = new FormData(form).get('book_id');

                    // Mirror the new state on every card of the same
                    // book, wherever it appears on the dashboard.
                    document.querySelectorAll('[data-wishlist-form]').forEach((other) => {
                        if (new FormData(other).get('book_id') !== bookId) return;

                        const otherButton = other.querySelector('[data-wishlist-state]');
                        const icon = otherButton?.querySelector('i');
                        const text = otherButton?.querySelector('.rec-wish-text');
                        const saved = payload.saved;

                        otherButton?.classList.toggle('is-saved', saved);
                        otherButton?.setAttribute('aria-pressed', saved ? 'true' : 'false');
                        otherButton?.setAttribute('data-wishlist-state', saved ? 'saved' : 'open');
                        if (icon) icon.className = saved ? 'fa-solid fa-heart' : 'fa-regular fa-heart';
                        if (text) text.textContent = saved ? 'In wishlist' : 'Wishlist';
                    });
                }
            } catch (error) {
                // Network hiccup: fall back to the native form post,
                // which flashes and redirects like the no-JS path.
                form.submit();
            }
        });
    });

    /* ---------- 4. Scroll-reveal animations (GSAP) ---------- */

    if (window.gsap && !reduceMotion) {
        const sections = gsap.utils.toArray('[data-reveal]');

        const revealSection = (section) => {
            const targets = section.querySelectorAll('.rec-card, .genre-card, .stat-card');
            const payload = targets.length ? targets : [section];

            gsap.fromTo(payload, {
                opacity: 0,
                y: 24,
            }, {
                opacity: 1,
                y: 0,
                duration: 0.55,
                ease: 'power2.out',
                stagger: targets.length ? 0.05 : 0,
            });
        };

        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (!entry.isIntersecting) return;

                    observer.unobserve(entry.target);
                    revealSection(entry.target);
                });
            }, { threshold: 0.08 });

            sections.forEach((section) => observer.observe(section));
        } else {
            sections.forEach(revealSection);
        }
    }
})();
