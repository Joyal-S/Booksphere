/**
 * settings.js — the Settings page (Phase 9.5).
 *
 * Progressive enhancement over the server-rendered form: the toggle
 * form [data-settings-form] is a real CSRF POST to
 * /settings/email-preferences, so the no-JS path (native submit +
 * redirect + flash) always works. With JavaScript this module
 * intercepts the submit and sends it via fetch (the dual-answer
 * convention every write endpoint in the app speaks):
 *
 *     1. SUBMIT     — the form is posted with X-Requested-With: fetch
 *     2. ANNOUNCE   — the [data-settings-status] aria-live region
 *        shows "Saved ✓" (or the server's error message) in place,
 *        without a page reload
 *     3. NO-JS      — if fetch is unavailable, the native submit runs
 *        untouched (the region also renders the flash via the
 *        session).
 */
(() => {
    'use strict';

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

    /* A CSRF-protected fetch submission of a real form (its _token
       input travels with it). */
    const fetchForm = (form) => fetch(form.action, {
        method: 'POST',
        headers: { 'X-Requested-With': 'fetch' },
        body: new URLSearchParams(new FormData(form)),
        credentials: 'same-origin',
    });

    /* Show the result in the aria-live status region (success green,
       error red), clearing it after a few seconds. */
    const announce = (form, message, isError = false) => {
        const status = form.querySelector('[data-settings-status]');
        if (!status) return;

        status.textContent = message;
        status.classList.toggle('is-success', !isError);
        status.classList.toggle('is-error', isError);

        window.clearTimeout(announce._timer);
        announce._timer = window.setTimeout(() => {
            status.textContent = '';
            status.classList.remove('is-success', 'is-error');
        }, 4500);
    };

    const init = () => {
        const form = document.querySelector('[data-settings-form]');
        if (!form) return;

        form.addEventListener('submit', (event) => {
            if (!('fetch' in window)) return; /* the no-JS path */

            event.preventDefault();

            const button = form.querySelector('button[type="submit"]');
            const original = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2" aria-hidden="true"></i>Saving…';

            fetchForm(form)
                .then((response) => {
                    if (response.redirected && !response.ok) {
                        window.location.href = response.url;
                        return null;
                    }
                    return toPayload(response);
                })
                .then((payload) => {
                    if (payload === null) return;
                    announce(form, 'Saved — your email preferences are up to date.');
                    button.disabled = false;
                    button.innerHTML = original;
                })
                .catch((error) => {
                    announce(form, error.message || 'Could not save — please try again.', true);
                    button.disabled = false;
                    button.innerHTML = original;
                });
        });
    };

    if (document.readyState !== 'loading') {
        init();
    } else {
        document.addEventListener('DOMContentLoaded', init);
    }
})();