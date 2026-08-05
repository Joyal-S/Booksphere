/**
 * reviews.js — the professional review interface (Phase 7.4 + 7.5).
 *
 * Progressive enhancements over the server-rendered review lists:
 *
 *     1. READ MORE / READ LESS — long review bodies (over ~250
 *        characters) are truncated in place with a "Read more"
 *        button; clicking expands/collapses smoothly (GSAP height
 *        animation, skipped for reduced-motion users) without any
 *        page reload. The full text stays in the DOM, so no-JS
 *        visitors and screen readers always see the whole review.
 *
 *     2. LOADING SKELETONS — submitting the review toolbar form
 *        (search, sort select, per-page select) fades the list out
 *        and shows skeleton cards while the browser navigates, so
 *        the next page appears without a jarring blank flash.
 *
 *     3. HELPFUL TOGGLE (Phase 7.5) — each Helpful button posts to
 *        POST /reviews/{id}/helpful (or /helpful/remove) via fetch
 *        and repaints in place: the pressed state, the count and a
 *        subtle GSAP pop on the counter. No page reload; the
 *        server stays the source of truth (the payload carries the
 *        fresh voted state and count).
 *
 *     4. REPORT MODAL (Phase 7.5) — the shared #reviewReportModal:
 *        the triggering button's data-report-id becomes the form
 *        action, the submit posts via fetch, validation errors
 *        (422) render inline under the fields, rule failures (409)
 *        show in the general alert, and success swaps the form for
 *        the thank-you state. Reopening the modal resets it.
 *
 * The star-rating component keeps its own rating.js; the dashboard
 * keeps its own app.js — no script reaches across module boundaries.
 */
(() => {
    'use strict';

    const READ_MORE_LIMIT = 250;
    const REDUCED_MOTION = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ---- 1. Read More / Read Less --------------------------------- */

    const initReadMore = () => {
        document.querySelectorAll('[data-review-body]').forEach((element) => {
            const fullText = (element.textContent || '').trim();

            if (fullText.length <= READ_MORE_LIMIT) {
                return;
            }

            // Truncate at a word boundary so the preview never ends
            // mid-word, and mark the preview as truncated.
            const shortText = fullText.slice(0, READ_MORE_LIMIT).replace(/\s+\S*$/, '') + '…';

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'review-read-more';
            button.setAttribute('aria-expanded', 'false');
            button.textContent = 'Read more';

            element.textContent = shortText;
            element.after(button);

            // The smooth height animation (GSAP, already loaded
            // globally) is skipped entirely for reduced-motion users
            // and when GSAP is unavailable - the text simply swaps.
            const animate = (fromHeight, toHeight, then) => {
                element.style.height = fromHeight + 'px';
                element.style.overflow = 'hidden';

                if (REDUCED_MOTION || !window.gsap) {
                    then();
                    return;
                }

                window.gsap.to(element, {
                    height: toHeight,
                    duration: 0.3,
                    ease: 'power2.out',
                    onComplete: then,
                });
            };

            button.addEventListener('click', () => {
                const isExpanding = button.getAttribute('aria-expanded') !== 'true';

                if (isExpanding) {
                    // Expand: swap the full text in, then animate from
                    // the measured collapsed height to the natural one.
                    const fromHeight = element.offsetHeight;
                    element.textContent = fullText;

                    animate(fromHeight, element.scrollHeight, () => {
                        element.style.height = '';
                        element.style.overflow = '';
                    });
                } else {
                    // Collapse: measure the short target height first,
                    // restore the full text, then animate down.
                    const fromHeight = element.offsetHeight;
                    element.textContent = shortText;
                    const toHeight = element.offsetHeight;
                    element.textContent = fullText;

                    animate(fromHeight, toHeight, () => {
                        element.textContent = shortText;
                        element.style.height = '';
                        element.style.overflow = '';
                    });
                }

                button.setAttribute('aria-expanded', isExpanding ? 'true' : 'false');
                button.textContent = isExpanding ? 'Read less' : 'Read more';
            });
        });
    };

    /* ---- 2. Loading skeletons while the list navigates ------------ */

    const showSkeletons = () => {
        document.querySelectorAll('[data-review-list]').forEach((container) => {
            container.classList.add('is-loading');
        });
    };

    const initToolbar = () => {
        // Any select inside a toolbar form (sort, per page) submits
        // the form on change - the state the form carries (search
        // term, active filters) is preserved automatically.
        document.querySelectorAll('form[data-review-toolbar]').forEach((form) => {
            form.addEventListener('submit', showSkeletons);

            form.querySelectorAll('select[data-review-select]').forEach((select) => {
                select.addEventListener('change', () => form.requestSubmit());
            });
        });
    };

    /* ---- 3. Helpful toggle (Phase 7.5) ----------------------------- */

    const popCounter = (counter) => {
        if (REDUCED_MOTION || !window.gsap || !counter) return;

        window.gsap.fromTo(counter, { scale: 1.45 }, {
            scale: 1,
            duration: 0.35,
            ease: 'back.out(2.5)',
        });
    };

    const initHelpful = () => {
        document.querySelectorAll('[data-helpful-form]').forEach((form) => {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();

                const button = form.querySelector('.review-helpful');
                const voted = button.classList.contains('is-active');
                const url = voted
                    ? button.dataset.helpfulRemoveUrl
                    : button.dataset.helpfulUrl;

                button.disabled = true;

                try {
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'fetch' },
                        body: new URLSearchParams(new FormData(form)),
                        credentials: 'same-origin',
                    });

                    if (!response.ok) {
                        throw new Error('Helpful toggle failed');
                    }

                    const payload = await response.json();

                    if (typeof payload.voted === 'boolean' && typeof payload.count === 'number') {
                        button.classList.toggle('is-active', payload.voted);
                        button.setAttribute('aria-pressed', payload.voted ? 'true' : 'false');
                        button.title = payload.voted
                            ? 'Remove your helpful vote'
                            : 'Mark this review as helpful';

                        const counter = button.querySelector('[data-helpful-count]');
                        counter.textContent = String(payload.count);
                        popCounter(counter);
                    }
                } catch (error) {
                    // Network or server hiccup: restore the previous
                    // state and let the user retry (the flash
                    // fallback would need a full page load).
                    window.alert('Something went wrong. Please try again.');
                } finally {
                    button.disabled = false;
                }
            });
        });
    };

    /* ---- 4. Report modal (Phase 7.5) ------------------------------- */

    const initReportModal = () => {
        const modal = document.getElementById('reviewReportModal');
        if (!modal) return;

        const form = modal.querySelector('#reviewReportForm');
        const successState = modal.querySelector('#reportSuccessState');
        const generalError = modal.querySelector('#reportGeneralError');
        const submitBtn = modal.querySelector('#reportSubmitBtn');

        const clearErrors = () => {
            form.querySelectorAll('.is-invalid').forEach((field) => field.classList.remove('is-invalid'));
            form.querySelectorAll('.invalid-feedback').forEach((box) => { box.textContent = ''; });
            generalError.classList.add('d-none');
            generalError.textContent = '';
        };

        const showFieldError = (name, message) => {
            const field = form.querySelector(`[name="${name}"]`);
            const box = form.querySelector(`#${name}Error`);

            field.classList.add('is-invalid');
            if (box) box.textContent = message;
        };

        const resetModal = () => {
            clearErrors();
            form.reset();
            form.hidden = false;
            successState.classList.add('d-none');
        };

        // The one modal serves every card: the clicked Report button
        // carries the review id that becomes the form action.
        modal.addEventListener('show.bs.modal', (event) => {
            const trigger = event.relatedTarget;
            const reviewId = trigger?.getAttribute('data-report-id');

            resetModal();
            form.setAttribute('action', `/reviews/${reviewId}/report`);
        });

        // Reopening always starts from the form state, never a stale
        // thank-you screen.
        modal.addEventListener('hidden.bs.modal', resetModal);

        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            clearErrors();
            submitBtn.disabled = true;

            try {
                const response = await fetch(form.getAttribute('action'), {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'fetch' },
                    body: new URLSearchParams(new FormData(form)),
                    credentials: 'same-origin',
                });

                const payload = await response.json().catch(() => ({}));

                if (response.ok) {
                    form.hidden = true;
                    successState.classList.remove('d-none');
                    return;
                }

                if (response.status === 422 && payload.errors) {
                    Object.entries(payload.errors).forEach(([field, messages]) => {
                        showFieldError(field, String(messages[0] ?? ''));
                    });
                    return;
                }

                generalError.textContent = payload.error || 'Something went wrong. Please try again.';
                generalError.classList.remove('d-none');
            } catch (error) {
                generalError.textContent = 'Something went wrong. Please try again.';
                generalError.classList.remove('d-none');
            } finally {
                submitBtn.disabled = false;
            }
        });
    };

    const init = () => {
        initReadMore();
        initToolbar();
        initHelpful();
        initReportModal();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
