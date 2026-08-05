/**
 * auth.js
 *
 * Frontend behaviour of the standalone authentication pages
 * (layouts/auth.php). Everything here is progressive enhancement:
 * the pages are fully functional with JavaScript disabled (real form
 * POSTs, server-side validation, server-rendered success states).
 *
 * With JavaScript enabled it provides:
 *
 *   1. the dark/light toggle - stored under the SAME
 *      "booksphere-theme" key the app shell uses, so the theme
 *      chosen here carries into the dashboard after login;
 *   2. password visibility toggles ([data-auth-eye]);
 *   3. the live password strength meter ([data-auth-strength]);
 *   4. client-side validation mirroring the server rules, shown in
 *      the same per-field UI the server errors use;
 *   5. a loading state on submit buttons ([data-auth-submit]).
 *
 * Loaded after the markup, so no DOMContentLoaded wrapper is needed.
 */
(function () {
    'use strict';

    /* ---------- 1. Theme toggle (shared preference) ---------- */
    var root = document.documentElement;
    var toggle = document.getElementById('auth-theme-toggle');

    var syncIcons = function () {
        var dark = root.dataset.bsTheme === 'dark';
        var moon = document.getElementById('auth-icon-moon');
        var sun = document.getElementById('auth-icon-sun');

        if (moon) { moon.classList.toggle('auth-hidden', dark); }
        if (sun) { sun.classList.toggle('auth-hidden', !dark); }
        if (toggle) { toggle.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode'); }
    };

    if (toggle) {
        toggle.addEventListener('click', function () {
            var next = root.dataset.bsTheme === 'dark' ? 'light' : 'dark';
            root.dataset.bsTheme = next;

            try {
                localStorage.setItem('booksphere-theme', next);
            } catch (e) {}

            syncIcons();
        });
    }

    syncIcons();

    /* ---------- 2. Password visibility toggles ---------- */
    var toggles = document.querySelectorAll('[data-auth-eye]');

    toggles.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById(btn.getAttribute('data-auth-eye'));

            if (!input) {
                return;
            }

            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    });

    /* ---------- 3. Password strength meter ---------- */
    var meter = document.querySelector('[data-auth-strength]');
    var strengthInput = null;
    var strengthBars = [];
    var strengthLabel = null;

    var strengthMeta = [
        null,
        { label: 'Weak password',   color: '#c43131' },
        { label: 'Fair password',   color: '#a55c00' },
        { label: 'Good password',   color: '#2563eb' },
        { label: 'Strong password', color: '#16803c' },
    ];

    var strengthScore = function (pw) {
        if (!pw) { return 0; }
        if (pw.length < 6) { return 1; }
        if (pw.length < 8) { return 2; }

        var strong = /[A-Z]/.test(pw) && /[0-9]/.test(pw);
        var symbol = /[^A-Za-z0-9]/.test(pw);

        return strong && symbol ? 4 : strong ? 3 : 2;
    };

    if (meter) {
        var host = meter.closest('.auth-field');

        strengthInput = host ? host.querySelector('input') : null;
        strengthBars = Array.prototype.slice.call(meter.querySelectorAll('[data-auth-bar]'));
        strengthLabel = meter.querySelector('[data-auth-strength-label]');

        var paintStrength = function (value) {
            var score = strengthScore(value);

            if (!value) {
                meter.classList.add('auth-hidden');
                return;
            }

            meter.classList.remove('auth-hidden');

            var meta = strengthMeta[score];
            strengthBars.forEach(function (bar, i) {
                bar.style.background = i < score ? meta.color : 'var(--au-border)';
            });

            strengthLabel.textContent = meta.label;
            strengthLabel.style.color = meta.color;
        };

        if (strengthInput) {
            strengthInput.addEventListener('input', function () {
                paintStrength(strengthInput.value);
            });
        }
    }

    /* ---------- 4. Client-side validation ---------- */
    var EMAIL_RE = /\S+@\S+\.\S+/;

    var RULES = {
        full_name: {
            required: { ok: function (v) { return v.trim() !== ''; }, message: 'Full name is required.' },
        },
        email: {
            required: { ok: function (v) { return v.trim() !== ''; }, message: 'Email address is required.' },
            email:    { ok: function (v) { return EMAIL_RE.test(v); }, message: 'Enter a valid email address.' },
        },
        password: {
            required: { ok: function (v) { return v !== ''; }, message: 'Password is required.' },
            min:      { ok: function (v) { return v.length >= 8; }, message: 'Password must be at least 8 characters.' },
        },
        password_confirmation: {
            required: { ok: function (v) { return v !== ''; }, message: 'Please confirm your password.' },
            matches:  { ok: function (v, form) { return v === form.password.value; }, message: 'Passwords do not match.' },
        },
        terms: {
            checked:  { ok: function (v, form, el) { return el.checked; }, message: 'You must accept the Terms of Service to continue.' },
        },
    };

    var errorElement = function (name) {
        return document.querySelector('[data-auth-error="' + name + '"]');
    };

    var showError = function (el, message) {
        var field = el.closest('.auth-field');
        var err = errorElement(el.name);

        if (field) { field.classList.add('auth-field--error'); }
        if (err) {
            err.classList.remove('auth-hidden');
            err.querySelector('span').textContent = message;
        }
        el.setAttribute('aria-invalid', 'true');
    };

    var clearError = function (el) {
        var field = el.closest('.auth-field');
        var err = errorElement(el.name);

        if (field) { field.classList.remove('auth-field--error'); }
        if (err) {
            err.classList.add('auth-hidden');
            err.querySelector('span').textContent = '';
        }
        el.removeAttribute('aria-invalid');
    };

    var validateElement = function (el, form) {
        var rules = RULES[el.name];

        if (!rules) {
            return true;
        }

        for (var key in rules) {
            if (Object.prototype.hasOwnProperty.call(rules, key)) {
                if (!rules[key].ok(el.value, form, el)) {
                    showError(el, rules[key].message);
                    return false;
                }
            }
        }

        clearError(el);
        return true;
    };

    /* ---------- 5. Loading state on submit ---------- */
    var spinner =
        '<span class="auth-spin" aria-hidden="true"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg></span> ';

    var setLoading = function (btn) {
        btn.disabled = true;
        btn.innerHTML = spinner + btn.textContent.trim();
    };

    var forms = document.querySelectorAll('[data-auth-form]');

    forms.forEach(function (form) {
        // The browser's own bubbles would fight the styled inline
        // errors; JS now owns client-side validation.
        form.noValidate = true;

        form.addEventListener('submit', function (e) {
            var valid = true;

            Array.prototype.forEach.call(form.elements, function (el) {
                if (!validateElement(el, form)) {
                    valid = false;
                }
            });

            if (!valid) {
                e.preventDefault();
                return;
            }

            var submit = form.querySelector('[data-auth-submit]');
            if (submit) {
                setLoading(submit);
            }
        });

        Array.prototype.forEach.call(form.elements, function (el) {
            if (RULES[el.name]) {
                el.addEventListener('input', function () {
                    clearError(el);
                });
            }
        });
    });
})();