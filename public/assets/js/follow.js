/**
 * follow.js — the Follow Authors toggle (Phase 9.2).
 *
 * Progressive enhancement over the server-rendered follow control
 * (components/follow-button.php), which stays a real CSRF form so the
 * no-JS path (plain POST + redirect + flash) always works:
 *
 *     1. SUBMIT INTERCEPT — every .follow-form submit (delegated, so
 *        controls added later are covered too) is sent via fetch with
 *        X-Requested-With: fetch; the FormData carries the CSRF token
 *        and, when the visitor already follows, the _method=DELETE
 *        the router uses to distinguish unfollow.
 *
 *     2. IN-PLACE REPAINT — the controller answers {following: bool};
 *        the control swaps its state: the button class and label
 *        (Follow <-> Following), the icon, aria-pressed / aria-label,
 *        the hidden _method input (so the NEXT click flips back) and
 *        the follower count link (+1 / -1). A small GSAP pop on the
 *        icon gives tactile feedback (skipped for reduced-motion).
 *
 *     3. ERROR SURFACING — any non-2xx answer (validation 422, rule
 *        failures 404/400/409, the write throttle 429) announces its
 *        server message into the control's own aria-live region; the
 *        button re-enables in the unchanged state, because the server
 *        is the single source of truth and never got the change.
 *
 * The module never calls the notification endpoints (deferred phase)
 * and never reaches across to other modules' scripts.
 */
(() => {
    'use strict';

    const REDUCED_MOTION = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

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

    /* The control's own aria-live region: server messages land here,
       politely, for assistive technology and anyone watching. */
    const announce = (control, message) => {
        const live = control.querySelector('[data-follow-status]');
        if (live) live.textContent = message;
    };

    /* Swap the whole control to the given following state. The count
       link moves with the state (the server's answer is the truth;
       the +1 / -1 keeps the page's other reader of the number in
       sync until the next full render). */
    const setState = (control, following) => {
        const form   = control.querySelector('[data-follow-form]');
        const button = control.querySelector('[data-follow-button]');
        const icon   = control.querySelector('[data-follow-icon]');
        const label  = control.querySelector('[data-follow-label]');
        const count  = control.querySelector('[data-follow-count]');
        const name   = button?.getAttribute('data-author-name') || 'this author';

        form.setAttribute('data-current', following ? '1' : '0');

        // The hidden _method=DELETE input travels only when following:
        // the router's method override turns the next submit into an
        // unfollow, and its absence makes it a follow again.
        let methodInput = form.querySelector('[data-follow-method]');
        if (following && !methodInput) {
            methodInput = document.createElement('input');
            methodInput.type = 'hidden';
            methodInput.name = '_method';
            methodInput.value = 'DELETE';
            methodInput.setAttribute('data-follow-method', '');
            form.appendChild(methodInput);
        } else if (!following && methodInput) {
            methodInput.remove();
        }

        button.classList.toggle('btn-following', following);
        button.classList.toggle('btn-follow', !following);
        button.setAttribute('aria-pressed', following ? 'true' : 'false');
        button.setAttribute('aria-label', (following ? 'Unfollow ' : 'Follow ') + name);
        icon.className = 'fa-solid ' + (following ? 'fa-circle-check' : 'fa-square-plus');
        label.textContent = following ? 'Following' : 'Follow';

        if (count) {
            const next = Math.max(0, (parseInt(count.textContent, 10) || 0) + (following ? 1 : -1));
            count.textContent = String(next);
            count.closest('a')?.setAttribute(
                'aria-label',
                next + ' follower' + (next === 1 ? '' : 's')
            );
        }

        announce(control, following
            ? 'You are now following ' + name + '.'
            : 'You unfollowed ' + name + '.');
    };

    /* The icon pop: a short GSAP spring, guarded by reduced-motion
       and the presence of GSAP (the state swap alone still answers). */
    const pop = (button) => {
        if (REDUCED_MOTION || !window.gsap || !button) return;
        const icon = button.querySelector('i');
        if (!icon) return;
        window.gsap.fromTo(icon, { scale: 0.6 }, {
            scale: 1,
            duration: 0.35,
            ease: 'back.out(2)',
        });
    };

    /* One delegated handler covers every follow form on the page. */
    document.addEventListener('submit', async (event) => {
        const form = event.target.closest('[data-follow-form]');
        if (!form) return;

        event.preventDefault();

        const control = form.closest('[data-follow-control]');
        const button  = form.querySelector('[data-follow-button]');
        if (!control || !button || button.disabled) return;

        const following = form.getAttribute('data-current') === '1';

        form.classList.add('is-busy');
        button.disabled = true;
        announce(control, '');

        try {
            const payload = await toPayload(await fetch(form.action, {
                method: 'POST',
                headers: { 'X-Requested-With': 'fetch' },
                body: new URLSearchParams(new FormData(form)),
                credentials: 'same-origin',
            }));
            setState(control, Boolean(payload.following));
            pop(button);
        } catch (error) {
            announce(control, error.message);
        } finally {
            form.classList.remove('is-busy');
            button.disabled = false;
        }
    });
})();
